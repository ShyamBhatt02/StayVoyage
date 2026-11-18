<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AmenityResource;
use App\Http\Resources\DestinationResource;
use App\Models\Amenity;
use App\Models\Destination;
use App\Services\AuditLogger;
use App\Services\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DestinationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $items = Destination::query()->withCount(['hotels', 'tourPackages'])
            ->when($request->query('q'), fn ($q, $t) => $q->where('name', 'like', "%{$t}%"))
            ->orderBy('sort_order')->orderBy('name')->get();

        return $this->ok(DestinationResource::collection($items));
    }

    public function store(Request $request, ImageUploadService $images): JsonResponse
    {
        $data = $this->validated($request, null);
        $data['slug'] = $data['slug'] ?? $this->slug($data['name']);
        if ($request->hasFile('image')) {
            $data['image_path'] = $images->store($request->file('image'), 'destinations');
        }
        $d = Destination::query()->create($data);
        $this->audit->log('destination.created', $d, null, $d->only(['name', 'country']));
        Cache::forget('meta.home');

        return $this->created(new DestinationResource($d), 'Destination created.');
    }

    public function update(Request $request, Destination $destination, ImageUploadService $images): JsonResponse
    {
        $data = $this->validated($request, $destination);
        if ($request->hasFile('image')) {
            $images->delete($destination->image_path);
            $data['image_path'] = $images->store($request->file('image'), 'destinations');
        }
        $original = $destination->getOriginal();
        $destination->update($data);
        $this->audit->logChanges('destination.updated', $destination, $original);
        Cache::forget('meta.home');

        return $this->ok(new DestinationResource($destination), 'Destination updated.');
    }

    public function destroy(Destination $destination): JsonResponse
    {
        if ($destination->hotels()->exists() || $destination->tourPackages()->exists()) {
            return $this->fail('Move or archive this destination\'s hotels and tours before deleting it.', 409, [], 'in_use');
        }
        $destination->delete();
        $this->audit->log('destination.deleted', $destination, ['name' => $destination->name], null);
        Cache::forget('meta.home');

        return $this->ok(null, 'Destination deleted.');
    }

    // Amenities (small reference list, managed here)
    public function amenities(): JsonResponse
    {
        return $this->ok(AmenityResource::collection(Amenity::query()->orderBy('name')->get()));
    }

    public function storeAmenity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:amenities,name'],
            'icon' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/'],
            'scope' => ['required', 'in:hotel,room,both'],
        ]);
        $a = Amenity::query()->create($data + ['slug' => Str::slug($data['name'])]);
        Cache::forget('meta.config');

        return $this->created(new AmenityResource($a), 'Amenity added.');
    }

    public function destroyAmenity(Amenity $amenity): JsonResponse
    {
        $amenity->delete();
        Cache::forget('meta.config');

        return $this->ok(null, 'Amenity removed.');
    }

    private function validated(Request $request, ?Destination $d): array
    {
        $req = $d ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', Rule::unique('destinations', 'slug')->ignore($d?->id)],
            'country' => [$req, 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'highlights' => ['nullable', 'array', 'max:10'],
            'highlights.*' => ['string', 'max:120'],
            'best_time_to_visit' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_featured' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'image' => ['nullable', ...array_slice(ImageUploadService::RULES, 1)],
        ]);
        unset($data['image']);

        return $data;
    }

    private function slug(string $name): string
    {
        $slug = Str::slug($name);
        $c = $slug;
        $i = 2;
        while (Destination::withTrashed()->where('slug', $c)->exists()) {
            $c = $slug.'-'.$i++;
        }

        return $c;
    }
}
