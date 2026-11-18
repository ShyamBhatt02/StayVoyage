<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TourPackage */
class TourPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'description' => $this->description,
            'duration_days' => $this->duration_days,
            'duration_nights' => $this->duration_nights,
            'cities' => $this->cities,
            'default_adults' => $this->default_adults,
            'max_travelers' => $this->max_travelers,
            'price_from' => $this->price_from,
            'image_url' => $this->image_url,
            'gallery' => $this->gallery_urls,
            'highlights' => $this->highlights ?? [],
            'inclusions' => $this->inclusions ?? [],
            'exclusions' => $this->exclusions ?? [],
            'difficulty' => $this->difficulty,
            'rating_avg' => round((float) $this->rating_avg, 1),
            'review_count' => $this->review_count,
            'is_featured' => $this->is_featured,
            'status' => $this->when($request->is('api/v1/admin/*'), $this->status),
            'destination' => $this->whenLoaded('destination', fn () => $this->destination ? ['id' => $this->destination->id, 'name' => $this->destination->name, 'slug' => $this->destination->slug, 'country' => $this->destination->country] : null),
            'days' => $this->whenLoaded('items', fn () => $this->items->groupBy('day_number')->map(fn ($items, $day) => [
                'day_number' => (int) $day,
                'title' => $items->first()->day_title ?: "Day {$day}",
                'items' => $items->map(fn ($i) => [
                    'id' => $i->id,
                    'item_type' => $i->item_type,
                    'itemable_type' => $i->itemable_type,
                    'itemable_id' => $i->itemable_id,
                    'title' => $i->title,
                    'description' => $i->description,
                    'start_time' => $i->start_time ? substr($i->start_time, 0, 5) : null,
                    'nights' => $i->nights,
                    'hours' => $i->hours,
                    'from_location' => $i->from_location,
                    'to_location' => $i->to_location,
                ])->values(),
            ])->values()),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
        ];
    }
}
