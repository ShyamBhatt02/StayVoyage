<?php

namespace App\Services\Hotel;

use App\Models\Hotel;
use App\Models\HotelRoom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Hotel search with filters, sorting and availability. Filtering is done in SQL; when dates are supplied,
 * availability for the candidate rooms is computed with ONE aggregate query, then results are paginated.
 */
class HotelSearchService
{
    public const PER_PAGE = 12;

    public function __construct(private readonly InventoryService $inventory) {}

    public function search(array $p): LengthAwarePaginator
    {
        $page = max(1, (int) ($p['page'] ?? 1));
        $perPage = min(48, max(6, (int) ($p['per_page'] ?? self::PER_PAGE)));
        $rooms = max(1, (int) ($p['rooms'] ?? 1));
        $guests = max(1, (int) ($p['guests'] ?? 2));
        $perRoomGuests = (int) ceil($guests / $rooms);
        $hasDates = ! empty($p['check_in']) && ! empty($p['check_out']);

        $query = Hotel::query()->active()->select('hotels.*');
        $this->applyFilters($query, $p, $perRoomGuests);
        $this->applySort($query, $p['sort'] ?? 'recommended');

        $ids = $query->pluck('hotels.id');

        $roomAvailability = [];
        $roomsByHotel = collect();
        if ($ids->isNotEmpty()) {
            $roomsByHotel = HotelRoom::query()
                ->whereIn('hotel_id', $ids)
                ->where('status', 'active')
                ->where('max_guests', '>=', $perRoomGuests)
                ->when(! empty($p['membership']), fn ($q) => $q->where('is_membership_eligible', true))
                ->get(['id', 'hotel_id', 'base_price', 'weekend_price', 'breakfast_included', 'free_cancellation', 'is_membership_eligible', 'total_rooms'])
                ->groupBy('hotel_id');

            if ($hasDates) {
                $roomAvailability = $this->inventory->availableForRooms($roomsByHotel->flatten()->pluck('id')->all(), $p['check_in'], $p['check_out']);
                $ids = $ids->filter(fn ($id) => $roomsByHotel->get($id, collect())->contains(fn ($r) => ($roomAvailability[$r->id] ?? 0) >= $rooms))->values();
            }
        }

        $total = $ids->count();
        $pageIds = $ids->slice(($page - 1) * $perPage, $perPage)->values();

        $hotels = Hotel::query()
            ->with(['destination:id,name,slug', 'coverImage', 'amenities:id,name,slug,icon'])
            ->whereIn('id', $pageIds)
            ->get()
            ->sortBy(fn ($h) => $pageIds->search($h->id))
            ->values()
            ->each(function (Hotel $h) use ($roomsByHotel, $roomAvailability, $hasDates, $rooms) {
                /** @var Collection $hr */
                $hr = $roomsByHotel->get($h->id, collect());
                $bookable = $hasDates ? $hr->filter(fn ($r) => ($roomAvailability[$r->id] ?? 0) >= $rooms) : $hr;
                $h->setAttribute('search_meta', [
                    'from_price' => (float) ($bookable->min('base_price') ?? $h->min_price),
                    'breakfast_available' => $hr->contains('breakfast_included', true),
                    'free_cancellation_available' => $hr->contains('free_cancellation', true),
                    'membership_rooms' => $hr->where('is_membership_eligible', true)->count(),
                    'rooms_left' => $hasDates ? (int) $bookable->sum(fn ($r) => $roomAvailability[$r->id] ?? 0) : null,
                ]);
            });

        return new LengthAwarePaginator($hotels, $total, $perPage, $page, ['path' => request()->url(), 'query' => request()->query()]);
    }

    private function applyFilters(Builder $q, array $p, int $perRoomGuests): void
    {
        if (! empty($p['destination'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($p['destination'])).'%';
            $q->where(function ($w) use ($term) {
                $w->where('hotels.name', 'like', $term)
                    ->orWhere('hotels.city', 'like', $term)
                    ->orWhere('hotels.state', 'like', $term)
                    ->orWhere('hotels.country', 'like', $term)
                    ->orWhereHas('destination', fn ($d) => $d->where('name', 'like', $term)->orWhere('slug', 'like', $term));
            });
        }
        if (! empty($p['destination_id'])) {
            $q->where('destination_id', (int) $p['destination_id']);
        }
        if (isset($p['price_min']) && $p['price_min'] !== '') {
            $q->where('min_price', '>=', (float) $p['price_min']);
        }
        if (isset($p['price_max']) && $p['price_max'] !== '') {
            $q->where('min_price', '<=', (float) $p['price_max']);
        }
        if (! empty($p['rating_min'])) {
            $q->where('rating_avg', '>=', (float) $p['rating_min']);
        }
        if (! empty($p['stars'])) {
            $q->whereIn('star_rating', array_map('intval', (array) $p['stars']));
        }
        if (! empty($p['property_types'])) {
            $q->whereIn('property_type', (array) $p['property_types']);
        }
        if (! empty($p['distance_max'])) {
            $q->where('distance_from_center_km', '<=', (float) $p['distance_max']);
        }
        if (! empty($p['membership'])) {
            $q->where('is_membership_eligible', true);
        }
        foreach ((array) ($p['amenities'] ?? []) as $slug) {
            $q->whereHas('amenities', fn ($a) => $a->where('slug', $slug));
        }
        $roomCond = function ($r) use ($p, $perRoomGuests) {
            $r->where('status', 'active')->where('max_guests', '>=', $perRoomGuests);
            if (! empty($p['breakfast'])) {
                $r->where('breakfast_included', true);
            }
            if (! empty($p['free_cancellation'])) {
                $r->where('free_cancellation', true);
            }
            if (! empty($p['membership'])) {
                $r->where('is_membership_eligible', true);
            }
        };
        $q->whereHas('rooms', $roomCond);
    }

    private function applySort(Builder $q, string $sort): void
    {
        match ($sort) {
            'price_asc' => $q->orderBy('min_price')->orderByDesc('rating_avg'),
            'price_desc' => $q->orderByDesc('min_price')->orderByDesc('rating_avg'),
            'rating' => $q->orderByDesc('rating_avg')->orderByDesc('review_count'),
            'distance' => $q->orderByRaw('distance_from_center_km IS NULL, distance_from_center_km ASC'),
            default => $q->orderByDesc('is_featured')->orderByDesc('rating_avg')->orderByDesc('review_count')->orderBy('min_price'),
        };
        $q->orderBy('hotels.id');
    }
}
