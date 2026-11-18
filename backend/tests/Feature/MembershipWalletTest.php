<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Services\Membership\MembershipWalletService;
use App\Services\Payments\GatewayResult;
use App\Services\Payments\PaymentService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Critical scenarios 1–12: membership purchase, ledger deductions, refunds, limits and expiry. */
class MembershipWalletTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function payFor(array $response): void
    {
        $payment = Payment::query()->where('reference', $response['data']['checkout']['reference'])->firstOrFail();
        $payment->forceFill(['gateway_order_id' => 'sbx_order_test'])->save();
        app(PaymentService::class)->markPaid($payment, GatewayResult::paid('sbx_pay_'.uniqid(), 'card', Money::toMinor($payment->amount)));
    }

    public function test_purchase_credits_30_nights_only_after_verified_payment(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();

        $res = $this->actingAs($user)->postJson('/api/v1/membership/purchase', [
            'membership_plan_id' => $plan->id, 'gateway' => 'sandbox', 'accept_terms' => true,
        ])->assertCreated()->json();

        $membership = $user->memberships()->first();
        $this->assertSame('pending', $membership->status->value);
        $this->assertSame(0, $membership->nights_balance, 'No nights before payment is verified');

        $this->payFor($res);
        $membership->refresh();
        $this->assertSame('active', $membership->status->value);
        $this->assertSame(30, $membership->nights_balance);
        $this->assertDatabaseHas('membership_transactions', ['membership_id' => $membership->id, 'type' => 'membership_purchase', 'nights' => 30, 'balance_after' => 30]);
    }

    public function test_bookings_deduct_cancellation_returns_and_ledger_matches(): void
    {
        $user = $this->makeUser();
        $membership = $this->activeMembership($user);
        [, $room] = $this->makeHotel();

        // Book 3 nights → 27
        $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 5, 3, 'membership'))->assertCreated();
        $this->assertSame(27, $membership->fresh()->nights_balance);

        // Book 5 nights → 22
        $second = $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 10, 5, 'membership'))->assertCreated()->json();
        $this->assertSame(22, $membership->fresh()->nights_balance);
        $this->payFor($second);

        // Cancel eligible booking → nights returned (27)
        $number = $second['data']['booking']['booking_number'];
        $this->actingAs($user)->postJson("/api/v1/bookings/{$number}/cancel", ['reason' => 'Plans changed'])->assertOk();
        $this->assertSame(27, $membership->fresh()->nights_balance);

        // Cancelling again is rejected and never returns nights twice
        $this->actingAs($user)->postJson("/api/v1/bookings/{$number}/cancel")->assertStatus(409);
        $booking = Booking::query()->where('booking_number', $number)->first();
        app(MembershipWalletService::class)->refundForBooking($booking, 'replay');
        $this->assertSame(27, $membership->fresh()->nights_balance);
        $this->assertSame(1, MembershipTransaction::query()->where('booking_id', $booking->id)->where('type', 'booking_cancellation_refund')->count());

        $this->assertSame((int) MembershipTransaction::query()->where('membership_id', $membership->id)->sum('nights'), $membership->fresh()->nights_balance);
    }

    public function test_using_more_nights_than_available_is_rejected(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan(['max_nights_per_booking' => 30, 'included_nights' => 5]);
        $membership = $this->activeMembership($user, $plan);
        [, $room] = $this->makeHotel();

        $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 3, 6, 'membership'))
            ->assertStatus(422)->assertJsonPath('code', 'insufficient_membership_nights');

        $this->assertSame(5, $membership->fresh()->nights_balance);
        $this->assertSame(0, Booking::query()->count(), 'No booking is created');
    }

    public function test_expired_membership_cannot_be_used(): void
    {
        $user = $this->makeUser();
        $membership = $this->activeMembership($user, null, 40, 30); // expired 10 days ago
        [, $room] = $this->makeHotel();

        $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 3, 2, 'membership'))
            ->assertStatus(422)->assertJsonPath('code', 'membership_expired');
        $this->assertSame(30, $membership->fresh()->nights_balance);
    }

    public function test_membership_rules_are_enforced_server_side(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $plan->blackoutDates()->create(['start_date' => now()->addDays(20), 'end_date' => now()->addDays(22), 'reason' => 'Peak']);
        $this->activeMembership($user, $plan);
        [, $room] = $this->makeHotel();
        [, $nonMemberRoom] = $this->makeHotel([], 10, 5000, false);

        $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 3, 8, 'membership'))->assertStatus(422)->assertJsonPath('code', 'membership_rule_violation');
        $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 19, 2, 'membership'))->assertStatus(422)->assertJsonPath('code', 'membership_blackout');
        $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($nonMemberRoom, 3, 2, 'membership'))->assertStatus(422)->assertJsonPath('code', 'membership_not_eligible');
        $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 3, 2, 'membership', ['adults' => 3, 'rooms' => 1]))->assertStatus(422);
    }

    public function test_membership_booking_price_is_taxes_and_fee_only(): void
    {
        $user = $this->makeUser();
        $this->activeMembership($user);
        [, $room] = $this->makeHotel([], 10, 5000);

        $q = $this->actingAs($user)->postJson('/api/v1/hotels/quote', [
            'hotel_room_id' => $room->id, 'check_in' => now()->next('Monday')->toDateString(), 'check_out' => now()->next('Monday')->addDays(4)->toDateString(),
            'rooms' => 1, 'adults' => 2, 'booking_type' => 'membership',
        ])->assertOk()->json('data');

        $this->assertEquals(20000, $q['room_amount']);
        $this->assertEquals(20000, $q['membership_covered_amount']);
        $this->assertEquals(2400, $q['tax_amount']);   // 12% GST on covered amount
        $this->assertEquals(500, $q['service_fee']);
        $this->assertEquals(2900, $q['total_amount']);
        $this->assertSame(4, $q['membership_nights_used']);
    }

    public function test_balance_can_never_go_negative_even_at_database_level(): void
    {
        $user = $this->makeUser();
        $membership = $this->activeMembership($user);
        $this->expectException(\Illuminate\Database\QueryException::class);
        $membership->forceFill(['nights_balance' => -1])->save();
    }

    public function test_ledger_rows_are_immutable(): void
    {
        $membership = $this->activeMembership($this->makeUser());
        $tx = MembershipTransaction::query()->where('membership_id', $membership->id)->first();
        $this->expectException(\LogicException::class);
        $tx->update(['nights' => 100]);
    }

    public function test_unpaid_hold_expiry_returns_nights_and_inventory(): void
    {
        $user = $this->makeUser();
        $membership = $this->activeMembership($user);
        [, $room] = $this->makeHotel([], 1);
        $this->actingAs($user)->postJson('/api/v1/bookings/hotel', $this->bookingPayload($room, 3, 2, 'membership'))->assertCreated();
        $this->assertSame(28, $membership->fresh()->nights_balance);

        Booking::query()->update(['hold_expires_at' => now()->subHour()]);
        $this->artisan('bookings:expire-holds')->assertSuccessful();

        $this->assertSame(BookingStatus::Failed, Booking::query()->first()->status);
        $this->assertSame(30, $membership->fresh()->nights_balance);
        $this->assertSame(0, (int) $room->availability()->sum('booked_count'));
    }
}
