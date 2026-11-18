<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Enums\TripItemType;
use App\Models\Booking;
use App\Models\BookingCancellation;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Review;
use App\Models\Role;
use App\Models\TourActivity;
use App\Models\TourGuide;
use App\Models\TourPackage;
use App\Models\TourTransportation;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Services\Booking\BookingLifecycleService;
use App\Services\Booking\BookingNumber;
use App\Services\Booking\HotelBookingService;
use App\Services\Booking\TourBookingService;
use App\Services\Hotel\InventoryService;
use App\Services\InvoiceService;
use App\Services\Membership\MembershipWalletService;
use App\Services\Payments\GatewayResult;
use App\Services\Payments\PaymentService;
use App\Services\SettingsService;
use App\Services\Tour\TripBuilderService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Users and realistic activity: memberships with ledgers, past & upcoming bookings, payments, refunds, reviews,
 * trips and notifications. Future-dated bookings go through the real booking services (same code path as the
 * app). Past-dated history is written directly (the booking flow correctly refuses past dates) but still
 * reserves inventory and writes the membership ledger through the services.
 */
class DemoDataSeeder extends Seeder
{
    private InventoryService $inventory;
    private MembershipWalletService $wallet;
    private SettingsService $settings;
    private string $password;

    public function run(): void
    {
        $this->inventory = app(InventoryService::class);
        $this->wallet = app(MembershipWalletService::class);
        $this->settings = app(SettingsService::class);
        $this->password = (string) env('SEED_TEST_PASSWORD', 'Password@123');
        mt_srand(20261117);

        $roles = Role::query()->pluck('id', 'slug');

        // ---------------------------------------------------------------- accounts
        $admin = $this->user('StayVoyage Admin', 'test.admin@stayvoyage.test', '+919800000001', [$roles[Role::SUPER_ADMIN]], 'seed/people/p09.jpg');
        $this->user('Kunal Bhatia', 'staff@stayvoyage.test', '+919800000002', [$roles[Role::ADMIN]]);
        $partner = $this->user('Rajendra Singh Chauhan', 'test.partner@stayvoyage.test', '+919800000003', [$roles[Role::HOTEL_PARTNER]]);
        $partner->hotels()->sync([Hotel::query()->where('name', 'Grand Palace Hotel')->value('id')]);
        $customer = $this->user('Ananya Desai', 'test.customer@stayvoyage.test', '+919825012345', [$roles[Role::CUSTOMER]], 'seed/people/p12.jpg');
        $customer->forceFill(['created_at' => now()->subMonths(5)])->saveQuietly();

        $names = ['Rahul Verma', 'Sneha Iyer', 'Aditya Kulkarni', 'Neha Gupta', 'Vikram Joshi', 'Pooja Reddy', 'Karthik Subramanian', 'Ishita Banerjee',
            'Rohit Malhotra', 'Kavya Menon', 'Siddharth Rao', 'Tanvi Shah', 'Manish Agarwal', 'Divya Pillai', 'Nikhil Chauhan', 'Ritu Saxena',
            'Varun Bhatt', 'Meghna Das', 'Aman Khurana', 'Shreya Kulkarni'];
        $avatars = ['Sneha Iyer' => 'p11', 'Neha Gupta' => 'p08', 'Tanvi Shah' => 'p09'];
        $others = collect($names)->map(function ($n, $i) use ($roles, $avatars) {
            $u = $this->user($n, Str::slug($n, '.').'@example.com', '+9198'.str_pad((string) (30000000 + $i * 7919), 8, '0', STR_PAD_LEFT), [$roles[Role::CUSTOMER]],
                isset($avatars[$n]) ? "seed/people/{$avatars[$n]}.jpg" : null);
            $u->forceFill(['created_at' => now()->subDays(20 + $i * 13)])->saveQuietly();

            return $u;
        });

        // ---------------------------------------------------------------- history for other customers
        $hotels = Hotel::query()->with('rooms')->get();
        $monthly = MembershipPlan::query()->where('slug', 'stayvoyage-monthly')->firstOrFail();

        foreach ($others as $i => $u) {
            // Some customers held a membership in the past (expired) and some hold one now.
            $membership = null;
            if ($i % 4 === 0) {
                $start = now()->subDays(40 + $i * 9);
                $membership = $this->membership($u, $monthly, $start);
            } elseif ($i % 5 === 1) {
                $membership = $this->membership($u, $monthly, now()->subDays(5 + $i));
            }

            $count = 4 + ($i % 3);
            for ($b = 0; $b < $count; $b++) {
                $hotel = $hotels[($i * 5 + $b * 7) % $hotels->count()];
                $room = $hotel->rooms->first();
                $useMembership = $membership && $b === 0 && $hotel->is_membership_eligible && $room->is_membership_eligible;
                $checkIn = $useMembership
                    ? CarbonImmutable::parse($membership->starts_at)->addDays(2 + $b)
                    : CarbonImmutable::now()->subDays(25 + (($i * 37 + $b * 53) % 300));
                $nights = 1 + (($i + $b) % 4);
                if ($useMembership && $checkIn->addDays($nights)->isFuture()) {
                    $checkIn = CarbonImmutable::now()->subDays($nights + 1);
                }
                $status = (($i + $b) % 7 === 3) ? 'cancelled' : 'completed';
                $booking = $this->pastHotelBooking($u, $room, $checkIn, $nights, 1, 2, 0, $useMembership ? $membership : null, $status,
                    $checkIn->subDays(5 + ($b * 3) % 20));
                if ($status === 'completed' && ($i + $b) % 5 !== 4) {
                    $this->review($booking, $u, ($i * 3 + $b * 2) % 6);
                }
            }
            if ($membership && $membership->expires_at->isPast()) {
                $this->expire($membership);
            }
        }

        // ---------------------------------------------------------------- the test customer
        $this->testCustomer($customer, $monthly, $hotels);

        $this->upcomingForOthers($others, $hotels);

        foreach (Hotel::all() as $h) {
            $h->refreshAggregates();
        }
        unset($admin);
    }

