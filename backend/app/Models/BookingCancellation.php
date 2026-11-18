<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingCancellation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'within_free_window' => 'boolean',
        'penalty_amount' => 'float',
        'refund_amount' => 'float',
        'nights_returned' => 'integer',
        'nights_forfeited' => 'integer',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }
}
