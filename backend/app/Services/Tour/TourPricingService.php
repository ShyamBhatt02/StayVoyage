<?php

namespace App\Services\Tour;

use App\Enums\TripItemType;
use App\Models\HotelRoom;
use App\Models\TourActivity;
use App\Models\TourGuide;
use App\Models\TourMeal;
use App\Models\TourTransportation;
use App\Models\TripItem;
use App\Models\UserTrip;
use App\Services\Hotel\InventoryService;
use App\Services\SettingsService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

/**
 * Server-side tour pricing engine. Every itinerary mutation calls reprice(); the stored totals are a cache
 * of this calculation and the booking flow recalculates again under lock before charging.
 *
 *   Transportation + Accommodation + Activities + Guides + Meals + Transfers + Other
 *   + Taxes + Service fees − Discounts = Final total
 */
class TourPricingService
{
    /** Per-person-per-night supplements for hotel meal plans (configurable via settings `tours.meal_plan_rates`). */
    public const DEFAULT_MEAL_PLAN_RATES = ['room_only' => 0, 'breakfast' => 650, 'half_board' => 1450, 'full_board' => 2300];

    public const GROUPS = ['transportation', 'accommodation', 'activities', 'guides', 'meals', 'transfers', 'other'];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SettingsService $settings,
    ) {}

    /** Recalculate every item and persist the totals on the trip. */
    public function reprice(UserTrip $trip): UserTrip
    {
        $trip->load(['days.items' => fn ($q) => $q->with(['itemable' => fn (MorphTo $m) => $m->morphWith([
            HotelRoom::class => ['hotel', 'seasonalPrices'],
        ])])]);
        $groups = array_fill_keys(self::GROUPS, 0.0);

        foreach ($trip->days as $day) {
            $date = $trip->start_date ? CarbonImmutable::parse($trip->start_date)->addDays($day->day_number - 1) : null;
            foreach ($day->items as $item) {
                [$unit, $total, $available, $note] = $this->priceItem($trip, $item, $date);
                $item->forceFill([
                    'unit_price' => $unit,
                    'total_price' => $total,
                    'is_available' => $available,
                    'availability_note' => $note,
                ])->saveQuietly();
                $groups[$item->item_type->group()] += $total;
            }
        }

        $groups = array_map([Money::class, 'round'], $groups);
        $subtotal = Money::round(array_sum($groups));
        $tax = Money::percent($subtotal, $this->settings->float('pricing.tour_tax_percent'));
        $fee = Money::percent($subtotal, $this->settings->float('pricing.tour_service_fee_percent'));

        $trip->forceFill([
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'service_fee' => $fee,
            'total_amount' => Money::round($subtotal + $tax + $fee),
            'price_breakdown' => [
                'groups' => $groups,
                'tax_percent' => $this->settings->float('pricing.tour_tax_percent'),
                'service_fee_percent' => $this->settings->float('pricing.tour_service_fee_percent'),
                'travelers' => $trip->travelerCount(),
                'per_person' => Money::round(($subtotal + $tax + $fee) / max(1, $trip->travelerCount())),
                'unavailable_items' => $trip->days->flatMap->items->where('is_available', false)->count(),
            ],
            'priced_at' => now(),
        ])->save();

        return $trip;
    }

    /** Totals with an optional coupon discount — used at checkout. */
    public function totalsWithDiscount(UserTrip $trip, float $discount): array
    {
        $subtotal = $trip->subtotal;
        $discount = Money::round(min($discount, $subtotal));
        $taxable = Money::round($subtotal - $discount);
        $tax = Money::percent($taxable, $this->settings->float('pricing.tour_tax_percent'));
        $fee = Money::percent($taxable, $this->settings->float('pricing.tour_service_fee_percent'));

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'service_fee' => $fee,
            'total_amount' => Money::round($taxable + $tax + $fee),
        ];
    }

    /** @return array{0:float,1:float,2:bool,3:?string} unit, total, available, note */
    public function priceItem(UserTrip $trip, TripItem $item, ?CarbonImmutable $date): array
    {
        $travelers = $trip->travelerCount();
        $catalogue = $item->itemable;

        if (! $catalogue && $item->itemable_type) {
            return [0.0, 0.0, false, 'This service is no longer offered. Please remove it or choose an alternative.'];
        }

        return match (true) {
            $catalogue instanceof HotelRoom => $this->priceAccommodation($trip, $item, $catalogue, $date),
            $catalogue instanceof TourTransportation => $this->priceTransport($item, $catalogue, $travelers),
            $catalogue instanceof TourActivity => $this->priceActivity($trip, $item, $catalogue, $date),
            $catalogue instanceof TourGuide => $this->priceGuide($trip, $item, $catalogue, $date),
            $catalogue instanceof TourMeal => $this->priceMeal($item, $catalogue, $travelers),
            default => [0.0, 0.0, true, null], // free-form note / leisure time — never client-priced
        };
    }

    private function priceAccommodation(UserTrip $trip, TripItem $item, HotelRoom $room, ?CarbonImmutable $date): array
    {
        $nights = max(1, (int) ($item->nights ?: 1));
        $rooms = max(1, (int) ($item->quantity ?: 1));
        $guests = $trip->travelerCount();
        $mealRates = (array) $this->settings->get('tours.meal_plan_rates', self::DEFAULT_MEAL_PLAN_RATES);
        $mealPlan = $item->meal_plan ?: ($room->breakfast_included ? 'breakfast' : 'room_only');
        $mealPerNight = ($mealPlan === 'breakfast' && $room->breakfast_included) ? 0 : (float) ($mealRates[$mealPlan] ?? 0);

        $available = true;
        $note = null;
        if ($room->status !== 'active' || $room->hotel?->status !== 'active') {
            return [0.0, 0.0, false, 'This hotel room is no longer available.'];
        }
        if ($guests > $room->max_guests * $rooms) {
            $available = false;
            $note = "{$room->name} sleeps {$room->max_guests} per room — increase rooms for {$guests} travellers.";
        }

        if ($date) {
            $in = $date->toDateString();
            $out = $date->addDays($nights)->toDateString();
            $rates = $this->inventory->nightlyRates($room, $in, $out);
            $free = $this->inventory->availableForRooms([$room->id], $in, $out)[$room->id] ?? 0;
            if ($free < $rooms) {
                $available = false;
                $note = $free > 0 ? "Only {$free} room(s) left for these dates." : 'Sold out for these dates — try another room or hotel.';
            }
            $roomTotal = array_sum($rates) * $rooms;
        } else {
            $roomTotal = $room->base_price * $nights * $rooms;
        }

        $total = Money::round($roomTotal + $mealPerNight * $guests * $nights);

        return [Money::round($total / ($nights * $rooms)), $total, $available, $note];
    }

    private function priceTransport(TripItem $item, TourTransportation $t, int $travelers): array
    {
        if ($t->status !== 'active') {
            return [0.0, 0.0, false, 'This transport option is no longer offered.'];
        }
        $qty = match ($t->pricing_unit) {
            'per_vehicle' => max((int) $item->quantity, (int) ceil($travelers / max(1, $t->capacity))),
            'per_day' => max(1, (int) $item->quantity),
            default => max(1, (int) ($item->quantity ?: $travelers)),
        };
        $note = null;
        $available = true;
        if ($t->pricing_unit === 'per_vehicle' && $qty * $t->capacity < $travelers) {
            $available = false;
            $note = 'Not enough seats for your group.';
        }
        if ($t->pricing_unit === 'per_day' && $travelers > $t->capacity) {
            $note = "Seats {$t->capacity}; consider adding another vehicle.";
        }

        return [Money::round($t->price), Money::round($t->price * $qty), $available, $note];
    }

    private function priceActivity(UserTrip $trip, TripItem $item, TourActivity $a, ?CarbonImmutable $date): array
    {
        if ($a->status !== 'active') {
            return [0.0, 0.0, false, 'This activity is no longer offered.'];
        }
        $tickets = max(1, (int) ($item->quantity ?: $trip->travelerCount()));
        $childTickets = min((int) $trip->children, $tickets);
        $adultTickets = $tickets - $childTickets;
        $total = $adultTickets * $a->price + $childTickets * ($a->child_price ?? $a->price);

        $available = true;
        $note = null;
        if ($date) {
            if (is_array($a->available_days) && $a->available_days && ! in_array($date->dayOfWeek, $a->available_days, true)) {
                $available = false;
                $note = 'Not operating on '.$date->format('l').'s.';
            } else {
                $booked = $this->bookedActivitySeats($a->id, $date->toDateString(), $item->start_time, $trip->id);
                if ($booked + $tickets > $a->capacity_per_slot) {
                    $available = false;
                    $left = max(0, $a->capacity_per_slot - $booked);
                    $note = $left > 0 ? "Only {$left} spot(s) left in this time slot." : 'This time slot is full — choose another time.';
                }
            }
        }

        return [Money::round($a->price), Money::round($total), $available, $note];
    }

    private function priceGuide(UserTrip $trip, TripItem $item, TourGuide $g, ?CarbonImmutable $date): array
    {
        if ($g->status !== 'active') {
            return [0.0, 0.0, false, 'This guide is no longer available.'];
        }
        $hours = min($g->max_hours, max($g->min_hours, (int) ($item->hours ?: $g->min_hours)));
        $total = ($hours >= 8 && $g->full_day_rate) ? $g->full_day_rate : $g->hourly_rate * $hours;

        $available = true;
        $note = null;
        if ($date) {
            if (is_array($g->available_days) && $g->available_days && ! in_array($date->dayOfWeek, $g->available_days, true)) {
                $available = false;
                $note = "{$g->name} doesn't work on ".$date->format('l').'s.';
            } elseif ($this->guideBookedOn($g->id, $date->toDateString(), $trip->id)) {
                $available = false;
                $note = "{$g->name} is already booked on this date.";
            }
        }

        return [Money::round($g->hourly_rate), Money::round($total), $available, $note];
    }

    private function priceMeal(TripItem $item, TourMeal $m, int $travelers): array
    {
        if ($m->status !== 'active') {
            return [0.0, 0.0, false, 'This dining option is no longer offered.'];
        }
        $qty = max(1, (int) ($item->quantity ?: $travelers));

        return [Money::round($m->price), Money::round($m->price * $qty), true, null];
    }

    /** Seats already taken in an activity slot by other booked trips. */
    public function bookedActivitySeats(int $activityId, string $date, ?string $startTime, ?int $excludeTripId = null): int
    {
        return (int) DB::table('trip_items')
            ->join('trip_days', 'trip_days.id', '=', 'trip_items.trip_day_id')
            ->join('user_trips', 'user_trips.id', '=', 'trip_items.user_trip_id')
            ->where('trip_items.itemable_type', 'tour_activity')
            ->where('trip_items.itemable_id', $activityId)
            ->where('user_trips.status', 'booked')
            ->whereNull('user_trips.deleted_at')
            ->when($excludeTripId, fn ($q) => $q->where('user_trips.id', '<>', $excludeTripId))
            ->when($startTime, fn ($q) => $q->where('trip_items.start_time', $startTime))
            ->whereRaw('DATE_ADD(user_trips.start_date, INTERVAL (trip_days.day_number - 1) DAY) = ?', [$date])
            ->sum('trip_items.quantity');
    }

    public function guideBookedOn(int $guideId, string $date, ?int $excludeTripId = null): bool
    {
        return DB::table('trip_items')
            ->join('trip_days', 'trip_days.id', '=', 'trip_items.trip_day_id')
            ->join('user_trips', 'user_trips.id', '=', 'trip_items.user_trip_id')
            ->where('trip_items.itemable_type', 'tour_guide')
            ->where('trip_items.itemable_id', $guideId)
            ->where('user_trips.status', 'booked')
            ->whereNull('user_trips.deleted_at')
            ->when($excludeTripId, fn ($q) => $q->where('user_trips.id', '<>', $excludeTripId))
            ->whereRaw('DATE_ADD(user_trips.start_date, INTERVAL (trip_days.day_number - 1) DAY) = ?', [$date])
            ->exists();
    }

    /** Catalogue item type check: is this morph allowed for the item type? */
    public static function allowedMorphs(TripItemType $type): array
    {
        return match (true) {
            $type === TripItemType::Accommodation => ['hotel_room'],
            in_array($type->value, TripItemType::transport(), true) => ['tour_transportation'],
            $type === TripItemType::Activity, $type === TripItemType::Attraction => ['tour_activity'],
            $type === TripItemType::Guide => ['tour_guide'],
            $type === TripItemType::Meal => ['tour_meal'],
            default => [],
        };
    }
}
