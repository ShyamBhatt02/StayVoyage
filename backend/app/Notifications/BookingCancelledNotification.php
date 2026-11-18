<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Support\Money;

class BookingCancelledNotification extends AppNotification
{
    public function __construct(public readonly Booking $booking) {}

    protected function title(): string
    {
        return 'Booking '.$this->booking->booking_number.' cancelled';
    }

    protected function category(): string
    {
        return 'booking';
    }

    protected function lines(object $notifiable): array
    {
        $b = $this->booking;
        $c = $b->cancellation;
        $lines = ["Your booking {$b->booking_number} has been cancelled."];
        if ($c && $c->refund_amount > 0) {
            $lines[] = 'A refund of '.Money::format($c->refund_amount, $b->currency).' has been initiated to your original payment method. It usually reaches you within 5–7 working days.';
        }
        if ($c && $c->nights_returned > 0) {
            $lines[] = "{$c->nights_returned} membership night(s) have been returned to your balance.";
        }
        if ($c && $c->nights_forfeited > 0) {
            $lines[] = "{$c->nights_forfeited} membership night(s) were forfeited under the late cancellation policy.";
        }

        return $lines;
    }

    protected function actionText(): ?string
    {
        return 'View booking';
    }

    protected function actionPath(): ?string
    {
        return '/account/bookings/'.$this->booking->booking_number;
    }
}
