<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HotelRoom extends Model
{
    use SoftDeletes;

    public const ROOM_TYPES = ['standard', 'deluxe', 'premium', 'suite', 'family', 'villa'];

    protected $guarded = ['id'];

    protected $casts = [
        'base_price' => 'float',
        'weekend_price' => 'float',
        'breakfast_included' => 'boolean',
        'free_cancellation' => 'boolean',
        'is_membership_eligible' => 'boolean',
        'total_rooms' => 'integer',
        'max_guests' => 'integer',
        'max_adults' => 'integer',
        'max_children' => 'integer',
        'free_cancellation_hours' => 'integer',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(HotelRoomImage::class)->orderBy('sort_order');
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'hotel_room_amenities');
    }

    public function seasonalPrices(): HasMany
    {
        return $this->hasMany(HotelRoomSeasonalPrice::class)->orderBy('start_date');
    }

    public function availability(): HasMany
    {
        return $this->hasMany(HotelRoomAvailability::class);
    }

    public function getCoverUrlAttribute(): ?string
    {
        return Media::url(($this->relationLoaded('images') ? $this->images->first() : $this->images()->first())?->path);
    }
}
