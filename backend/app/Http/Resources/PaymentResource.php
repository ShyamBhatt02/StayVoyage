<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Never exposes gateway metadata, secrets or card data (none is stored). @mixin \App\Models\Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'purpose' => $this->purpose,
            'gateway' => $this->gateway,
            'gateway_payment_id' => $this->gateway_payment_id,
            'method' => $this->method,
            'amount' => $this->amount,
            'amount_refunded' => $this->amount_refunded,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'failure_reason' => $this->failure_reason,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'payable_type' => $this->payable_type,
            'payable_reference' => $this->when($this->relationLoaded('payable'), fn () => $this->payable?->booking_number ?? $this->payable?->membership_number),
            'customer' => $this->when($request->is('api/v1/admin/*') && $this->relationLoaded('user'), fn () => $this->user?->only(['id', 'name', 'email'])),
        ];
    }
}
