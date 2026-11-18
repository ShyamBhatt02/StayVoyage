<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Membership extends Model
{
    use SoftDeletes;

    /** Balance and status are only changed by MembershipWalletService / MembershipService. */
    protected $guarded = ['id', 'nights_balance', 'status'];

    protected $casts = [
        'status' => MembershipStatus::class,
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'activated_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expired_at' => 'datetime',
        'expiry_reminder_sent_at' => 'datetime',
        'total_nights' => 'integer',
        'nights_balance' => 'integer',
        'price_paid' => 'float',
        'auto_renew' => 'boolean',
        'plan_snapshot' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id')->withTrashed();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MembershipTransaction::class)->orderByDesc('id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function invoice(): MorphOne
    {
        return $this->morphOne(Invoice::class, 'invoiceable');
    }

    public function rule(string $key, mixed $default = null): mixed
    {
        return $this->plan_snapshot[$key] ?? $default;
    }

    public function isUsable(): bool
    {
        return $this->status === MembershipStatus::Active
            && $this->starts_at?->isPast()
            && $this->expires_at?->isFuture();
    }

    public function usedNights(): int
    {
        return max(0, $this->total_nights - $this->nights_balance);
    }

    public function daysRemaining(): int
    {
        return $this->expires_at && $this->expires_at->isFuture() ? (int) ceil(now()->diffInHours($this->expires_at) / 24) : 0;
    }
}
