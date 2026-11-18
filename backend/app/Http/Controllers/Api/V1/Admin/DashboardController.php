<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $data = Cache::remember('admin.dashboard', 120, function () {
            $paid = fn () => Payment::query()->whereIn('status', ['paid', 'partially_refunded', 'refunded']);
            $hotelRevenue = (float) $paid()->where('payable_type', 'booking')->whereIn('payable_id', Booking::query()->where('type', 'hotel')->select('id'))->sum(DB::raw('amount - amount_refunded'));
            $tourRevenue = (float) $paid()->where('payable_type', 'booking')->whereIn('payable_id', Booking::query()->where('type', 'tour')->select('id'))->sum(DB::raw('amount - amount_refunded'));
            $membershipRevenue = (float) $paid()->where('payable_type', 'membership')->sum(DB::raw('amount - amount_refunded'));

            $months = collect(range(11, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'));
            $revenueByMonth = DB::table('payments')->whereNotNull('paid_at')->where('paid_at', '>=', now()->startOfMonth()->subMonths(11))
                ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as m,
                    SUM(CASE WHEN payable_type='membership' THEN amount - amount_refunded ELSE 0 END) as membership,
                    SUM(CASE WHEN payable_type='booking' AND payable_id IN (SELECT id FROM bookings WHERE type='hotel') THEN amount - amount_refunded ELSE 0 END) as hotel,
                    SUM(CASE WHEN payable_type='booking' AND payable_id IN (SELECT id FROM bookings WHERE type='tour') THEN amount - amount_refunded ELSE 0 END) as tour")
                ->groupBy('m')->get()->keyBy('m');
            $bookingsByDay = DB::table('bookings')->where('created_at', '>=', now()->subDays(29)->startOfDay())->whereIn('status', ['confirmed', 'completed', 'cancelled'])
                ->selectRaw("DATE(created_at) as d, SUM(type='hotel') as hotel, SUM(type='tour') as tour")->groupBy('d')->get()->keyBy('d');
            $membershipSales = DB::table('memberships')->whereNotNull('activated_at')->where('activated_at', '>=', now()->startOfMonth()->subMonths(11))
                ->selectRaw("DATE_FORMAT(activated_at, '%Y-%m') as m, COUNT(*) as sold")->groupBy('m')->pluck('sold', 'm');

            return [
                'stats' => [
                    'total_users' => DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')->where('roles.slug', Role::CUSTOMER)->count(),
                    'active_members' => Membership::query()->where('status', 'active')->where('starts_at', '<=', now())->where('expires_at', '>', now())->count(),
                    'total_hotels' => Hotel::query()->count(),
                    'active_hotels' => Hotel::query()->where('status', 'active')->count(),
                    'total_rooms' => (int) HotelRoom::query()->where('status', 'active')->sum('total_rooms'),
                    'hotel_bookings' => Booking::query()->where('type', 'hotel')->whereIn('status', ['confirmed', 'completed'])->count(),
                    'tour_bookings' => Booking::query()->where('type', 'tour')->whereIn('status', ['confirmed', 'completed'])->count(),
                    'membership_sales' => Membership::query()->whereNotNull('activated_at')->count(),
                    'hotel_revenue' => round($hotelRevenue, 2),
                    'tour_revenue' => round($tourRevenue, 2),
                    'membership_revenue' => round($membershipRevenue, 2),
                    'total_revenue' => round($hotelRevenue + $tourRevenue + $membershipRevenue, 2),
                    'refunds' => round((float) Refund::query()->where('status', 'processed')->sum('amount'), 2),
                    'refund_count' => Refund::query()->where('status', 'processed')->count(),
                    'upcoming_bookings' => Booking::query()->where('status', 'confirmed')->whereDate('check_in', '>=', today())->count(),
                    'pending_payments' => Booking::query()->where('status', 'payment_pending')->count(),
                    'pending_reviews' => DB::table('reviews')->where('status', 'pending')->whereNull('deleted_at')->count(),
                    'new_messages' => DB::table('contact_messages')->where('status', 'new')->count(),
                ],
                'charts' => [
                    'revenue_trend' => $months->map(fn ($m) => [
                        'month' => $m,
                        'hotel' => round((float) ($revenueByMonth[$m]->hotel ?? 0), 2),
                        'tour' => round((float) ($revenueByMonth[$m]->tour ?? 0), 2),
                        'membership' => round((float) ($revenueByMonth[$m]->membership ?? 0), 2),
                    ])->values(),
                    'booking_trend' => collect(range(29, 0))->map(fn ($i) => now()->subDays($i)->toDateString())->map(fn ($d) => [
                        'date' => $d, 'hotel' => (int) ($bookingsByDay[$d]->hotel ?? 0), 'tour' => (int) ($bookingsByDay[$d]->tour ?? 0),
                    ])->values(),
                    'membership_sales' => $months->map(fn ($m) => ['month' => $m, 'sold' => (int) ($membershipSales[$m] ?? 0)])->values(),
                    'hotel_performance' => DB::table('bookings')->join('hotels', 'hotels.id', '=', 'bookings.hotel_id')
                        ->whereIn('bookings.status', ['confirmed', 'completed'])->groupBy('hotels.id', 'hotels.name')
                        ->selectRaw('hotels.name, COUNT(*) as bookings, SUM(bookings.nights * bookings.rooms_count) as room_nights, SUM(bookings.amount_paid - bookings.amount_refunded) as revenue')
                        ->orderByDesc('room_nights')->limit(8)->get()
                        ->map(fn ($r) => ['name' => $r->name, 'bookings' => (int) $r->bookings, 'room_nights' => (int) $r->room_nights, 'revenue' => round((float) $r->revenue, 2)]),
                    'popular_destinations' => DB::table('bookings')->join('hotels', 'hotels.id', '=', 'bookings.hotel_id')->join('destinations', 'destinations.id', '=', 'hotels.destination_id')
                        ->whereIn('bookings.status', ['confirmed', 'completed'])->groupBy('destinations.id', 'destinations.name')
                        ->selectRaw('destinations.name, COUNT(*) as bookings')->orderByDesc('bookings')->limit(6)->get()
                        ->map(fn ($r) => ['name' => $r->name, 'bookings' => (int) $r->bookings]),
                    'tour_sales' => $months->map(fn ($m) => ['month' => $m, 'revenue' => round((float) ($revenueByMonth[$m]->tour ?? 0), 2)])->values(),
                ],
            ];
        });

        $data['upcoming'] = BookingResource::collection(
            Booking::query()->where('status', 'confirmed')->whereDate('check_in', '>=', today())->with(['hotel.coverImage', 'user', 'trip'])->orderBy('check_in')->limit(8)->get()
        );

        return $this->ok($data);
    }
}
