<?php

namespace App\Http\Resources;

use App\Models\HotelRoom;
use App\Models\TourActivity;
use App\Models\TourGuide;
use App\Models\TourMeal;
use App\Models\TourTransportation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A custom trip with its full day/item structure (Tour Builder state).
 * `public` mode strips everything personal for /trip/{token} share links.
 * @mixin \App\Models\UserTrip
 */
class TripResource extends JsonResource
{
    public bool $publicView = false;

    public function asPublic(): static
    {
        $this->publicView = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $days = $this->relationLoaded('days') ? $this->days : collect();
        $endDate = $this->start_date?->copy()->addDays(max(0, $days->count() - 1));

        $data = [
            'id' => $this->publicView ? null : $this->id,
            'title' => $this->title,
            'destination_summary' => $this->destination_summary,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $endDate?->toDateString(),
            'duration_days' => $days->count(),
            'adults' => $this->adults,
            'children' => $this->children,
            'travelers' => $this->travelerCount(),
            'status' => $this->status,
            'visibility' => $this->visibility,
            'cover_url' => $this->cover_url,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'service_fee' => $this->service_fee,
            'total_amount' => $this->total_amount,
            'price_breakdown' => $this->price_breakdown,
            'priced_at' => $this->priced_at?->toIso8601String(),
            'package' => $this->whenLoaded('package', fn () => $this->package ? ['id' => $this->package->id, 'name' => $this->package->name, 'slug' => $this->package->slug] : null),
            'days' => $days->map(fn ($d) => [
                'id' => $d->id,
                'day_number' => $d->day_number,
                'date' => $this->start_date?->copy()->addDays($d->day_number - 1)->toDateString(),
                'title' => $d->title,
                'notes' => $d->notes,
                'destination_id' => $d->destination_id,
                'items' => $d->relationLoaded('items') ? $d->items->map(fn ($i) => $this->item($i))->values() : [],
            ])->values(),
            'items_count' => $days->sum(fn ($d) => $d->relationLoaded('items') ? $d->items->count() : 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if (! $this->publicView) {
            $data['notes'] = $this->notes;
            $data['share_token'] = $this->share_token;
            $data['share_url'] = $this->share_token ? rtrim((string) config('payments.frontend_url'), '/').'/trip/'.$this->share_token : null;
            $data['travelers_list'] = $this->whenLoaded('travelers', fn () => $this->travelers->map->only(['id', 'full_name', 'traveler_type', 'age', 'gender', 'nationality', 'email', 'phone', 'is_lead']));
            $data['booking'] = $this->whenLoaded('booking', fn () => $this->booking ? ['booking_number' => $this->booking->booking_number, 'status' => $this->booking->status->value] : null);
        } else {
            $data['owner_first_name'] = strtok((string) $this->user?->name, ' ') ?: 'A traveller';
        }

        return $data;
    }

    private function item($i): array
    {
        $c = $i->relationLoaded('itemable') ? $i->itemable : null;

        return [
            'id' => $this->publicView ? null : $i->id,
            'item_type' => $i->item_type->value,
            'group' => $i->item_type->group(),
            'itemable_id' => $this->publicView ? null : $i->itemable_id,
            'title' => $i->title,
            'provider' => $i->provider,
            'from_location' => $i->from_location,
            'to_location' => $i->to_location,
            'start_time' => $i->start_time ? substr($i->start_time, 0, 5) : null,
            'duration_minutes' => $i->duration_minutes,
            'quantity' => $i->quantity,
            'nights' => $i->nights,
            'hours' => $i->hours,
            'meal_plan' => $i->meal_plan,
            'notes' => $i->notes,
            'unit_price' => $i->unit_price,
            'total_price' => $i->total_price,
            'is_available' => $i->is_available,
            'availability_note' => $i->availability_note,
            'sort_order' => $i->sort_order,
            'image_url' => match (true) {
                $c instanceof HotelRoom => $c->relationLoaded('hotel') ? $c->hotel?->cover_url : null,
                $c instanceof TourActivity, $c instanceof TourTransportation, $c instanceof TourMeal, $c instanceof TourGuide => $c->image_url,
                default => null,
            },
            'pricing_unit' => $c instanceof TourTransportation ? $c->pricing_unit : null,
            'hotel_slug' => $c instanceof HotelRoom && $c->relationLoaded('hotel') ? $c->hotel?->slug : null,
        ];
    }
}
