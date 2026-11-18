<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\MembershipTransactionResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\TripResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer dashboard: one request with everything the overview screen shows. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $membership = $user->activeMembership()
            ?? $user->memberships()->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Expired->value])->latest('expires_at')->first();

        $upcomingHotels = $user->bookings()->where('type', 'hotel')->whereIn('status', ['confirmed', 'payment_pending'])
            ->whereDate('check_out', '>=', today())->with(['hotel.coverImage'])->orderBy('check_in')->limit(4)->get();
        $upcomingTours = $user->bookings()->where('type', 'tour')->whereIn('status', ['confirmed', 'payment_pending'])
            ->whereDate('check_out', '>=', today())->with('trip')->orderBy('check_in')->limit(4)->get();
        $recent = $user->bookings()->with(['hotel.coverImage', 'trip', 'review'])->latest()->limit(5)->get();
        $payments = $user->payments()->with('payable')->whereIn('status', ['paid', 'refunded', 'partially_refunded', 'failed'])->latest()->limit(5)->get();
        $transactions = $membership ? $membership->transactions()->with('booking:id,booking_number')->limit(6)->get() : collect();
        $savedTrips = $user->trips()->with('days.items')->latest('updated_at')->limit(3)->get();

        return $this->ok([
            'user' => ['name' => $user->name, 'first_name' => strtok($user->name, ' '), 'email_verified' => (bool) $user->email_verified_at],
            'membership' => $membership ? new MembershipResource($membership) : null,
            'stats' => [
                'total_bookings' => $user->bookings()->whereIn('status', ['confirmed', 'completed'])->count(),
                'nights_stayed' => (int) $user->bookings()->where('type', 'hotel')->where('status', 'completed')->sum('nights'),
                'saved_trips' => $user->trips()->count(),
                'saved_hotels' => $user->savedHotels()->count(),
            ],
            'upcoming_hotel_bookings' => BookingResource::collection($upcomingHotels),
            'upcoming_tours' => BookingResource::collection($upcomingTours),
            'recent_bookings' => BookingResource::collection($recent),
            'recent_payments' => PaymentResource::collection($payments),
            'membership_transactions' => MembershipTransactionResource::collection($transactions),
            'saved_trips' => TripResource::collection($savedTrips),
            'notifications' => $user->notifications()->limit(5)->get()->map(fn ($n) => [
                'id' => $n->id, 'title' => $n->data['title'] ?? '', 'message' => $n->data['message'] ?? '', 'category' => $n->data['category'] ?? '',
                'action_path' => $n->data['action_path'] ?? null, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ]);
    }
}
