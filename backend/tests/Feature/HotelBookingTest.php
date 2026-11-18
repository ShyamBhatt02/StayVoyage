<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\HotelRoomAvailability;
use App\Models\Payment;
use App\Services\Payments\Gateways\SandboxGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Critical scenarios 13–18: inventory under contention and payment-driven confirmation. */
class HotelBookingTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_two_users_competing_for_the_last_room_only_one_succeeds(): void
    {
        [, $room] = $this->makeHotel([], 1);
        $a = $this->makeUser();
        $b = $this->makeUser();
        $payload = $this->bookingPayload($room, 7, 2);

        $this->actingAs($a)->postJson('/api/v1/bookings/hotel', $payload)->assertCreated();
        $this->actingAs($b)->postJson('/api/v1/bookings/hotel', $payload)->assertStatus(409)->assertJsonPath('code', 'room_unavailable');

        $this->assertSame(1, Booking::query()->count());
        $this->assertSame(1, (int) HotelRoomAvailability::query()->max('booked_count'));
    }

    public function test_database_constraint_prevents_overselling_even_if_code_is_bypassed(): void
    {
        [, $room] = $this->makeHotel([], 2);
        HotelRoomAvailability::query()->create(['hotel_room_id' => $room->id, 'date' => now()->addDays(3)->toDateString(), 'total_inventory' => 2, 'booked_count' => 2]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('hotel_room_availability')->update(['booked_count' => 3]);
    }

    public function test_failed_payment_leaves_booking_unconfirmed_and_success_confirms_it(): void
    {
        [, $room] = $this->makeHotel([], 5, 6500);
        $user = $this->makeUser();
        $res = $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 8, 2))->assertCreated()->json('data');
        $ref = $res['checkout']['reference'];
        $number = $res['booking']['booking_number'];

        // Server computed the price (13,000 room + 12% GST + 2.5% fee)
        $this->assertEquals(13000 + 1560 + 325, $res['booking']['total_amount']);

        $this->actingAs($user)->postJson("/api/v1/payments/{$ref}/sandbox-complete", ['outcome' => 'failure', 'method' => 'card'])->assertOk();
        $this->assertSame(BookingStatus::PaymentPending, Booking::query()->where('booking_number', $number)->first()->status);
        $this->assertSame('failed', Payment::query()->where('reference', $ref)->first()->status->value);

        // Retry with a fresh payment, then succeed
        $checkout = $this->actingAs($user)->postJson("/api/v1/bookings/{$number}/pay", [])->assertOk()->json('data.checkout');
        $this->actingAs($user)->postJson("/api/v1/payments/{$checkout['reference']}/sandbox-complete", ['outcome' => 'success', 'method' => 'upi'])->assertOk();
        $booking = Booking::query()->where('booking_number', $number)->first();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertEquals($booking->total_amount, $booking->amount_paid);
        $this->assertNotNull($booking->invoice()->first(), 'Invoice generated on confirmation');
    }

    public function test_client_cannot_confirm_payment_without_a_valid_gateway_signature(): void
    {
        [, $room] = $this->makeHotel();
        $user = $this->makeUser();
        $ref = $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 8, 1))->json('data.checkout.reference');
        $payment = Payment::query()->where('reference', $ref)->first();
        $event = app(SandboxGateway::class)->buildSignedEvent($payment, 'success', 'card');

        // Forged signature → rejected
        $this->call('POST', '/api/v1/webhooks/sandbox', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SANDBOX_SIGNATURE' => 'forged'], $event['body'])->assertStatus(400);
        $this->assertSame('pending', $payment->fresh()->status->value);

        // Tampered body (amount changed) with the old signature → rejected
        $tampered = str_replace((string) json_decode($event['body'], true)['data']['amount'], '100', $event['body']);
        $this->call('POST', '/api/v1/webhooks/sandbox', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SANDBOX_SIGNATURE' => $event['signature']], $tampered)->assertStatus(400);

        // Genuine signed webhook → confirmed; replay is idempotent
        $this->call('POST', '/api/v1/webhooks/sandbox', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SANDBOX_SIGNATURE' => $event['signature']], $event['body'])->assertOk()->assertJsonPath('data.status', 'processed');
        $this->call('POST', '/api/v1/webhooks/sandbox', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SANDBOX_SIGNATURE' => $event['signature']], $event['body'])->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertSame(BookingStatus::Confirmed, Booking::query()->first()->status);
    }

    public function test_client_supplied_prices_are_ignored(): void
    {
        [, $room] = $this->makeHotel([], 5, 8000);
        $user = $this->makeUser();
        $payload = $this->bookingPayload($room, 9, 1, 'regular', ['total_amount' => 1, 'room_amount' => 1, 'tax_amount' => 0, 'discount_amount' => 99999]);
        $booking = $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $payload)->assertCreated()->json('data.booking');
        $this->assertEquals(8000, $booking['room_amount']);
        $this->assertEquals(0, $booking['discount_amount']);
        $this->assertGreaterThan(8000, $booking['total_amount']);

        // A stale expected_total forces the customer to re-confirm instead of charging a different amount
        $this->actingAs($user)->postJson('/api/v1/bookings/hotel', array_merge($this->bookingPayload($room, 12, 1), ['expected_total' => 10]))
            ->assertStatus(409)->assertJsonPath('code', 'price_changed');
    }

    public function test_cancellation_releases_inventory_and_refunds(): void
    {
        [, $room] = $this->makeHotel([], 1, 5000);
        $user = $this->makeUser();
        $res = $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 10, 2))->json('data');
        $this->actingAs($user)->postJson("/api/v1/payments/{$res['checkout']['reference']}/sandbox-complete", ['outcome' => 'success', 'method' => 'card']);
        $number = $res['booking']['booking_number'];

        $preview = $this->actingAs($user)->getJson("/api/v1/bookings/{$number}/cancellation-preview")->assertOk()->json('data');
        $this->assertTrue($preview['within_free_window']);
        $this->actingAs($user)->postJson("/api/v1/bookings/{$number}/cancel")->assertOk();

        $booking = Booking::query()->where('booking_number', $number)->first();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertEquals($booking->amount_paid, $booking->amount_refunded);
        $this->assertSame('refunded', $booking->payment_status->value);
        $this->assertSame(0, (int) HotelRoomAvailability::query()->sum('booked_count'));
        // Room is bookable again
        $this->actingAs($this->makeUser())->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 10, 2))->assertCreated();
    }

    public function test_search_hides_sold_out_hotels_for_dates(): void
    {
        [$hotel, $room] = $this->makeHotel([], 1);
        $user = $this->makeUser();
        $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 6, 2))->assertCreated();
        $in = now()->addDays(6)->toDateString();
        $out = now()->addDays(8)->toDateString();
        $ids = collect($this->getJson("/api/v1/hotels?check_in={$in}&check_out={$out}&guests=2&rooms=1")->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($hotel->id));
        $ids = collect($this->getJson('/api/v1/hotels')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($hotel->id));
    }
}
