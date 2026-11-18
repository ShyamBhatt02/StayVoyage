<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserTrip extends Model
{
    use SoftDeletes;

    /** Prices are server-computed by TourPricingService and never mass assigned. */
    protected $guarded = ['id', 'user_id', 'subtotal', 'tax_amount', 'service_fee', 'total_amount', 'price_breakdown', 'priced_at', 'share_token', 'status'];

    protected $casts = [
        'start_date' => 'date',
        'adults' => 'integer',
        'children' => 'integer',
        'subtotal' => 'float',
        'tax_amount' => 'float',
        'service_fee' => 'float',
        'total_amount' => 'float',
        'price_breakdown' => 'array',
        'priced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class, 'tour_package_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(TripDay::class)->orderBy('day_number');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TripItem::class);
    }

    public function travelers(): HasMany
    {
        return $this->hasMany(TripTraveler::class);
    }

    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class)->latestOfMany();
    }

    public function travelerCount(): int
    {
        return max(1, $this->adults + $this->children);
    }

    public function endDate(): ?\Carbon\Carbon
    {
        return $this->start_date?->copy()->addDays(max(0, $this->days()->count() - 1));
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function getCoverUrlAttribute(): ?string
    {
        return Media::url($this->cover_image);
    }
}
