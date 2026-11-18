<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\TripItemType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Coupon;
use App\Models\HotelRoom;
use App\Models\Payment;
use App\Models\TourActivity;
use App\Models\TourGuide;
use App\Models\User;
use App\Models\UserTrip;
use App\Services\CouponService;
use App\Services\Hotel\InventoryService;
use App\Services\Payments\PaymentService;
use App\Services\SettingsService;
use App\Services\Tour\TourPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Books a custom trip. Under one transaction: lock the trip, reprice from scratch, re-verify every item's
 * availability with row locks (hotel inventory, activity slots, guides), apply coupon, reserve, create payment.
 */
class TourBookingService
{
    public function __construct(
        private readonly TourPricingService $pricing,
        private readonly InventoryService $inventory,
        private readonly CouponService $coupons,
        private readonly PaymentService $payments,
        private readonly SettingsService $settings,
        private readonly BookingLifecycleService $lifecycle,
    ) {}

    /** Price summary shown on the checkout page (including a coupon if supplied). */
    public function quote(UserTrip $trip, User $user, ?string $couponCode): array
    {
        $this->assertBookable($trip);
        $trip = $this->pricing->reprice($trip);
        $discount = 0.0;
        $coupon = null;
        if ($couponCode) {
            $r = $this->coupons->evaluate($couponCode, 'tours', $trip->subtotal, $user, ['tour_package_id' => $trip->tour_package_id]);
            $discount = $r['discount'];
            $coupon = $r['coupon'];
        }
        $totals = $this->pricing->totalsWithDiscount($trip, $discount);
        $unavailable = $trip->days->flatMap->items->where('is_available', false)->values();

        return array_merge($totals, [
            'trip_id' => $trip->id,
            'currency' => $trip->currency,
            'coupon_code' => $coupon?->code,
            'coupon_id' => $coupon?->id,
            'groups' => $trip->price_breakdown['groups'] ?? [],
            'tax_percent' => $trip->price_breakdown['tax_percent'] ?? 0,
            'service_fee_percent' => $trip->price_breakdown['service_fee_percent'] ?? 0,
            'travelers' => $trip->travelerCount(),
            'start_date' => $trip->start_date?->toDateString(),
            'end_date' => $trip->start_date?->copy()->addDays(max(0, $trip->days->count() - 1))->toDateString(),
            'bookable' => $unavailable->isEmpty(),
            'unavailable_items' => $unavailable->map(fn ($i) => ['id' => $i->id, 'title' => $i->title, 'note' => $i->availability_note])->all(),
        ]);
    }

