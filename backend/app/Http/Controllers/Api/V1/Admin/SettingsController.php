<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    /** Validation for every editable key: the admin UI can only change known settings with valid values. */
    private const RULES = [
        'site.name' => ['string', 'max:60'],
        'site.tagline' => ['string', 'max:120'],
        'site.support_email' => ['email', 'max:150'],
        'site.support_phone' => ['string', 'max:30'],
        'site.address' => ['string', 'max:250'],
        'site.currency' => ['string', 'size:3'],
        'site.currency_symbol' => ['string', 'max:4'],
        'pricing.hotel_tax_percent' => ['numeric', 'min:0', 'max:50'],
        'pricing.tour_tax_percent' => ['numeric', 'min:0', 'max:50'],
        'pricing.membership_tax_percent' => ['numeric', 'min:0', 'max:50'],
        'pricing.hotel_service_fee_percent' => ['numeric', 'min:0', 'max:30'],
        'pricing.hotel_service_fee_min' => ['numeric', 'min:0', 'max:10000'],
        'pricing.membership_booking_service_fee' => ['numeric', 'min:0', 'max:20000'],
        'pricing.tour_service_fee_percent' => ['numeric', 'min:0', 'max:30'],
        'booking.payment_hold_minutes' => ['integer', 'min:5', 'max:120'],
        'booking.max_rooms_per_booking' => ['integer', 'min:1', 'max:20'],
        'booking.max_nights_per_booking' => ['integer', 'min:1', 'max:90'],
        'booking.max_advance_days' => ['integer', 'min:7', 'max:730'],
        'booking.late_cancellation_penalty_percent' => ['numeric', 'min:0', 'max:100'],
        'booking.allow_late_cancellation' => ['boolean'],
        'tours.free_cancellation_hours' => ['integer', 'min:0', 'max:720'],
        'tours.late_cancellation_penalty_percent' => ['numeric', 'min:0', 'max:100'],
        'payments.default_gateway' => ['in:sandbox,razorpay,stripe'],
        'payments.enabled_gateways' => ['array', 'min:1'],
        'security.enforce_admin_2fa' => ['boolean'],
        'mail.from_name' => ['string', 'max:60'],
        'mail.reply_to' => ['email', 'max:150'],
    ];

    public function index(SettingsService $settings): JsonResponse
    {
        $all = $settings->all();
        $values = collect(self::RULES)->keys()->mapWithKeys(fn ($k) => [$k => $all[$k] ?? null]);
        $values['tours.free_cancellation_hours'] ??= 72;
        $values['tours.late_cancellation_penalty_percent'] ??= 25;

        return $this->ok([
            'values' => $values,
            'groups' => $values->keys()->groupBy(fn ($k) => explode('.', $k)[0])->map->values(),
            'gateway_status' => [
                'sandbox' => (bool) config('payments.sandbox.enabled'),
                'razorpay' => filled(config('payments.razorpay.key_id')),
                'stripe' => filled(config('payments.stripe.secret')),
            ],
            'mail_driver' => config('mail.default'),
        ]);
    }

    public function update(Request $request, SettingsService $settings, AuditLogger $audit): JsonResponse
    {
        $input = $request->validate(['values' => ['required', 'array']])['values'];
        $rules = [];
        foreach ($input as $key => $_) {
            if (! isset(self::RULES[$key])) {
                return $this->fail("Unknown setting: {$key}", 422, [], 'unknown_setting');
            }
            $rules['values.'.$key] = array_merge(['required'], self::RULES[$key]);
        }
        $validated = $request->validate($rules)['values'];
        if (isset($validated['payments.enabled_gateways'])) {
            $validated['payments.enabled_gateways'] = array_values(array_intersect(['sandbox', 'razorpay', 'stripe'], (array) $validated['payments.enabled_gateways']));
        }

        $before = $settings->all();
        foreach ($validated as $key => $value) {
            $settings->set($key, is_string($value) ? strip_tags($value) : $value);
        }
        $changed = collect($validated)->filter(fn ($v, $k) => ($before[$k] ?? null) != $v);
        if ($changed->isNotEmpty()) {
            $audit->log('settings.updated', null, $changed->mapWithKeys(fn ($v, $k) => [$k => $before[$k] ?? null])->all(), $changed->all());
        }
        Cache::forget('meta.config');

        return $this->ok(null, 'Settings saved.');
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $request->validate(['action' => ['nullable', 'string', 'max:100'], 'user_id' => ['nullable', 'integer'], 'entity_type' => ['nullable', 'string', 'max:60']]);
        $logs = AuditLog::query()->with('user:id,name,email')
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', "{$a}%"))
            ->when($request->query('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->query('entity_type'), fn ($q, $e) => $q->where('entity_type', $e))
            ->latest('id')->paginate(30);

        return $this->ok($logs->getCollection()->map(fn ($l) => [
            'id' => $l->id, 'action' => $l->action, 'entity_type' => $l->entity_type, 'entity_id' => $l->entity_id,
            'old_values' => $l->old_values, 'new_values' => $l->new_values, 'description' => $l->description,
            'ip_address' => $l->ip_address, 'user' => $l->user?->only(['id', 'name', 'email']), 'created_at' => $l->created_at?->toIso8601String(),
        ]), 'OK', ['pagination' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total(), 'per_page' => $logs->perPage()]]);
    }
}