    private function testCustomer(User $customer, MembershipPlan $monthly, $hotels): void
    {
        $grand = Hotel::query()->where('name', 'Grand Palace Hotel')->firstOrFail();
        $palm = Hotel::query()->where('name', 'Palm Grove Beach Resort')->firstOrFail();
        $haveli = Hotel::query()->where('name', 'Haveli Rajputana')->firstOrFail();
        $marine = Hotel::query()->where('name', 'Marine Drive Grand')->firstOrFail();
        $coral = Hotel::query()->where('name', 'Coral Lagoon Overwater Resort')->firstOrFail();

        // Past regular stay (before membership) with a coupon and an approved review
        $f = $this->pastHotelBooking($customer, $marine->rooms()->where('name', 'Sea View Premier')->firstOrFail(), CarbonImmutable::now()->subDays(75), 2, 1, 2, 0, null, 'completed',
            CarbonImmutable::now()->subDays(90), 'WELCOME10');
        $this->review($f, $customer, 0, 'Sea views worth every rupee', 'We had a corner room on the 9th floor and watched the whole Queen\'s Necklace light up at night. Check-in was quick, the rooftop bar is fantastic and breakfast had excellent Parsi dishes. Only wish the pool was a little bigger.');

        // Active monthly membership: 30 nights, 12 used, 18 remaining (matches the dashboard example)
        $membership = $this->membership($customer, $monthly, now()->subDays(12));

        $a = $this->pastHotelBooking($customer, $grand->rooms()->where('name', 'Deluxe Lake View')->firstOrFail(), CarbonImmutable::now()->subDays(10), 3, 1, 2, 0, $membership, 'completed', CarbonImmutable::now()->subDays(11));
        $this->review($a, $customer, 0, 'Magical lake views and royal service', 'Waking up to the City Palace across Lake Pichola was unforgettable. The complimentary sunset boat ride, the rooftop pool and the Mewari dinner were highlights. Staff remembered our names from day one. Using membership nights made it an incredible deal.');

        $this->pastHotelBooking($customer, $palm->rooms()->where('name', 'Deluxe Garden Room')->firstOrFail(), CarbonImmutable::now()->subDays(6), 5, 1, 2, 0, $membership, 'completed', CarbonImmutable::now()->subDays(9));

        // Future bookings through the real services
        $bookings = app(HotelBookingService::class);
        $payments = app(PaymentService::class);
        $lifecycle = app(BookingLifecycleService::class);
        $contact = ['contact_name' => $customer->name, 'contact_email' => $customer->email, 'contact_phone' => $customer->phone, 'accept_terms' => true];
        $heritage = $haveli->rooms()->where('name', 'Heritage Deluxe Room')->firstOrFail();

        // Membership booking that is then cancelled within the free window → nights returned (ledger: -2, +2)
        $c = $bookings->create($customer, $contact + [
            'hotel_room_id' => $heritage->id, 'check_in' => now()->addDays(12)->toDateString(), 'check_out' => now()->addDays(14)->toDateString(),
            'rooms' => 1, 'adults' => 2, 'children' => 0, 'booking_type' => 'membership', 'gateway' => 'sandbox',
        ]);
        $this->pay($payments, $c['payment']);
        $lifecycle->cancel($c['booking']->fresh(), $customer, 'Change of plans — moving the trip to next month');

        // Upcoming confirmed membership booking (4 nights)
        $b = $bookings->create($customer, $contact + [
            'hotel_room_id' => $heritage->id, 'check_in' => now()->addDays(5)->toDateString(), 'check_out' => now()->addDays(9)->toDateString(),
            'rooms' => 1, 'adults' => 2, 'children' => 0, 'booking_type' => 'membership', 'gateway' => 'sandbox',
            'special_requests' => 'Anniversary trip — a room with a view of the old city would be lovely.',
        ]);
        $this->pay($payments, $b['payment']);

        // Upcoming regular paid booking at a non-member resort
        $g = $bookings->create($customer, $contact + [
            'hotel_room_id' => $coral->rooms()->where('name', 'Overwater Villa')->value('id'), 'check_in' => now()->addDays(40)->toDateString(),
            'check_out' => now()->addDays(43)->toDateString(), 'rooms' => 1, 'adults' => 2, 'children' => 0, 'booking_type' => 'regular', 'gateway' => 'sandbox',
            'guests' => [['full_name' => 'Ananya Desai', 'guest_type' => 'adult'], ['full_name' => 'Kabir Desai', 'guest_type' => 'adult']],
        ]);
        $this->pay($payments, $g['payment']);

        // Upcoming tour booking from the Dubai package
        $builder = app(TripBuilderService::class);
        $trip = $builder->createFromPackage($customer, TourPackage::query()->where('slug', 'dubai-premium-5-day-tour')->firstOrFail(), [
            'start_date' => now()->addDays(25)->toDateString(), 'adults' => 2, 'children' => 0, 'title' => 'Dubai & Abu Dhabi with Kabir',
        ]);
        $h = app(TourBookingService::class)->create($customer, $trip, $contact + [
            'gateway' => 'sandbox',
            'travelers' => [
                ['full_name' => 'Ananya Desai', 'traveler_type' => 'adult', 'age' => 31, 'gender' => 'female', 'nationality' => 'Indian'],
                ['full_name' => 'Kabir Desai', 'traveler_type' => 'adult', 'age' => 33, 'gender' => 'male', 'nationality' => 'Indian'],
            ],
        ]);
        $this->pay($payments, $h['payment']);

        // A saved draft trip built item by item
        $draft = $builder->create($customer, ['title' => 'My Goa Getaway', 'destination_summary' => 'North Goa', 'start_date' => now()->addDays(60)->toDateString(), 'days' => 4, 'adults' => 2]);
        $days = $draft->days()->orderBy('day_number')->get();
        $tr = fn ($n) => TourTransportation::query()->where('name', $n)->value('id');
        $builder->addItem($draft, $days[0], ['item_type' => TripItemType::Flight->value, 'itemable_id' => $tr('Mumbai (BOM) → Goa (GOX), Economy'), 'start_time' => '07:00']);
        $builder->addItem($draft, $days[0], ['item_type' => TripItemType::AirportTransfer->value, 'itemable_id' => $tr('Goa Airport (GOX) ↔ North Goa Hotel Transfer')]);
        $builder->addItem($draft, $days[0], ['item_type' => TripItemType::Accommodation->value, 'itemable_id' => $palm->rooms()->where('name', 'Premium Pool View')->value('id'), 'nights' => 3, 'meal_plan' => 'breakfast']);
        $builder->addItem($draft, $days[1], ['item_type' => TripItemType::Activity->value, 'itemable_id' => TourActivity::query()->where('name', 'Scuba Diving at Grande Island')->value('id')]);
        $builder->addItem($draft, $days[2], ['item_type' => TripItemType::Activity->value, 'itemable_id' => TourActivity::query()->where('name', 'Old Goa Heritage & Fontainhas Walk')->value('id')]);
        $builder->addItem($draft, $days[2], ['item_type' => TripItemType::Guide->value, 'itemable_id' => TourGuide::query()->where('name', 'Rohan D\'Souza')->value('id'), 'hours' => 4]);
        $builder->addItem($draft, $days[3], ['item_type' => TripItemType::Other->value, 'title' => 'Beach morning at Candolim & souvenir shopping', 'start_time' => '09:00']);
        $builder->addItem($draft, $days[3], ['item_type' => TripItemType::Flight->value, 'itemable_id' => $tr('Goa (GOX) → Mumbai (BOM), Economy'), 'start_time' => '21:00']);
        $builder->setSharing($draft->fresh(), 'public');

        $customer->savedHotels()->syncWithoutDetaching([$coral->id, $haveli->id, Hotel::query()->where('name', 'Ubud Rainforest Villas')->value('id')]);
        $customer->notify(new WelcomeNotification());
    }

