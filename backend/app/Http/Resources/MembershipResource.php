<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Membership */
class MembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'membership_number' => $this->membership_number,
            'plan_id' => $this->membership_plan_id,
            'plan_name' => $this->plan_snapshot['name'] ?? null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_usable' => $this->isUsable(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'days_remaining' => $this->daysRemaining(),
            'total_nights' => $this->total_nights,
            'used_nights' => $this->usedNights(),
            'remaining_nights' => $this->nights_balance,
            'price_paid' => $this->price_paid,
            'currency' => $this->currency,
            'auto_renew' => $this->auto_renew,
            'rules' => collect($this->plan_snapshot)->except(['quote', 'plan_id'])->all(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'customer' => $this->when($request->is('api/v1/admin/*') && $this->relationLoaded('user'), fn () => $this->user?->only(['id', 'name', 'email'])),
        ];
    }
}
