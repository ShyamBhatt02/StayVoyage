<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserTrip;

class UserTripPolicy
{
    public function view(User $user, UserTrip $trip): bool
    {
        return $trip->user_id === $user->id || $user->hasPermission('bookings.view');
    }

    public function update(User $user, UserTrip $trip): bool
    {
        return $trip->user_id === $user->id;
    }

    public function delete(User $user, UserTrip $trip): bool
    {
        return $trip->user_id === $user->id;
    }

    public function book(User $user, UserTrip $trip): bool
    {
        return $trip->user_id === $user->id;
    }
}
