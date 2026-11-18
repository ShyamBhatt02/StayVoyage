<?php

namespace App\Http\Resources;

use App\Services\Booking\BookingLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Booking */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $this->relationLoaded('guests');

        return [
            'id' => $this->id,
            'booking_number' => $this->booking_number,
            'type' => $this->type,
            'booking_type' => $this->booking_type,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_status' => $this->payment_status->value,
            'payment_status_label' => $this->payment_status->label(),
            'check_in' => $this->check_in?->toDateString(),
            'check_out' => $this->check_out?->toDateString(),
            'nights' => $this->nights,
            'rooms_count' => $this->rooms_count,
            'adults' => $this->adults,
            'children' => $this->children,
            'currency' => $this->currency,
            'room_amount' => $this->room_amount,
            'membership_nights_used' => $this->membership_nights_used,
            'membership_covered_amount' => $this->membership_covered_amount,
            'subtotal' => $this->subtotal,
            'coupon_code' => $this->coupon_code,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'service_fee' => $this->service_fee,
            'total_amount' => $this->total_amount,
            'amount_paid' => $this->amount_paid,
            'amount_refunded' => $this->amount_refunded,
            'amount_due' => $this->amountDue(),
            'hold_expires_at' => $this->hold_expires_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'hotel' => $this->whenLoaded('hotel', fn () => $this->hotel ? [
                'id' => $this->hotel->id, 'name' => $this->hotel->name, 'slug' => $this->hotel->slug, 'city' => $this->hotel->city,
                'address' => $this->hotel->address, 'star_rating' => $this->hotel->star_rating, 'cover_url' => $this->hotel->cover_url,
                'check_in_time' => substr((string) $this->hotel->check_in_time, 0, 5), 'check_out_time' => substr((string) $this->hotel->check_out_time, 0, 5),
                'phone' => $this->hotel->phone, 'latitude' => $this->hotel->latitude, 'longitude' => $this->hotel->longitude,
            ] : null),
            'trip' => $this->when($this->type === 'tour', fn () => [
                'id' => $this->user_trip_id,
                'title' => $this->itinerary_snapshot['title'] ?? null,
                'destination_summary' => $this->itinerary_snapshot['destination_summary'] ?? null,
                'cover_url' => $this->relationLoaded('trip') ? $this->trip?->cover_url : null,
            ]),
            'rooms' => $this->whenLoaded('rooms', fn () => $this->rooms->map(fn ($r) => [
                'id' => $r->id, 'hotel_room_id' => $r->hotel_room_id, 'room_name' => $r->room?->name, 'hotel_name' => $r->hotel?->name,
                'check_in' => $r->check_in->toDateString(), 'check_out' => $r->check_out->toDateString(),
                'quantity' => $r->quantity, 'nights' => $r->nights, 'nightly_rates' => $r->nightly_rates, 'amount' => $r->amount,
                'image_url' => $r->room?->relationLoaded('images') ? $r->room->images->first()?->url : null,
                'bed_type' => $r->room?->bed_type, 'breakfast_included' => $r->room?->breakfast_included,
            ])),
            'guests' => $this->whenLoaded('guests', fn () => $this->guests->map->only(['id', 'full_name', 'guest_type', 'age', 'email', 'phone', 'is_primary'])),
            'contact' => $this->when($detail, fn () => ['name' => $this->contact_name, 'email' => $this->contact_email, 'phone' => $this->contact_phone]),
            'special_requests' => $this->when($detail, $this->special_requests),
            'price_breakdown' => $this->when($detail, $this->price_breakdown),
            'itinerary' => $this->when($detail && $this->type === 'tour', $this->itinerary_snapshot),
            'payments' => $this->whenLoaded('payments', fn () => PaymentResource::collection($this->payments)),
            'cancellation' => $this->whenLoaded('cancellation', fn () => $this->cancellation ? $this->cancellation->only([
                'reason', 'within_free_window', 'penalty_amount', 'refund_amount', 'nights_returned', 'nights_forfeited', 'cancelled_by_role', 'created_at',
            ]) : null),
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds->map->only(['reference', 'amount', 'status', 'reason', 'processed_at', 'created_at'])),
            'review' => $this->whenLoaded('review', fn () => $this->review ? ['id' => $this->review->id, 'status' => $this->review->status, 'overall' => $this->review->overall] : null),
            'can_cancel' => $this->when($detail, fn () => app(BookingLifecycleService::class)->cancellationPreview($this->resource)['allowed']),
            'can_pay' => in_array($this->status->value, ['payment_pending', 'pending'], true) && (! $this->hold_expires_at || $this->hold_expires_at->isFuture()),
            'can_review' => $this->type === 'hotel' && $this->status->value === 'completed' && ($this->relationLoaded('review') ? ! $this->review : true),
            'has_invoice' => in_array($this->payment_status->value, ['paid', 'refunded', 'partially_refunded'], true),
            'customer' => $this->when($request->is('api/v1/admin/*') || $request->is('api/v1/partner/*'), fn () => $this->relationLoaded('user') && $this->user ? [
                'id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email,
            ] : null),
        ];
    }
}
