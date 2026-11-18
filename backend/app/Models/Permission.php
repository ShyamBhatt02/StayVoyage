<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /**
     * Canonical permission catalogue: slug => [name, group].
     * Super admins implicitly hold every permission.
     */
    public const CATALOGUE = [
        'dashboard.view' => ['View admin dashboard', 'dashboard'],
        'users.view' => ['View customers & staff', 'users'],
        'users.manage' => ['Suspend / activate / edit users', 'users'],
        'roles.manage' => ['Manage roles & permissions', 'users'],
        'memberships.view' => ['View memberships & ledgers', 'memberships'],
        'memberships.manage' => ['Manage membership plans', 'memberships'],
        'memberships.adjust' => ['Adjust membership night balances', 'memberships'],
        'hotels.view' => ['View hotels', 'hotels'],
        'hotels.manage' => ['Create / edit / delete hotels & rooms', 'hotels'],
        'inventory.manage' => ['Manage availability, inventory & pricing', 'hotels'],
        'destinations.manage' => ['Manage destinations', 'tours'],
        'tours.manage' => ['Manage tour packages & catalogue', 'tours'],
        'bookings.view' => ['View bookings', 'bookings'],
        'bookings.manage' => ['Cancel / update bookings', 'bookings'],
        'payments.view' => ['View payments', 'payments'],
        'refunds.manage' => ['Issue refunds', 'payments'],
        'coupons.manage' => ['Manage coupons', 'marketing'],
        'reviews.moderate' => ['Moderate reviews', 'marketing'],
        'content.manage' => ['Manage FAQs & contact messages', 'marketing'],
        'reports.view' => ['View & export reports', 'reports'],
        'settings.manage' => ['Manage platform settings', 'settings'],
        'audit.view' => ['View audit logs', 'settings'],
    ];

    protected $fillable = ['name', 'slug', 'group'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
