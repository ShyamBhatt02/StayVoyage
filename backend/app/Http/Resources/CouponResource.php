<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Coupon */
class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/v1/admin/*');

        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'min_purchase' => $this->min_purchase,
            'max_discount' => $this->max_discount,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'applies_to' => $this->applies_to,
            'image_url' => $this->image_url,
            'is_public' => $this->is_public,
            'usage_limit' => $this->when($admin, $this->usage_limit),
            'per_user_limit' => $this->per_user_limit,
            'used_count' => $this->when($admin, $this->used_count),
            'status' => $this->when($admin, $this->status),
            'hotel_ids' => $this->when($admin && $this->relationLoaded('hotels'), fn () => $this->hotels->pluck('id')),
            'tour_package_ids' => $this->when($admin && $this->relationLoaded('tourPackages'), fn () => $this->tourPackages->pluck('id')),
            'membership_plan_ids' => $this->when($admin && $this->relationLoaded('membershipPlans'), fn () => $this->membershipPlans->pluck('id')),
        ];
    }
}
