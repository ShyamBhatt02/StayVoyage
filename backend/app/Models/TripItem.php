<?php

namespace App\Models;

use App\Enums\TripItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TripItem extends Model
{
    protected $guarded = ['id', 'unit_price', 'total_price', 'is_available', 'availability_note'];

    protected $casts = [
        'item_type' => TripItemType::class,
        'quantity' => 'integer',
        'nights' => 'integer',
        'hours' => 'integer',
        'duration_minutes' => 'integer',
        'unit_price' => 'float',
        'total_price' => 'float',
        'is_available' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(UserTrip::class, 'user_trip_id');
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(TripDay::class, 'trip_day_id');
    }

    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }
}
