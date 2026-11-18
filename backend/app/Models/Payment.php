<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['gateway_metadata'];

    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'float',
        'amount_refunded' => 'float',
        'gateway_metadata' => 'array',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function payable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function refundableAmount(): float
    {
        return round(max(0, $this->amount - $this->amount_refunded), 2);
    }
}
