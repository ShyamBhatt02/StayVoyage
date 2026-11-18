<?php

namespace App\Services\Hotel;

use App\Exceptions\DomainException;
use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\Membership;
use App\Models\User;
use App\Services\CouponService;
use App\Services\Membership\MembershipRulesService;
use App\Services\SettingsService;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Authoritative hotel price calculation. The frontend only ever displays the result of quote().
 *
 * Regular:     room_amount = Σ nightly rate × rooms;  fee = max(min_fee, pct × (room - discount));
 *              tax = pct × (room - discount);  total = room - discount + tax + fee
 * Membership:  covered = room_amount; tax on covered amount (if plan says so); flat booking fee;
 *              total = tax + fee
 */
class HotelPricingService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SettingsService $settings,
        private readonly CouponService $coupons,
        private readonly MembershipRulesService $rules,
    ) {}

    /**
     * @param array{check_in:string,check_out:string,rooms:int,adults:int,children:int,booking_type:string,coupon_code?:?string} $input
     */
    public function quote(Hotel $hotel, HotelRoom $room, array $input, ?User $user, ?Membership $membership = null): array
    {
        $this->assertStayIsValid($hotel, $room, $input);

        $checkIn = $input['check_in'];
        $checkOut = $input['check_out'];
        $rooms = (int) $input['rooms'];
        $guests = (int) $input['adults'] + (int) ($input['children'] ?? 0);
        $isMembership = ($input['booking_type'] ?? 'regular') === 'membership';

        $rates = $this->inventory->nightlyRates($room, $checkIn, $checkOut);
        $nights = count($rates);
        $roomAmount = Money::round(array_sum($rates) * $rooms);

        $taxPct = $this->settings->float('pricing.hotel_tax_percent');
        $currency = $this->settings->get('site.currency', 'INR');

        $quote = [
            'hotel_id' => $hotel->id,
            'hotel_room_id' => $room->id,
            'booking_type' => $isMembership ? 'membership' : 'regular',
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'nights' => $nights,
            'rooms' => $rooms,
            'guests' => $guests,
            'currency' => $currency,
            'nightly_rates' => $rates,
            'room_amount' => $roomAmount,
            'membership_nights_used' => 0,
            'membership_covered_amount' => 0.0,
            'subtotal' => $roomAmount,
            'discount_amount' => 0.0,
            'coupon_code' => null,
            'coupon_id' => null,
            'tax_percent' => $taxPct,
            'tax_amount' => 0.0,
            'service_fee' => 0.0,
            'total_amount' => 0.0,
            'membership' => null,
        ];

        if ($isMembership) {
            if (! $user) {
                throw DomainException::make('Please log in to book with membership nights.', 'unauthenticated', 401);
            }
            $membership ??= $this->rules->requireUsableMembership($user);
            $needed = $this->rules->assertBookable($membership, $hotel, $room, $checkIn, $checkOut, $rooms, $guests);

            $taxable = $membership->rule('charge_taxes_on_covered_amount', true) ? $roomAmount : 0;
            $quote['membership_nights_used'] = $needed;
            $quote['membership_covered_amount'] = $roomAmount;
            $quote['subtotal'] = 0.0;
            $quote['tax_amount'] = Money::percent($taxable, $taxPct);
            $quote['service_fee'] = Money::round($this->settings->float('pricing.membership_booking_service_fee'));
            $quote['membership'] = [
                'id' => $membership->id,
                'balance_before' => $membership->nights_balance,
                'balance_after' => $membership->nights_balance - $needed,
                'expires_at' => $membership->expires_at?->toIso8601String(),
            ];
            if (! empty($input['coupon_code'])) {
                throw DomainException::make('Coupons cannot be combined with membership bookings.', 'invalid_coupon');
            }
        } else {
            $discount = 0.0;
            if (! empty($input['coupon_code'])) {
                if (! $user) {
                    throw DomainException::make('Please log in to apply a coupon.', 'unauthenticated', 401);
                }
                $result = $this->coupons->evaluate($input['coupon_code'], 'hotels', $roomAmount, $user, ['hotel_id' => $hotel->id]);
                $discount = $result['discount'];
                $quote['coupon_code'] = $result['coupon']->code;
                $quote['coupon_id'] = $result['coupon']->id;
            }
            $afterDiscount = Money::round($roomAmount - $discount);
            $feePct = $this->settings->float('pricing.hotel_service_fee_percent');
            $feeMin = $this->settings->float('pricing.hotel_service_fee_min');

            $quote['discount_amount'] = $discount;
            $quote['tax_amount'] = Money::percent($afterDiscount, $taxPct);
            $quote['service_fee'] = $afterDiscount > 0 ? Money::round(max($feeMin, Money::percent($afterDiscount, $feePct))) : 0.0;
        }

        $quote['total_amount'] = Money::round($quote['subtotal'] - $quote['discount_amount'] + $quote['tax_amount'] + $quote['service_fee']);
        $quote['line_items'] = $this->lineItems($quote, $room);

        return $quote;
    }

    public function assertStayIsValid(Hotel $hotel, HotelRoom $room, array $input): void
    {
        if ($hotel->status !== 'active' || $room->status !== 'active' || $room->hotel_id !== $hotel->id) {
            throw DomainException::make('This room is not available for booking.', 'room_unavailable', 409);
        }

        $in = CarbonImmutable::parse($input['check_in'])->startOfDay();
        $out = CarbonImmutable::parse($input['check_out'])->startOfDay();
        if ($in->lt(CarbonImmutable::today())) {
            throw DomainException::make('Check-in date cannot be in the past.', 'invalid_dates');
        }
        if ($out->lte($in)) {
            throw DomainException::make('Check-out must be at least one night after check-in.', 'invalid_dates');
        }
        $maxNights = $this->settings->int('booking.max_nights_per_booking');
        if ($in->diffInDays($out) > $maxNights) {
            throw DomainException::make("Stays are limited to {$maxNights} nights per booking.", 'invalid_dates');
        }
        if ($in->gt(CarbonImmutable::today()->addDays($this->settings->int('booking.max_advance_days')))) {
            throw DomainException::make('That date is too far ahead — bookings open '.$this->settings->int('booking.max_advance_days').' days in advance.', 'invalid_dates');
        }

        $rooms = (int) $input['rooms'];
        $adults = (int) $input['adults'];
        $children = (int) ($input['children'] ?? 0);
        $maxRooms = $this->settings->int('booking.max_rooms_per_booking');
        if ($rooms < 1 || $rooms > $maxRooms) {
            throw DomainException::make("You can book between 1 and {$maxRooms} rooms at a time.", 'invalid_guests');
        }
        if ($adults < $rooms) {
            throw DomainException::make('Each room needs at least one adult.', 'invalid_guests');
        }
        if ($adults > $room->max_adults * $rooms || $children > $room->max_children * $rooms || ($adults + $children) > $room->max_guests * $rooms) {
            throw DomainException::make(
                "{$room->name} sleeps up to {$room->max_guests} guests per room ({$room->max_adults} adults". ($room->max_children ? ", {$room->max_children} children" : '').'). Add another room or choose a larger room.',
                'invalid_guests',
            );
        }
    }

    private function lineItems(array $q, HotelRoom $room): array
    {
        $items = [[
            'label' => "{$room->name} × {$q['rooms']} room(s) × {$q['nights']} night(s)",
            'amount' => $q['room_amount'],
        ]];
        if ($q['booking_type'] === 'membership') {
            $items[] = ['label' => "Covered by membership ({$q['membership_nights_used']} night credits)", 'amount' => -$q['membership_covered_amount']];
        }
        if ($q['discount_amount'] > 0) {
            $items[] = ['label' => "Coupon {$q['coupon_code']}", 'amount' => -$q['discount_amount']];
        }
        $items[] = ['label' => "Taxes ({$q['tax_percent']}% GST)", 'amount' => $q['tax_amount']];
        $items[] = ['label' => $q['booking_type'] === 'membership' ? 'Membership booking fee' : 'Service fee', 'amount' => $q['service_fee']];

        return $items;
    }
}
