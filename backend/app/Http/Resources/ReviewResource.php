<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Review */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/v1/admin/*');

        return [
            'id' => $this->id,
            'overall' => $this->overall,
            'cleanliness' => $this->cleanliness,
            'location' => $this->location,
            'service' => $this->service,
            'value' => $this->value,
            'title' => $this->title,
            'comment' => $this->comment,
            'images' => $this->image_urls,
            'status' => $this->when($admin || $request->user()?->id === $this->user_id, $this->status),
            'moderation_note' => $this->when($admin, $this->moderation_note),
            'author' => $this->whenLoaded('user', fn () => [
                'name' => $admin ? $this->user?->name : self::publicName((string) $this->user?->name),
                'avatar_url' => $this->user?->avatar_url,
                'email' => $this->when($admin, $this->user?->email),
            ]),
            'hotel' => $this->whenLoaded('hotel', fn () => ['id' => $this->hotel?->id, 'name' => $this->hotel?->name, 'slug' => $this->hotel?->slug, 'city' => $this->hotel?->city]),
            'stay_month' => $this->whenLoaded('booking', fn () => $this->booking?->check_in?->format('F Y')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** "Priya Sharma" -> "Priya S." (privacy: no full surnames on public pages). */
    public static function publicName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) < 2) {
            return $parts[0] ?? 'Guest';
        }

        return $parts[0].' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.';
    }
}
