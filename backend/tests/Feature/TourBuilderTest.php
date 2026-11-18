<?php

namespace Tests\Feature;

use App\Models\TourActivity;
use App\Models\TourGuide;
use App\Models\UserTrip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Critical scenarios 19–22 plus tour checkout: server-side repricing on every itinerary change. */
class TourBuilderTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function activity(int $price = 2000, int $capacity = 20): TourActivity
    {
        [$hotel] = $this->makeHotel();

        return TourActivity::query()->create([
            'destination_id' => $hotel->destination_id, 'name' => 'City Tour', 'slug' => 'city-tour-'.uniqid(), 'category' => 'sightseeing', 'location' => 'Old Town',
            'duration_minutes' => 180, 'start_times' => ['10:00'], 'capacity_per_slot' => $capacity, 'price' => $price, 'child_price' => 1000, 'status' => 'active',
        ]);
    }

    public function test_adding_and_removing_items_recalculates_total(): void
    {
        $user = $this->makeUser();
        $activity = $this->activity(2000);
        $trip = $this->actingAs($user)->postJson('/api/v1/trips', ['title' => 'Test Trip', 'days' => 2, 'adults' => 2, 'children' => 0, 'start_date' => now()->addDays(20)->toDateString()])
            ->assertCreated()->json('data');
        $this->assertEquals(0, $trip['total_amount']);

        $after = $this->actingAs($user)->postJson("/api/v1/trips/{$trip['id']}/items", [
            'trip_day_id' => $trip['days'][0]['id'], 'item_type' => 'activity', 'itemable_id' => $activity->id,
            'unit_price' => 1, 'total_price' => 1, // must be ignored
        ])->assertOk()->json('data');
        // 2 adults × 2000 = 4000 + 5% GST + 3% fee
        $this->assertEquals(4000, $after['subtotal']);
        $this->assertEquals(4320, $after['total_amount']);

        $itemId = $after['days'][0]['items'][0]['id'];
        $this->actingAs($user)->patchJson("/api/v1/trips/{$trip['id']}/items/{$itemId}", ['quantity' => 3])->assertOk()->assertJsonPath('data.subtotal', 6000);

        $removed = $this->actingAs($user)->deleteJson("/api/v1/trips/{$trip['id']}/items/{$itemId}")->assertOk()->json('data');
        $this->assertEquals(0, $removed['total_amount']);
    }

    public function test_reorder_moves_items_across_days_and_rejects_foreign_items(): void
    {
        $user = $this->makeUser();
        $activity = $this->activity();
        $trip = $this->actingAs($user)->postJson('/api/v1/trips', ['title' => 'Two day trip', 'days' => 2, 'adults' => 1, 'children' => 0])->json('data');
        $t = $this->actingAs($user)->postJson("/api/v1/trips/{$trip['id']}/items", ['trip_day_id' => $trip['days'][0]['id'], 'item_type' => 'activity', 'itemable_id' => $activity->id])->json('data');
        $itemId = $t['days'][0]['items'][0]['id'];

        $moved = $this->actingAs($user)->putJson("/api/v1/trips/{$trip['id']}/items/order", ['layout' => [
            ['day_id' => $trip['days'][0]['id'], 'item_ids' => []], ['day_id' => $trip['days'][1]['id'], 'item_ids' => [$itemId]],
        ]])->assertOk()->json('data');
        $this->assertCount(1, $moved['days'][1]['items']);

        $this->actingAs($user)->putJson("/api/v1/trips/{$trip['id']}/items/order", ['layout' => [['day_id' => $trip['days'][0]['id'], 'item_ids' => [999999]]]])->assertStatus(409);
    }

    public function test_other_users_cannot_view_or_edit_a_private_trip_and_share_link_hides_personal_data(): void
    {
        $owner = $this->makeUser();
        $trip = $this->actingAs($owner)->postJson('/api/v1/trips', ['title' => 'Private', 'days' => 1, 'adults' => 1, 'children' => 0])->json('data');
        $intruder = $this->makeUser();
        $this->actingAs($intruder)->getJson("/api/v1/trips/{$trip['id']}")->assertForbidden();
        $this->actingAs($intruder)->patchJson("/api/v1/trips/{$trip['id']}", ['title' => 'Hacked'])->assertForbidden();

        $shared = $this->actingAs($owner)->postJson("/api/v1/trips/{$trip['id']}/share", ['visibility' => 'public'])->json('data');
        $token = basename($shared['share_url']);
        $public = $this->getJson("/api/v1/trips/shared/{$token}")->assertOk()->json('data');
        $this->assertNull($public['id']);
        $this->assertArrayNotHasKey('share_token', $public);
        $this->assertStringNotContainsString($owner->email, json_encode($public));

        $this->actingAs($owner)->postJson("/api/v1/trips/{$trip['id']}/share", ['visibility' => 'private']);
        $this->getJson("/api/v1/trips/shared/{$token}")->assertNotFound();
    }

    public function test_trip_booking_reprices_checks_capacity_and_locks_trip(): void
    {
        $user = $this->makeUser();
        $activity = $this->activity(2500, 2);
        [, $room] = $this->makeHotel([], 3, 4000);
        $trip = $this->actingAs($user)->postJson('/api/v1/trips', ['title' => 'Bookable', 'days' => 2, 'adults' => 2, 'children' => 0, 'start_date' => now()->addDays(15)->toDateString()])->json('data');
        $day = $trip['days'][0]['id'];
        $this->actingAs($user)->postJson("/api/v1/trips/{$trip['id']}/items", ['trip_day_id' => $day, 'item_type' => 'accommodation', 'itemable_id' => $room->id, 'nights' => 1]);
        $priced = $this->actingAs($user)->postJson("/api/v1/trips/{$trip['id']}/items", ['trip_day_id' => $day, 'item_type' => 'activity', 'itemable_id' => $activity->id])->json('data');

        $travelers = [['full_name' => 'A One', 'traveler_type' => 'adult'], ['full_name' => 'B Two', 'traveler_type' => 'adult']];
        $res = $this->actingAs($user)->postJson("/api/v1/trips/{$trip['id']}/book", [
            'contact_name' => 'A One', 'contact_email' => 'a@example.test', 'contact_phone' => '+919800000000', 'travelers' => $travelers,
            'accept_terms' => true, 'gateway' => 'sandbox', 'expected_total' => $priced['total_amount'],
        ])->assertCreated()->json('data');
        $this->assertEquals($priced['total_amount'], $res['booking']['total_amount']);
        $this->assertSame('booked', UserTrip::query()->find($trip['id'])->status);
        $this->assertSame(1, (int) $room->availability()->sum('booked_count'), 'Hotel inventory reserved for the trip');

        // Locked: no further edits
        $this->actingAs($user)->postJson("/api/v1/trips/{$trip['id']}/days")->assertStatus(409);

        // A second traveller group can't exceed the activity slot capacity (2)
        $other = $this->makeUser();
        $t2 = $this->actingAs($other)->postJson('/api/v1/trips', ['title' => 'Late', 'days' => 1, 'adults' => 2, 'children' => 0, 'start_date' => now()->addDays(15)->toDateString()])->json('data');
        $after = $this->actingAs($other)->postJson("/api/v1/trips/{$t2['id']}/items", ['trip_day_id' => $t2['days'][0]['id'], 'item_type' => 'activity', 'itemable_id' => $activity->id])->json('data');
        $this->assertFalse($after['days'][0]['items'][0]['is_available']);
        $this->actingAs($other)->postJson("/api/v1/trips/{$t2['id']}/book", [
            'contact_name' => 'Chirag Shah', 'contact_email' => 'c@example.test', 'contact_phone' => '+919800000001', 'travelers' => $travelers, 'accept_terms' => true,
        ])->assertStatus(409)->assertJsonPath('code', 'tour_item_unavailable');
    }

    public function test_guide_unavailable_day_is_flagged(): void
    {
        $user = $this->makeUser();
        [$hotel] = $this->makeHotel();
        $start = now()->next('Monday');
        $guide = TourGuide::query()->create([
            'destination_id' => $hotel->destination_id, 'name' => 'Test Guide', 'slug' => 'test-guide', 'languages' => ['English'], 'hourly_rate' => 1000,
            'available_days' => [2, 3, 4, 5, 6], 'status' => 'active', 'min_hours' => 2, 'max_hours' => 8,
        ]);
        $trip = $this->actingAs($user)->postJson('/api/v1/trips', ['title' => 'Guide day', 'days' => 1, 'adults' => 1, 'children' => 0, 'start_date' => $start->toDateString()])->json('data');
        $res = $this->actingAs($user)->postJson("/api/v1/trips/{$trip['id']}/items", ['trip_day_id' => $trip['days'][0]['id'], 'item_type' => 'guide', 'itemable_id' => $guide->id, 'hours' => 4])->json('data');
        $this->assertFalse($res['days'][0]['items'][0]['is_available']);
        $this->assertEquals(4000, $res['days'][0]['items'][0]['total_price']);
    }
}
