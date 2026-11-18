<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-configurable platform settings (taxes, fees, currency, gateway, site info...).
 * Cached as one map; invalidated whenever a setting is written.
 */
class SettingsService
{
    private const CACHE_KEY = 'settings.all';

    /** Defaults used when a key has never been saved. Keeps business rules out of code paths. */
    public const DEFAULTS = [
        // general
        'site.name' => 'StayVoyage',
        'site.tagline' => 'Book Stays. Build Trips. Travel Your Way.',
        'site.support_email' => 'support@stayvoyage.test',
        'site.support_phone' => '+91 79 4000 1234',
        'site.address' => '4th Floor, Riverfront Business Hub, Ashram Road, Ahmedabad, Gujarat 380009',
        'site.currency' => 'INR',
        'site.currency_symbol' => '₹',
        // pricing
        'pricing.hotel_tax_percent' => 12.0,        // GST on accommodation
        'pricing.tour_tax_percent' => 5.0,          // GST on tour packages
        'pricing.membership_tax_percent' => 18.0,   // GST on membership fee
        'pricing.hotel_service_fee_percent' => 2.5,
        'pricing.hotel_service_fee_min' => 99.0,
        'pricing.membership_booking_service_fee' => 500.0, // flat fee per membership booking
        'pricing.tour_service_fee_percent' => 3.0,
        // booking
        'booking.payment_hold_minutes' => 20,       // unpaid booking keeps inventory for this long
        'booking.max_rooms_per_booking' => 5,
        'booking.max_nights_per_booking' => 30,
        'booking.max_advance_days' => 365,
        'booking.late_cancellation_penalty_percent' => 50.0, // regular bookings cancelled after free window
        'booking.allow_late_cancellation' => true,
        // payments
        'payments.default_gateway' => 'sandbox',
        'payments.enabled_gateways' => ['sandbox', 'razorpay', 'stripe'],
        // security
        'security.enforce_admin_2fa' => false,
        // email
        'mail.from_name' => 'StayVoyage',
        'mail.reply_to' => 'support@stayvoyage.test',
        // tours
        'tours.free_cancellation_hours' => 72,
        'tours.late_cancellation_penalty_percent' => 25.0,
        'tours.meal_plan_rates' => ['room_only' => 0, 'breakfast' => 650, 'half_board' => 1450, 'full_board' => 2300],
    ];

    /** Keys exposed to the public site config endpoint. */
    public const PUBLIC_KEYS = [
        'site.name', 'site.tagline', 'site.support_email', 'site.support_phone', 'site.address', 'site.currency',
        'site.currency_symbol', 'pricing.hotel_tax_percent', 'pricing.tour_tax_percent', 'pricing.membership_tax_percent',
        'pricing.hotel_service_fee_percent', 'pricing.membership_booking_service_fee', 'pricing.tour_service_fee_percent',
        'booking.payment_hold_minutes', 'booking.max_rooms_per_booking', 'booking.max_nights_per_booking',
    ];

    public function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());

        return array_merge(self::DEFAULTS, $stored);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function float(string $key): float
    {
        return (float) $this->get($key, 0);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key, 0);
    }

    public function bool(string $key): bool
    {
        return filter_var($this->get($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    public function set(string $key, mixed $value): void
    {
        $group = explode('.', $key)[0];
        Setting::query()->updateOrCreate(['key' => $key], [
            'group' => $group,
            'value' => $value,
            'type' => get_debug_type($value),
            'is_public' => in_array($key, self::PUBLIC_KEYS, true),
        ]);
        $this->flush();
    }

    public function publicSettings(): array
    {
        return array_intersect_key($this->all(), array_flip(self::PUBLIC_KEYS));
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