    /** @return array{booking: Booking, payment: ?Payment} */
    public function create(User $user, UserTrip $trip, array $input): array
    {
        $gateway = null;
        try {
            $result = DB::transaction(function () use ($user, $trip, $input, &$gateway) {
                $locked = UserTrip::query()->lockForUpdate()->findOrFail($trip->id);
                $this->assertBookable($locked);

                // Authoritative reprice — the client's total is never used.
                $locked = $this->pricing->reprice($locked);
                $items = $locked->days->flatMap(fn ($d) => $d->items->each(fn ($i) => $i->setRelation('day', $d)));

                $unavailable = $items->where('is_available', false);
                if ($unavailable->isNotEmpty()) {
                    $first = $unavailable->first();
                    throw DomainException::make("“{$first->title}” is unavailable: {$first->availability_note}", 'tour_item_unavailable', 409, [
                        'items' => $unavailable->map(fn ($i) => ['id' => $i->id, 'title' => $i->title, 'note' => $i->availability_note])->values()->all(),
                    ]);
                }

                $discount = 0.0;
                $coupon = null;
                if (! empty($input['coupon_code'])) {
                    $r = $this->coupons->evaluate($input['coupon_code'], 'tours', $locked->subtotal, $user, ['tour_package_id' => $locked->tour_package_id]);
                    $discount = $r['discount'];
                    $coupon = $r['coupon'];
                }
                $totals = $this->pricing->totalsWithDiscount($locked, $discount);

                if (isset($input['expected_total']) && abs((float) $input['expected_total'] - $totals['total_amount']) > 0.009) {
                    throw DomainException::make('Your trip price has changed. Please review the updated total.', 'price_changed', 409, ['total_amount' => $totals['total_amount']]);
                }

                $gateway = $totals['total_amount'] > 0 ? $this->payments->resolveGateway($input['gateway'] ?? null) : null;
                $start = CarbonImmutable::parse($locked->start_date);
                $end = $start->addDays(max(0, $locked->days->count() - 1));

                $booking = new Booking();
                $booking->forceFill([
                    'booking_number' => BookingNumber::make('T'),
                    'user_id' => $user->id,
                    'type' => Booking::TYPE_TOUR,
                    'booking_type' => Booking::BOOKING_REGULAR,
                    'user_trip_id' => $locked->id,
                    'check_in' => $start->toDateString(),
                    'check_out' => $end->toDateString(),
                    'nights' => $locked->days->count() - 1,
                    'rooms_count' => 0,
                    'adults' => $locked->adults,
                    'children' => $locked->children,
                    'status' => BookingStatus::PaymentPending,
                    'payment_status' => PaymentStatus::Pending,
                    'currency' => $locked->currency,
                    'room_amount' => $totals['subtotal'],
                    'subtotal' => $totals['subtotal'],
                    'coupon_id' => $coupon?->id,
                    'coupon_code' => $coupon?->code,
                    'discount_amount' => $totals['discount_amount'],
                    'tax_amount' => $totals['tax_amount'],
                    'service_fee' => $totals['service_fee'],
                    'total_amount' => $totals['total_amount'],
                    'price_breakdown' => array_merge($locked->price_breakdown ?? [], $totals),
                    'itinerary_snapshot' => $this->snapshot($locked),
                    'contact_name' => $input['contact_name'],
                    'contact_email' => $input['contact_email'],
                    'contact_phone' => $input['contact_phone'],
                    'special_requests' => $input['special_requests'] ?? null,
                    'hold_expires_at' => now()->addMinutes($this->settings->int('booking.payment_hold_minutes')),
                    'ip_address' => request()?->ip(),
                ])->save();

                // Reserve hotel inventory for every accommodation item (locks rows per room, ordered by date).
                foreach ($items->where('item_type', TripItemType::Accommodation)->sortBy('itemable_id') as $item) {
                    $room = HotelRoom::query()->findOrFail($item->itemable_id);
                    $in = $start->addDays($item->day->day_number - 1);
                    $out = $in->addDays(max(1, (int) $item->nights));
                    $rates = $this->inventory->nightlyRates($room, $in->toDateString(), $out->toDateString());
                    $this->inventory->reserve($room, $in->toDateString(), $out->toDateString(), max(1, (int) $item->quantity));
                    $booking->rooms()->create([
                        'hotel_id' => $room->hotel_id,
                        'hotel_room_id' => $room->id,
                        'check_in' => $in->toDateString(),
                        'check_out' => $out->toDateString(),
                        'quantity' => max(1, (int) $item->quantity),
                        'nights' => count($rates),
                        'nightly_rates' => $rates,
                        'amount' => array_sum($rates) * max(1, (int) $item->quantity),
                    ]);
                }

                // Re-verify activity slot capacity and guide availability under row locks.
                foreach ($items->where('itemable_type', 'tour_activity') as $item) {
                    $activity = TourActivity::query()->lockForUpdate()->findOrFail($item->itemable_id);
                    $date = $start->addDays($item->day->day_number - 1)->toDateString();
                    $taken = $this->pricing->bookedActivitySeats($activity->id, $date, $item->start_time, $locked->id);
                    if ($taken + $item->quantity > $activity->capacity_per_slot) {
                        throw DomainException::make("“{$activity->name}” just filled up for that time slot. Please pick another time.", 'tour_item_unavailable', 409);
                    }
                }
                foreach ($items->where('itemable_type', 'tour_guide') as $item) {
                    $guide = TourGuide::query()->lockForUpdate()->findOrFail($item->itemable_id);
                    $date = $start->addDays($item->day->day_number - 1)->toDateString();
                    if ($this->pricing->guideBookedOn($guide->id, $date, $locked->id)) {
                        throw DomainException::make("{$guide->name} was just booked by another traveller on that date.", 'tour_item_unavailable', 409);
                    }
                }

                // Travellers
                $locked->travelers()->delete();
                foreach (array_values($input['travelers']) as $i => $t) {
                    $locked->travelers()->create([
                        'full_name' => $t['full_name'],
                        'traveler_type' => $t['traveler_type'] ?? 'adult',
                        'age' => $t['age'] ?? null,
                        'gender' => $t['gender'] ?? null,
                        'nationality' => $t['nationality'] ?? null,
                        'email' => $t['email'] ?? null,
                        'phone' => $t['phone'] ?? null,
                        'is_lead' => $i === 0,
                    ]);
                    $booking->guests()->create([
                        'full_name' => $t['full_name'],
                        'guest_type' => $t['traveler_type'] ?? 'adult',
                        'age' => $t['age'] ?? null,
                        'email' => $t['email'] ?? null,
                        'phone' => $t['phone'] ?? null,
                        'is_primary' => $i === 0,
                    ]);
                }

                // Trip is locked from edits while the booking holds its resources.
                $locked->forceFill(['status' => 'booked'])->save();

                if ($coupon) {
                    $this->coupons->reserve(Coupon::query()->findOrFail($coupon->id), $user, $totals['discount_amount'], $booking);
                }

                $payment = null;
                if ($totals['total_amount'] > 0) {
                    $payment = $this->payments->createPayment($user, $booking, $totals['total_amount'], 'booking', $gateway);
                } else {
                    $this->lifecycle->confirmWithoutPayment($booking);
                }

                return ['booking' => $booking, 'payment' => $payment];
            }, 3);

            Log::channel('bookings')->info('Tour booking created', ['booking' => $result['booking']->booking_number, 'trip' => $trip->id, 'total' => $result['booking']->total_amount]);

            return $result;
        } catch (DomainException $e) {
            Log::channel('bookings')->info('Tour booking rejected', ['trip' => $trip->id, 'code' => $e->errorCode, 'reason' => $e->getMessage()]);
            throw $e;
        } catch (Throwable $e) {
            Log::channel('bookings')->error('Tour booking failed', ['trip' => $trip->id, 'error' => $e->getMessage()]);
            if (str_contains($e->getMessage(), 'chk_inventory_not_oversold')) {
                throw DomainException::make('A hotel in your trip just sold out for those dates. Please choose another room.', 'tour_item_unavailable', 409);
            }
            throw $e;
        }
    }

