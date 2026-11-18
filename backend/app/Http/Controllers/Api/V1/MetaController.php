<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AmenityResource;
use App\Http\Resources\CouponResource;
use App\Http\Resources\DestinationResource;
use App\Http\Resources\HotelCardResource;
use App\Http\Resources\MembershipPlanResource;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\TourPackageResource;
use App\Models\Amenity;
use App\Models\Coupon;
use App\Models\Destination;
use App\Models\Faq;
use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\MembershipPlan;
use App\Models\Review;
use App\Models\TourPackage;
use App\Services\Payments\PaymentService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class MetaController extends Controller
{
    /** Public site configuration consumed by the SPA on boot. */
    public function config(SettingsService $settings, PaymentService $payments): JsonResponse
    {
        $data = Cache::remember('meta.config', 600, fn () => [
            'settings' => $settings->publicSettings(),
            'amenities' => AmenityResource::collection(Amenity::query()->orderBy('name')->get())->resolve(),
            'property_types' => Hotel::PROPERTY_TYPES,
            'room_types' => HotelRoom::ROOM_TYPES,
            'price_range' => [
                'min' => (float) floor((Hotel::query()->active()->min('min_price') ?? 0) / 500) * 500,
                'max' => (float) ceil((Hotel::query()->active()->max('min_price') ?? 50000) / 500) * 500,
            ],
        ]);
        $data['payment_gateways'] = $payments->availableGateways();
        $data['google_maps_key'] = config('services.google_maps.key') ?: null;

        return $this->ok($data);
    }

    /** Everything the home page needs in a single cached request. */
    public function home(): JsonResponse
    {
        $data = Cache::remember('meta.home', 600, function () {
            return [
                'destinations' => DestinationResource::collection(
                    Destination::query()->active()->where('is_featured', true)->withCount('hotels')
                        ->addSelect(['from_price' => Hotel::query()->selectRaw('MIN(min_price)')->whereColumn('destination_id', 'destinations.id')->where('status', 'active')])
                        ->orderBy('sort_order')->limit(8)->get()
                )->resolve(),
                'featured_hotels' => HotelCardResource::collection(
                    Hotel::query()->active()->where('is_featured', true)->with(['coverImage', 'amenities', 'destination:id,name,slug'])
                        ->orderByDesc('rating_avg')->limit(8)->get()
                )->resolve(),
                'popular_tours' => TourPackageResource::collection(
                    TourPackage::query()->active()->with('destination')->orderByDesc('is_featured')->orderByDesc('rating_avg')->limit(6)->get()
                )->resolve(),
                'reviews' => ReviewResource::collection(
                    Review::query()->where('status', 'approved')->where('overall', '>=', 4)->with(['user', 'hotel', 'booking'])
                        ->latest()->limit(6)->get()
                )->resolve(),
                'faqs' => Faq::query()->where('is_active', true)->where('show_on_home', true)->orderBy('sort_order')->limit(6)
                    ->get(['id', 'category', 'question', 'answer']),
                'membership_plan' => ($plan = MembershipPlan::query()->active()->orderByDesc('is_featured')->orderBy('sort_order')->first())
                    ? (new MembershipPlanResource($plan))->resolve() : null,
                'stats' => [
                    'hotels' => Hotel::query()->active()->count(),
                    'destinations' => Destination::query()->active()->count(),
                    'membership_hotels' => Hotel::query()->active()->where('is_membership_eligible', true)->count(),
                    'avg_rating' => round((float) Hotel::query()->active()->where('review_count', '>', 0)->avg('rating_avg'), 1),
                ],
            ];
        });

        return $this->ok($data);
    }

    public function faqs(): JsonResponse
    {
        $faqs = Cache::remember('meta.faqs', 600, fn () => Faq::query()->where('is_active', true)->orderBy('category')->orderBy('sort_order')
            ->get(['id', 'category', 'question', 'answer']));

        return $this->ok($faqs);
    }

    public function offers(): JsonResponse
    {
        $coupons = Coupon::query()->where('is_public', true)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->orderBy('ends_at')->get();

        return $this->ok(CouponResource::collection($coupons));
    }
}
