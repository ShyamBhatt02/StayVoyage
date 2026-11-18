<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TourGuide extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'languages' => 'array',
        'available_days' => 'array',
        'hourly_rate' => 'float',
        'full_day_rate' => 'float',
        'rating_avg' => 'float',
        'min_hours' => 'integer',
        'max_hours' => 'integer',
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
        return Media::url($this->profile_image);
    }
}
