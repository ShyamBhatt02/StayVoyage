<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact hotel used in listings, search results and carousels. @mixin \App\Models\Hotel */
class HotelCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = $this->search_meta ?? [];

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'property_type' => $this->property_type,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'destination' => $this->whenLoaded('destination', fn () => ['name' => $this->destination?->name, 'slug' => $this->destination?->slug]),
            'star_rating' => $this->star_rating,
            'rating_avg' => round((float) $this->rating_avg, 1),
            'review_count' => $this->review_count,
            'cover_url' => $this->cover_url,
            'short_description' => $this->short_description,
            'amenities' => $this->whenLoaded('amenities', fn () => AmenityResource::collection($this->amenities->take(6))),
            'distance_from_center_km' => $this->distance_from_center_km,
            'from_price' => (float) ($meta['from_price'] ?? $this->min_price),
            'is_membership_eligible' => $this->is_membership_eligible,
            'is_featured' => $this->is_featured,
            'breakfast_available' => $meta['breakfast_available'] ?? null,
            'free_cancellation_available' => $meta['free_cancellation_available'] ?? null,
            'membership_rooms' => $meta['membership_rooms'] ?? null,
            'rooms_left' => $meta['rooms_left'] ?? null,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
