<?php

namespace App\Http\Resources;

use App\Models\TourActivity;
use App\Models\TourGuide;
use App\Models\TourMeal;
use App\Models\TourTransportation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Unified shape for activities / guides / transport / meals in the Tour Builder picker and admin. */
class CatalogueItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $base = [
            'id' => $r->id,
            'name' => $r->name,
            'image_url' => $r->image_url,
            'destination_id' => $r->destination_id,
            'destination' => $r->relationLoaded('destination') ? $r->destination?->name : null,
            'status' => $r->status,
        ];

        return match (true) {
            $r instanceof TourActivity => $base + [
                'kind' => 'activity',
                'slug' => $r->slug,
                'category' => $r->category,
                'location' => $r->location,
                'short_description' => $r->short_description,
                'description' => $r->description,
                'duration_minutes' => $r->duration_minutes,
                'start_times' => $r->start_times ?? [],
                'available_days' => $r->available_days,
                'capacity_per_slot' => $r->capacity_per_slot,
                'price' => $r->price,
                'child_price' => $r->child_price,
                'inclusions' => $r->inclusions ?? [],
                'rating_avg' => $r->rating_avg,
                'review_count' => $r->review_count,
                'price_label' => 'per adult',
            ],
            $r instanceof TourGuide => $base + [
                'kind' => 'guide',
                'slug' => $r->slug,
                'languages' => $r->languages ?? [],
                'bio' => $r->bio,
                'specialty' => $r->specialty,
                'experience_years' => $r->experience_years,
                'available_from' => substr((string) $r->available_from, 0, 5),
                'available_to' => substr((string) $r->available_to, 0, 5),
                'available_days' => $r->available_days,
                'min_hours' => $r->min_hours,
                'max_hours' => $r->max_hours,
                'hourly_rate' => $r->hourly_rate,
                'full_day_rate' => $r->full_day_rate,
                'price' => $r->hourly_rate,
                'rating_avg' => $r->rating_avg,
                'review_count' => $r->review_count,
                'price_label' => 'per hour',
            ],
            $r instanceof TourTransportation => $base + [
                'kind' => 'transportation',
                'type' => $r->type,
                'provider' => $r->provider,
                'from_location' => $r->from_location,
                'to_location' => $r->to_location,
                'pricing_unit' => $r->pricing_unit,
                'price' => $r->price,
                'capacity' => $r->capacity,
                'duration_minutes' => $r->duration_minutes,
                'departure_times' => $r->departure_times ?? [],
                'description' => $r->description,
                'price_label' => str_replace('_', ' ', $r->pricing_unit),
            ],
            $r instanceof TourMeal => $base + [
                'kind' => 'meal',
                'meal_type' => $r->meal_type,
                'venue' => $r->venue,
                'cuisine' => $r->cuisine,
                'description' => $r->description,
                'price' => $r->price,
                'price_label' => 'per person',
            ],
            default => $base,
        };
    }
}
