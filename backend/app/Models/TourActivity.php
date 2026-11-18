<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TourActivity extends Model
{
    use SoftDeletes;

    public const CATEGORIES = ['sightseeing', 'adventure', 'museum', 'theme_park', 'safari', 'cruise', 'trekking', 'water_sports', 'cultural', 'attraction'];

    protected $guarded = ['id'];

    protected $casts = [
        'start_times' => 'array',
        'available_days' => 'array',
        'gallery' => 'array',
        'inclusions' => 'array',
        'price' => 'float',
        'child_price' => 'float',
        'rating_avg' => 'float',
        'capacity_per_slot' => 'integer',
        'duration_minutes' => 'integer',
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
