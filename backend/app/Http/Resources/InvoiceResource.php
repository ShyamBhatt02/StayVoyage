<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'type' => $this->invoiceable_type,
            'reference' => $this->billing_details['reference'] ?? null,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'items' => $this->items,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'service_fee' => $this->service_fee,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'payment_status' => $this->payment_status,
        ];
    }
}
