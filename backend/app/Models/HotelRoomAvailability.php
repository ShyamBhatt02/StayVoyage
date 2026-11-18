<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelRoomAvailability extends Model
{
    protected $table = 'hotel_room_availability';

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'total_inventory' => 'integer',
        'booked_count' => 'integer',
        'blocked_count' => 'integer',
        'price_override' => 'float',
        'is_closed' => 'boolean',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(HotelRoom::class, 'hotel_room_id');
    }

    public function available(): int
    {
        if ($this->is_closed) {
            return 0;
        }

        return max(0, $this->total_inventory - $this->booked_count - $this->blocked_count);
    }
}
