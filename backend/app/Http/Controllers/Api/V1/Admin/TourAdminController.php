<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TripItemType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogueItemResource;
use App\Http\Resources\TourPackageResource;
use App\Models\TourActivity;
use App\Models\TourGuide;
use App\Models\TourMeal;
use App\Models\TourPackage;
use App\Models\TourTransportation;
use App\Services\AuditLogger;
use App\Services\ImageUploadService;
use App\Services\Tour\TourPackageService;
use App\Services\Tour\TourPricingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Tour packages (templates) and the bookable catalogue: activities, guides, transportation, meals. */
class TourAdminController extends Controller
{
    private const KINDS = [
        'activities' => TourActivity::class,
        'guides' => TourGuide::class,
        'transportation' => TourTransportation::class,
        'meals' => TourMeal::class,
    ];

    public function __construct(private readonly AuditLogger $audit, private readonly TourPackageService $packages) {}

    // ------------------------------------------------------------- packages

    public function packages(Request $request): JsonResponse
    {
        $items = TourPackage::query()->with('destination')
            ->when($request->query('q'), fn ($q, $t) => $q->where('name', 'like', "%{$t}%"))
            ->orderByDesc('is_featured')->orderBy('name')->paginate(20);

        return $this->ok(TourPackageResource::collection($items));
    }

    public function package(TourPackage $package): JsonResponse
    {
        return $this->ok(new TourPackageResource($package->load(['destination', 'items'])));
    }

    public function storePackage(Request $request, ImageUploadService $images): JsonResponse
    {
        $data = $this->validatedPackage($request, null);
        $package = DB::transaction(function () use ($data, $request, $images) {
            $p = new TourPackage(collect($data)->except(['items', 'image'])->all());
            $p->slug = $data['slug'] ?? $this->slug($data['name']);
            if ($request->hasFile('image')) {
                $p->image_path = $images->store($request->file('image'), 'tours');
            }
            $p->save();
            $this->syncItems($p, $data['items'] ?? []);

            return $p;
        });
        $this->packages->recalculatePriceFrom($package);
        $this->audit->log('tour_package.created', $package, null, $package->only(['name', 'duration_days']));
        Cache::forget('meta.home');

        return $this->created(new TourPackageResource($package->fresh(['destination', 'items'])), 'Tour package created.');
    }

    public function updatePackage(Request $request, TourPackage $package, ImageUploadService $images): JsonResponse
    {
        $data = $this->validatedPackage($request, $package);
        DB::transaction(function () use ($package, $data, $request, $images) {
            $original = $package->getOriginal();
            $package->fill(collect($data)->except(['items', 'image'])->all());
            if ($request->hasFile('image')) {
                $images->delete($package->image_path);
                $package->image_path = $images->store($request->file('image'), 'tours');
            }
            $package->save();
            if (array_key_exists('items', $data)) {
                $this->syncItems($package, $data['items']);
            }
            $this->audit->logChanges('tour_package.updated', $package, $original);
        });
        $this->packages->recalculatePriceFrom($package);
        Cache::forget('meta.home');

        return $this->ok(new TourPackageResource($package->fresh(['destination', 'items'])), 'Tour package updated.');
    }

    public function destroyPackage(TourPackage $package): JsonResponse
    {
        $package->forceFill(['status' => 'inactive'])->save();
        $package->delete();
        $this->audit->log('tour_package.deleted', $package, ['name' => $package->name], null);
        Cache::forget('meta.home');

        return $this->ok(null, 'Tour package archived.');
    }

    // ------------------------------------------------------------- catalogue

    public function catalogue(Request $request, string $kind): JsonResponse
    {
        $class = self::KINDS[$kind] ?? abort(404);
        $items = $class::query()->with('destination:id,name')
            ->when($request->query('q'), fn ($q, $t) => $q->where('name', 'like', "%{$t}%"))
            ->when($request->query('destination_id'), fn ($q, $d) => $q->where('destination_id', $d))
            ->orderBy('name')->paginate(25);

        return $this->ok(CatalogueItemResource::collection($items));
    }

    public function storeCatalogue(Request $request, string $kind, ImageUploadService $images): JsonResponse
    {
        $class = self::KINDS[$kind] ?? abort(404);
        $data = $this->validatedCatalogue($request, $kind, null);
        $model = new $class($data);
        if (in_array($kind, ['activities', 'guides'], true)) {
            $model->slug = $this->catalogueSlug($class, $data['name']);
        }
        $this->attachImage($request, $model, $kind, $images);
        $model->save();
        $this->audit->log("catalogue.{$kind}.created", $model, null, ['name' => $model->name, 'price' => $data['price'] ?? $data['hourly_rate'] ?? null]);

        return $this->created(new CatalogueItemResource($model->load('destination:id,name')), 'Saved.');
    }

