<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\HotelRoomAvailability;
use App\Services\AuditLogger;
use App\Services\Hotel\InventoryService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Availability calendar & inventory controls (block rooms, close dates, date price overrides). */
class AvailabilityController extends Controller
{
    public function __construct(private readonly InventoryService $inventory, private readonly AuditLogger $audit) {}

    public function calendar(Request $request, Hotel $hotel, HotelRoom $room): JsonResponse
    {
        $this->authorize('manageInventory', $hotel);
        abort_unless($room->hotel_id === $hotel->id, 404);
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $start = CarbonImmutable::parse(($request->query('month') ?: now()->format('Y-m')).'-01');
        $end = $start->endOfMonth();
        $rows = HotelRoomAvailability::query()->where('hotel_room_id', $room->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()
            ->keyBy(fn ($r) => $r->date->toDateString());
        $rates = $this->inventory->nightlyRates($room->load('seasonalPrices'), $start->toDateString(), $end->addDay()->toDateString());

        $days = collect(CarbonPeriod::create($start, $end))->map(function ($d) use ($rows, $room, $rates) {
            $date = $d->toDateString();
            $row = $rows->get($date);
            $total = $row?->total_inventory ?? $room->total_rooms;
            $booked = $row?->booked_count ?? 0;
            $blocked = $row?->blocked_count ?? 0;

            return [
                'date' => $date,
                'total' => $total,
                'booked' => $booked,
                'blocked' => $blocked,
                'available' => $row ? $row->available() : $total,
                'is_closed' => (bool) $row?->is_closed,
                'price' => $rates[$date] ?? $room->base_price,
                'price_override' => $row?->price_override,
                'note' => $row?->note,
                'is_past' => $d->isPast() && ! $d->isToday(),
            ];
        })->values();

        return $this->ok([
            'room' => ['id' => $room->id, 'name' => $room->name, 'total_rooms' => $room->total_rooms, 'base_price' => $room->base_price, 'weekend_price' => $room->weekend_price],
            'month' => $start->format('Y-m'),
            'days' => $days,
            'summary' => [
                'room_nights_sold' => $days->sum('booked'),
                'occupancy_percent' => $days->sum('total') ? round($days->sum('booked') / $days->sum('total') * 100, 1) : 0,
            ],
        ]);
    }

    public function update(Request $request, Hotel $hotel, HotelRoom $room): JsonResponse
    {
        $this->authorize('manageInventory', $hotel);
        abort_unless($room->hotel_id === $hotel->id, 404);
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'total_inventory' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'blocked_count' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'is_closed' => ['nullable', 'boolean'],
            'price_override' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'clear_price_override' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $changes = collect($data)->only(['total_inventory', 'blocked_count', 'is_closed', 'note'])->all();
        if ($request->boolean('clear_price_override')) {
            $changes['price_override'] = null;
        } elseif ($request->filled('price_override')) {
            $changes['price_override'] = (float) $data['price_override'];
        }

        $count = $this->inventory->updateRange($room, $data['start_date'], $data['end_date'], $changes);
        $this->audit->log('inventory.updated', $room, null, array_merge($changes, ['from' => $data['start_date'], 'to' => $data['end_date']]),
            "Updated {$count} night(s) for {$room->name}");

        return $this->ok(['updated_nights' => $count], "Updated {$count} night(s).");
    }
}
