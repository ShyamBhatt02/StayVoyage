<?php

namespace App\Services\Hotel;

use App\Exceptions\DomainException;
use App\Models\BookingRoom;
use App\Models\HotelRoom;
use App\Models\HotelRoomAvailability;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Room inventory per room-type per night.
 *
 * Reservation protocol (always inside a DB transaction):
 *   1. ensureRows()  — materialise missing date rows (INSERT IGNORE, safe under concurrency)
 *   2. lock rows     — SELECT ... FOR UPDATE ordered by date (consistent lock order => no deadlocks)
 *   3. re-check availability for every night
 *   4. increment booked_count
 * A DB CHECK constraint (booked + blocked <= total) makes overselling impossible even if code is wrong.
 */
class InventoryService
{
    /** @return array<int,string> list of Y-m-d nights in [checkIn, checkOut) */
    public function nights(string|Carbon $checkIn, string|Carbon $checkOut): array
    {
        $from = CarbonImmutable::parse($checkIn)->startOfDay();
        $to = CarbonImmutable::parse($checkOut)->startOfDay();
        if ($to->lte($from)) {
            return [];
        }

        return collect(CarbonPeriod::create($from, $to->subDay()))->map(fn ($d) => $d->toDateString())->all();
    }

    public function ensureRows(HotelRoom $room, array $dates): void
    {
        if (! $dates) {
            return;
        }
        $now = now();
        DB::table('hotel_room_availability')->insertOrIgnore(array_map(fn ($d) => [
            'hotel_room_id' => $room->id,
            'date' => $d,
            'total_inventory' => $room->total_rooms,
            'booked_count' => 0,
            'blocked_count' => 0,
            'is_closed' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $dates));
    }

    /** @return Collection<string,HotelRoomAvailability> keyed by Y-m-d, locked FOR UPDATE. */
    public function lockRows(HotelRoom $room, array $dates): Collection
    {
        $this->assertInTransaction();
        $this->ensureRows($room, $dates);

        return HotelRoomAvailability::query()
            ->where('hotel_room_id', $room->id)
            ->whereIn('date', $dates)
            ->orderBy('date')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn ($r) => $r->date->toDateString());
    }

    /** Lock + verify + reserve. Must be called inside DB::transaction(). */
    public function reserve(HotelRoom $room, string $checkIn, string $checkOut, int $quantity): Collection
    {
        $dates = $this->nights($checkIn, $checkOut);
        if (! $dates) {
            throw DomainException::make('Check-out must be after check-in.', 'invalid_dates');
        }

        $rows = $this->lockRows($room, $dates);
        foreach ($dates as $date) {
            $row = $rows->get($date);
            if (! $row || $row->available() < $quantity) {
                Log::channel('inventory')->info('Reservation rejected: sold out', [
                    'room_id' => $room->id, 'date' => $date, 'requested' => $quantity, 'available' => $row?->available(),
                ]);
                throw DomainException::make(
                    $row && $row->is_closed
                        ? "{$room->name} is not available on ".Carbon::parse($date)->format('j M Y').'.'
                        : "Sorry — {$room->name} just sold out for ".Carbon::parse($date)->format('j M Y').'. Please choose other dates or another room.',
                    'room_unavailable',
                    409,
                    ['date' => $date, 'available' => $row?->available() ?? 0],
                );
            }
        }

        HotelRoomAvailability::query()->whereIn('id', $rows->pluck('id'))->update([
            'booked_count' => DB::raw('booked_count + '.(int) $quantity),
            'updated_at' => now(),
        ]);

        Log::channel('inventory')->info('Inventory reserved', ['room_id' => $room->id, 'nights' => $dates, 'qty' => $quantity]);

        return $rows;
    }

