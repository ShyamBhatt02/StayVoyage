<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
use App\Http\Resources\ReviewResource;
use App\Models\ContactMessage;
use App\Models\Coupon;
use App\Models\Faq;
use App\Models\Review;
use App\Services\AuditLogger;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Coupons, review moderation, FAQs and contact messages. */
class MarketingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    // ------------------------------------------------------------- coupons

    public function coupons(Request $request): JsonResponse
    {
        $items = Coupon::query()->with(['hotels:id', 'tourPackages:id', 'membershipPlans:id'])
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('code', 'like', "%{$t}%")->orWhere('title', 'like', "%{$t}%")))
            ->latest()->paginate(25);

        return $this->ok(CouponResource::collection($items));
    }

    public function storeCoupon(Request $request): JsonResponse
    {
        $data = $this->validatedCoupon($request, null);
        $coupon = DB::transaction(function () use ($data) {
            $c = Coupon::query()->create(collect($data)->except(['hotel_ids', 'tour_package_ids', 'membership_plan_ids'])->all());
            $this->syncScopes($c, $data);

            return $c;
        });
        $this->audit->log('coupon.created', $coupon, null, $coupon->only(['code', 'discount_type', 'discount_value']));

        return $this->created(new CouponResource($coupon->load(['hotels:id', 'tourPackages:id', 'membershipPlans:id'])), 'Coupon created.');
    }

    public function updateCoupon(Request $request, Coupon $coupon): JsonResponse
    {
        $data = $this->validatedCoupon($request, $coupon);
        DB::transaction(function () use ($coupon, $data) {
            $original = $coupon->getOriginal();
            $coupon->fill(collect($data)->except(['hotel_ids', 'tour_package_ids', 'membership_plan_ids'])->all())->save();
            $this->syncScopes($coupon, $data);
            $this->audit->logChanges('coupon.updated', $coupon, $original);
        });

        return $this->ok(new CouponResource($coupon->fresh(['hotels:id', 'tourPackages:id', 'membershipPlans:id'])), 'Coupon updated.');
    }

    public function destroyCoupon(Coupon $coupon): JsonResponse
    {
        $coupon->forceFill(['status' => 'inactive'])->save();
        $coupon->delete();
        $this->audit->log('coupon.deleted', $coupon, ['code' => $coupon->code], null);

        return $this->ok(null, 'Coupon deactivated.');
    }

    private function syncScopes(Coupon $c, array $data): void
    {
        if (array_key_exists('hotel_ids', $data)) {
            $c->hotels()->sync($data['hotel_ids'] ?? []);
        }
        if (array_key_exists('tour_package_ids', $data)) {
            $c->tourPackages()->sync($data['tour_package_ids'] ?? []);
        }
        if (array_key_exists('membership_plan_ids', $data)) {
            $c->membershipPlans()->sync($data['membership_plan_ids'] ?? []);
        }
    }

    private function validatedCoupon(Request $request, ?Coupon $c): array
    {
        $req = $c ? 'sometimes' : 'required';
        if ($request->has('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        }

        return $request->validate([
            'code' => [$req, 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($c?->id)],
            'title' => [$req, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'discount_type' => [$req, 'in:percentage,fixed'],
            'discount_value' => [$req, 'numeric', 'gt:0', Rule::when($request->input('discount_type', $c?->discount_type) === 'percentage', ['max:100'])],
            'min_purchase' => ['sometimes', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'applies_to' => [$req, 'in:all,hotels,tours,memberships'],
            'is_public' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
            'hotel_ids' => ['sometimes', 'nullable', 'array'],
            'hotel_ids.*' => ['integer', 'exists:hotels,id'],
            'tour_package_ids' => ['sometimes', 'nullable', 'array'],
            'tour_package_ids.*' => ['integer', 'exists:tour_packages,id'],
            'membership_plan_ids' => ['sometimes', 'nullable', 'array'],
            'membership_plan_ids.*' => ['integer', 'exists:membership_plans,id'],
        ], ['code.regex' => 'Use letters, numbers, dashes or underscores only.']);
    }

    // ------------------------------------------------------------- reviews

    public function reviews(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:pending,approved,rejected,hidden']]);
        $items = Review::query()->with(['user', 'hotel', 'booking'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate(20);

        return $this->ok(ReviewResource::collection($items));
    }

    public function moderateReview(Request $request, Review $review, ReviewService $reviews): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected,hidden'], 'note' => ['nullable', 'string', 'max:250']]);
        $reviews->moderate($review, $data['status'], $data['note'] ?? null, $request->user());
        Cache::forget('meta.home');

        return $this->ok(new ReviewResource($review->fresh(['user', 'hotel', 'booking'])), 'Review '.$data['status'].'.');
    }

    public function destroyReview(Request $request, Review $review, ReviewService $reviews): JsonResponse
    {
        $reviews->delete($review, $request->user());
        Cache::forget('meta.home');

        return $this->ok(null, 'Review deleted.');
    }

    // ------------------------------------------------------------- content

    public function faqs(): JsonResponse
    {
        return $this->ok(Faq::query()->orderBy('category')->orderBy('sort_order')->get());
    }

    public function saveFaq(Request $request, ?Faq $faq = null): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:50'],
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:3000'],
            'show_on_home' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
        $data = array_map(fn ($v) => is_string($v) ? strip_tags($v) : $v, $data);
        $faq = $faq ? tap($faq)->update($data) : Faq::query()->create($data);
        Cache::forget('meta.faqs');
        Cache::forget('meta.home');

        return $this->ok($faq, 'FAQ saved.');
    }

    public function destroyFaq(Faq $faq): JsonResponse
    {
        $faq->delete();
        Cache::forget('meta.faqs');
        Cache::forget('meta.home');

        return $this->ok(null, 'FAQ deleted.');
    }

    public function messages(Request $request): JsonResponse
    {
        $items = ContactMessage::query()->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest()->paginate(25);

        return $this->ok($items->items(), 'OK', ['pagination' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total(), 'per_page' => $items->perPage()]]);
    }

    public function updateMessage(Request $request, ContactMessage $message): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:new,read,resolved']]);
        $message->update($data);

        return $this->ok($message, 'Message updated.');
    }
}
