<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\HotelBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Services\Booking\BookingLifecycleService;
use App\Services\Booking\HotelBookingService;
use App\Services\InvoiceService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'scope' => ['nullable', 'in:upcoming,past,cancelled,all'],
            'type' => ['nullable', 'in:hotel,tour'],
        ]);
        $scope = $request->query('scope', 'all');

        $bookings = $request->user()->bookings()
            ->with(['hotel.coverImage', 'trip', 'review'])
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($scope === 'upcoming', fn ($q) => $q->whereIn('status', ['confirmed', 'payment_pending'])->whereDate('check_out', '>=', today())->orderBy('check_in'))
            ->when($scope === 'past', fn ($q) => $q->where(fn ($w) => $w->where('status', 'completed')->orWhere(fn ($x) => $x->where('status', 'confirmed')->whereDate('check_out', '<', today())))->orderByDesc('check_in'))
            ->when($scope === 'cancelled', fn ($q) => $q->whereIn('status', ['cancelled', 'failed'])->latest())
            ->when($scope === 'all', fn ($q) => $q->latest())
            ->paginate(10);

        return $this->ok(BookingResource::collection($bookings));
    }

    public function show(Request $request, string $number): JsonResponse
    {
        $booking = $this->findOwned($request, $number);
        $booking->load(['hotel.coverImage', 'rooms.room.images', 'rooms.hotel', 'guests', 'payments', 'cancellation', 'refunds', 'review', 'trip', 'membership']);

        return $this->ok(new BookingResource($booking));
    }

    public function storeHotel(HotelBookingRequest $request, HotelBookingService $bookings, PaymentService $payments): JsonResponse
    {
        $result = $bookings->create($request->user(), $request->validated());
        $checkout = $result['payment'] ? $payments->startCheckout($result['payment']) : null;
        $booking = $result['booking']->fresh(['hotel.coverImage', 'rooms.room', 'guests']);

        return $this->created([
            'booking' => new BookingResource($booking),
            'checkout' => $checkout,
        ], $checkout ? 'Your room is reserved. Complete payment within '.$booking->hold_expires_at?->diffForHumans(null, true).' to confirm.' : 'Your booking is confirmed!');
    }

    public function cancellationPreview(Request $request, string $number, BookingLifecycleService $lifecycle): JsonResponse
    {
        $booking = $this->findOwned($request, $number);
        $this->authorize('cancel', $booking);

        return $this->ok($lifecycle->cancellationPreview($booking->loadMissing('hotel', 'membership')));
    }

    public function cancel(Request $request, string $number, BookingLifecycleService $lifecycle): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:250']]);
        $booking = $this->findOwned($request, $number);
        $this->authorize('cancel', $booking);
        $booking = $lifecycle->cancel($booking, $request->user(), isset($data['reason']) ? strip_tags($data['reason']) : null);
        $booking->load(['hotel.coverImage', 'rooms.room', 'guests', 'payments', 'cancellation', 'refunds']);

        return $this->ok(new BookingResource($booking), 'Your booking has been cancelled.');
    }

    public function pay(Request $request, string $number, BookingLifecycleService $lifecycle): JsonResponse
    {
        $data = $request->validate(['gateway' => ['nullable', 'in:sandbox,razorpay,stripe']]);
        $booking = $this->findOwned($request, $number);
        $this->authorize('pay', $booking);
        $result = $lifecycle->retryPayment($booking, $data['gateway'] ?? null);

        return $this->ok(['checkout' => $result['checkout']], 'Opening secure checkout…');
    }

    public function invoice(Request $request, string $number, InvoiceService $invoices): Response
    {
        $booking = $this->findOwned($request, $number);
        if (! in_array($booking->payment_status->value, ['paid', 'refunded', 'partially_refunded'], true)) {
            return $this->fail('An invoice is available once payment is complete.', 409, [], 'invoice_unavailable');
        }
        $invoice = $invoices->forBooking($booking);

        return response($invoices->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->invoice_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function findOwned(Request $request, string $number): Booking
    {
        $booking = Booking::query()->where('booking_number', $number)->firstOrFail();
        $this->authorize('view', $booking);

        return $booking;
    }
}
