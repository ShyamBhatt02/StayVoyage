<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\BookingFailed;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\BookingCancellation;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CouponService;
use App\Services\Hotel\InventoryService;
use App\Services\Membership\MembershipWalletService;
use App\Services\Payments\PaymentService;
use App\Services\SettingsService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Everything that happens to a booking after it is created: payment outcomes, hold expiry,
 * cancellation (with refunds + membership nights), and completion.
 */
class BookingLifecycleService
{
    /** Unpaid holds get this much extra time for in-flight gateway callbacks before being released. */
    private const HOLD_GRACE_MINUTES = 10;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly MembershipWalletService $wallet,
        private readonly CouponService $coupons,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    private function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    // ------------------------------------------------------------------ payment outcomes

    /** Called inside PaymentService::markPaid's transaction with the booking row locked. */
    public function onPaymentSucceeded(Booking $booking, Payment $payment): void
    {
        $status = $booking->status;

        if (in_array($status, [BookingStatus::PaymentPending, BookingStatus::Pending], true)) {
            $booking->forceFill([
                'status' => BookingStatus::Confirmed,
                'payment_status' => PaymentStatus::Paid,
                'amount_paid' => Money::round($booking->amount_paid + $payment->amount),
                'confirmed_at' => now(),
                'hold_expires_at' => null,
            ])->save();
            $this->coupons->markApplied($booking);
            if ($booking->user_trip_id) {
                $booking->trip()->update(['status' => 'booked']);
            }
            DB::afterCommit(fn () => event(new BookingConfirmed($booking->fresh())));
            Log::channel('bookings')->info('Booking confirmed', ['booking' => $booking->booking_number, 'payment' => $payment->reference]);

            return;
        }

        // Paid after the hold lapsed, or a duplicate payment for an already-confirmed booking:
        // never silently keep the money — refund it in full once this transaction commits.
        $reason = $status === BookingStatus::Confirmed
            ? 'Duplicate payment for an already confirmed booking'
            : 'Payment received after the booking hold expired';
        Log::channel('bookings')->warning('Auto-refunding payment', ['booking' => $booking->booking_number, 'payment' => $payment->reference, 'reason' => $reason]);
        DB::afterCommit(fn () => $this->payments()->refund($payment->fresh(), $payment->amount, $reason));
    }

    /** A payment attempt failed. The booking stays unconfirmed; the customer may retry until the hold expires. */
    public function onPaymentFailed(Booking $booking, Payment $payment): void
    {
        if (in_array($booking->status, [BookingStatus::PaymentPending, BookingStatus::Pending], true)) {
            $booking->forceFill(['payment_status' => PaymentStatus::Failed])->save();
            Log::channel('bookings')->info('Payment failed — booking remains unconfirmed', ['booking' => $booking->booking_number]);
        }
    }

    public function confirmWithoutPayment(Booking $booking): void
    {
        $booking->forceFill([
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'confirmed_at' => now(),
            'hold_expires_at' => null,
        ])->save();
        $this->coupons->markApplied($booking);
        if ($booking->user_trip_id) {
            $booking->trip()->update(['status' => 'booked']);
        }
        DB::afterCommit(fn () => event(new BookingConfirmed($booking->fresh())));
    }

