<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Coupon;
use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\User;
use App\Services\CouponService;
use App\Services\Hotel\HotelPricingService;
use App\Services\Hotel\InventoryService;
use App\Services\Membership\MembershipRulesService;
use App\Services\Membership\MembershipWalletService;
use App\Services\Payments\PaymentService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hotel booking creation — the 9-step consistency protocol, all inside ONE database transaction:
 *   1 validate stay  2 lock inventory rows  3 recalculate price  4 lock + validate membership balance
 *   5 create booking  6 deduct inventory  7 deduct membership nights  8 create payment  9 commit
 * Anything that throws rolls the whole thing back — no partial bookings, no leaked inventory or nights.
 */
class HotelBookingService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly HotelPricingService $pricing,
        private readonly MembershipRulesService $rules,
        private readonly MembershipWalletService $wallet,
        private readonly CouponService $coupons,
        private readonly PaymentService $payments,
        private readonly SettingsService $settings,
        private readonly BookingLifecycleService $lifecycle,
    ) {}

    /**
     * @param array $input validated request data
     * @return array{booking: Booking, payment: ?Payment}
     */
    public function create(User $user, array $input): array
    {
        $room = HotelRoom::query()->with('seasonalPrices')->findOrFail($input['hotel_room_id']);
        $hotel = Hotel::query()->findOrFail($room->hotel_id);
        $isMembership = $input['booking_type'] === Booking::BOOKING_MEMBERSHIP;
        $gateway = null;

        try {
            $result = DB::transaction(function () use ($user, $input, $room, $hotel, $isMembership, &$gateway) {
                // (1) stay validation (dates, occupancy, status)
                $this->pricing->assertStayIsValid($hotel, $room, $input);

                // (2) lock inventory rows for every night and verify availability
                $nights = $this->inventory->nights($input['check_in'], $input['check_out']);
                $rows = $this->inventory->lockRows($room, $nights);
                foreach ($nights as $date) {
                    $row = $rows->get($date);
                    if (! $row || $row->available() < (int) $input['rooms']) {
                        throw DomainException::make(
                            "Sorry — {$room->name} is no longer available for your dates. Please choose different dates or another room.",
                            'room_unavailable',
                            409,
                            ['date' => $date],
                        );
                    }
                }

                // (4) membership: lock the membership row so the balance can't change under us
                $membership = null;
                if ($isMembership) {
                    $current = $this->rules->requireUsableMembership($user);
                    $membership = Membership::query()->lockForUpdate()->findOrFail($current->id);
                }

                // (3) authoritative price (never from the client)
                $quote = $this->pricing->quote($hotel, $room, $input, $user, $membership);

                if (isset($input['expected_total']) && abs((float) $input['expected_total'] - $quote['total_amount']) > 0.009) {
                    // Price moved since the customer saw it — make them review the new total instead of charging silently.
                    throw DomainException::make('The price for your stay has changed. Please review the updated total.', 'price_changed', 409, ['total_amount' => $quote['total_amount']]);
                }

                $gateway = $quote['total_amount'] > 0 ? $this->payments->resolveGateway($input['gateway'] ?? null) : null;

                // (5) create booking
                $booking = new Booking();
                $booking->forceFill([
                    'booking_number' => BookingNumber::make('H'),
                    'user_id' => $user->id,
                    'type' => Booking::TYPE_HOTEL,
                    'booking_type' => $quote['booking_type'],
                    'hotel_id' => $hotel->id,
                    'membership_id' => $membership?->id,
                    'check_in' => $input['check_in'],
                    'check_out' => $input['check_out'],
                    'nights' => $quote['nights'],
                    'rooms_count' => $quote['rooms'],
                    'adults' => (int) $input['adults'],
                    'children' => (int) ($input['children'] ?? 0),
                    'status' => BookingStatus::PaymentPending,
                    'payment_status' => PaymentStatus::Pending,
                    'currency' => $quote['currency'],
                    'room_amount' => $quote['room_amount'],
                    'membership_nights_used' => $quote['membership_nights_used'],
                    'membership_covered_amount' => $quote['membership_covered_amount'],
                    'subtotal' => $quote['subtotal'],
                    'coupon_id' => $quote['coupon_id'],
                    'coupon_code' => $quote['coupon_code'],
                    'discount_amount' => $quote['discount_amount'],
                    'tax_amount' => $quote['tax_amount'],
                    'service_fee' => $quote['service_fee'],
                    'total_amount' => $quote['total_amount'],
                    'price_breakdown' => $quote,
                    'contact_name' => $input['contact_name'],
                    'contact_email' => $input['contact_email'],
                    'contact_phone' => $input['contact_phone'],
                    'special_requests' => $input['special_requests'] ?? null,
                    'hold_expires_at' => now()->addMinutes($this->settings->int('booking.payment_hold_minutes')),
                    'ip_address' => request()?->ip(),
                ])->save();

                $booking->rooms()->create([
                    'hotel_id' => $hotel->id,
                    'hotel_room_id' => $room->id,
                    'check_in' => $input['check_in'],
                    'check_out' => $input['check_out'],
                    'quantity' => $quote['rooms'],
                    'nights' => $quote['nights'],
                    'nightly_rates' => $quote['nightly_rates'],
                    'amount' => $quote['room_amount'],
                ]);

                $guests = $input['guests'] ?? [];
                if (! $guests) {
                    $guests = [['full_name' => $input['contact_name'], 'guest_type' => 'adult', 'email' => $input['contact_email'], 'phone' => $input['contact_phone']]];
                }
                foreach (array_values($guests) as $i => $g) {
                    $booking->guests()->create([
                        'full_name' => $g['full_name'],
                        'guest_type' => $g['guest_type'] ?? 'adult',
                        'age' => $g['age'] ?? null,
                        'email' => $g['email'] ?? null,
                        'phone' => $g['phone'] ?? null,
                        'is_primary' => $i === 0,
                    ]);
                }

                // (6) deduct inventory (rows already locked; CHECK constraint is the last line of defence)
                $this->inventory->reserve($room, $input['check_in'], $input['check_out'], $quote['rooms']);

                // (7) deduct membership nights through the ledger (idempotent, lock held)
                if ($membership) {
                    $this->wallet->deductForBooking($membership, $booking, $quote['membership_nights_used']);
                }

                if ($quote['coupon_id']) {
                    $this->coupons->reserve(Coupon::query()->findOrFail($quote['coupon_id']), $user, $quote['discount_amount'], $booking);
                }

                // (8) payment record — or confirm immediately when nothing is payable
                $payment = null;
                if ($quote['total_amount'] > 0) {
                    $payment = $this->payments->createPayment($user, $booking, $quote['total_amount'], 'booking', $gateway);
                } else {
                    $this->lifecycle->confirmWithoutPayment($booking);
                }

                return ['booking' => $booking, 'payment' => $payment];
            }, 3); // retry on deadlock

            Log::channel('bookings')->info('Hotel booking created', [
                'booking' => $result['booking']->booking_number, 'user_id' => $user->id, 'type' => $input['booking_type'],
                'total' => $result['booking']->total_amount,
            ]);

            return $result;
        } catch (DomainException $e) {
            Log::channel('bookings')->info('Hotel booking rejected', ['user_id' => $user->id, 'room_id' => $room->id, 'code' => $e->errorCode, 'reason' => $e->getMessage()]);
            throw $e;
        } catch (Throwable $e) {
            Log::channel('bookings')->error('Hotel booking failed', ['user_id' => $user->id, 'room_id' => $room->id, 'error' => $e->getMessage()]);
            if (str_contains($e->getMessage(), 'chk_inventory_not_oversold')) {
                throw DomainException::make('Sorry — that room just sold out. Please choose other dates or another room.', 'room_unavailable', 409);
            }
            if (str_contains($e->getMessage(), 'chk_nights_balance')) {
                throw DomainException::make('You do not have enough membership nights for this stay.', 'insufficient_membership_nights', 422);
            }
            throw $e;
        }
    }
}
