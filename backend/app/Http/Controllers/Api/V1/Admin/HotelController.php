<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HotelRequest;
use App\Http\Resources\HotelResource;
use App\Models\Hotel;
use App\Models\HotelImage;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Services\ImageUploadService;
use App\Services\ImageUploadService as Images;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mounted under /admin (staff) and /partner (hotel partners). Every action is authorised through HotelPolicy,
 * so partners can only ever touch the hotels linked to their account, and cannot change platform-level fields.
 */
class HotelController extends Controller
{
    /** Fields only staff may set (partners' values are ignored). */
    private const STAFF_ONLY = ['destination_id', 'slug', 'is_membership_eligible', 'is_featured', 'status', 'partner_ids', 'star_rating'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,draft'],
            'destination_id' => ['nullable', 'integer'],
            'membership' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();

        $hotels = Hotel::query()
            ->with(['destination:id,name,slug', 'coverImage'])
            ->withCount('rooms')
            ->when(! $user->hasPermission('hotels.view'), fn ($q) => $q->whereHas('partners', fn ($p) => $p->whereKey($user->id)))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('city', 'like', "%{$t}%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('destination_id'), fn ($q, $d) => $q->where('destination_id', $d))
            ->when($request->has('membership'), fn ($q) => $q->where('is_membership_eligible', $request->boolean('membership')))
            ->orderBy('name')
            ->paginate(20);

        return $this->ok(HotelResource::collection($hotels));
    }

    public function show(Request $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('update', $hotel);
        $hotel->load(['destination', 'images', 'amenities', 'policy', 'rooms.images', 'rooms.amenities', 'rooms.seasonalPrices', 'partners']);

        return $this->ok(new HotelResource($hotel));
    }

    public function store(HotelRequest $request): JsonResponse
    {
        $this->authorize('create', Hotel::class);
        $data = $request->validated();

        $hotel = DB::transaction(function () use ($data) {
            $hotel = Hotel::query()->create(array_merge(
                collect($data)->except(['amenity_ids', 'policy', 'partner_ids'])->all(),
                ['slug' => $data['slug'] ?? $this->uniqueSlug($data['name'].' '.$data['city'])],
            ));
            $hotel->amenities()->sync($data['amenity_ids'] ?? []);
            $hotel->policy()->create($data['policy'] ?? []);
            $this->syncPartners($hotel, $data['partner_ids'] ?? []);

            return $hotel;
        });
        $this->audit->log('hotel.created', $hotel, null, $hotel->only(['name', 'city', 'status']));
        $this->flushCaches();

        return $this->created(new HotelResource($hotel->load(['destination', 'amenities', 'policy'])), 'Hotel created. Add rooms and photos next.');
    }

    public function update(HotelRequest $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('update', $hotel);
        $data = $request->validated();
        if (! $request->user()->can('administer', $hotel)) {
            $data = collect($data)->except(self::STAFF_ONLY)->all();
        }

        DB::transaction(function () use ($hotel, $data, $request) {
            $original = $hotel->getOriginal();
            $hotel->fill(collect($data)->except(['amenity_ids', 'policy', 'partner_ids'])->all())->save();
            $this->audit->logChanges('hotel.updated', $hotel, $original);
            if (array_key_exists('amenity_ids', $data)) {
                $hotel->amenities()->sync($data['amenity_ids']);
            }
            if (array_key_exists('policy', $data)) {
                $hotel->policy()->updateOrCreate(['hotel_id' => $hotel->id], $data['policy']);
            }
            if (array_key_exists('partner_ids', $data) && $request->user()->can('administer', $hotel)) {
                $this->syncPartners($hotel, $data['partner_ids']);
            }
        });
        $this->flushCaches();

        return $this->ok(new HotelResource($hotel->fresh(['destination', 'images', 'amenities', 'policy', 'partners'])), 'Hotel updated.');
    }

    public function destroy(Request $request, Hotel $hotel): JsonResponse
    {
        $this->authorize('delete', $hotel);
        $upcoming = $hotel->bookings()->whereIn('status', ['confirmed', 'payment_pending'])->whereDate('check_out', '>=', today())->count();
        if ($upcoming) {
            return $this->fail("This hotel has {$upcoming} upcoming booking(s). Deactivate it instead, or cancel those bookings first.", 409, [], 'has_bookings');
        }
        $hotel->forceFill(['status' => 'inactive'])->save();
        $hotel->delete(); // soft delete — booking history stays intact
        $this->audit->log('hotel.deleted', $hotel, ['name' => $hotel->name], null);
        $this->flushCaches();

        return $this->ok(null, 'Hotel archived.');
    }

    public function images(Request $request, Hotel $hotel, ImageUploadService $images): JsonResponse
    {
        $this->authorize('update', $hotel);
        $request->validate(['images' => ['required', 'array', 'min:1', 'max:10'], 'images.*' => Images::RULES, 'caption' => ['nullable', 'string', 'max:150']]);
        $max = (int) $hotel->images()->max('sort_order');
        $hasCover = $hotel->images()->where('is_cover', true)->exists();
        foreach ($request->file('images') as $i => $file) {
            $hotel->images()->create([
                'path' => $images->store($file, 'hotels/'.$hotel->id),
                'caption' => $request->input('caption'),
                'sort_order' => $max + $i + 1,
                'is_cover' => ! $hasCover && $i === 0,
            ]);
        }
        $this->audit->log('hotel.images_uploaded', $hotel, null, ['count' => count($request->file('images'))]);
        $this->flushCaches();

        return $this->ok(new HotelResource($hotel->fresh(['images'])), 'Photos uploaded.');
    }

    public function deleteImage(Request $request, Hotel $hotel, HotelImage $image, ImageUploadService $images): JsonResponse
    {
        $this->authorize('update', $hotel);
        abort_unless($image->hotel_id === $hotel->id, 404);
        if ($hotel->images()->count() <= 1) {
            return $this->fail('A hotel needs at least one photo.', 422, [], 'last_image');
        }
        $images->delete($image->path);
        $wasCover = $image->is_cover;
        $image->delete();
        if ($wasCover) {
            $hotel->images()->orderBy('sort_order')->first()?->update(['is_cover' => true]);
        }
        $this->flushCaches();

        return $this->ok(new HotelResource($hotel->fresh(['images'])), 'Photo removed.');
    }

    public function setCover(Request $request, Hotel $hotel, HotelImage $image): JsonResponse
    {
        $this->authorize('update', $hotel);
        abort_unless($image->hotel_id === $hotel->id, 404);
        DB::transaction(function () use ($hotel, $image) {
            $hotel->images()->update(['is_cover' => false]);
            $image->update(['is_cover' => true]);
        });
        $this->flushCaches();

        return $this->ok(new HotelResource($hotel->fresh(['images'])), 'Cover photo updated.');
    }

    public function partnerCandidates(): JsonResponse
    {
        $partners = Role::query()->where('slug', Role::HOTEL_PARTNER)->first()?->users()->get(['users.id', 'users.name', 'users.email']) ?? collect();

        return $this->ok($partners);
    }

    private function syncPartners(Hotel $hotel, array $ids): void
    {
        $valid = Role::query()->where('slug', Role::HOTEL_PARTNER)->first()?->users()->whereIn('users.id', $ids)->pluck('users.id')->all() ?? [];
        $hotel->partners()->sync($valid);
    }

    private function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base);
        $candidate = $slug;
        $i = 2;
        while (Hotel::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$i++;
        }

        return $candidate;
    }

    private function flushCaches(): void
    {
        Cache::forget('meta.home');
        Cache::forget('meta.config');
    }
}
