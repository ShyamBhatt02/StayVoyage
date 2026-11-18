<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\HotelSearchRequest;
use App\Http\Resources\HotelCardResource;
use App\Http\Resources\HotelResource;
use App\Http\Resources\ReviewResource;
use App\Models\Hotel;
use App\Services\Hotel\HotelPricingService;
use App\Services\Hotel\HotelSearchService;
use App\Services\Hotel\InventoryService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HotelController extends Controller
{
    public function index(HotelSearchRequest $request, HotelSearchService $search): JsonResponse
    {
        $results = $search->search($request->validated());

        return $this->ok(HotelCardResource::collection($results), $results->total() ? 'OK' : 'No hotels match your search.');
    }

    public function show(string $slug, Request $request, InventoryService $inventory): JsonResponse
    {
        $hotel = Hotel::query()->active()->where('slug', $slug)
            ->with(['destination:id,name,slug', 'images', 'amenities', 'policy', 'activeRooms.images', 'activeRooms.amenities', 'activeRooms.seasonalPrices'])
            ->firstOrFail();
        $hotel->setRelation('rooms', $hotel->activeRooms);

        $checkIn = $request->query('check_in');
        $checkOut = $request->query('check_out');
        if ($checkIn && $checkOut && strtotime($checkOut) > strtotime($checkIn) && strtotime($checkIn) >= strtotime('today')) {
            $available = $inventory->availableForRooms($hotel->rooms->pluck('id')->all(), $checkIn, $checkOut);
            foreach ($hotel->rooms as $room) {
                $rates = $inventory->nightlyRates($room, $checkIn, $checkOut);
                $room->setAttribute('available_count', $available[$room->id] ?? 0);
                $room->setAttribute('nightly_rates', $rates);
                $room->setAttribute('stay_price', Money::round(array_sum($rates)));
            }
        }

        $breakdown = $hotel->reviews()->where('status', 'approved')
            ->selectRaw('ROUND(AVG(cleanliness),1) as cleanliness, ROUND(AVG(location),1) as location, ROUND(AVG(service),1) as service, ROUND(AVG(value),1) as value')
            ->first();
        $hotel->setAttribute('rating_breakdown', $breakdown?->toArray());

        $similar = Hotel::query()->active()->where('destination_id', $hotel->destination_id)->whereKeyNot($hotel->id)
            ->with(['coverImage', 'amenities', 'destination:id,name,slug'])->orderByDesc('rating_avg')->limit(4)->get();

        return $this->ok([
            'hotel' => new HotelResource($hotel),
            'similar' => HotelCardResource::collection($similar),
            'is_saved' => (bool) $request->user()?->savedHotels()->whereKey($hotel->id)->exists(),
        ]);
    }

    public function reviews(string $slug, Request $request): JsonResponse
    {
        $hotel = Hotel::query()->active()->where('slug', $slug)->firstOrFail();
        $reviews = $hotel->reviews()->where('status', 'approved')->with(['user', 'booking'])
            ->when($request->query('sort') === 'lowest', fn ($q) => $q->orderBy('overall'), fn ($q) => $q->latest())
            ->paginate(8);

        return $this->ok(ReviewResource::collection($reviews));
    }

    /** Live price quote for the booking widget — always computed server-side. */
    public function quote(Request $request, HotelPricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'hotel_room_id' => ['required', 'integer', 'exists:hotel_rooms,id'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'rooms' => ['required', 'integer', 'min:1', 'max:10'],
            'adults' => ['required', 'integer', 'min:1', 'max:30'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'booking_type' => ['required', 'in:membership,regular'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);
        $room = \App\Models\HotelRoom::query()->with('seasonalPrices')->findOrFail($data['hotel_room_id']);
        $hotel = Hotel::query()->findOrFail($room->hotel_id);
        $quote = $pricing->quote($hotel, $room, $data, $request->user());
        $available = app(InventoryService::class)->availableForRooms([$room->id], $data['check_in'], $data['check_out'])[$room->id] ?? 0;
        $quote['available_count'] = $available;
        $quote['is_available'] = $available >= (int) $data['rooms'];
        $room->loadMissing('images');
        $quote['display'] = [
            'hotel_name' => $hotel->name, 'hotel_slug' => $hotel->slug, 'city' => $hotel->city, 'cover_url' => $hotel->cover_url,
            'room_name' => $room->name, 'bed_type' => $room->bed_type, 'breakfast_included' => $room->breakfast_included,
            'image_url' => $room->images->first()?->url ?? $hotel->cover_url, 'cancellation_policy' => $room->cancellation_policy,
            'check_in_time' => substr((string) $hotel->check_in_time, 0, 5),
        ];

        return $this->ok($quote);
    }

    public function toggleSave(string $slug, Request $request): JsonResponse
    {
        $hotel = Hotel::query()->active()->where('slug', $slug)->firstOrFail();
        $result = $request->user()->savedHotels()->toggle([$hotel->id]);
        $saved = ! empty($result['attached']);

        return $this->ok(['saved' => $saved], $saved ? 'Saved to your wishlist.' : 'Removed from your wishlist.');
    }

    public function saved(Request $request): JsonResponse
    {
        $hotels = $request->user()->savedHotels()->active()->with(['coverImage', 'amenities', 'destination:id,name,slug'])->latest('saved_hotels.created_at')->get();

        return $this->ok(HotelCardResource::collection($hotels));
    }
}
