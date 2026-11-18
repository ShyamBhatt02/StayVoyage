<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MembershipPlan extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'price' => 'float',
        'duration_days' => 'integer',
        'included_nights' => 'integer',
        'max_nights_per_booking' => 'integer',
        'max_rooms_per_booking' => 'integer',
        'max_guests_per_booking' => 'integer',
        'min_advance_booking_days' => 'integer',
        'max_advance_booking_days' => 'integer',
        'cancellation_deadline_hours' => 'integer',
        'all_hotels_eligible' => 'boolean',
        'eligible_room_types' => 'array',
        'unused_nights_expire' => 'boolean',
        'nights_extend_past_expiry' => 'boolean',
        'auto_renew_available' => 'boolean',
        'charge_taxes_on_covered_amount' => 'boolean',
        'features' => 'array',
        'is_featured' => 'boolean',
    ];

    public function hotels(): BelongsToMany
    {
        return $this->belongsToMany(Hotel::class, 'membership_plan_hotels');
    }

    public function blackoutDates(): HasMany
    {
        return $this->hasMany(MembershipBlackoutDate::class)->orderBy('start_date');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /** Rules frozen onto a membership at purchase so later plan edits never change a customer's deal. */
    public function toSnapshot(): array
    {
        return [
            'plan_id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'currency' => $this->currency,
            'duration_days' => $this->duration_days,
            'included_nights' => $this->included_nights,
            'max_nights_per_booking' => $this->max_nights_per_booking,
            'max_rooms_per_booking' => $this->max_rooms_per_booking,
            'max_guests_per_booking' => $this->max_guests_per_booking,
            'min_advance_booking_days' => $this->min_advance_booking_days,
            'max_advance_booking_days' => $this->max_advance_booking_days,
            'cancellation_deadline_hours' => $this->cancellation_deadline_hours,
            'late_cancellation_policy' => $this->late_cancellation_policy,
            'no_show_policy' => $this->no_show_policy,
            'unused_nights_expire' => $this->unused_nights_expire,
            'nights_extend_past_expiry' => $this->nights_extend_past_expiry,
            'charge_taxes_on_covered_amount' => $this->charge_taxes_on_covered_amount,
            'eligible_room_types' => $this->eligible_room_types,
            'all_hotels_eligible' => $this->all_hotels_eligible,
        ];
    }
}
