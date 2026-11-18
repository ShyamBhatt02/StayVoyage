<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogueItemResource;
use App\Http\Resources\TourPackageResource;
use App\Models\HotelRoom;
use App\Models\TourActivity;
use App\Models\TourGuide;
use App\Models\TourMeal;
use App\Models\TourPackage;
use App\Models\TourTransportation;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tour packages (templates) and the bookable catalogue used by the Tour Builder. */
class TourController extends Controller
{
    public function packages(Request $request): JsonResponse
    {
        $request->validate([
            'destination' => ['nullable', 'string', 'max:80'],
            'duration' => ['nullable', 'in:short,medium,long'],
            'sort' => ['nullable', 'in:popular,price_asc,price_desc,duration'],
        ]);
        $packages = TourPackage::query()->active()->with('destination')
            ->when($request->query('destination'), fn ($q, $d) => $q->whereHas('destination', fn ($w) => $w->where('slug', $d)->orWhere('name', 'like', "%{$d}%")))
            ->when($request->query('duration'), fn ($q, $d) => match ($d) {
                'short' => $q->where('duration_days', '<=', 3),
                'medium' => $q->whereBetween('duration_days', [4, 6]),
                default => $q->where('duration_days', '>=', 7),
            })
            ->when($request->query('sort'), fn ($q, $s) => match ($s) {
                'price_asc' => $q->orderBy('price_from'),
                'price_desc' => $q->orderByDesc('price_from'),
                'duration' => $q->orderBy('duration_days'),
                default => $q->orderByDesc('is_featured')->orderByDesc('rating_avg'),
            }, fn ($q) => $q->orderByDesc('is_featured')->orderByDesc('rating_avg'))
            ->paginate(12);

        return $this->ok(TourPackageResource::collection($packages));
    }

    public function package(string $slug): JsonResponse
    {
        $package = TourPackage::query()->active()->where('slug', $slug)->with(['destination', 'items'])->firstOrFail();
        $related = TourPackage::query()->active()->whereKeyNot($package->id)->with('destination')->orderByDesc('rating_avg')->limit(3)->get();

        return $this->ok(['package' => new TourPackageResource($package), 'related' => TourPackageResource::collection($related)]);
    }

    /** Builder picker: catalogue items by kind, optionally filtered by destination and text. */
    public function catalogue(Request $request, string $kind): JsonResponse
    {
        $request->validate([
            'destination_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:80'],
            'type' => ['nullable', 'string', 'max:30'],
            'category' => ['nullable', 'string', 'max:30'],
        ]);
        $term = $request->query('q');
        $dest = $request->query('destination_id');

        $items = match ($kind) {
            'activities' => TourActivity::query()->active()->with('destination:id,name')
                ->when($dest, fn ($q) => $q->where('destination_id', $dest))
                ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
                ->when($term, fn ($q) => $q->where('name', 'like', "%{$term}%"))
                ->orderByDesc('rating_avg')->limit(60)->get(),
            'guides' => TourGuide::query()->active()->with('destination:id,name')
                ->when($dest, fn ($q) => $q->where('destination_id', $dest))
                ->when($term, fn ($q) => $q->where('name', 'like', "%{$term}%"))
                ->orderByDesc('rating_avg')->limit(60)->get(),
            'transportation' => TourTransportation::query()->active()->with('destination:id,name')
                ->when($dest, fn ($q) => $q->where(fn ($w) => $w->where('destination_id', $dest)->orWhereNull('destination_id')))
                ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
                ->when($term, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('from_location', 'like', "%{$term}%")->orWhere('to_location', 'like', "%{$term}%")))
                ->orderBy('price')->limit(60)->get(),
            'meals' => TourMeal::query()->active()->with('destination:id,name')
                ->when($dest, fn ($q) => $q->where('destination_id', $dest))
                ->when($term, fn ($q) => $q->where('name', 'like', "%{$term}%"))
                ->orderBy('price')->limit(60)->get(),
            'hotels' => null,
            default => abort(404),
        };

        if ($kind === 'hotels') {
            $rooms = HotelRoom::query()->where('status', 'active')
                ->whereHas('hotel', fn ($q) => $q->where('status', 'active')
                    ->when($dest, fn ($w) => $w->where('destination_id', $dest))
                    ->when($term, fn ($w) => $w->where('name', 'like', "%{$term}%")))
                ->with(['hotel:id,name,slug,city,star_rating,rating_avg,destination_id', 'hotel.coverImage', 'images'])
                ->orderBy('base_price')->limit(80)->get();

            return $this->ok($rooms->map(fn ($r) => [
                'id' => $r->id,
                'kind' => 'hotel_room',
                'name' => $r->name,
                'room_type' => $r->room_type,
                'hotel_name' => $r->hotel->name,
                'hotel_slug' => $r->hotel->slug,
                'city' => $r->hotel->city,
                'star_rating' => $r->hotel->star_rating,
                'rating_avg' => round((float) $r->hotel->rating_avg, 1),
                'max_guests' => $r->max_guests,
                'bed_type' => $r->bed_type,
                'breakfast_included' => $r->breakfast_included,
                'price' => $r->base_price,
                'price_label' => 'per night',
                'image_url' => Media::url($r->images->first()?->path) ?? $r->hotel->cover_url,
                'destination_id' => $r->hotel->destination_id,
            ])->values());
        }

        return $this->ok(CatalogueItemResource::collection($items));
    }
}