    private function upcomingForOthers($others, $hotels): void
    {
        $bookings = app(HotelBookingService::class);
        $payments = app(PaymentService::class);
        foreach ($others->take(8) as $i => $u) {
            $hotel = $hotels[($i * 7 + 2) % $hotels->count()];
            $room = $hotel->rooms->first();
            $in = now()->addDays(3 + $i * 4);
            $r = $bookings->create($u, [
                'hotel_room_id' => $room->id, 'check_in' => $in->toDateString(), 'check_out' => $in->copy()->addDays(2 + $i % 3)->toDateString(),
                'rooms' => 1, 'adults' => min(2, $room->max_adults), 'children' => 0, 'booking_type' => 'regular', 'gateway' => 'sandbox',
                'contact_name' => $u->name, 'contact_email' => $u->email, 'contact_phone' => $u->phone,
            ]);
            if ($i !== 7) {
                $this->pay($payments, $r['payment']);
            } // last one stays payment_pending (hold) to show in admin
        }
    }

    // ==================================================================== helpers

    private function user(string $name, string $email, string $phone, array $roleIds, ?string $avatar = null): User
    {
        $u = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'phone' => $phone, 'password' => $this->password, 'status' => 'active', 'avatar_path' => $avatar]);
        $u->forceFill(['email_verified_at' => now()->subMonths(3)])->saveQuietly();
        $u->roles()->sync($roleIds);

        return $u;
    }

    private function membership(User $u, MembershipPlan $plan, $start): Membership
    {
        $start = CarbonImmutable::parse($start);
        $price = $plan->price;
        $tax = Money::percent($price, $this->settings->float('pricing.membership_tax_percent'));
        $total = Money::round($price + $tax);

        $m = new Membership([
            'membership_number' => 'SVM-'.$start->format('ym').'-'.strtoupper(Str::random(6)),
            'user_id' => $u->id, 'membership_plan_id' => $plan->id, 'total_nights' => $plan->included_nights,
            'price_paid' => $total, 'currency' => 'INR', 'auto_renew' => false,
            'plan_snapshot' => array_merge($plan->toSnapshot(), ['quote' => ['price' => $price, 'discount_amount' => 0, 'tax_amount' => $tax, 'total_amount' => $total]]),
            'starts_at' => $start, 'expires_at' => $start->addDays($plan->duration_days)->endOfDay(), 'activated_at' => $start,
        ]);
        $m->forceFill(['status' => MembershipStatus::Active, 'nights_balance' => 0, 'created_at' => $start])->save();

        $p = $this->paidPayment($u, $m, $total, 'membership', $start);
        $tx = $this->wallet->creditPurchase($m);
        DB::table('membership_transactions')->where('id', $tx->id)->update(['created_at' => $start]);
        app(InvoiceService::class)->forMembership($m->fresh());
        unset($p);

        return $m->fresh();
    }

    private function expire(Membership $m): void
    {
        $tx = $this->wallet->expireRemaining($m);
        if ($tx) {
            DB::table('membership_transactions')->where('id', $tx->id)->update(['created_at' => $m->expires_at]);
        }
        $m->forceFill(['status' => MembershipStatus::Expired, 'expired_at' => $m->expires_at])->save();
    }

    private function pastHotelBooking(User $u, HotelRoom $room, CarbonImmutable $checkIn, int $nights, int $rooms, int $adults, int $children,
        ?Membership $membership, string $status, CarbonImmutable $createdAt, ?string $couponCode = null): Booking
    {
        $hotel = $room->hotel;
        $checkOut = $checkIn->addDays($nights);
        $rates = $this->inventory->nightlyRates($room, $checkIn->toDateString(), $checkOut->toDateString());
        $roomAmount = Money::round(array_sum($rates) * $rooms);
        $taxPct = $this->settings->float('pricing.hotel_tax_percent');

        if ($membership) {
            $covered = $roomAmount;
            $subtotal = 0.0;
            $discount = 0.0;
            $tax = Money::percent($roomAmount, $taxPct);
            $fee = Money::round($this->settings->float('pricing.membership_booking_service_fee'));
        } else {
            $covered = 0.0;
            $subtotal = $roomAmount;
            $coupon = $couponCode ? Coupon::query()->where('code', $couponCode)->first() : null;
            $discount = $coupon ? min($coupon->max_discount ?? INF, Money::percent($roomAmount, $coupon->discount_value)) : 0.0;
            $after = $roomAmount - $discount;
            $tax = Money::percent($after, $taxPct);
            $fee = Money::round(max($this->settings->float('pricing.hotel_service_fee_min'), Money::percent($after, $this->settings->float('pricing.hotel_service_fee_percent'))));
        }
        $total = Money::round($subtotal - $discount + $tax + $fee);

        return DB::transaction(function () use ($u, $room, $hotel, $checkIn, $checkOut, $nights, $rooms, $adults, $children, $membership, $status, $createdAt,
            $rates, $roomAmount, $covered, $subtotal, $discount, $tax, $fee, $total, $couponCode) {
            $booking = new Booking();
            $booking->forceFill([
                'booking_number' => BookingNumber::make('H'), 'user_id' => $u->id, 'type' => 'hotel',
                'booking_type' => $membership ? 'membership' : 'regular', 'hotel_id' => $hotel->id, 'membership_id' => $membership?->id,
                'check_in' => $checkIn->toDateString(), 'check_out' => $checkOut->toDateString(), 'nights' => $nights, 'rooms_count' => $rooms,
                'adults' => $adults, 'children' => $children, 'status' => BookingStatus::Confirmed, 'payment_status' => PaymentStatus::Paid,
                'currency' => 'INR', 'room_amount' => $roomAmount, 'membership_nights_used' => $membership ? $nights * $rooms : 0,
                'membership_covered_amount' => $covered, 'subtotal' => $subtotal, 'discount_amount' => $discount, 'coupon_code' => $discount > 0 ? $couponCode : null,
                'coupon_id' => $discount > 0 ? Coupon::query()->where('code', $couponCode)->value('id') : null,
                'tax_amount' => $tax, 'service_fee' => $fee, 'total_amount' => $total, 'amount_paid' => $total,
                'price_breakdown' => ['nightly_rates' => $rates, 'tax_percent' => $this->settings->float('pricing.hotel_tax_percent')],
                'contact_name' => $u->name, 'contact_email' => $u->email, 'contact_phone' => (string) $u->phone,
                'confirmed_at' => $createdAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
            ])->save();
            $line = $booking->rooms()->create([
                'hotel_id' => $hotel->id, 'hotel_room_id' => $room->id, 'check_in' => $checkIn->toDateString(), 'check_out' => $checkOut->toDateString(),
                'quantity' => $rooms, 'nights' => $nights, 'nightly_rates' => $rates, 'amount' => $roomAmount,
            ]);
            $booking->guests()->create(['full_name' => $u->name, 'guest_type' => 'adult', 'email' => $u->email, 'is_primary' => true]);

            $this->inventory->reserve($room, $checkIn->toDateString(), $checkOut->toDateString(), $rooms);
            if ($membership) {
                $tx = $this->wallet->deductForBooking($membership, $booking, $nights * $rooms);
                DB::table('membership_transactions')->where('id', $tx->id)->update(['created_at' => $createdAt]);
            }
            if ($discount > 0) {
                CouponUsage::query()->create(['coupon_id' => $booking->coupon_id, 'user_id' => $u->id, 'booking_id' => $booking->id, 'discount_amount' => $discount, 'status' => 'applied']);
                Coupon::query()->whereKey($booking->coupon_id)->increment('used_count');
            }
            $payment = $this->paidPayment($u, $booking, $total, 'booking', $createdAt);

            if ($status === 'cancelled') {
                $cancelledAt = $createdAt->addDays(2);
                $this->inventory->release($line);
                $returned = 0;
                if ($membership) {
                    $tx = $this->wallet->refundForBooking($booking, 'booking cancelled');
                    if ($tx) {
                        DB::table('membership_transactions')->where('id', $tx->id)->update(['created_at' => $cancelledAt]);
                        $returned = $tx->nights;
                    }
                }
                BookingCancellation::query()->create([
                    'booking_id' => $booking->id, 'cancelled_by' => $u->id, 'cancelled_by_role' => 'customer', 'reason' => 'Plans changed',
                    'within_free_window' => true, 'refund_amount' => $total, 'nights_returned' => $returned, 'created_at' => $cancelledAt,
                ]);
                Refund::query()->create([
                    'reference' => 'RFD-'.strtoupper(Str::random(12)), 'payment_id' => $payment->id, 'booking_id' => $booking->id, 'user_id' => $u->id,
                    'amount' => $total, 'status' => 'processed', 'gateway_refund_id' => 'sbx_rfnd_'.Str::lower(Str::random(14)), 'reason' => 'Booking cancelled',
                    'processed_at' => $cancelledAt, 'created_at' => $cancelledAt,
                ]);
                $payment->forceFill(['status' => PaymentStatus::Refunded, 'amount_refunded' => $total])->save();
                $booking->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_at' => $cancelledAt, 'payment_status' => PaymentStatus::Refunded, 'amount_refunded' => $total])->save();
            } elseif ($status === 'completed') {
                $booking->forceFill(['status' => BookingStatus::Completed, 'completed_at' => $checkOut])->save();
            }
            app(InvoiceService::class)->forBooking($booking->fresh());

            return $booking->fresh();
        });
    }

    private function paidPayment(User $u, $payable, float $amount, string $purpose, $at): Payment
    {
        return Payment::query()->create([
            'reference' => 'PAY-'.strtoupper(Str::random(12)), 'user_id' => $u->id, 'payable_type' => $payable->getMorphClass(), 'payable_id' => $payable->id,
            'purpose' => $purpose, 'gateway' => 'sandbox', 'gateway_order_id' => 'sbx_order_'.Str::lower(Str::random(16)),
            'gateway_payment_id' => 'sbx_pay_'.Str::lower(Str::random(16)), 'amount' => $amount, 'currency' => 'INR',
            'method' => ['upi', 'card', 'netbanking', 'card'][mt_rand(0, 3)], 'status' => PaymentStatus::Paid, 'paid_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** Completes a seeded checkout through the real, idempotent payment confirmation path. */
    private function pay(PaymentService $payments, ?Payment $payment): void
    {
        if (! $payment) {
            return;
        }
        $payment->forceFill(['gateway_order_id' => 'sbx_order_'.Str::lower(Str::random(16))])->save();
        $payments->markPaid($payment, GatewayResult::paid('sbx_pay_'.Str::lower(Str::random(16)), 'upi', Money::toMinor($payment->amount), ['seeded' => true]));
    }

    private function review(Booking $booking, User $u, int $variant, ?string $title = null, ?string $comment = null): void
    {
        $hotel = $booking->hotel;
        $templates = [
            [5, 5, 5, 5, 4, 'Perfect stay from start to finish', "Everything about {$hotel->name} was spot on — spotless room, thoughtful staff and a breakfast spread we still talk about. The location made exploring {$hotel->city} effortless."],
            [5, 5, 4, 5, 5, 'Great value with lovely touches', "We booked last minute and were upgraded on arrival. Little touches like the welcome drink, turndown service and late checkout made the stay feel special."],
            [4, 4, 5, 4, 4, 'Excellent location, comfortable rooms', "Couldn't fault the location — walking distance to the main sights. Rooms were comfortable and quiet; the Wi-Fi was a bit slow in the evenings but otherwise a very good stay."],
            [4, 5, 4, 4, 3, 'Beautiful property, slightly pricey food', "The property is gorgeous and very well maintained. The pool area was our favourite spot. Restaurant food was tasty but on the expensive side — plenty of good options nearby though."],
            [5, 5, 5, 5, 5, 'Our new favourite in '.$hotel->city, "Third time staying here and it keeps getting better. The team remembers your preferences and the rooms are immaculate. Highly recommend booking through StayVoyage membership."],
            [3, 3, 4, 3, 3, 'Good location but needs a refresh', "The staff were polite and helpful, and the location is convenient. The bathroom fittings are showing their age and housekeeping was inconsistent. Fine for a short stay."],
        ];
        [$overall, $clean, $loc, $service, $value, $t, $c] = $templates[$variant % count($templates)];
        $review = new Review([
            'overall' => $overall, 'cleanliness' => $clean, 'location' => $loc, 'service' => $service, 'value' => $value,
            'title' => $title ?? $t, 'comment' => $comment ?? $c, 'images' => [],
        ]);
        $review->user_id = $u->id;
        $review->booking_id = $booking->id;
        $review->hotel_id = $booking->hotel_id;
        $review->forceFill(['status' => 'approved', 'moderated_at' => now(), 'created_at' => $booking->check_out->copy()->addDays(2)])->save();
    }
}
