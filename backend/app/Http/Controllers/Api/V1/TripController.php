<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TourBookingRequest;
use App\Http\Requests\TripItemRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\TripResource;
use App\Models\HotelRoom;
use App\Models\TourPackage;
use App\Models\TripDay;
use App\Models\TripItem;
use App\Models\UserTrip;
use App\Services\Booking\TourBookingService;
use App\Services\Payments\PaymentService;
use App\Services\Tour\TripBuilderService;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function __construct(private readonly TripBuilderService $builder) {}

    public function index(Request $request): JsonResponse
    {
        $trips = $request->user()->trips()->with(['days.items', 'booking'])->latest('updated_at')->paginate(12);

        return $this->ok(TripResource::collection($trips));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'destination_summary' => ['nullable', 'string', 'max:150'],
            'destination_id' => ['nullable', 'integer', 'exists:destinations,id'],
            'start_date' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'days' => ['required', 'integer', 'min:1', 'max:'.TripBuilderService::MAX_DAYS],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:10'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['title'] = strip_tags($data['title']);
        $trip = $this->builder->create($request->user(), $data);

        return $this->created($this->present($trip), 'Trip created — start adding stays, transport and experiences.');
    }

    public function fromPackage(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);
        $package = TourPackage::query()->active()->where('slug', $slug)->firstOrFail();
        $trip = $this->builder->createFromPackage($request->user(), $package, $data);

        return $this->created($this->present($trip), 'Tour copied to your Trip Builder — customise every detail.');
    }

    public function show(Request $request, UserTrip $trip): JsonResponse
    {
        $this->authorize('view', $trip);

        return $this->ok($this->present($trip));
    }

    public function update(Request $request, UserTrip $trip): JsonResponse
    {
        $this->authorize('update', $trip);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:2', 'max:120'],
            'destination_summary' => ['nullable', 'string', 'max:150'],
            'start_date' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->ok($this->present($this->builder->update($trip, array_map(fn ($v) => is_string($v) ? strip_tags($v) : $v, $data))), 'Trip updated.');
    }

    public function destroy(Request $request, UserTrip $trip): JsonResponse
    {
        $this->authorize('delete', $trip);
        if ($trip->status === 'booked') {
            return $this->fail('Booked trips cannot be deleted. Cancel the booking first.', 409, [], 'trip_locked');
        }
        $trip->delete();

        return $this->ok(null, 'Trip deleted.');
    }

    public function duplicate(Request $request, UserTrip $trip): JsonResponse
    {
        $this->authorize('view', $trip);

        return $this->created($this->present($this->builder->duplicate($trip, $request->user())), 'Trip duplicated.');
    }

    public function share(Request $request, UserTrip $trip): JsonResponse
    {
        $this->authorize('update', $trip);
        $data = $request->validate(['visibility' => ['required', 'in:public,private']]);
        $trip = $this->builder->setSharing($trip, $data['visibility']);

        return $this->ok($this->present($trip), $data['visibility'] === 'public' ? 'Anyone with the link can now view this trip.' : 'Trip is now private.');
    }

    // ------------------------------------------------------------------ days

    public function addDay(Request $request, UserTrip $trip): JsonResponse
    {
        $this->authorize('update', $trip);
        $data = $request->validate(['after_day_number' => ['nullable', 'integer', 'min:0']]);

        return $this->ok($this->present($this->builder->addDay($trip, $data['after_day_number'] ?? null)), 'Day added.');
    }

    public function updateDay(Request $request, UserTrip $trip, TripDay $day): JsonResponse
    {
        $this->authorize('update', $trip);
        abort_unless($day->user_trip_id === $trip->id, 404);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
            'destination_id' => ['nullable', 'integer', 'exists:destinations,id'],
        ]);
        $this->builder->updateDay($day, array_map(fn ($v) => is_string($v) ? strip_tags($v) : $v, $data));

        return $this->ok($this->present($trip->fresh()), 'Day updated.');
    }

    public function deleteDay(Request $request, UserTrip $trip, TripDay $day): JsonResponse
    {
        $this->authorize('update', $trip);
        abort_unless($day->user_trip_id === $trip->id, 404);

        return $this->ok($this->present($this->builder->deleteDay($day)), 'Day removed.');
    }

    public function reorderDays(Request $request, UserTrip $trip): JsonResponse
    {
        $this->authorize('update', $trip);
        $data = $request->validate(['day_ids' => ['required', 'array', 'min:1'], 'day_ids.*' => ['integer']]);

        return $this->ok($this->present($this->builder->reorderDays($trip, array_map('intval', $data['day_ids']))), 'Days reordered.');
    }

    // ------------------------------------------------------------------ items

    public function addItem(TripItemRequest $request, UserTrip $trip): JsonResponse
    {
        $this->authorize('update', $trip);
        $data = $request->validated();
        $day = $trip->days()->whereKey($data['trip_day_id'])->firstOrFail();

        return $this->ok($this->present($this->builder->addItem($trip, $day, $data)), 'Added to your itinerary.');
    }

    public function updateItem(TripItemRequest $request, UserTrip $trip, TripItem $item): JsonResponse
    {
        $this->authorize('update', $trip);
        abort_unless($item->user_trip_id === $trip->id, 404);

        return $this->ok($this->present($this->builder->updateItem($item, $request->validated())), 'Item updated.');
    }

    public function duplicateItem(Request $request, UserTrip $trip, TripItem $item): JsonResponse
    {
        $this->authorize('update', $trip);
        abort_unless($item->user_trip_id === $trip->id, 404);

        return $this->ok($this->present($this->builder->duplicateItem($item)), 'Item duplicated.');
    }

    public function deleteItem(Request $request, UserTrip $trip, TripItem $item): JsonResponse
    {
        $this->authorize('update', $trip);
        abort_unless($item->user_trip_id === $trip->id, 404);

        return $this->ok($this->present($this->builder->deleteItem($item)), 'Item removed.');
    }

    public function reorderItems(Request $request, UserTrip $trip): JsonResponse
    {
        $this->authorize('update', $trip);
        $data = $request->validate([
            'layout' => ['required', 'array', 'min:1'],
            'layout.*.day_id' => ['required', 'integer'],
            'layout.*.item_ids' => ['present', 'array'],
            'layout.*.item_ids.*' => ['integer'],
        ]);

        return $this->ok($this->present($this->builder->reorderItems($trip, $data['layout'])), 'Itinerary updated.');
    }

    // ------------------------------------------------------------------ checkout

    public function quote(Request $request, UserTrip $trip, TourBookingService $bookings): JsonResponse
    {
        $this->authorize('book', $trip);
        $data = $request->validate(['coupon_code' => ['nullable', 'string', 'max:40']]);

        return $this->ok($bookings->quote($trip, $request->user(), $data['coupon_code'] ?? null));
    }

    public function book(TourBookingRequest $request, UserTrip $trip, TourBookingService $bookings, PaymentService $payments): JsonResponse
    {
        $this->authorize('book', $trip);
        $data = $request->validated();
        if (isset($data['special_requests'])) {
            $data['special_requests'] = strip_tags($data['special_requests']);
        }
        $result = $bookings->create($request->user(), $trip, $data);
        $checkout = $result['payment'] ? $payments->startCheckout($result['payment']) : null;

        return $this->created([
            'booking' => new BookingResource($result['booking']->fresh(['trip', 'guests'])),
            'checkout' => $checkout,
        ], $checkout ? 'Your trip is reserved — complete payment to confirm.' : 'Your trip is booked!');
    }

    /** Public, read-only shared itinerary. Exposes no personal data. */
    public function publicShow(string $token): JsonResponse
    {
        $trip = UserTrip::query()->where('share_token', $token)->where('visibility', 'public')->with('user:id,name')->firstOrFail();

        return $this->ok($this->present($trip, true));
    }

    public function copyShared(Request $request, string $token): JsonResponse
    {
        $trip = UserTrip::query()->where('share_token', $token)->where('visibility', 'public')->firstOrFail();

        return $this->created($this->present($this->builder->duplicate($trip, $request->user())), 'Trip copied to your account.');
    }

    private function present(UserTrip $trip, bool $public = false): array
    {
        $trip->load([
            'days.items' => fn ($q) => $q->with(['itemable' => fn (MorphTo $m) => $m->morphWith([HotelRoom::class => ['hotel.coverImage']])]),
            'package',
            'travelers',
            'booking',
        ]);
        $resource = new TripResource($trip);

        return ($public ? $resource->asPublic() : $resource)->resolve(request());
    }
}
