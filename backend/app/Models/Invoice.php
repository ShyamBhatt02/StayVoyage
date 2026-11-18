<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'issued_at' => 'datetime',
        'billing_details' => 'array',
        'items' => 'array',
        'subtotal' => 'float',
        'discount_amount' => 'float',
        'tax_amount' => 'float',
        'service_fee' => 'float',
        'total' => 'float',
        'amount_paid' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function invoiceable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
