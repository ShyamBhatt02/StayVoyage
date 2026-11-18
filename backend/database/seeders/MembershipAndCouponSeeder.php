<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\Hotel;
use App\Models\MembershipPlan;
use Illuminate\Database\Seeder;

class MembershipAndCouponSeeder extends Seeder
{
    public function run(): void
    {
        $monthly = MembershipPlan::query()->updateOrCreate(['slug' => 'stayvoyage-monthly'], [
            'name' => 'StayVoyage Monthly',
            'tagline' => 'Up to 30 hotel nights, every month.',
            'description' => 'One simple monthly membership that gives you up to 30 hotel-night credits at participating StayVoyage hotels across India and abroad. Pay only taxes and a small booking fee when you stay.',
            'price' => 14999, 'currency' => 'INR', 'duration_days' => 30, 'included_nights' => 30,
            'max_nights_per_booking' => 7, 'max_rooms_per_booking' => 1, 'max_guests_per_booking' => 2,
            'min_advance_booking_days' => 0, 'max_advance_booking_days' => 90,
            'all_hotels_eligible' => true, 'eligible_room_types' => ['standard', 'deluxe', 'premium', 'family'],
            'cancellation_deadline_hours' => 24, 'late_cancellation_policy' => 'forfeit_nights', 'no_show_policy' => 'forfeit_nights',
            'unused_nights_expire' => true, 'nights_extend_past_expiry' => false, 'auto_renew_available' => true, 'charge_taxes_on_covered_amount' => true,
            'features' => [
                '30 hotel-night credits valid for 30 days',
                'Book up to 7 nights per stay',
                '14+ participating hotels in India, Dubai & Bali',
                'Free cancellation up to 24 hours before check-in',
                'Member-only offers on tours and upgrades',
                'Priority support on chat and phone',
            ],
            'is_featured' => true, 'status' => 'active', 'sort_order' => 1,
        ]);
        $monthly->blackoutDates()->delete();
        $monthly->blackoutDates()->create(['start_date' => '2026-12-24', 'end_date' => '2027-01-01', 'reason' => 'Christmas & New Year peak']);
        $monthly->blackoutDates()->create(['start_date' => '2027-03-03', 'end_date' => '2027-03-05', 'reason' => 'Holi festival weekend']);

        MembershipPlan::query()->updateOrCreate(['slug' => 'stayvoyage-quarterly'], [
            'name' => 'StayVoyage Quarterly',
            'tagline' => 'Three months of stays for frequent travellers.',
            'description' => 'Ideal for remote workers and frequent business travellers: 75 hotel-night credits across 90 days, longer stays and two rooms per booking.',
            'price' => 38999, 'currency' => 'INR', 'duration_days' => 90, 'included_nights' => 75,
            'max_nights_per_booking' => 10, 'max_rooms_per_booking' => 2, 'max_guests_per_booking' => 4,
            'min_advance_booking_days' => 0, 'max_advance_booking_days' => 120,
            'all_hotels_eligible' => true, 'eligible_room_types' => ['standard', 'deluxe', 'premium', 'family'],
            'cancellation_deadline_hours' => 48, 'late_cancellation_policy' => 'refund_nights', 'no_show_policy' => 'forfeit_nights',
            'unused_nights_expire' => true, 'nights_extend_past_expiry' => false, 'auto_renew_available' => true, 'charge_taxes_on_covered_amount' => true,
            'features' => [
                '75 hotel-night credits valid for 90 days',
                'Up to 10 nights and 2 rooms per booking',
                'Late cancellations still return your nights',
                'Everything in Monthly',
            ],
            'is_featured' => false, 'status' => 'active', 'sort_order' => 2,
        ]);

        $goaHotels = Hotel::query()->whereHas('destination', fn ($q) => $q->where('name', 'Goa'))->pluck('id');
        $coupons = [
            ['WELCOME10', 'Welcome offer: 10% off your first hotel stay', 'New to StayVoyage? Take 10% off any regular hotel booking, up to ₹2,000.', 'percentage', 10, 3000, 2000, 'hotels', true, null, 1, 'hotels/palm-resort', null, '+6 months'],
            ['TRIP15', '15% off custom trips over ₹40,000', 'Build your own itinerary and save 15% (up to ₹7,500) on trips worth ₹40,000 or more.', 'percentage', 15, 40000, 7500, 'tours', true, null, 2, 'misc/trip-planning', null, '+4 months'],
            ['MEMBER1000', '₹1,000 off your membership', 'Join StayVoyage Monthly or Quarterly and get ₹1,000 off the membership fee.', 'fixed', 1000, 5000, null, 'memberships', true, null, 1, 'hotels/infinity-deck', null, '+3 months'],
            ['GOASUN', '₹1,500 off Goa beach stays', 'Flat ₹1,500 off regular bookings at our Goa hotels for stays worth ₹8,000+.', 'fixed', 1500, 8000, null, 'hotels', true, 500, 2, 'destinations/goa-beach', 'goa', '+2 months'],
            ['FLAT750', 'Flat ₹750 off anything over ₹10,000', 'Use on hotels or trips — one per customer.', 'fixed', 750, 10000, null, 'all', false, 1000, 1, null, null, '+5 months'],
            ['SUMMER25', 'Summer sale (expired)', 'Last season\'s summer offer — kept for reference.', 'percentage', 25, 0, 3000, 'all', false, null, 1, null, null, '-1 month'],
            ['ONEUSE', 'Single-use test coupon', 'Global usage limit of 1 — useful for testing limits.', 'fixed', 300, 0, null, 'all', false, 1, 1, null, null, '+12 months'],
        ];
        foreach ($coupons as [$code, $title, $desc, $type, $value, $min, $max, $applies, $public, $limit, $perUser, $img, $scope, $ends]) {
            $c = Coupon::query()->updateOrCreate(['code' => $code], [
                'title' => $title, 'description' => $desc, 'discount_type' => $type, 'discount_value' => $value, 'min_purchase' => $min,
                'max_discount' => $max, 'applies_to' => $applies, 'is_public' => $public, 'usage_limit' => $limit, 'per_user_limit' => $perUser,
                'image_path' => $img ? "seed/{$img}.jpg" : null, 'status' => 'active',
                'starts_at' => $code === 'SUMMER25' ? now()->subMonths(4) : now()->subDays(10),
                'ends_at' => now()->modify($ends),
            ]);
            if ($scope === 'goa') {
                $c->hotels()->sync($goaHotels);
            }
        }
    }
}
