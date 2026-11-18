<?php

namespace App\Policies;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id
            || $user->hasPermission('bookings.view')
            || ($user->isPartner() && $booking->hotel_id && $user->managesHotel($booking->hotel_id));
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id;
    }

    public function adminCancel(User $user, Booking $booking): bool
    {
        return $user->hasPermission('bookings.manage');
    }

    public function pay(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id;
    }

    public function review(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id
            && $booking->isHotel()
            && $booking->status === BookingStatus::Completed;
    }
}
