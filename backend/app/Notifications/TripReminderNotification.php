<?php

namespace App\Notifications;

use App\Models\Booking;

class TripReminderNotification extends AppNotification
{
    public function __construct(public readonly Booking $booking) {}

    protected function title(): string
    {
        return $this->booking->isHotel() ? 'Your stay is coming up' : 'Your trip starts soon';
    }

    protected function category(): string
    {
        return 'trip';
    }

    protected function lines(object $notifiable): array
    {
        $b = $this->booking;
        $days = (int) today()->diffInDays($b->check_in);
        $when = $days <= 1 ? 'tomorrow' : "in {$days} days";

        return $b->isHotel()
            ? ["Your stay at {$b->hotel?->name} begins {$when} ({$b->check_in->format('D, j M')}). Check-in opens at ".substr((string) $b->hotel?->check_in_time, 0, 5).'.',
                'Need to change something? Manage your booking any time from your account.']
            : ['“'.($b->itinerary_snapshot['title'] ?? 'Your trip')."” starts {$when} ({$b->check_in->format('D, j M')}).",
                'Review your day-by-day itinerary and pickup times before you go.'];
    }

    protected function actionText(): ?string
    {
        return 'View itinerary';
    }

    protected function actionPath(): ?string
    {
        return '/account/bookings/'.$this->booking->booking_number;
    }
}
