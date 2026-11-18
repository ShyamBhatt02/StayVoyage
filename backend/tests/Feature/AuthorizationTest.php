<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Critical scenarios 23–24 plus auth, coupons, reviews and suspension. */
class AuthorizationTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_guests_and_customers_are_rejected_from_admin_api(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
        $this->actingAs($this->makeUser())->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->actingAs($this->makeUser())->postJson('/api/v1/admin/hotels', ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->makeUser(Role::ADMIN))->getJson('/api/v1/admin/dashboard')->assertOk();
    }

    public function test_staff_cannot_use_super_admin_only_endpoints(): void
    {
        $staff = $this->makeUser(Role::ADMIN);
        $this->actingAs($staff)->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->actingAs($staff)->getJson('/api/v1/admin/roles')->assertForbidden();
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->getJson('/api/v1/admin/settings')->assertOk();
    }

    public function test_hotel_partner_cannot_access_another_hotels_data(): void
    {
        [$mine] = $this->makeHotel();
        [$theirs, $theirRoom] = $this->makeHotel();
        $partner = $this->makeUser(Role::HOTEL_PARTNER);
        $partner->hotels()->attach($mine->id);

        $this->actingAs($partner)->getJson("/api/v1/partner/hotels/{$mine->id}")->assertOk();
        $this->actingAs($partner)->getJson("/api/v1/partner/hotels/{$theirs->id}")->assertForbidden();
        $this->actingAs($partner)->putJson("/api/v1/partner/hotels/{$theirs->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($partner)->getJson("/api/v1/partner/hotels/{$theirs->id}/rooms/{$theirRoom->id}/availability")->assertForbidden();
        $this->assertCount(1, $this->actingAs($partner)->getJson('/api/v1/partner/hotels')->json('data'));

        // Partner edits of platform-level fields are ignored
        $this->actingAs($partner)->putJson("/api/v1/partner/hotels/{$mine->id}", ['short_description' => 'Updated by partner', 'is_featured' => true, 'status' => 'inactive'])->assertOk();
        $mine->refresh();
        $this->assertSame('Updated by partner', $mine->short_description);
        $this->assertFalse($mine->is_featured);
        $this->assertSame('active', $mine->status);

        // Partner cannot reach the admin API
        $this->actingAs($partner)->getJson('/api/v1/admin/hotels')->assertForbidden();
    }

    public function test_customers_cannot_see_each_others_bookings(): void
    {
        [, $room] = $this->makeHotel();
        $owner = $this->makeUser();
        $number = $this->actingAs($owner)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 5, 1))->json('data.booking.booking_number');
        $other = $this->makeUser();
        $this->actingAs($other)->getJson("/api/v1/bookings/{$number}")->assertForbidden();
        $this->actingAs($other)->postJson("/api/v1/bookings/{$number}/cancel")->assertForbidden();
    }

    public function test_register_login_and_suspension(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'New User', 'email' => 'new@example.test', 'phone' => '+919811122233', 'password' => 'weak', 'password_confirmation' => 'weak', 'accept_terms' => true])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
        $this->postJson('/api/v1/auth/register', ['name' => 'New User', 'email' => 'new@example.test', 'phone' => '+919811122233', 'password' => 'Str0ng!Pass', 'password_confirmation' => 'Str0ng!Pass', 'accept_terms' => true])
            ->assertCreated()->assertJsonPath('data.email', 'new@example.test');

        $user = $this->makeUser(Role::CUSTOMER, ['email' => 'login@example.test']);
        $this->postJson('/api/v1/auth/login', ['email' => 'login@example.test', 'password' => 'wrong'])->assertStatus(422);
        $user->forceFill(['status' => 'suspended'])->save();
        $this->postJson('/api/v1/auth/login', ['email' => 'login@example.test', 'password' => 'Password@123'])->assertForbidden()->assertJsonPath('code', 'account_suspended');
    }

    public function test_coupon_rules_are_validated_server_side(): void
    {
        [, $room] = $this->makeHotel([], 5, 5000);
        $user = $this->makeUser();
        Coupon::query()->create(['code' => 'TEN', 'title' => '10%', 'discount_type' => 'percentage', 'discount_value' => 10, 'max_discount' => 600, 'min_purchase' => 4000, 'applies_to' => 'hotels', 'per_user_limit' => 1, 'status' => 'active']);
        Coupon::query()->create(['code' => 'OLD', 'title' => 'old', 'discount_type' => 'fixed', 'discount_value' => 500, 'applies_to' => 'all', 'status' => 'active', 'ends_at' => now()->subDay()]);
        Coupon::query()->create(['code' => 'TOURS', 'title' => 't', 'discount_type' => 'fixed', 'discount_value' => 500, 'applies_to' => 'tours', 'status' => 'active']);

        $quote = fn ($code, $nights = 2) => $this->actingAs($user)->postJson('/api/v1/hotels/quote', ['hotel_room_id' => $room->id, 'check_in' => now()->next('Monday')->toDateString(),
            'check_out' => now()->next('Monday')->addDays($nights)->toDateString(), 'rooms' => 1, 'adults' => 2, 'booking_type' => 'regular', 'coupon_code' => $code]);

        $this->assertEquals(600, $quote('TEN', 2)->assertOk()->json('data.discount_amount')); // capped at max
        $quote('TEN', 0 + 1)->assertOk(); // 5000 ≥ min
        $quote('OLD')->assertStatus(422)->assertJsonPath('code', 'invalid_coupon');
        $quote('TOURS')->assertStatus(422)->assertJsonPath('code', 'invalid_coupon');
        $quote('NOPE')->assertStatus(422);

        $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 9, 2, 'regular', ['coupon_code' => 'TEN']))->assertCreated();
        $quote('TEN')->assertStatus(422); // per-user limit reached
    }

    public function test_only_completed_stays_can_be_reviewed_once(): void
    {
        [, $room] = $this->makeHotel();
        $user = $this->makeUser();
        $number = $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 5, 1))->json('data.booking.booking_number');
        $review = ['overall' => 5, 'cleanliness' => 5, 'location' => 5, 'service' => 5, 'value' => 4, 'title' => 'Lovely', 'comment' => 'A really lovely stay with friendly staff.'];
        $this->actingAs($user)->postJson("/api/v1/bookings/{$number}/review", $review)->assertForbidden();

        \App\Models\Booking::query()->where('booking_number', $number)->update(['status' => 'completed']);
        $this->actingAs($user)->postJson("/api/v1/bookings/{$number}/review", $review)->assertCreated();
        $this->actingAs($user)->postJson("/api/v1/bookings/{$number}/review", $review)->assertStatus(409)->assertJsonPath("code", "review_exists");
    }

    public function test_image_upload_rejects_non_images(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [$hotel] = $this->makeHotel();
        $fake = \Illuminate\Http\UploadedFile::fake()->create('evil.php.jpg', 10, 'application/x-php');
        $this->actingAs($admin)->postJson("/api/v1/admin/hotels/{$hotel->id}/images", ['images' => [$fake]])->assertStatus(422);
    }
}
