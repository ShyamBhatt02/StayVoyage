<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Support\Money;

class BookingConfirmedNotification extends AppNotification
{
    public function __construct(public readonly Booking $booking) {}

    protected function title(): string
    {
        return $this->booking->isHotel() ? 'Your hotel booking is confirmed' : 'Your trip is booked';
    }

    protected function category(): string
    {
        return 'booking';
    }

    protected function lines(object $notifiable): array
    {
        $b = $this->booking;
        if ($b->isHotel()) {
            $lines = [
                "Booking {$b->booking_number} at {$b->hotel?->name} is confirmed for {$b->check_in->format('D, j M Y')} → {$b->check_out->format('D, j M Y')} ({$b->nights} night(s), {$b->rooms_count} room(s)).",
                'Check-in from '.substr((string) $b->hotel?->check_in_time, 0, 5).'. Show this booking number at the front desk.',
            ];
            if ($b->isMembershipBooking()) {
                $lines[] = "{$b->membership_nights_used} membership night(s) were used for this stay.";
            }
        } else {
            $lines = [
                "Booking {$b->booking_number} for “".($b->itinerary_snapshot['title'] ?? 'your trip')."” is confirmed: {$b->check_in->format('j M Y')} → {$b->check_out->format('j M Y')}.",
                'Your full day-by-day itinerary, travellers and vouchers are in your account.',
            ];
        }
        $lines[] = 'Amount paid: '.Money::format($b->amount_paid, $b->currency).'. Your invoice is ready to download.';

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
