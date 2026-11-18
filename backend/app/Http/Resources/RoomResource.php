<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\HotelRoom */
class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/v1/admin/*') || $request->is('api/v1/partner/*');

        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'name' => $this->name,
            'room_type' => $this->room_type,
            'description' => $this->description,
            'max_guests' => $this->max_guests,
            'max_adults' => $this->max_adults,
            'max_children' => $this->max_children,
            'bed_type' => $this->bed_type,
            'size_sqft' => $this->size_sqft,
            'base_price' => $this->base_price,
            'weekend_price' => $this->weekend_price,
            'breakfast_included' => $this->breakfast_included,
            'free_cancellation' => $this->free_cancellation,
            'free_cancellation_hours' => $this->free_cancellation_hours,
            'cancellation_policy' => $this->cancellation_policy,
            'is_membership_eligible' => $this->is_membership_eligible,
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url, 'caption' => $i->caption])),
            'amenities' => $this->whenLoaded('amenities', fn () => AmenityResource::collection($this->amenities)),
            // Search context (set by controller when dates are given)
            'available_count' => $this->when(isset($this->available_count), fn () => $this->available_count),
            'stay_price' => $this->when(isset($this->stay_price), fn () => $this->stay_price),
            'nightly_rates' => $this->when(isset($this->nightly_rates), fn () => $this->nightly_rates),
            'total_rooms' => $this->when($admin, $this->total_rooms),
            'status' => $this->when($admin, $this->status),
            'sort_order' => $this->when($admin, $this->sort_order),
            'seasonal_prices' => $this->when($admin && $this->relationLoaded('seasonalPrices'), fn () => $this->seasonalPrices->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'start_date' => $s->start_date->toDateString(), 'end_date' => $s->end_date->toDateString(),
                'price' => $s->price, 'weekend_price' => $s->weekend_price,
            ])),
        ];
    }
}
