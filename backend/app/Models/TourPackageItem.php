<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TourPackageItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'day_number' => 'integer',
        'nights' => 'integer',
        'hours' => 'integer',
        'duration_minutes' => 'integer',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class, 'tour_package_id');
    }

    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }
}
