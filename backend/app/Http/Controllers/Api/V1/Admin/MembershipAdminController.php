<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MembershipPlanResource;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\MembershipTransactionResource;
use App\Models\Membership;
use App\Models\MembershipBlackoutDate;
use App\Models\MembershipPlan;
use App\Services\AuditLogger;
use App\Services\Membership\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Membership plans (configuration), member wallets and audited night adjustments. */
class MembershipAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    // ------------------------------------------------------------- plans

    public function plans(): JsonResponse
    {
        $plans = MembershipPlan::query()->with(['hotels:id', 'blackoutDates'])
            ->withCount(['memberships as active_members_count' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('sort_order')->get();

        return $this->ok(MembershipPlanResource::collection($plans));
    }

    public function storePlan(Request $request): JsonResponse
    {
        $data = $this->validatedPlan($request, null);
        $plan = DB::transaction(function () use ($data) {
            $plan = MembershipPlan::query()->create(collect($data)->except('hotel_ids')->all() + ['slug' => $data['slug'] ?? Str::slug($data['name'])]);
            $plan->hotels()->sync($data['hotel_ids'] ?? []);

            return $plan;
        });
        $this->audit->log('membership_plan.created', $plan, null, $plan->only(['name', 'price', 'included_nights', 'duration_days']));
        Cache::forget('meta.home');

        return $this->created(new MembershipPlanResource($plan->load(['hotels:id', 'blackoutDates'])), 'Membership plan created.');
    }

    public function updatePlan(Request $request, MembershipPlan $plan): JsonResponse
    {
        $data = $this->validatedPlan($request, $plan);
        DB::transaction(function () use ($plan, $data) {
            $original = $plan->getOriginal();
            $plan->fill(collect($data)->except('hotel_ids')->all())->save();
            if (array_key_exists('hotel_ids', $data)) {
                $plan->hotels()->sync($data['hotel_ids']);
            }
            $this->audit->logChanges('membership_plan.updated', $plan, $original, 'Existing members keep the rules frozen at their purchase.');
        });
        Cache::forget('meta.home');

        return $this->ok(new MembershipPlanResource($plan->fresh(['hotels:id', 'blackoutDates'])), 'Plan updated. Changes apply to new purchases; existing members keep their purchased terms.');
    }

    public function addBlackout(Request $request, MembershipPlan $plan): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:150'],
        ]);
        $b = $plan->blackoutDates()->create($data);
        $this->audit->log('membership_plan.blackout_added', $plan, null, $data);

        return $this->created(['id' => $b->id], 'Blackout dates added.');
    }

    public function removeBlackout(MembershipPlan $plan, MembershipBlackoutDate $blackout): JsonResponse
    {
        abort_unless($blackout->membership_plan_id === $plan->id, 404);
        $this->audit->log('membership_plan.blackout_removed', $plan, $blackout->only(['start_date', 'end_date', 'reason']), null);
        $blackout->delete();

        return $this->ok(null, 'Blackout dates removed.');
    }

    // ------------------------------------------------------------- member wallets

    public function memberships(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:pending,active,expired,cancelled'], 'q' => ['nullable', 'string', 'max:100']]);
        $items = Membership::query()->with('user')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('membership_number', 'like', "%{$t}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$t}%")->orWhere('email', 'like', "%{$t}%"))))
            ->latest()->paginate(20);

        return $this->ok(MembershipResource::collection($items));
    }

    public function membership(Membership $membership): JsonResponse
    {
        $membership->load('user');

        return $this->ok([
            'membership' => new MembershipResource($membership),
            'ledger' => MembershipTransactionResource::collection($membership->transactions()->with(['booking:id,booking_number', 'creator:id,name'])->get()),
            'ledger_balance' => (int) $membership->transactions()->sum('nights'),
            'bookings' => $membership->bookings()->latest()->get(['id', 'booking_number', 'check_in', 'check_out', 'status', 'membership_nights_used']),
        ]);
    }

    public function adjust(Request $request, Membership $membership, MembershipService $service): JsonResponse
    {
        $data = $request->validate([
            'nights' => ['required', 'integer', 'not_in:0', 'between:-60,60'],
            'reason' => ['required', 'string', 'min:5', 'max:200'],
        ]);
        $service->adminAdjust($membership, (int) $data['nights'], strip_tags($data['reason']), $request->user());

        return $this->ok(new MembershipResource($membership->fresh()), 'Balance adjusted and recorded in the ledger.');
    }

    private function validatedPlan(Request $request, ?MembershipPlan $plan): array
    {
        $req = $plan ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', Rule::unique('membership_plans', 'slug')->ignore($plan?->id)],
            'tagline' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:3000'],
            'price' => [$req, 'numeric', 'min:0', 'max:10000000'],
            'currency' => [$req, 'string', 'size:3'],
            'duration_days' => [$req, 'integer', 'min:1', 'max:730'],
            'included_nights' => [$req, 'integer', 'min:1', 'max:365'],
            'max_nights_per_booking' => [$req, 'integer', 'min:1', 'max:60'],
            'max_rooms_per_booking' => [$req, 'integer', 'min:1', 'max:10'],
            'max_guests_per_booking' => [$req, 'integer', 'min:1', 'max:20'],
            'min_advance_booking_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'max_advance_booking_days' => ['sometimes', 'integer', 'min:1', 'max:730'],
            'all_hotels_eligible' => ['sometimes', 'boolean'],
            'eligible_room_types' => ['nullable', 'array'],
            'eligible_room_types.*' => [Rule::in(\App\Models\HotelRoom::ROOM_TYPES)],
            'cancellation_deadline_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'late_cancellation_policy' => ['sometimes', 'in:forfeit_nights,refund_nights'],
            'no_show_policy' => ['sometimes', 'in:forfeit_nights,refund_nights'],
            'unused_nights_expire' => ['sometimes', 'boolean'],
            'nights_extend_past_expiry' => ['sometimes', 'boolean'],
            'auto_renew_available' => ['sometimes', 'boolean'],
            'charge_taxes_on_covered_amount' => ['sometimes', 'boolean'],
            'features' => ['nullable', 'array', 'max:12'],
            'features.*' => ['string', 'max:150'],
            'is_featured' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'hotel_ids' => ['sometimes', 'array'],
            'hotel_ids.*' => ['integer', 'exists:hotels,id'],
        ]);
    }
}
