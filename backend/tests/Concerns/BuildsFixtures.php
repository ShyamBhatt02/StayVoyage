<?php

namespace Tests\Concerns;

use App\Enums\MembershipStatus;
use App\Models\Destination;
use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Role;
use App\Models\User;
use App\Services\Membership\MembershipWalletService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/** Small, explicit fixtures for the critical business-rule tests. */
trait BuildsFixtures
{
    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeUser(string $role = Role::CUSTOMER, array $attrs = []): User
    {
        $u = User::query()->create(array_merge([
            'name' => 'Test '.Str::random(5), 'email' => Str::random(10).'@example.test', 'phone' => '+919800011122', 'password' => 'Password@123',
        ], $attrs));
        $u->forceFill(['email_verified_at' => now()])->save();
        $u->roles()->attach(Role::query()->where('slug', $role)->value('id'));

        return $u->fresh();
    }

    protected function makeHotel(array $attrs = [], int $totalRooms = 10, float $price = 5000, bool $memberRoom = true): array
    {
        $dest = Destination::query()->firstOrCreate(['slug' => 'test-city'], ['name' => 'Test City', 'country' => 'India', 'status' => 'active']);
        $hotel = Hotel::query()->create(array_merge([
            'destination_id' => $dest->id, 'name' => 'Hotel '.Str::random(6), 'slug' => 'hotel-'.Str::lower(Str::random(8)), 'property_type' => 'hotel',
            'star_rating' => 4, 'address' => '1 Test Road', 'city' => 'Test City', 'country' => 'India', 'is_membership_eligible' => true, 'status' => 'active',
            'check_in_time' => '14:00', 'check_out_time' => '11:00',
        ], $attrs));
        $room = $hotel->rooms()->create([
            'name' => 'Deluxe Room', 'room_type' => 'deluxe', 'max_guests' => 3, 'max_adults' => 2, 'max_children' => 1, 'bed_type' => 'King',
            'total_rooms' => $totalRooms, 'base_price' => $price, 'weekend_price' => null, 'free_cancellation' => true, 'free_cancellation_hours' => 48,
            'is_membership_eligible' => $memberRoom, 'status' => 'active',
        ]);
        $hotel->refreshAggregates();

        return [$hotel, $room];
    }

    protected function makePlan(array $attrs = []): MembershipPlan
    {
        return MembershipPlan::query()->create(array_merge([
            'name' => 'StayVoyage Monthly', 'slug' => 'monthly-'.Str::lower(Str::random(5)), 'price' => 14999, 'currency' => 'INR', 'duration_days' => 30,
            'included_nights' => 30, 'max_nights_per_booking' => 7, 'max_rooms_per_booking' => 1, 'max_guests_per_booking' => 2,
            'min_advance_booking_days' => 0, 'max_advance_booking_days' => 90, 'all_hotels_eligible' => true, 'eligible_room_types' => null,
            'cancellation_deadline_hours' => 24, 'late_cancellation_policy' => 'forfeit_nights', 'no_show_policy' => 'forfeit_nights',
            'unused_nights_expire' => true, 'auto_renew_available' => true, 'charge_taxes_on_covered_amount' => true, 'status' => 'active',
        ], $attrs));
    }

    /** An active membership credited through the real wallet service. */
    protected function activeMembership(User $user, ?MembershipPlan $plan = null, int $daysAgo = 1, int $duration = 30): Membership
    {
        $plan ??= $this->makePlan();
        $m = new Membership([
            'membership_number' => 'SVM-T-'.Str::upper(Str::random(6)), 'user_id' => $user->id, 'membership_plan_id' => $plan->id,
            'total_nights' => $plan->included_nights, 'price_paid' => 17698.82, 'plan_snapshot' => $plan->toSnapshot(),
            'starts_at' => now()->subDays($daysAgo), 'expires_at' => now()->subDays($daysAgo)->addDays($duration), 'activated_at' => now()->subDays($daysAgo),
        ]);
        $m->forceFill(['status' => MembershipStatus::Active, 'nights_balance' => 0])->save();
        app(MembershipWalletService::class)->creditPurchase($m);

        return $m->fresh();
    }

    protected function bookingPayload(HotelRoom $room, int $checkInDays, int $nights, string $type = 'regular', array $extra = []): array
    {
        return array_merge([
            'hotel_room_id' => $room->id,
            'check_in' => now()->addDays($checkInDays)->toDateString(),
            'check_out' => now()->addDays($checkInDays + $nights)->toDateString(),
            'rooms' => 1, 'adults' => 2, 'children' => 0, 'booking_type' => $type, 'gateway' => 'sandbox',
            'contact_name' => 'Asha Patel', 'contact_email' => 'asha@example.test', 'contact_phone' => '+919825012345', 'accept_terms' => true,
        ], $extra);
    }
}