    public function updateCatalogue(Request $request, string $kind, int $id, ImageUploadService $images): JsonResponse
    {
        $class = self::KINDS[$kind] ?? abort(404);
        /** @var Model $model */
        $model = $class::query()->findOrFail($id);
        $data = $this->validatedCatalogue($request, $kind, $model);
        $original = $model->getOriginal();
        $model->fill($data);
        $this->attachImage($request, $model, $kind, $images);
        $model->save();
        $this->audit->logChanges("catalogue.{$kind}.updated", $model, $original);

        return $this->ok(new CatalogueItemResource($model->load('destination:id,name')), 'Saved.');
    }

    public function destroyCatalogue(string $kind, int $id): JsonResponse
    {
        $class = self::KINDS[$kind] ?? abort(404);
        $model = $class::query()->findOrFail($id);
        $model->forceFill(['status' => 'inactive'])->save();
        $model->delete();
        $this->audit->log("catalogue.{$kind}.deleted", $model, ['name' => $model->name], null);

        return $this->ok(null, 'Archived. Existing itineraries will show it as unavailable.');
    }

    // ------------------------------------------------------------- helpers

    private function syncItems(TourPackage $package, array $items): void
    {
        $package->items()->delete();
        foreach (array_values($items) as $i => $item) {
            $type = TripItemType::from($item['item_type']);
            $allowed = TourPricingService::allowedMorphs($type);
            $morph = $allowed[0] ?? null;
            if ($morph && ! empty($item['itemable_id'])) {
                $class = Relation::getMorphedModel($morph);
                abort_unless($class::query()->whereKey($item['itemable_id'])->exists(), 422, 'Unknown catalogue item in day '.$item['day_number']);
            }
            $package->items()->create([
                'day_number' => $item['day_number'],
                'day_title' => $item['day_title'] ?? null,
                'item_type' => $type->value,
                'itemable_type' => $morph && ! empty($item['itemable_id']) ? $morph : null,
                'itemable_id' => $morph ? ($item['itemable_id'] ?? null) : null,
                'title' => strip_tags($item['title']),
                'description' => isset($item['description']) ? strip_tags($item['description']) : null,
                'start_time' => $item['start_time'] ?? null,
                'nights' => $item['nights'] ?? null,
                'hours' => $item['hours'] ?? null,
                'from_location' => $item['from_location'] ?? null,
                'to_location' => $item['to_location'] ?? null,
                'sort_order' => $i + 1,
            ]);
        }
    }

