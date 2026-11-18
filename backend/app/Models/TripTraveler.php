<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripTraveler extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_lead' => 'boolean', 'age' => 'integer'];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(UserTrip::class, 'user_trip_id');
    }
}
