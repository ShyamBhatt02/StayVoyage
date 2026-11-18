<?php

namespace App\Services\Tour;

use App\Models\HotelRoom;
use App\Models\TourActivity;
use App\Models\TourGuide;
use App\Models\TourMeal;
use App\Models\TourPackage;
use App\Models\TourTransportation;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Computes a package's "from" price per person from its template items (base prices + tax + fees). */
class TourPackageService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function recalculatePriceFrom(TourPackage $package): float
    {
        $package->load(['items' => fn ($q) => $q->with(['itemable' => fn (MorphTo $m) => $m->morphWith([HotelRoom::class => ['hotel']])])]);
        $people = max(1, (int) $package->default_adults);
        $total = 0.0;

        foreach ($package->items as $item) {
            $c = $item->itemable;
            $total += match (true) {
                $c instanceof HotelRoom => $c->base_price * max(1, (int) $item->nights) * (int) ceil($people / max(1, $c->max_guests)),
                $c instanceof TourTransportation => match ($c->pricing_unit) {
                    'per_vehicle' => $c->price * (int) ceil($people / max(1, $c->capacity)),
                    'per_day' => $c->price,
                    default => $c->price * $people,
                },
                $c instanceof TourActivity => $c->price * $people,
                $c instanceof TourGuide => ($item->hours ?? $c->min_hours) >= 8 && $c->full_day_rate ? $c->full_day_rate : $c->hourly_rate * ($item->hours ?? $c->min_hours),
                $c instanceof TourMeal => $c->price * $people,
                default => 0,
            };
        }

        $pct = $this->settings->float('pricing.tour_tax_percent') + $this->settings->float('pricing.tour_service_fee_percent');
        $perPerson = Money::round(($total * (1 + $pct / 100)) / $people);
        $package->forceFill(['price_from' => $perPerson])->saveQuietly();

        return $perPerson;
    }
}
