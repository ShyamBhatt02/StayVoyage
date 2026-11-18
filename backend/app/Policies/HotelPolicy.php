<?php

namespace App\Policies;

use App\Models\Hotel;
use App\Models\User;

/**
 * Staff with hotels.manage can manage every hotel; hotel partners only the hotels linked to them.
 */
class HotelPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('hotels.manage');
    }

    public function update(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('hotels.manage') || ($user->isPartner() && $user->managesHotel($hotel->id));
    }

    public function manageInventory(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('inventory.manage') || ($user->isPartner() && $user->managesHotel($hotel->id));
    }

    public function viewBookings(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('bookings.view') || ($user->isPartner() && $user->managesHotel($hotel->id));
    }

    /** Only staff may delete, change membership eligibility or activate/deactivate. */
    public function administer(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('hotels.manage');
    }

    public function delete(User $user, Hotel $hotel): bool
    {
        return $user->hasPermission('hotels.manage');
    }
}
