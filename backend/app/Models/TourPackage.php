<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TourPackage extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'price_from', 'rating_avg', 'review_count'];

    protected $casts = [
        'gallery' => 'array',
        'highlights' => 'array',
        'inclusions' => 'array',
        'exclusions' => 'array',
        'price_from' => 'float',
        'rating_avg' => 'float',
        'is_featured' => 'boolean',
        'duration_days' => 'integer',
        'duration_nights' => 'integer',
    ];

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TourPackageItem::class)->orderBy('day_number')->orderBy('sort_order');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function getImageUrlAttribute(): ?string
    {
        return Media::url($this->image_path);
    }

    public function getGalleryUrlsAttribute(): array
    {
        return array_values(array_filter(array_map([Media::class, 'url'], $this->gallery ?? [])));
    }
}