    private function validatedPackage(Request $request, ?TourPackage $p): array
    {
        $req = $p ? 'sometimes' : 'required';

        return $request->validate([
            'destination_id' => [$req, 'integer', 'exists:destinations,id'],
            'name' => [$req, 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9-]+$/', Rule::unique('tour_packages', 'slug')->ignore($p?->id)],
            'summary' => [$req, 'string', 'max:400'],
            'description' => ['nullable', 'string', 'max:8000'],
            'duration_days' => [$req, 'integer', 'min:1', 'max:30'],
            'duration_nights' => [$req, 'integer', 'min:0', 'max:30'],
            'cities' => ['nullable', 'string', 'max:200'],
            'default_adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'max_travelers' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'highlights' => ['nullable', 'array', 'max:12'],
            'highlights.*' => ['string', 'max:200'],
            'inclusions' => ['nullable', 'array', 'max:20'],
            'inclusions.*' => ['string', 'max:200'],
            'exclusions' => ['nullable', 'array', 'max:20'],
            'exclusions.*' => ['string', 'max:200'],
            'difficulty' => ['sometimes', 'in:easy,moderate,challenging'],
            'is_featured' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'image' => ['nullable', ...array_slice(ImageUploadService::RULES, 1)],
            'items' => ['sometimes', 'array', 'max:150'],
            'items.*.day_number' => ['required', 'integer', 'min:1', 'max:30'],
            'items.*.day_title' => ['nullable', 'string', 'max:120'],
            'items.*.item_type' => ['required', Rule::enum(TripItemType::class)],
            'items.*.itemable_id' => ['nullable', 'integer'],
            'items.*.title' => ['required', 'string', 'max:150'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.start_time' => ['nullable', 'date_format:H:i'],
            'items.*.nights' => ['nullable', 'integer', 'min:1', 'max:30'],
            'items.*.hours' => ['nullable', 'integer', 'min:1', 'max:12'],
            'items.*.from_location' => ['nullable', 'string', 'max:120'],
            'items.*.to_location' => ['nullable', 'string', 'max:120'],
        ]);
    }

    private function validatedCatalogue(Request $request, string $kind, ?Model $m): array
    {
        $req = $m ? 'sometimes' : 'required';
        $common = [
            'destination_id' => [$kind === 'transportation' ? 'nullable' : $req, 'integer', 'exists:destinations,id'],
            'name' => [$req, 'string', 'max:150'],
            'status' => ['sometimes', 'in:active,inactive'],
            'image' => ['nullable', ...array_slice(ImageUploadService::RULES, 1)],
        ];
        $rules = match ($kind) {
            'activities' => [
                'category' => [$req, Rule::in(TourActivity::CATEGORIES)],
                'location' => [$req, 'string', 'max:200'],
                'short_description' => ['nullable', 'string', 'max:300'],
                'description' => ['nullable', 'string', 'max:5000'],
                'duration_minutes' => [$req, 'integer', 'min:15', 'max:1440'],
                'start_times' => ['nullable', 'array', 'max:12'],
                'start_times.*' => ['date_format:H:i'],
                'available_days' => ['nullable', 'array'],
                'available_days.*' => ['integer', 'between:0,6'],
                'capacity_per_slot' => [$req, 'integer', 'min:1', 'max:1000'],
                'price' => [$req, 'numeric', 'min:0', 'max:1000000'],
                'child_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
                'inclusions' => ['nullable', 'array', 'max:15'],
                'inclusions.*' => ['string', 'max:150'],
            ],
            'guides' => [
                'languages' => [$req, 'array', 'min:1'],
                'languages.*' => ['string', 'max:40'],
                'bio' => ['nullable', 'string', 'max:3000'],
                'specialty' => ['nullable', 'string', 'max:150'],
                'experience_years' => ['sometimes', 'integer', 'min:0', 'max:60'],
                'available_from' => ['sometimes', 'date_format:H:i'],
                'available_to' => ['sometimes', 'date_format:H:i'],
                'available_days' => ['nullable', 'array'],
                'available_days.*' => ['integer', 'between:0,6'],
                'min_hours' => ['sometimes', 'integer', 'min:1', 'max:12'],
                'max_hours' => ['sometimes', 'integer', 'min:1', 'max:12'],
                'hourly_rate' => [$req, 'numeric', 'min:0', 'max:100000'],
                'full_day_rate' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            ],
            'transportation' => [
                'type' => [$req, Rule::in(TourTransportation::TYPES)],
                'provider' => [$req, 'string', 'max:120'],
                'from_location' => ['nullable', 'string', 'max:150'],
                'to_location' => ['nullable', 'string', 'max:150'],
                'pricing_unit' => [$req, 'in:per_person,per_vehicle,per_day'],
                'price' => [$req, 'numeric', 'min:0', 'max:10000000'],
                'capacity' => [$req, 'integer', 'min:1', 'max:1000'],
                'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:4320'],
                'departure_times' => ['nullable', 'array', 'max:12'],
                'departure_times.*' => ['date_format:H:i'],
                'description' => ['nullable', 'string', 'max:3000'],
            ],
            'meals' => [
                'meal_type' => [$req, 'in:breakfast,lunch,dinner,experience'],
                'venue' => [$req, 'string', 'max:150'],
                'cuisine' => ['nullable', 'string', 'max:80'],
                'description' => ['nullable', 'string', 'max:3000'],
                'price' => [$req, 'numeric', 'min:0', 'max:1000000'],
            ],
        };
        $data = $request->validate($common + $rules);
        unset($data['image']);

        return array_map(fn ($v) => is_string($v) ? strip_tags($v) : $v, $data);
    }

    private function attachImage(Request $request, Model $model, string $kind, ImageUploadService $images): void
    {
        if (! $request->hasFile('image')) {
            return;
        }
        $column = $kind === 'guides' ? 'profile_image' : 'image_path';
        $images->delete($model->{$column});
        $model->{$column} = $images->store($request->file('image'), 'catalogue/'.$kind, $kind === 'guides' ? 600 : 1600);
    }

    private function slug(string $name): string
    {
        return $this->catalogueSlug(TourPackage::class, $name);
    }

    private function catalogueSlug(string $class, string $name): string
    {
        $slug = Str::slug($name);
        $c = $slug;
        $i = 2;
        while ($class::withTrashed()->where('slug', $c)->exists()) {
            $c = $slug.'-'.$i++;
        }

        return $c;
    }
}
