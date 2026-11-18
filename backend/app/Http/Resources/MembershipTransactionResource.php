<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MembershipTransaction */
class MembershipTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'membership_id' => $this->membership_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'nights' => $this->nights,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'booking_number' => $this->whenLoaded('booking', fn () => $this->booking?->booking_number),
            'description' => $this->description,
            'created_by' => $this->when($request->is('api/v1/admin/*'), fn () => $this->relationLoaded('creator') ? $this->creator?->name : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
