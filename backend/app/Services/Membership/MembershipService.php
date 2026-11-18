<?php

namespace App\Services\Membership;

use App\Enums\MembershipStatus;
use App\Events\MembershipActivated;
use App\Events\MembershipExpired;
use App\Exceptions\DomainException;
use App\Models\Coupon;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CouponService;
use App\Services\Payments\PaymentService;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MembershipService
{
    public function __construct(
        private readonly MembershipWalletService $wallet,
        private readonly CouponService $coupons,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** Server-side price for buying a plan. */
    public function quote(MembershipPlan $plan, User $user, ?string $couponCode = null): array
    {
        if ($plan->status !== 'active') {
            throw DomainException::make('This membership plan is not currently available.', 'plan_unavailable');
        }
        $price = Money::round($plan->price);
        $discount = 0.0;
        $coupon = null;
        if ($couponCode) {
            $result = $this->coupons->evaluate($couponCode, 'memberships', $price, $user, ['membership_plan_id' => $plan->id]);
            $discount = $result['discount'];
            $coupon = $result['coupon'];
        }
        $taxPct = $this->settings->float('pricing.membership_tax_percent');
        $taxable = Money::round($price - $discount);
        $tax = Money::percent($taxable, $taxPct);
        $current = $user->activeMembership();
        $startsAt = $current ? $current->expires_at->copy() : now();

        return [
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'currency' => $plan->currency,
            'price' => $price,
            'discount_amount' => $discount,
            'coupon_code' => $coupon?->code,
            'coupon_id' => $coupon?->id,
            'tax_percent' => $taxPct,
            'tax_amount' => $tax,
            'total_amount' => Money::round($taxable + $tax),
            'included_nights' => $plan->included_nights,
            'duration_days' => $plan->duration_days,
            'is_renewal' => (bool) $current,
            'starts_at' => $startsAt->toIso8601String(),
            'expires_at' => $startsAt->copy()->addDays($plan->duration_days)->toIso8601String(),
        ];
    }

    /**
     * Create a pending membership + payment. Nights are credited only when the payment is verified.
     * @return array{membership: Membership, payment: ?Payment, quote: array}
     */
    public function purchase(User $user, MembershipPlan $plan, ?string $couponCode, bool $autoRenew, string $gateway, PaymentService $payments): array
    {
        return DB::transaction(function () use ($user, $plan, $couponCode, $autoRenew, $gateway, $payments) {
            // Serialise purchases per user to prevent double-buying via two tabs.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $queued = $user->memberships()->where('status', MembershipStatus::Active->value)->where('starts_at', '>', now())->exists();
            if ($queued) {
                throw DomainException::make('You already have a renewal queued to start when your current membership ends.', 'renewal_already_queued', 409);
            }

            // Abandon earlier unpaid attempts.
            $user->memberships()->where('status', MembershipStatus::Pending->value)->get()->each(function (Membership $m) {
                $this->coupons->release(membership: $m);
                $m->forceFill(['status' => MembershipStatus::Cancelled, 'cancelled_at' => now()])->save();
            });

            $quote = $this->quote($plan, $user, $couponCode);

            $membership = new Membership([
                'membership_number' => 'SVM-'.now()->format('ym').'-'.strtoupper(Str::random(6)),
                'user_id' => $user->id,
                'membership_plan_id' => $plan->id,
                'total_nights' => $plan->included_nights,
                'price_paid' => $quote['total_amount'],
                'currency' => $plan->currency,
                'auto_renew' => $autoRenew && $plan->auto_renew_available,
                'plan_snapshot' => array_merge($plan->toSnapshot(), ['quote' => $quote]),
            ]);
            $membership->forceFill(['status' => MembershipStatus::Pending, 'nights_balance' => 0])->save();

            if ($quote['coupon_id']) {
                $this->coupons->reserve(Coupon::query()->findOrFail($quote['coupon_id']), $user, $quote['discount_amount'], membership: $membership);
            }

            $payment = null;
            if ($quote['total_amount'] > 0) {
                $payment = $payments->createPayment($user, $membership, $quote['total_amount'], 'membership', $gateway);
            } else {
                $this->activate($membership, null);
            }

            Log::channel('membership')->info('Membership purchase started', ['membership' => $membership->membership_number, 'user_id' => $user->id, 'total' => $quote['total_amount']]);

            return ['membership' => $membership, 'payment' => $payment, 'quote' => $quote];
        });
    }

    /** Called by PaymentService::markPaid inside its transaction with the membership row locked. */
    public function onPaymentSucceeded(Membership $membership, Payment $payment): void
    {
        if ($membership->status !== MembershipStatus::Pending) {
            Log::channel('membership')->warning('Payment for non-pending membership', ['membership' => $membership->id, 'status' => $membership->status->value]);
            if ($membership->status === MembershipStatus::Cancelled) {
                DB::afterCommit(fn () => app(PaymentService::class)->refund($payment, $payment->amount, 'Membership purchase was cancelled before payment completed'));
            }

            return;
        }
        $membership->forceFill(['price_paid' => $payment->amount]);
        $this->activate($membership, $payment);
    }

    private function activate(Membership $membership, ?Payment $payment): void
    {
        $user = $membership->user;
        $current = $user->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->whereKeyNot($membership->id)
            ->where('expires_at', '>', now())
            ->latest('expires_at')
            ->first();

        $startsAt = $current ? $current->expires_at->copy() : now();
        $days = (int) ($membership->plan_snapshot['duration_days'] ?? $membership->plan->duration_days);

        $membership->forceFill([
            'status' => MembershipStatus::Active,
            'starts_at' => $startsAt,
            'expires_at' => $startsAt->copy()->addDays($days)->endOfDay(),
            'activated_at' => now(),
            'renewed_from_id' => $current?->id,
        ])->save();

        $this->wallet->creditPurchase($membership);
        $this->coupons->markApplied(membership: $membership);

        DB::afterCommit(fn () => event(new MembershipActivated($membership->fresh())));
        Log::channel('membership')->info('Membership activated', ['membership' => $membership->membership_number, 'payment' => $payment?->reference]);
    }

    /** Scheduled: expire memberships past their end date and lapse unused nights per plan rules. */
    public function expireDue(): int
    {
        $count = 0;
        Membership::query()
            ->where('status', MembershipStatus::Active->value)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($batch) use (&$count) {
                foreach ($batch as $membership) {
                    DB::transaction(function () use ($membership, &$count) {
                        $locked = Membership::query()->lockForUpdate()->find($membership->id);
                        if (! $locked || $locked->status !== MembershipStatus::Active || $locked->expires_at->isFuture()) {
                            return;
                        }
                        if ($locked->rule('unused_nights_expire', true)) {
                            $this->wallet->expireRemaining($locked);
                        }
                        $locked->forceFill(['status' => MembershipStatus::Expired, 'expired_at' => now()])->save();
                        DB::afterCommit(fn () => event(new MembershipExpired($locked->fresh())));
                        $count++;
                    });
                }
            });

        return $count;
    }

    /** Scheduled: drop unpaid membership checkouts after 2 hours. */
    public function cancelAbandonedPurchases(): int
    {
        $count = 0;
        Membership::query()->where('status', MembershipStatus::Pending->value)->where('created_at', '<', now()->subHours(2))
            ->get()->each(function (Membership $m) use (&$count) {
                DB::transaction(function () use ($m, &$count) {
                    $locked = Membership::query()->lockForUpdate()->find($m->id);
                    if ($locked?->status !== MembershipStatus::Pending) {
                        return;
                    }
                    $this->coupons->release(membership: $locked);
                    $locked->forceFill(['status' => MembershipStatus::Cancelled, 'cancelled_at' => now()])->save();
                    $locked->payments()->where('status', 'pending')->update(['status' => 'failed', 'failure_reason' => 'Checkout abandoned', 'failed_at' => now()]);
                    $count++;
                });
            });

        return $count;
    }

    public function setAutoRenew(Membership $membership, bool $enabled): Membership
    {
        if ($enabled && ! ($membership->plan?->auto_renew_available)) {
            throw DomainException::make('Auto-renewal is not available for this plan.', 'auto_renew_unavailable');
        }
        $membership->forceFill(['auto_renew' => $enabled])->save();

        return $membership;
    }

    /** Admin: change night balance with a mandatory reason; fully audited. */
    public function adminAdjust(Membership $membership, int $nights, string $reason, User $admin): void
    {
        $before = $membership->nights_balance;
        $tx = $this->wallet->adjust($membership, $nights, 'Admin adjustment: '.$reason, $admin->id);
        $this->audit->log('membership.adjust', $membership, ['nights_balance' => $before], ['nights_balance' => $tx->balance_after, 'nights' => $nights], $reason);
    }

    public function summary(?Membership $m): ?array
    {
        if (! $m) {
            return null;
        }

        return [
            'id' => $m->id,
            'membership_number' => $m->membership_number,
            'plan_name' => $m->plan_snapshot['name'] ?? $m->plan?->name,
            'status' => $m->status->value,
            'status_label' => $m->status->label(),
            'starts_at' => $m->starts_at?->toIso8601String(),
            'expires_at' => $m->expires_at?->toIso8601String(),
            'days_remaining' => $m->daysRemaining(),
            'total_nights' => $m->total_nights,
            'used_nights' => $m->usedNights(),
            'remaining_nights' => $m->nights_balance,
            'auto_renew' => $m->auto_renew,
            'is_usable' => $m->isUsable(),
            'rules' => collect($m->plan_snapshot)->except(['quote'])->all(),
        ];
    }
}
