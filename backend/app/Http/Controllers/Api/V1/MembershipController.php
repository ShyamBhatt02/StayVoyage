<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MembershipPlanResource;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\MembershipTransactionResource;
use App\Models\Hotel;
use App\Models\MembershipPlan;
use App\Services\Membership\MembershipService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function plans(): JsonResponse
    {
        $plans = MembershipPlan::query()->active()->with('blackoutDates')->orderByDesc('is_featured')->orderBy('sort_order')->get();

        return $this->ok([
            'plans' => MembershipPlanResource::collection($plans),
            'eligible_hotels' => Hotel::query()->active()->where('is_membership_eligible', true)->count(),
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membership_plan_id' => ['required', 'integer', 'exists:membership_plans,id'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);
        $plan = MembershipPlan::query()->findOrFail($data['membership_plan_id']);

        return $this->ok($this->memberships->quote($plan, $request->user(), $data['coupon_code'] ?? null));
    }

    public function purchase(Request $request, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'membership_plan_id' => ['required', 'integer', 'exists:membership_plans,id'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'auto_renew' => ['sometimes', 'boolean'],
            'gateway' => ['nullable', 'in:sandbox,razorpay,stripe'],
            'accept_terms' => ['accepted'],
        ], ['accept_terms.accepted' => 'Please accept the membership terms to continue.']);

        $plan = MembershipPlan::query()->findOrFail($data['membership_plan_id']);
        $gateway = $payments->resolveGateway($data['gateway'] ?? null);
        $result = $this->memberships->purchase($request->user(), $plan, $data['coupon_code'] ?? null, (bool) ($data['auto_renew'] ?? false), $gateway, $payments);

        $checkout = $result['payment'] ? $payments->startCheckout($result['payment']) : null;

        return $this->created([
            'membership' => new MembershipResource($result['membership']->fresh()),
            'quote' => $result['quote'],
            'checkout' => $checkout,
        ], $checkout ? 'Complete payment to activate your membership.' : 'Your membership is active!');
    }

    /** Current wallet: active membership (or latest), queued renewal, recent ledger. */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $active = $user->activeMembership();
        $latest = $active ?? $user->memberships()->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Expired->value])->latest('expires_at')->first();
        $queued = $user->memberships()->where('status', MembershipStatus::Active->value)->where('starts_at', '>', now())->first();

        return $this->ok([
            'membership' => $latest ? new MembershipResource($latest) : null,
            'queued_renewal' => $queued ? new MembershipResource($queued) : null,
            'transactions' => $latest
                ? MembershipTransactionResource::collection($latest->transactions()->with('booking:id,booking_number')->limit(10)->get())
                : [],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $memberships = $request->user()->memberships()->whereIn('status', ['active', 'expired', 'cancelled'])->latest()->get();

        return $this->ok(MembershipResource::collection($memberships));
    }

    public function transactions(Request $request): JsonResponse
    {
        $request->validate(['membership_id' => ['nullable', 'integer']]);
        $tx = $request->user()->membershipTransactions()
            ->when($request->query('membership_id'), fn ($q, $id) => $q->where('membership_id', $id))
            ->with('booking:id,booking_number')->orderByDesc('id')->paginate(20);

        return $this->ok(MembershipTransactionResource::collection($tx));
    }

    public function autoRenew(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $membership = $request->user()->activeMembership();
        if (! $membership) {
            return $this->fail('You do not have an active membership.', 404, [], 'membership_required');
        }
        $this->memberships->setAutoRenew($membership->loadMissing('plan'), $data['enabled']);

        return $this->ok(new MembershipResource($membership), $data['enabled'] ? 'Auto-renewal turned on — we\'ll remind you 3 days before expiry.' : 'Auto-renewal turned off.');
    }
}
