<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\PaymentResource;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\Booking\BookingLifecycleService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bookings & payments administration. Also serves hotel partners (scoped to their hotels). */
class BookingAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', 'in:hotel,tour'],
            'status' => ['nullable', 'in:pending,payment_pending,confirmed,cancelled,completed,failed'],
            'booking_type' => ['nullable', 'in:membership,regular'],
            'hotel_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $user = $request->user();
        $partnerOnly = ! $user->hasPermission('bookings.view');

        $bookings = Booking::query()->with(['user', 'hotel.coverImage', 'trip'])
            ->when($partnerOnly, fn ($q) => $q->whereIn('hotel_id', $user->hotels()->pluck('hotels.id')))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('booking_type'), fn ($q, $v) => $q->where('booking_type', $v))
            ->when($request->query('hotel_id'), fn ($q, $v) => $q->where('hotel_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->whereDate('check_in', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->whereDate('check_in', '<=', $v))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('booking_number', 'like', "%{$t}%")
                ->orWhere('contact_email', 'like', "%{$t}%")->orWhere('contact_name', 'like', "%{$t}%")))
            ->latest()->paginate(20);

        return $this->ok(BookingResource::collection($bookings));
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('view', $booking);
        $booking->load(['user', 'hotel.coverImage', 'rooms.room.images', 'rooms.hotel', 'guests', 'payments', 'cancellation', 'refunds', 'review', 'trip', 'membership']);

        return $this->ok(new BookingResource($booking));
    }

    public function cancellationPreview(Request $request, Booking $booking, BookingLifecycleService $lifecycle): JsonResponse
    {
        $this->authorize('adminCancel', $booking);

        return $this->ok($lifecycle->cancellationPreview($booking, true, $request->boolean('waive_penalty')));
    }

    public function cancel(Request $request, Booking $booking, BookingLifecycleService $lifecycle): JsonResponse
    {
        $this->authorize('adminCancel', $booking);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:250'], 'waive_penalty' => ['sometimes', 'boolean']]);
        $booking = $lifecycle->cancel($booking, $request->user(), strip_tags($data['reason']), true, (bool) ($data['waive_penalty'] ?? false));

        return $this->ok(new BookingResource($booking->load(['user', 'hotel', 'rooms.room', 'guests', 'payments', 'cancellation', 'refunds'])), 'Booking cancelled.');
    }

    public function payments(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:pending,processing,paid,failed,refunded,partially_refunded'], 'gateway' => ['nullable', 'in:sandbox,razorpay,stripe'], 'q' => ['nullable', 'string', 'max:100']]);
        $payments = Payment::query()->with(['user', 'payable'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('gateway'), fn ($q, $v) => $q->where('gateway', $v))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('reference', 'like', "%{$t}%")->orWhere('gateway_payment_id', 'like', "%{$t}%")))
            ->latest()->paginate(25);

        return $this->ok(PaymentResource::collection($payments));
    }

    public function refund(Request $request, Payment $payment, PaymentService $payments, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:250'],
        ]);
        $refund = $payments->refund($payment, (float) $data['amount'], strip_tags($data['reason']), $request->user()->id);
        $audit->log('refund.issued', $payment, null, ['amount' => $data['amount'], 'status' => $refund->status, 'refund' => $refund->reference], $data['reason']);

        return $refund->status === 'processed'
            ? $this->ok(new PaymentResource($payment->fresh()), 'Refund of '.number_format($refund->amount, 2).' processed.')
            : $this->fail('The payment gateway rejected the refund. It has been recorded as failed — please retry or refund manually.', 502, [], 'refund_failed');
    }

    public function partnerHotels(Request $request): JsonResponse
    {
        return $this->ok($request->user()->hotels()->get(['hotels.id', 'hotels.name', 'hotels.city', 'hotels.slug']));
    }

    public function hotelOptions(): JsonResponse
    {
        return $this->ok(Hotel::query()->orderBy('name')->get(['id', 'name', 'city']));
    }
}
