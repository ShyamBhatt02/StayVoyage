<?php

namespace App\Providers;

use App\Listeners\DomainEventSubscriber;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\Membership;
use App\Models\Review;
use App\Models\TourActivity;
use App\Models\TourGuide;
use App\Models\TourMeal;
use App\Models\TourTransportation;
use App\Models\User;
use App\Models\UserTrip;
use App\Policies\BookingPolicy;
use App\Policies\HotelPolicy;
use App\Policies\ReviewPolicy;
use App\Policies\UserTripPolicy;
use App\Services\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        // Short, stable morph aliases (never store PHP class names in the database).
        Relation::enforceMorphMap([
            'user' => User::class,
            'booking' => Booking::class,
            'membership' => Membership::class,
            'hotel_room' => HotelRoom::class,
            'tour_activity' => TourActivity::class,
            'tour_guide' => TourGuide::class,
            'tour_transportation' => TourTransportation::class,
            'tour_meal' => TourMeal::class,
        ]);

        // Surface N+1 queries during development without breaking pages.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            Log::warning('Lazy loading detected', ['model' => get_class($model), 'relation' => $relation]);
        });

        Password::defaults(fn () => Password::min(8)->letters()->mixedCase()->numbers()->symbols());

        Event::subscribe(DomainEventSubscriber::class);

        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(Hotel::class, HotelPolicy::class);
        Gate::policy(UserTrip::class, UserTripPolicy::class);
        Gate::policy(Review::class, ReviewPolicy::class);

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // Brute-force protection: per email+IP and per IP.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('auth-sensitive', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()?->getId() ?: $request->ip()));
        RateLimiter::for('booking', fn (Request $request) => Limit::perMinute(15)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('payment', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('contact', fn (Request $request) => Limit::perHour(5)->by($request->ip()));
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(90)->by($request->ip()));
    }
}
