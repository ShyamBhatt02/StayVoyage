<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Full hotel detail. @mixin \App\Models\Hotel */
class HotelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/v1/admin/*') || $request->is('api/v1/partner/*');

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'property_type' => $this->property_type,
            'star_rating' => $this->star_rating,
            'rating_avg' => round((float) $this->rating_avg, 1),
            'review_count' => $this->review_count,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'distance_from_center_km' => $this->distance_from_center_km,
            'check_in_time' => substr((string) $this->check_in_time, 0, 5),
            'check_out_time' => substr((string) $this->check_out_time, 0, 5),
            'phone' => $this->phone,
            'email' => $this->email,
            'from_price' => (float) $this->min_price,
            'is_membership_eligible' => $this->is_membership_eligible,
            'is_featured' => $this->is_featured,
            'cover_url' => $this->cover_url,
            'destination' => $this->whenLoaded('destination', fn () => $this->destination ? ['id' => $this->destination->id, 'name' => $this->destination->name, 'slug' => $this->destination->slug] : null),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url, 'caption' => $i->caption, 'is_cover' => $i->is_cover])),
            'amenities' => $this->whenLoaded('amenities', fn () => AmenityResource::collection($this->amenities)),
            'policy' => $this->whenLoaded('policy', fn () => $this->policy ? $this->policy->only([
                'cancellation_policy', 'children_policy', 'pet_policy', 'smoking_policy', 'extra_policies', 'pets_allowed', 'smoking_allowed',
            ]) : null),
            'rooms' => $this->whenLoaded('rooms', fn () => RoomResource::collection($this->rooms)),
            'rating_breakdown' => $this->when(isset($this->rating_breakdown), fn () => $this->rating_breakdown),
            'status' => $this->when($admin, $this->status),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'partners' => $this->when($admin && $this->relationLoaded('partners'), fn () => $this->partners->map->only(['id', 'name', 'email'])),
            'rooms_count' => $this->whenCounted('rooms'),
            'created_at' => $this->when($admin, fn () => $this->created_at?->toIso8601String()),
        ];
    }
}
