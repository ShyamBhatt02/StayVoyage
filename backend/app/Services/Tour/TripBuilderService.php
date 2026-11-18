<?php

namespace App\Services\Tour;

use App\Enums\TripItemType;
use App\Exceptions\DomainException;
use App\Models\HotelRoom;
use App\Models\TourActivity;
use App\Models\TourGuide;
use App\Models\TourMeal;
use App\Models\TourPackage;
use App\Models\TourTransportation;
use App\Models\TripDay;
use App\Models\TripItem;
use App\Models\User;
use App\Models\UserTrip;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * All itinerary mutations. Each public method runs in a transaction and finishes with a server-side reprice,
 * so the stored total always reflects the current itinerary.
 */
class TripBuilderService
{
    public const MAX_DAYS = 30;
    public const MAX_ITEMS_PER_DAY = 15;

    public function __construct(private readonly TourPricingService $pricing) {}

    public function create(User $user, array $data): UserTrip
    {
        return DB::transaction(function () use ($user, $data) {
            $trip = new UserTrip([
                'title' => $data['title'],
                'destination_summary' => $data['destination_summary'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'adults' => $data['adults'] ?? 2,
                'children' => $data['children'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'cover_image' => $data['cover_image'] ?? (! empty($data['destination_id'])
                    ? \App\Models\Destination::query()->whereKey($data['destination_id'])->value('image_path')
                    : null),
            ]);
            $trip->user_id = $user->id;
            $trip->forceFill(['status' => 'draft', 'visibility' => 'private'])->save();

            $days = max(1, min(self::MAX_DAYS, (int) ($data['days'] ?? 1)));
            for ($i = 1; $i <= $days; $i++) {
                $trip->days()->create(['day_number' => $i, 'title' => "Day {$i}", 'destination_id' => $data['destination_id'] ?? null]);
            }

            return $this->pricing->reprice($trip);
        });
    }

    /** "Customize This Tour": copy a package template into a new editable trip for the user. */
    public function createFromPackage(User $user, TourPackage $package, array $data): UserTrip
    {
        if ($package->status !== 'active') {
            throw DomainException::make('This tour package is no longer available.', 'package_unavailable');
        }

        return DB::transaction(function () use ($user, $package, $data) {
            $package->loadMissing('items', 'destination');
            $trip = new UserTrip([
                'title' => $data['title'] ?? $package->name,
                'destination_summary' => $package->cities ?: $package->destination?->name,
                'start_date' => $data['start_date'] ?? null,
                'adults' => $data['adults'] ?? $package->default_adults,
                'children' => $data['children'] ?? 0,
                'cover_image' => $package->image_path,
                'tour_package_id' => $package->id,
            ]);
            $trip->user_id = $user->id;
            $trip->forceFill(['status' => 'draft', 'visibility' => 'private'])->save();

            $days = [];
            foreach ($package->items->groupBy('day_number') as $dayNumber => $items) {
                $days[$dayNumber] = $trip->days()->create([
                    'day_number' => $dayNumber,
                    'title' => $items->first()->day_title ?: "Day {$dayNumber}",
                    'destination_id' => $package->destination_id,
                ]);
            }
            for ($i = 1; $i <= $package->duration_days; $i++) {
                $days[$i] ??= $trip->days()->create(['day_number' => $i, 'title' => "Day {$i}", 'destination_id' => $package->destination_id]);
            }

            foreach ($package->items as $pi) {
                $item = new TripItem([
                    'item_type' => $pi->item_type,
                    'itemable_type' => $pi->itemable_type,
                    'itemable_id' => $pi->itemable_id,
                    'title' => $pi->title,
                    'from_location' => $pi->from_location,
                    'to_location' => $pi->to_location,
                    'start_time' => $pi->start_time,
                    'duration_minutes' => $pi->duration_minutes,
                    'nights' => $pi->nights,
                    'hours' => $pi->hours,
                    'quantity' => $this->defaultQuantity(TripItemType::from($pi->item_type), $pi->itemable_type, $pi->itemable_id, $trip),
                    'notes' => $pi->description,
                    'sort_order' => $pi->sort_order,
                ]);
                $item->user_trip_id = $trip->id;
                $item->trip_day_id = $days[$pi->day_number]->id;
                $item->save();
            }

            return $this->pricing->reprice($trip);
        });
    }

    public function update(UserTrip $trip, array $data): UserTrip
    {
        $this->assertEditable($trip);

        return DB::transaction(function () use ($trip, $data) {
            $trip->fill(collect($data)->only(['title', 'destination_summary', 'start_date', 'adults', 'children', 'notes'])->all())->save();

            return $this->pricing->reprice($trip);
        });
    }

    public function duplicate(UserTrip $trip, User $owner): UserTrip
    {
        return DB::transaction(function () use ($trip, $owner) {
            $trip->load('days.items');
            $copy = $trip->replicate(['share_token', 'status', 'visibility', 'user_id', 'priced_at']);
            $copy->title = Str::limit($trip->title.' (copy)', 250, '');
            $copy->user_id = $owner->id;
            $copy->forceFill(['status' => 'draft', 'visibility' => 'private', 'share_token' => null])->save();

            foreach ($trip->days as $day) {
                $newDay = $copy->days()->create($day->only(['day_number', 'title', 'destination_id', 'notes']));
                foreach ($day->items as $item) {
                    $newItem = $item->replicate(['user_trip_id', 'trip_day_id', 'unit_price', 'total_price', 'is_available', 'availability_note']);
                    $newItem->user_trip_id = $copy->id;
                    $newItem->trip_day_id = $newDay->id;
                    $newItem->save();
                }
            }

            return $this->pricing->reprice($copy);
        });
    }

    public function addDay(UserTrip $trip, ?int $afterDayNumber = null): UserTrip
    {
        $this->assertEditable($trip);

        return DB::transaction(function () use ($trip, $afterDayNumber) {
            $count = $trip->days()->count();
            if ($count >= self::MAX_DAYS) {
                throw DomainException::make('Trips can have at most '.self::MAX_DAYS.' days.', 'too_many_days');
            }
            $position = $afterDayNumber === null ? $count + 1 : min($count + 1, max(1, $afterDayNumber + 1));
            $trip->days()->where('day_number', '>=', $position)->increment('day_number');
            $trip->days()->create(['day_number' => $position, 'title' => "Day {$position}"]);

            return $this->pricing->reprice($trip);
        });
    }

    public function updateDay(TripDay $day, array $data): UserTrip
    {
        $this->assertEditable($day->trip);
        $day->fill(collect($data)->only(['title', 'notes', 'destination_id'])->all())->save();

        return $day->trip;
    }

    public function deleteDay(TripDay $day): UserTrip
    {
        $trip = $day->trip;
        $this->assertEditable($trip);

        return DB::transaction(function () use ($trip, $day) {
            if ($trip->days()->count() <= 1) {
                throw DomainException::make('A trip needs at least one day.', 'last_day');
            }
            $number = $day->day_number;
            $day->delete(); // cascades its items
            $trip->days()->where('day_number', '>', $number)->decrement('day_number');

            return $this->pricing->reprice($trip);
        });
    }

    /** @param array<int,int> $dayIds new order */
    public function reorderDays(UserTrip $trip, array $dayIds): UserTrip
    {
        $this->assertEditable($trip);

        return DB::transaction(function () use ($trip, $dayIds) {
            $existing = $trip->days()->pluck('id')->all();
            if (count($dayIds) !== count($existing) || array_diff($existing, $dayIds)) {
                throw DomainException::make('The day order is out of date — please refresh and try again.', 'stale_order', 409);
            }
            // Two passes to avoid transient duplicates.
            foreach ($dayIds as $i => $id) {
                TripDay::query()->whereKey($id)->update(['day_number' => 100 + $i + 1]);
            }
            foreach ($dayIds as $i => $id) {
                TripDay::query()->whereKey($id)->update(['day_number' => $i + 1, 'title' => DB::raw("IF(title REGEXP '^Day [0-9]+$', 'Day ".($i + 1)."', title)")]);
            }

            return $this->pricing->reprice($trip);
        });
    }

    public function addItem(UserTrip $trip, TripDay $day, array $data): UserTrip
    {
        $this->assertEditable($trip);
        if ($day->user_trip_id !== $trip->id) {
            throw DomainException::make('That day does not belong to this trip.', 'invalid_day', 404);
        }

        return DB::transaction(function () use ($trip, $day, $data) {
            if ($day->items()->count() >= self::MAX_ITEMS_PER_DAY) {
                throw DomainException::make('You can add up to '.self::MAX_ITEMS_PER_DAY.' items per day.', 'too_many_items');
            }
            $type = TripItemType::from($data['item_type']);
            [$morph, $id, $catalogue] = $this->resolveCatalogue($type, $data['itemable_id'] ?? null);

            $item = new TripItem([
                'item_type' => $type,
                'itemable_type' => $morph,
                'itemable_id' => $id,
                'title' => $catalogue ? $this->catalogueTitle($catalogue) : trim((string) ($data['title'] ?? 'Free time')),
                'provider' => $catalogue instanceof TourTransportation ? $catalogue->provider : ($catalogue instanceof HotelRoom ? $catalogue->hotel?->name : null),
                'from_location' => $data['from_location'] ?? ($catalogue instanceof TourTransportation ? $catalogue->from_location : null),
                'to_location' => $data['to_location'] ?? ($catalogue instanceof TourTransportation ? $catalogue->to_location : null),
                'start_time' => $data['start_time'] ?? $this->defaultStartTime($catalogue),
                'duration_minutes' => $catalogue?->duration_minutes ?? null,
                'quantity' => $data['quantity'] ?? $this->defaultQuantity($type, $morph, $id, $trip),
                'nights' => $type === TripItemType::Accommodation ? max(1, (int) ($data['nights'] ?? 1)) : null,
                'hours' => $type === TripItemType::Guide ? (int) ($data['hours'] ?? $catalogue?->min_hours ?? 4) : null,
                'meal_plan' => $type === TripItemType::Accommodation ? ($data['meal_plan'] ?? null) : null,
                'notes' => $data['notes'] ?? null,
                'sort_order' => (int) $day->items()->max('sort_order') + 1,
            ]);
            $item->user_trip_id = $trip->id;
            $item->trip_day_id = $day->id;
            $item->save();

            // Give scratch-built trips a cover photo from the first hotel (or activity) added.
            if (! $trip->cover_image) {
                $cover = match (true) {
                    $catalogue instanceof HotelRoom => $catalogue->hotel?->images()->orderByDesc('is_cover')->value('path'),
                    $catalogue instanceof TourActivity => $catalogue->image_path,
                    default => null,
                };
                if ($cover) {
                    $trip->forceFill(['cover_image' => $cover])->saveQuietly();
                }
            }

            return $this->pricing->reprice($trip);
        });
    }

    public function updateItem(TripItem $item, array $data): UserTrip
    {
        $trip = $item->trip;
        $this->assertEditable($trip);

        return DB::transaction(function () use ($trip, $item, $data) {
            $fill = collect($data)->only(['start_time', 'quantity', 'nights', 'hours', 'meal_plan', 'notes', 'from_location', 'to_location'])->all();
            if (! $item->itemable_type && isset($data['title'])) {
                $fill['title'] = $data['title'];
            }
            if (isset($data['trip_day_id']) && (int) $data['trip_day_id'] !== $item->trip_day_id) {
                $target = $trip->days()->whereKey($data['trip_day_id'])->firstOrFail();
                $item->trip_day_id = $target->id;
                $item->sort_order = (int) $target->items()->max('sort_order') + 1;
            }
            $item->fill($fill)->save();

            return $this->pricing->reprice($trip);
        });
    }

    public function duplicateItem(TripItem $item): UserTrip
    {
        $trip = $item->trip;
        $this->assertEditable($trip);

        return DB::transaction(function () use ($trip, $item) {
            if ($item->day->items()->count() >= self::MAX_ITEMS_PER_DAY) {
                throw DomainException::make('You can add up to '.self::MAX_ITEMS_PER_DAY.' items per day.', 'too_many_items');
            }
            $copy = $item->replicate(['unit_price', 'total_price', 'is_available', 'availability_note']);
            $copy->sort_order = (int) $item->day->items()->max('sort_order') + 1;
            $copy->save();

            return $this->pricing->reprice($trip);
        });
    }

    public function deleteItem(TripItem $item): UserTrip
    {
        $trip = $item->trip;
        $this->assertEditable($trip);

        return DB::transaction(function () use ($trip, $item) {
            $item->delete();

            return $this->pricing->reprice($trip);
        });
    }

    /**
     * Reorder items within and across days (drag & drop on desktop, move up/down on mobile).
     * @param array<int,array{day_id:int,item_ids:array<int,int>}> $layout
     */
    public function reorderItems(UserTrip $trip, array $layout): UserTrip
    {
        $this->assertEditable($trip);

        return DB::transaction(function () use ($trip, $layout) {
            $dayIds = $trip->days()->pluck('id')->all();
            $itemIds = $trip->items()->pluck('id')->all();
            $seen = [];
            foreach ($layout as $col) {
                if (! in_array((int) $col['day_id'], $dayIds, true)) {
                    throw DomainException::make('Invalid day in new order.', 'stale_order', 409);
                }
                if (count($col['item_ids']) > self::MAX_ITEMS_PER_DAY) {
                    throw DomainException::make('You can add up to '.self::MAX_ITEMS_PER_DAY.' items per day.', 'too_many_items');
                }
                foreach ($col['item_ids'] as $i => $id) {
                    if (! in_array((int) $id, $itemIds, true) || isset($seen[$id])) {
                        throw DomainException::make('The itinerary changed — please refresh and try again.', 'stale_order', 409);
                    }
                    $seen[$id] = true;
                    TripItem::query()->whereKey($id)->update(['trip_day_id' => $col['day_id'], 'sort_order' => $i + 1]);
                }
            }

            return $this->pricing->reprice($trip);
        });
    }

    public function setSharing(UserTrip $trip, string $visibility): UserTrip
    {
        $trip->forceFill([
            'visibility' => $visibility,
            'share_token' => $trip->share_token ?: Str::lower(Str::random(32)),
        ])->save();

        return $trip;
    }

    public function assertEditable(UserTrip $trip): void
    {
        if (! $trip->isEditable()) {
            throw DomainException::make('This trip has been booked and can no longer be edited. Duplicate it to plan a new version.', 'trip_locked', 409);
        }
    }

    /** @return array{0:?string,1:?int,2:mixed} */
    private function resolveCatalogue(TripItemType $type, ?int $id): array
    {
        $allowed = TourPricingService::allowedMorphs($type);
        if (! $allowed) {
            return [null, null, null]; // free-form item (e.g. "Leisure time at the beach")
        }
        if (! $id) {
            throw DomainException::make('Please choose an option from the list.', 'item_required');
        }
        $morph = $allowed[0];
        $class = Relation::getMorphedModel($morph);
        $query = $class::query()->where('status', 'active');
        if ($class === HotelRoom::class) {
            $query->with('hotel')->whereHas('hotel', fn ($q) => $q->where('status', 'active'));
        }
        if ($class === TourTransportation::class) {
            $query->where('type', $type->value);
        }
        if ($class === TourActivity::class) {
            $type === TripItemType::Attraction ? $query->where('category', 'attraction') : $query->where('category', '<>', 'attraction');
        }
        $catalogue = $query->find($id);
        if (! $catalogue) {
            throw DomainException::make('That option is no longer available.', 'tour_item_unavailable', 404);
        }

        return [$morph, $catalogue->id, $catalogue];
    }

    private function catalogueTitle(mixed $c): string
    {
        return match (true) {
            $c instanceof HotelRoom => ($c->hotel?->name ?? 'Hotel').' — '.$c->name,
            $c instanceof TourTransportation => $c->name,
            $c instanceof TourActivity => $c->name,
            $c instanceof TourGuide => 'Guide: '.$c->name,
            $c instanceof TourMeal => $c->name,
            default => 'Item',
        };
    }

    private function defaultStartTime(mixed $c): ?string
    {
        return match (true) {
            $c instanceof TourActivity => $c->start_times[0] ?? '10:00',
            $c instanceof TourTransportation => $c->departure_times[0] ?? null,
            $c instanceof TourGuide => substr((string) $c->available_from, 0, 5),
            $c instanceof TourMeal => match ($c->meal_type) { 'breakfast' => '08:00', 'lunch' => '13:00', default => '20:00' },
            $c instanceof HotelRoom => substr((string) ($c->hotel?->check_in_time ?? '14:00'), 0, 5),
            default => null,
        };
    }

    private function defaultQuantity(TripItemType $type, ?string $morph, ?int $id, UserTrip $trip): int
    {
        $travelers = $trip->travelerCount();
        if ($type === TripItemType::Accommodation && $id) {
            $room = HotelRoom::query()->find($id);

            return $room ? max(1, (int) ceil($travelers / max(1, $room->max_guests))) : 1;
        }
        if ($morph === 'tour_transportation' && $id) {
            $t = TourTransportation::query()->find($id);
            if ($t?->pricing_unit === 'per_vehicle') {
                return max(1, (int) ceil($travelers / max(1, $t->capacity)));
            }
            if ($t?->pricing_unit === 'per_day') {
                return 1;
            }
        }
        if ($type === TripItemType::Guide) {
            return 1;
        }

        return $travelers;
    }
}