    public function assertBookable(UserTrip $trip): void
    {
        if ($trip->status !== 'draft') {
            throw DomainException::make('This trip has already been booked.', 'trip_locked', 409);
        }
        if (! $trip->start_date) {
            throw DomainException::make('Please choose a start date for your trip before booking.', 'start_date_required');
        }
        if (CarbonImmutable::parse($trip->start_date)->lt(CarbonImmutable::today()->addDay())) {
            throw DomainException::make('Trips must start at least one day from today.', 'invalid_dates');
        }
        if (! $trip->items()->exists()) {
            throw DomainException::make('Add at least one item to your itinerary before booking.', 'empty_trip');
        }
    }

    private function snapshot(UserTrip $trip): array
    {
        return [
            'title' => $trip->title,
            'destination_summary' => $trip->destination_summary,
            'start_date' => $trip->start_date?->toDateString(),
            'adults' => $trip->adults,
            'children' => $trip->children,
            'days' => $trip->days->map(fn ($d) => [
                'day_number' => $d->day_number,
                'date' => $trip->start_date?->copy()->addDays($d->day_number - 1)->toDateString(),
                'title' => $d->title,
                'items' => $d->items->map(fn ($i) => [
                    'type' => $i->item_type->value,
                    'title' => $i->title,
                    'provider' => $i->provider,
                    'from' => $i->from_location,
                    'to' => $i->to_location,
                    'start_time' => $i->start_time ? substr($i->start_time, 0, 5) : null,
                    'quantity' => $i->quantity,
                    'nights' => $i->nights,
                    'hours' => $i->hours,
                    'meal_plan' => $i->meal_plan,
                    'notes' => $i->notes,
                    'total_price' => $i->total_price,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
