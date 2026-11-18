<?php

namespace App\Http\Resources;

use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Destination */
class DestinationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'country' => $this->country,
            'state' => $this->state,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'gallery' => array_values(array_filter(array_map([Media::class, 'url'], $this->gallery ?? []))),
            'highlights' => $this->highlights ?? [],
            'best_time_to_visit' => $this->best_time_to_visit,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_featured' => $this->is_featured,
            'hotels_count' => $this->whenCounted('hotels'),
            'packages_count' => $this->whenCounted('tourPackages'),
            'from_price' => $this->when(isset($this->from_price), fn () => (float) $this->from_price),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'status' => $this->when($request->is('api/v1/admin/*'), $this->status),
            'sort_order' => $this->when($request->is('api/v1/admin/*'), $this->sort_order),
        ];
    }
}
