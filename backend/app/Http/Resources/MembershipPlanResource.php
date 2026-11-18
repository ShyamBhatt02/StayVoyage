<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MembershipPlan */
class MembershipPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/v1/admin/*');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'duration_days' => $this->duration_days,
            'included_nights' => $this->included_nights,
            'price_per_night' => $this->included_nights ? round($this->price / $this->included_nights, 2) : null,
            'max_nights_per_booking' => $this->max_nights_per_booking,
            'max_rooms_per_booking' => $this->max_rooms_per_booking,
            'max_guests_per_booking' => $this->max_guests_per_booking,
            'min_advance_booking_days' => $this->min_advance_booking_days,
            'max_advance_booking_days' => $this->max_advance_booking_days,
            'all_hotels_eligible' => $this->all_hotels_eligible,
            'eligible_room_types' => $this->eligible_room_types,
            'cancellation_deadline_hours' => $this->cancellation_deadline_hours,
            'late_cancellation_policy' => $this->late_cancellation_policy,
            'no_show_policy' => $this->no_show_policy,
            'unused_nights_expire' => $this->unused_nights_expire,
            'nights_extend_past_expiry' => $this->nights_extend_past_expiry,
            'auto_renew_available' => $this->auto_renew_available,
            'charge_taxes_on_covered_amount' => $this->charge_taxes_on_covered_amount,
            'features' => $this->features ?? [],
            'is_featured' => $this->is_featured,
            'status' => $this->status,
            'sort_order' => $this->when($admin, $this->sort_order),
            'eligible_hotel_ids' => $this->when($admin && $this->relationLoaded('hotels'), fn () => $this->hotels->pluck('id')),
            'blackout_dates' => $this->whenLoaded('blackoutDates', fn () => $this->blackoutDates->map(fn ($b) => [
                'id' => $b->id, 'start_date' => $b->start_date->toDateString(), 'end_date' => $b->end_date->toDateString(), 'reason' => $b->reason,
            ])),
            'active_members' => $this->when($admin && isset($this->active_members_count), fn () => $this->active_members_count),
        ];
    }
}