    /** Start a fresh payment attempt for an unpaid booking still within its hold window. */
    public function retryPayment(Booking $booking, ?string $gateway): array
    {
        $payment = DB::transaction(function () use ($booking, $gateway) {
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if (! in_array($locked->status, [BookingStatus::PaymentPending, BookingStatus::Pending], true)) {
                throw DomainException::make('This booking is not awaiting payment.', 'payment_not_required', 409);
            }
            if ($locked->hold_expires_at && $locked->hold_expires_at->isPast()) {
                throw DomainException::make('Your reservation hold has expired. Please start a new booking.', 'hold_expired', 409);
            }
            $locked->payments()->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Failed->value, 'failure_reason' => 'Superseded by a new payment attempt', 'failed_at' => now()]);
            $locked->forceFill(['payment_status' => PaymentStatus::Pending])->save();

            return $this->payments()->createPayment($locked->user, $locked, $locked->amountDue(), 'booking', $this->payments()->resolveGateway($gateway));
        });

        return ['payment' => $payment, 'checkout' => $this->payments()->startCheckout($payment)];
    }

    // ------------------------------------------------------------------ hold expiry

    /** Scheduled every minute: release inventory + nights held by bookings that were never paid. */
    public function expireUnpaidHolds(): int
    {
        $ids = Booking::query()
            ->whereIn('status', [BookingStatus::PaymentPending->value, BookingStatus::Pending->value])
            ->where('hold_expires_at', '<', now()->subMinutes(self::HOLD_GRACE_MINUTES))
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $booking = Booking::query()->lockForUpdate()->find($id);
                if (! $booking || ! in_array($booking->status, [BookingStatus::PaymentPending, BookingStatus::Pending], true)) {
                    return;
                }
                $this->releaseHeldResources($booking, 'payment not completed');
                $booking->forceFill([
                    'status' => BookingStatus::Failed,
                    'payment_status' => PaymentStatus::Failed,
                ])->save();
                $booking->payments()->where('status', PaymentStatus::Pending->value)
                    ->update(['status' => PaymentStatus::Failed->value, 'failure_reason' => 'Reservation hold expired', 'failed_at' => now()]);
                if ($booking->user_trip_id) {
                    $booking->trip()->update(['status' => 'draft']); // trip becomes editable again
                }
                DB::afterCommit(fn () => event(new BookingFailed($booking->fresh())));
                Log::channel('bookings')->info('Unpaid booking expired', ['booking' => $booking->booking_number]);
            });
        }

        return $ids->count();
    }

    // ------------------------------------------------------------------ cancellation

    /**
     * What would happen if this booking were cancelled now (shown to the customer before they confirm).
     * @return array{allowed:bool, reason:?string, within_free_window:bool, deadline:?string, refund_amount:float, penalty_amount:float, nights_returned:int, nights_forfeited:int, policy:string}
     */
    public function cancellationPreview(Booking $booking, bool $asAdmin = false, bool $waivePenalty = false): array
    {
        $base = ['allowed' => false, 'reason' => null, 'within_free_window' => false, 'deadline' => null, 'refund_amount' => 0.0,
            'penalty_amount' => 0.0, 'nights_returned' => 0, 'nights_forfeited' => 0, 'policy' => ''];

        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::PaymentPending, BookingStatus::Confirmed], true)) {
            return array_merge($base, ['reason' => 'This booking can no longer be cancelled.']);
        }

        $checkInAt = $this->checkInMoment($booking);
        if (! $asAdmin && $checkInAt->isPast()) {
            return array_merge($base, ['reason' => 'Bookings cannot be cancelled online after check-in time. Please contact support.']);
        }

        $paid = $booking->status === BookingStatus::Confirmed ? $booking->amount_paid - $booking->amount_refunded : 0.0;
        $nights = $booking->membership_nights_used;

        // Unpaid booking: simply release everything.
        if ($booking->status !== BookingStatus::Confirmed) {
            return array_merge($base, ['allowed' => true, 'within_free_window' => true, 'nights_returned' => $nights, 'policy' => 'Unpaid reservation — cancelling releases it at no cost.']);
        }

        if ($booking->isHotel() && $booking->isMembershipBooking()) {
            $hours = (int) ($booking->membership?->rule('cancellation_deadline_hours', 24) ?? 24);
            $deadline = $checkInAt->copy()->subHours($hours);
            $within = now()->lt($deadline) || $waivePenalty;
            $late = $booking->membership?->rule('late_cancellation_policy', 'forfeit_nights') ?? 'forfeit_nights';
            $returnNights = $within || $late === 'refund_nights';

            return array_merge($base, [
                'allowed' => true,
                'within_free_window' => $within,
                'deadline' => $deadline->toIso8601String(),
                'refund_amount' => $within ? Money::round($paid) : 0.0,
                'penalty_amount' => $within ? 0.0 : Money::round($paid),
                'nights_returned' => $returnNights ? $nights : 0,
                'nights_forfeited' => $returnNights ? 0 : $nights,
                'policy' => "Free cancellation until {$hours} hours before check-in: membership nights and fees are returned in full. "
                    .($late === 'refund_nights' ? 'After that, nights are returned but fees are not refunded.' : 'After that, the nights and fees are forfeited.'),
            ]);
        }

        if ($booking->isHotel()) {
            $line = $booking->rooms()->with('room')->first();
            $room = $line?->room;
            $freeHours = (int) ($room?->free_cancellation_hours ?? 48);
            $deadline = $checkInAt->copy()->subHours($freeHours);
            $within = ($room?->free_cancellation && now()->lt($deadline)) || $waivePenalty;
            $allowLate = $this->settings->bool('booking.allow_late_cancellation') || $asAdmin;
            if (! $within && ! $allowLate) {
                return array_merge($base, ['reason' => 'The free cancellation window for this booking has passed.', 'deadline' => $deadline->toIso8601String()]);
            }
            $penaltyPct = $within ? 0 : $this->settings->float('booking.late_cancellation_penalty_percent');
            $penalty = Money::percent($paid, $penaltyPct);

            return array_merge($base, [
                'allowed' => true,
                'within_free_window' => $within,
                'deadline' => $room?->free_cancellation ? $deadline->toIso8601String() : null,
                'refund_amount' => Money::round($paid - $penalty),
                'penalty_amount' => $penalty,
                'policy' => $room?->free_cancellation
                    ? "Free cancellation until {$freeHours} hours before check-in. Later cancellations incur a {$this->settings->float('booking.late_cancellation_penalty_percent')}% charge."
                    : "Non-refundable rate: cancellations incur a {$this->settings->float('booking.late_cancellation_penalty_percent')}% charge.",
            ]);
        }

        // Tour booking
        $freeHours = (int) $this->settings->get('tours.free_cancellation_hours', 72);
        $deadline = $checkInAt->copy()->subHours($freeHours);
        $within = now()->lt($deadline) || $waivePenalty;
        $penaltyPct = $within ? 0 : (float) $this->settings->get('tours.late_cancellation_penalty_percent', 25);
        $penalty = Money::percent($paid, $penaltyPct);

        return array_merge($base, [
            'allowed' => true,
            'within_free_window' => $within,
            'deadline' => $deadline->toIso8601String(),
            'refund_amount' => Money::round($paid - $penalty),
            'penalty_amount' => $penalty,
            'policy' => "Free cancellation until {$freeHours} hours before your trip starts; later cancellations incur a {$this->settings->get('tours.late_cancellation_penalty_percent', 25)}% charge.",
        ]);
    }

    public function cancel(Booking $booking, User $actor, ?string $reason, bool $asAdmin = false, bool $waivePenalty = false): Booking
    {
        $refundJob = null;

        $booking = DB::transaction(function () use ($booking, $actor, $reason, $asAdmin, $waivePenalty, &$refundJob) {
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $preview = $this->cancellationPreview($locked, $asAdmin, $waivePenalty && $asAdmin);
            if (! $preview['allowed']) {
                throw DomainException::make($preview['reason'] ?? 'This booking cannot be cancelled.', 'not_cancellable', 409);
            }
            if (BookingCancellation::query()->where('booking_id', $locked->id)->exists()) {
                throw DomainException::make('This booking has already been cancelled.', 'already_cancelled', 409);
            }

            $wasConfirmed = $locked->status === BookingStatus::Confirmed;

            // Inventory always goes back; nights only per policy; coupon only if cancelled for free.
            foreach ($locked->rooms as $line) {
                $this->inventory->release($line);
            }
            $nightsReturned = 0;
            if ($preview['nights_returned'] > 0) {
                $tx = $this->wallet->refundForBooking($locked, 'booking cancelled');
                $nightsReturned = $tx?->nights ?? 0; // null when the membership has already expired
            }

            BookingCancellation::query()->create([
                'booking_id' => $locked->id,
                'cancelled_by' => $actor->id,
                'cancelled_by_role' => $asAdmin ? 'admin' : 'customer',
                'reason' => $reason ? mb_substr($reason, 0, 250) : null,
                'within_free_window' => $preview['within_free_window'],
                'penalty_amount' => $preview['penalty_amount'],
                'refund_amount' => $preview['refund_amount'],
                'nights_returned' => $nightsReturned,
                'nights_forfeited' => $locked->membership_nights_used - $nightsReturned,
            ]);
            if ($preview['within_free_window']) {
                $this->coupons->release($locked);
            }

            $locked->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'hold_expires_at' => null,
                'payment_status' => $wasConfirmed ? $locked->payment_status : PaymentStatus::Failed,
            ])->save();
            if (! $wasConfirmed) {
                $locked->payments()->where('status', PaymentStatus::Pending->value)
                    ->update(['status' => PaymentStatus::Failed->value, 'failure_reason' => 'Booking cancelled', 'failed_at' => now()]);
            }
            if ($locked->user_trip_id) {
                $locked->trip()->update(['status' => 'draft']);
            }

            if ($preview['refund_amount'] > 0) {
                $payment = $locked->payments()->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value])->first();
                if ($payment) {
                    $refundJob = [$payment, $preview['refund_amount']];
                }
            }

            if ($asAdmin) {
                $this->audit->log('booking.cancel', $locked, ['status' => $wasConfirmed ? 'confirmed' : 'payment_pending'], [
                    'status' => 'cancelled', 'refund' => $preview['refund_amount'], 'nights_returned' => $preview['nights_returned'],
                ], $reason);
            }
            Log::channel('bookings')->info('Booking cancelled', ['booking' => $locked->booking_number, 'by' => $actor->id, 'refund' => $preview['refund_amount']]);

            return $locked;
        });

        if ($refundJob) {
            [$payment, $amount] = $refundJob;
            $this->payments()->refund($payment, $amount, 'Booking '.$booking->booking_number.' cancelled', $actor->id, $booking);
        }

        event(new BookingCancelled($booking->fresh()));

        return $booking->fresh();
    }

    // ------------------------------------------------------------------ completion

    /** Scheduled daily: confirmed stays/trips that have ended become completed (unlocks reviews). */
    public function completePastBookings(): int
    {
        return Booking::query()
            ->where('status', BookingStatus::Confirmed->value)
            ->whereDate('check_out', '<=', today())
            ->update(['status' => BookingStatus::Completed->value, 'completed_at' => now(), 'updated_at' => now()]);
    }

    private function releaseHeldResources(Booking $booking, string $reason): void
    {
        foreach ($booking->rooms as $line) {
            $this->inventory->release($line);
        }
        if ($booking->membership_nights_used > 0) {
            $this->wallet->refundForBooking($booking, $reason);
        }
        $this->coupons->release($booking);
    }

    private function checkInMoment(Booking $booking): Carbon
    {
        $time = $booking->isHotel() ? ($booking->hotel?->check_in_time ?? '14:00:00') : '09:00:00';

        return Carbon::parse($booking->check_in->toDateString().' '.$time);
    }
}
