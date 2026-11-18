<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoomResource;
use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\HotelRoomImage;
use App\Models\HotelRoomSeasonalPrice;
use App\Services\AuditLogger;
use App\Services\Hotel\InventoryService;
use App\Services\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly InventoryService $inventory) {}

    public function index(Hotel $hotel): JsonResponse
    {
        $this->authorize('update', $hotel);

        return $this->ok(RoomResource::collection($hotel->rooms()->with(['images', 'amenities', 'seasonalPrices'])->get()));
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('update', $hotel);
        $data = $this->validated($request, true);
        if (! $request->user()->can('administer', $hotel)) {
            unset($data['is_membership_eligible']);
        }

        $room = DB::transaction(function () use ($hotel, $data) {
            $room = $hotel->rooms()->create(collect($data)->except('amenity_ids')->all());
            $room->amenities()->sync($data['amenity_ids'] ?? []);
            $hotel->refreshAggregates();

            return $room;
        });
        $this->audit->log('room.created', $room, null, $room->only(['name', 'total_rooms', 'base_price']));
        Cache::forget('meta.home');

        return $this->created(new RoomResource($room->load(['images', 'amenities'])), 'Room type created.');
    }

    public function update(Request $request, Hotel $hotel, HotelRoom $room): JsonResponse
    {
        $this->authorize('update', $hotel);
        abort_unless($room->hotel_id === $hotel->id, 404);
        $data = $this->validated($request, false);
        if (! $request->user()->can('administer', $hotel)) {
            unset($data['is_membership_eligible']);
        }

        DB::transaction(function () use ($room, $data, $hotel) {
            $original = $room->getOriginal();
            $room->fill(collect($data)->except('amenity_ids')->all())->save();
            if ($room->wasChanged('total_rooms')) {
                $this->inventory->syncTotalRooms($room); // throws (and rolls back) if below booked rooms
                $this->audit->log('inventory.total_changed', $room, ['total_rooms' => $original['total_rooms']], ['total_rooms' => $room->total_rooms]);
            }
            if ($room->wasChanged(['base_price', 'weekend_price'])) {
                $this->audit->log('price.changed', $room,
                    ['base_price' => $original['base_price'], 'weekend_price' => $original['weekend_price']],
                    ['base_price' => $room->base_price, 'weekend_price' => $room->weekend_price]);
            }
            $this->audit->logChanges('room.updated', $room, $original);
            if (array_key_exists('amenity_ids', $data)) {
                $room->amenities()->sync($data['amenity_ids']);
            }
            $hotel->refreshAggregates();
        });
        Cache::forget('meta.home');

        return $this->ok(new RoomResource($room->fresh(['images', 'amenities', 'seasonalPrices'])), 'Room updated.');
    }

    public function destroy(Request $request, Hotel $hotel, HotelRoom $room): JsonResponse
    {
        $this->authorize('update', $hotel);
        abort_unless($room->hotel_id === $hotel->id, 404);
        $future = $room->availability()->where('date', '>=', today())->where('booked_count', '>', 0)->exists();
        if ($future) {
            return $this->fail('This room has upcoming bookings. Set it to inactive instead to stop new sales.', 409, [], 'has_bookings');
        }
        $room->forceFill(['status' => 'inactive'])->save();
        $room->delete();
        $hotel->refreshAggregates();
        $this->audit->log('room.deleted', $room, ['name' => $room->name], null);

        return $this->ok(null, 'Room archived.');
    }

    public function images(Request $request, Hotel $hotel, HotelRoom $room, ImageUploadService $images): JsonResponse
    {
        $this->authorize('update', $hotel);
        abort_unless($room->hotel_id === $hotel->id, 404);
        $request->validate(['images' => ['required', 'array', 'min:1', 'max:10'], 'images.*' => ImageUploadService::RULES]);
        $max = (int) $room->images()->max('sort_order');
        foreach ($request->file('images') as $i => $file) {
            $room->images()->create(['path' => $images->store($file, 'rooms/'.$room->id), 'sort_order' => $max + $i + 1]);
        }

        return $this->ok(new RoomResource($room->fresh(['images', 'amenities'])), 'Room photos uploaded.');
    }

    public function deleteImage(Hotel $hotel, HotelRoom $room, HotelRoomImage $image, ImageUploadService $images): JsonResponse
    {
        $this->authorize('update', $hotel);
        abort_unless($room->hotel_id === $hotel->id && $image->hotel_room_id === $room->id, 404);
        $images->delete($image->path);
        $image->delete();

        return $this->ok(new RoomResource($room->fresh(['images', 'amenities'])), 'Photo removed.');
    }

    public function storeSeason(Request $request, Hotel $hotel, HotelRoom $room): JsonResponse
    {
        $this->authorize('manageInventory', $hotel);
        abort_unless($room->hotel_id === $hotel->id, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'price' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'weekend_price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);
        $overlap = $room->seasonalPrices()->where('start_date', '<=', $data['end_date'])->where('end_date', '>=', $data['start_date'])->exists();
        if ($overlap) {
            return $this->fail('This season overlaps an existing seasonal price for the room.', 422, [], 'season_overlap');
        }
        $season = $room->seasonalPrices()->create($data);
        $this->audit->log('price.season_created', $room, null, $data);

        return $this->created(['id' => $season->id], 'Seasonal price added.');
    }

    public function deleteSeason(Hotel $hotel, HotelRoom $room, HotelRoomSeasonalPrice $season): JsonResponse
    {
        $this->authorize('manageInventory', $hotel);
        abort_unless($room->hotel_id === $hotel->id && $season->hotel_room_id === $room->id, 404);
        $this->audit->log('price.season_deleted', $room, $season->only(['name', 'start_date', 'end_date', 'price']), null);
        $season->delete();

        return $this->ok(null, 'Seasonal price removed.');
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'room_type' => [$req, Rule::in(HotelRoom::ROOM_TYPES)],
            'description' => ['nullable', 'string', 'max:5000'],
            'max_guests' => [$req, 'integer', 'min:1', 'max:20'],
            'max_adults' => [$req, 'integer', 'min:1', 'max:20', 'lte:max_guests'],
            'max_children' => ['nullable', 'integer', 'min:0', 'max:10'],
            'bed_type' => [$req, 'string', 'max:50'],
            'size_sqft' => ['nullable', 'integer', 'min:50', 'max:20000'],
            'total_rooms' => [$req, 'integer', 'min:0', 'max:2000'],
            'base_price' => [$req, 'numeric', 'min:0', 'max:10000000'],
            'weekend_price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'breakfast_included' => ['sometimes', 'boolean'],
            'free_cancellation' => ['sometimes', 'boolean'],
            'free_cancellation_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'cancellation_policy' => ['nullable', 'string', 'max:2000'],
            'is_membership_eligible' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'amenity_ids' => ['sometimes', 'array'],
            'amenity_ids.*' => ['integer', 'exists:amenities,id'],
        ]);
        foreach (['description', 'cancellation_policy', 'name', 'bed_type'] as $f) {
            if (isset($data[$f])) {
                $data[$f] = strip_tags($data[$f]);
            }
        }

        return $data;
    }
}
