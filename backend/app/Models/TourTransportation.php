<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TourTransportation extends Model
{
    use SoftDeletes;

    public const TYPES = ['flight', 'train', 'bus', 'taxi', 'private_car', 'rental_car', 'airport_transfer', 'hotel_transfer'];

    protected $guarded = ['id'];

    protected $casts = [
        'price' => 'float',
        'capacity' => 'integer',
        'duration_minutes' => 'integer',
        'departure_times' => 'array',
    ];

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function getImageUrlAttribute(): ?string
    {
        return Media::url($this->image_path);
    }
}
