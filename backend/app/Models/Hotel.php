<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Hotel extends Model
{
    use SoftDeletes;

    public const PROPERTY_TYPES = ['hotel', 'resort', 'villa', 'boutique', 'apartment', 'homestay'];

    protected $guarded = ['id', 'rating_avg', 'review_count', 'min_price'];

    protected $casts = [
        'star_rating' => 'integer',
        'rating_avg' => 'float',
        'review_count' => 'integer',
        'min_price' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
        'distance_from_center_km' => 'float',
        'is_membership_eligible' => 'boolean',
        'is_featured' => 'boolean',
    ];

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(HotelImage::class)->orderByDesc('is_cover')->orderBy('sort_order');
    }

    public function coverImage(): HasOne
    {
        return $this->hasOne(HotelImage::class)->ofMany(['is_cover' => 'max', 'id' => 'min']);
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'hotel_amenities');
    }

    public function policy(): HasOne
    {
        return $this->hasOne(HotelPolicy::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(HotelRoom::class)->orderBy('sort_order');
    }

    public function activeRooms(): HasMany
    {
        return $this->rooms()->where('status', 'active');
    }

    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /** Recompute the denormalised "from" price and rating aggregates. */
    public function refreshAggregates(): void
    {
        $this->forceFill([
            'min_price' => $this->rooms()->where('status', 'active')->min('base_price'),
        ]);
        $approved = $this->reviews()->where('status', 'approved');
        $this->forceFill([
            'rating_avg' => round((float) $approved->avg('overall'), 2),
            'review_count' => $approved->count(),
        ])->saveQuietly();
    }

    public function getCoverUrlAttribute(): ?string
    {
        $img = $this->relationLoaded('coverImage') ? $this->coverImage : ($this->relationLoaded('images') ? $this->images->first() : $this->coverImage);

        return Media::url($img?->path);
    }
}
