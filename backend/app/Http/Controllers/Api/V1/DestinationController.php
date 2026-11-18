<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogueItemResource;
use App\Http\Resources\DestinationResource;
use App\Http\Resources\HotelCardResource;
use App\Http\Resources\TourPackageResource;
use App\Models\Destination;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DestinationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $destinations = Destination::query()->active()
            ->withCount(['hotels' => fn ($q) => $q->where('status', 'active'), 'tourPackages' => fn ($q) => $q->where('status', 'active')])
            ->addSelect(['from_price' => Hotel::query()->selectRaw('MIN(min_price)')->whereColumn('destination_id', 'destinations.id')->where('status', 'active')])
            ->when($request->query('country'), fn ($q, $c) => $q->where('country', $c))
            ->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('name')
            ->get();

        return $this->ok(DestinationResource::collection($destinations));
    }

    public function show(string $slug): JsonResponse
    {
        $destination = Destination::query()->active()->where('slug', $slug)->firstOrFail();

        return $this->ok([
            'destination' => new DestinationResource($destination),
            'hotels' => HotelCardResource::collection(
                $destination->hotels()->active()->with(['coverImage', 'amenities', 'destination:id,name,slug'])
                    ->orderByDesc('is_featured')->orderByDesc('rating_avg')->limit(12)->get()
            ),
            'tours' => TourPackageResource::collection($destination->tourPackages()->active()->with('destination')->get()),
            'activities' => CatalogueItemResource::collection($destination->activities()->active()->orderByDesc('rating_avg')->limit(8)->get()),
        ]);
    }
}
