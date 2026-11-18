<?php

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\MembershipActivated;
use App\Events\MembershipExpired;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\RefundProcessed;
use App\Jobs\GenerateInvoice;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\MembershipActivatedNotification;
use App\Notifications\MembershipExpiredNotification;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\RefundProcessedNotification;
use Illuminate\Events\Dispatcher;

/**
 * Side effects of domain events: notifications (queued) and invoice generation (queued job).
 * Critical state changes never live here — they happen inside the services' DB transactions.
 */
class DomainEventSubscriber
{
    public function onBookingConfirmed(BookingConfirmed $e): void
    {
        GenerateInvoice::dispatch('booking', $e->booking->id);
        $e->booking->user?->notify(new BookingConfirmedNotification($e->booking));
    }

    public function onBookingCancelled(BookingCancelled $e): void
    {
        $e->booking->loadMissing('cancellation');
        $e->booking->user?->notify(new BookingCancelledNotification($e->booking));
        if ($e->booking->invoice()->exists()) {
            GenerateInvoice::dispatch('booking', $e->booking->id);
        }
    }

    public function onPaymentSucceeded(PaymentSucceeded $e): void
    {
        $e->payment->user?->notify(new PaymentReceivedNotification($e->payment));
    }

    public function onPaymentFailed(PaymentFailed $e): void
    {
        $e->payment->user?->notify(new PaymentFailedNotification($e->payment));
    }

    public function onRefundProcessed(RefundProcessed $e): void
    {
        $e->refund->user?->notify(new RefundProcessedNotification($e->refund));
        if ($e->refund->booking_id) {
            GenerateInvoice::dispatch('booking', $e->refund->booking_id);
        }
    }

    public function onMembershipActivated(MembershipActivated $e): void
    {
        GenerateInvoice::dispatch('membership', $e->membership->id);
        $e->membership->user?->notify(new MembershipActivatedNotification($e->membership));
    }

    public function onMembershipExpired(MembershipExpired $e): void
    {
        $e->membership->user?->notify(new MembershipExpiredNotification($e->membership));
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            BookingConfirmed::class => 'onBookingConfirmed',
            BookingCancelled::class => 'onBookingCancelled',
            PaymentSucceeded::class => 'onPaymentSucceeded',
            PaymentFailed::class => 'onPaymentFailed',
            RefundProcessed::class => 'onRefundProcessed',
            MembershipActivated::class => 'onMembershipActivated',
            MembershipExpired::class => 'onMembershipExpired',
        ];
    }
}
