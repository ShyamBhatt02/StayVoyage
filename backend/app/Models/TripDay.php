<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripDay extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['day_number' => 'integer'];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(UserTrip::class, 'user_trip_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TripItem::class)->orderBy('sort_order');
    }
}