    /** Release the inventory held by a booking line. Idempotent via booking_rooms.inventory_released. */
    public function release(BookingRoom $line): void
    {
        DB::transaction(function () use ($line) {
            $locked = BookingRoom::query()->lockForUpdate()->find($line->id);
            if (! $locked || $locked->inventory_released) {
                return;
            }
            $dates = $this->nights($locked->check_in, $locked->check_out);
            $rows = HotelRoomAvailability::query()
                ->where('hotel_room_id', $locked->hotel_room_id)
                ->whereIn('date', $dates)
                ->orderBy('date')
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                if ($row->booked_count < $locked->quantity) {
                    Log::channel('inventory')->error('Inventory inconsistency on release', [
                        'availability_id' => $row->id, 'booked_count' => $row->booked_count, 'release_qty' => $locked->quantity,
                    ]);
                }
                $row->forceFill(['booked_count' => max(0, $row->booked_count - $locked->quantity)])->save();
            }

            $locked->forceFill(['inventory_released' => true])->save();
            Log::channel('inventory')->info('Inventory released', ['booking_room_id' => $locked->id, 'nights' => $dates]);
        });
    }

    /**
     * Minimum rooms available across all nights (no locks; for display/search only — the booking path re-checks under lock).
     * @return array<int,int> room_id => available
     */
    public function availableForRooms(array $roomIds, string $checkIn, string $checkOut): array
    {
        $dates = $this->nights($checkIn, $checkOut);
        if (! $roomIds || ! $dates) {
            return [];
        }

        $rooms = HotelRoom::query()->whereIn('id', $roomIds)->pluck('total_rooms', 'id');
        $stats = HotelRoomAvailability::query()
            ->whereIn('hotel_room_id', $roomIds)
            ->whereIn('date', $dates)
            ->groupBy('hotel_room_id')
            ->selectRaw('hotel_room_id, COUNT(*) as row_count, MIN(total_inventory - booked_count - blocked_count) as min_free, MAX(is_closed) as any_closed')
            ->get()
            ->keyBy('hotel_room_id');

        $result = [];
        foreach ($rooms as $id => $total) {
            $s = $stats->get($id);
            if (! $s) {
                $result[$id] = (int) $total;
                continue;
            }
            if ((int) $s->any_closed) {
                $result[$id] = 0;
                continue;
            }
            $min = max(0, (int) $s->min_free);
            // Nights without a row are untouched => full inventory available.
            $result[$id] = (int) $s->row_count < count($dates) ? min($min, (int) $total) : $min;
        }

        return $result;
    }

    /**
     * Price per room for each night: date override > seasonal (weekend-aware) > weekend price > base.
     * Weekend nights are Friday and Saturday.
     * @return array<string,float>
     */
    public function nightlyRates(HotelRoom $room, string $checkIn, string $checkOut): array
    {
        $dates = $this->nights($checkIn, $checkOut);
        if (! $dates) {
            return [];
        }

        $overrides = HotelRoomAvailability::query()
            ->where('hotel_room_id', $room->id)
            ->whereIn('date', $dates)
            ->whereNotNull('price_override')
            ->pluck('price_override', 'date')
            ->mapWithKeys(fn ($p, $d) => [Carbon::parse($d)->toDateString() => (float) $p]);

        $seasons = $room->relationLoaded('seasonalPrices')
            ? $room->seasonalPrices
            : $room->seasonalPrices()->where('end_date', '>=', $dates[0])->where('start_date', '<=', end($dates))->get();

        $rates = [];
        foreach ($dates as $date) {
            $d = CarbonImmutable::parse($date);
            $isWeekend = in_array($d->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY], true);

            if ($overrides->has($date)) {
                $rates[$date] = $overrides[$date];
                continue;
            }
            $season = $seasons->first(fn ($s) => $d->betweenIncluded($s->start_date, $s->end_date));
            if ($season) {
                $rates[$date] = (float) ($isWeekend && $season->weekend_price ? $season->weekend_price : $season->price);
                continue;
            }
            $rates[$date] = (float) ($isWeekend && $room->weekend_price ? $room->weekend_price : $room->base_price);
        }

        return $rates;
    }

    /** Admin: set blocked rooms / closure / price override / total inventory for a date range. */
    public function updateRange(HotelRoom $room, string $from, string $to, array $changes): int
    {
        $dates = collect(CarbonPeriod::create($from, $to))->map(fn ($d) => $d->toDateString())->all();
        if (count($dates) > 366) {
            throw DomainException::make('Please update at most one year at a time.', 'range_too_large');
        }

        return DB::transaction(function () use ($room, $dates, $changes) {
            $rows = $this->lockRows($room, $dates);
            $conflicts = [];
            foreach ($rows as $date => $row) {
                $total = array_key_exists('total_inventory', $changes) && $changes['total_inventory'] !== null ? (int) $changes['total_inventory'] : $row->total_inventory;
                $blocked = array_key_exists('blocked_count', $changes) && $changes['blocked_count'] !== null ? (int) $changes['blocked_count'] : $row->blocked_count;
                if ($row->booked_count + $blocked > $total) {
                    $conflicts[] = $date;
                    continue;
                }
                $fill = ['total_inventory' => $total, 'blocked_count' => $blocked];
                if (array_key_exists('is_closed', $changes) && $changes['is_closed'] !== null) {
                    $fill['is_closed'] = (bool) $changes['is_closed'];
                }
                if (array_key_exists('price_override', $changes)) {
                    $fill['price_override'] = $changes['price_override'];
                }
                if (array_key_exists('note', $changes)) {
                    $fill['note'] = $changes['note'];
                }
                $row->forceFill($fill)->save();
            }
            if ($conflicts) {
                throw DomainException::make(
                    'Cannot reduce availability below rooms already booked on: '.implode(', ', array_slice($conflicts, 0, 10)).(count($conflicts) > 10 ? '…' : ''),
                    'inventory_conflict',
                    409,
                    ['dates' => $conflicts],
                );
            }

            return count($dates);
        });
    }

    /** When a room type's physical count changes, propagate to future dated rows (never below booked + blocked). */
    public function syncTotalRooms(HotelRoom $room): void
    {
        DB::transaction(function () use ($room) {
            $conflict = HotelRoomAvailability::query()
                ->where('hotel_room_id', $room->id)
                ->where('date', '>=', today())
                ->whereRaw('booked_count + blocked_count > ?', [$room->total_rooms])
                ->lockForUpdate()
                ->orderBy('date')
                ->value('date');
            if ($conflict) {
                throw DomainException::make(
                    'Total rooms cannot be lower than rooms already booked/blocked (first conflict: '.Carbon::parse($conflict)->format('j M Y').').',
                    'inventory_conflict',
                    409,
                );
            }
            HotelRoomAvailability::query()
                ->where('hotel_room_id', $room->id)
                ->where('date', '>=', today())
                ->update(['total_inventory' => $room->total_rooms, 'updated_at' => now()]);
        });
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Inventory rows must be locked inside a database transaction.');
        }
    }
}
