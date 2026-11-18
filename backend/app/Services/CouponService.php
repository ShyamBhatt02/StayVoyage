<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Membership;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Coupon validation and redemption. Discounts are always computed here — never accepted from the client.
 * Usage counters are protected by locking the coupon row during redemption.
 */
class CouponService
{
    /**
     * @param string $context hotels | tours | memberships
     * @param array{hotel_id?:int,tour_package_id?:int,membership_plan_id?:int} $scope
     * @return array{coupon: Coupon, discount: float}
     */
    public function evaluate(string $code, string $context, float $amount, User $user, array $scope = []): array
    {
        $coupon = Coupon::query()->with(['hotels:id', 'tourPackages:id', 'membershipPlans:id'])
            ->where('code', strtoupper(trim($code)))
            ->first();

        $this->assertUsable($coupon, $context, $amount, $user, $scope);

        return ['coupon' => $coupon, 'discount' => $this->discountFor($coupon, $amount)];
    }

    public function discountFor(Coupon $coupon, float $amount): float
    {
        $discount = $coupon->discount_type === 'percentage'
            ? Money::percent($amount, $coupon->discount_value)
            : $coupon->discount_value;

        if ($coupon->max_discount !== null && $coupon->max_discount > 0) {
            $discount = min($discount, $coupon->max_discount);
        }

        return Money::round(min($discount, $amount));
    }

    /** Lock the coupon, re-validate limits and record a reserved usage. Call inside the booking transaction. */
    public function reserve(Coupon $coupon, User $user, float $discount, ?Booking $booking = null, ?Membership $membership = null): CouponUsage
    {
        return DB::transaction(function () use ($coupon, $user, $discount, $booking, $membership) {
            $locked = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);
            $this->assertLimits($locked, $user);

            $usage = CouponUsage::query()->create([
                'coupon_id' => $locked->id,
                'user_id' => $user->id,
                'booking_id' => $booking?->id,
                'membership_id' => $membership?->id,
                'discount_amount' => $discount,
                'status' => 'reserved',
            ]);
            $locked->increment('used_count');

            return $usage;
        });
    }

    public function markApplied(?Booking $booking = null, ?Membership $membership = null): void
    {
        CouponUsage::query()
            ->when($booking, fn ($q) => $q->where('booking_id', $booking->id))
            ->when($membership, fn ($q) => $q->where('membership_id', $membership->id))
            ->where('status', 'reserved')
            ->update(['status' => 'applied', 'updated_at' => now()]);
    }

    /** Give the coupon back (booking failed / cancelled before use). Idempotent. */
    public function release(?Booking $booking = null, ?Membership $membership = null): void
    {
        DB::transaction(function () use ($booking, $membership) {
            $usage = CouponUsage::query()
                ->when($booking, fn ($q) => $q->where('booking_id', $booking->id))
                ->when($membership, fn ($q) => $q->where('membership_id', $membership->id))
                ->whereIn('status', ['reserved', 'applied'])
                ->lockForUpdate()
                ->first();
            if (! $usage) {
                return;
            }
            $coupon = Coupon::withTrashed()->lockForUpdate()->find($usage->coupon_id);
            $usage->update(['status' => 'released']);
            if ($coupon && $coupon->used_count > 0) {
                $coupon->decrement('used_count');
            }
        });
    }

    private function assertUsable(?Coupon $coupon, string $context, float $amount, User $user, array $scope): void
    {
        if (! $coupon || ! $coupon->isLive()) {
            throw DomainException::make('This coupon code is invalid or has expired.', 'invalid_coupon');
        }
        if ($coupon->applies_to !== 'all' && $coupon->applies_to !== $context) {
            $label = ['hotels' => 'hotel bookings', 'tours' => 'tour bookings', 'memberships' => 'membership purchases'][$coupon->applies_to] ?? $coupon->applies_to;
            throw DomainException::make("This coupon can only be used on {$label}.", 'invalid_coupon');
        }
        if ($context === 'hotels' && $coupon->hotels->isNotEmpty() && ! $coupon->hotels->contains('id', $scope['hotel_id'] ?? 0)) {
            throw DomainException::make('This coupon is not valid for the selected hotel.', 'invalid_coupon');
        }
        if ($context === 'tours' && $coupon->tourPackages->isNotEmpty() && ! $coupon->tourPackages->contains('id', $scope['tour_package_id'] ?? 0)) {
            throw DomainException::make('This coupon is not valid for this trip.', 'invalid_coupon');
        }
        if ($context === 'memberships' && $coupon->membershipPlans->isNotEmpty() && ! $coupon->membershipPlans->contains('id', $scope['membership_plan_id'] ?? 0)) {
            throw DomainException::make('This coupon is not valid for the selected plan.', 'invalid_coupon');
        }
        if ($amount < $coupon->min_purchase) {
            throw DomainException::make('Add '.Money::format($coupon->min_purchase - $amount).' more to use this coupon (minimum '.Money::format($coupon->min_purchase).').', 'invalid_coupon');
        }
        $this->assertLimits($coupon, $user);
    }

    private function assertLimits(Coupon $coupon, User $user): void
    {
        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            throw DomainException::make('This coupon has reached its usage limit.', 'invalid_coupon');
        }
        $userUses = CouponUsage::query()
            ->where('coupon_id', $coupon->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['reserved', 'applied'])
            ->count();
        if ($coupon->per_user_limit && $userUses >= $coupon->per_user_limit) {
            throw DomainException::make('You have already used this coupon the maximum number of times.', 'invalid_coupon');
        }
    }
}
