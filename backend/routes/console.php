<?php

use App\Enums\BookingStatus;
use App\Enums\MembershipStatus;
use App\Models\Booking;
use App\Models\Membership;
use App\Models\MembershipTransaction;
use App\Notifications\MembershipExpiringNotification;
use App\Notifications\TripReminderNotification;
use App\Services\Booking\BookingLifecycleService;
use App\Services\Membership\MembershipService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled business processes (run `php artisan schedule:work` locally,
| or a cron `* * * * * php artisan schedule:run` in production).
|--------------------------------------------------------------------------
*/

Artisan::command('bookings:expire-holds', function (BookingLifecycleService $lifecycle) {
    $n = $lifecycle->expireUnpaidHolds();
    $this->info("Expired {$n} unpaid booking hold(s).");
})->purpose('Release inventory and membership nights held by unpaid bookings');

Artisan::command('bookings:complete', function (BookingLifecycleService $lifecycle) {
    $n = $lifecycle->completePastBookings();
    $this->info("Marked {$n} booking(s) as completed.");
})->purpose('Complete confirmed bookings whose stay/trip has ended');

Artisan::command('bookings:send-reminders', function () {
    $n = 0;
    Booking::query()->with(['user', 'hotel'])
        ->where('status', BookingStatus::Confirmed->value)
        ->whereNull('reminder_sent_at')
        ->whereBetween('check_in', [today()->addDay(), today()->addDays(3)])
        ->each(function (Booking $b) use (&$n) {
            $b->user?->notify(new TripReminderNotification($b));
            $b->forceFill(['reminder_sent_at' => now()])->saveQuietly();
            $n++;
        });
    $this->info("Sent {$n} reminder(s).");
})->purpose('Remind customers about upcoming stays and trips');

Artisan::command('memberships:expire', function (MembershipService $memberships) {
    $n = $memberships->expireDue();
    $m = $memberships->cancelAbandonedPurchases();
    $this->info("Expired {$n} membership(s); cleared {$m} abandoned checkout(s).");
})->purpose('Expire memberships and lapse unused nights per plan rules');

Artisan::command('memberships:send-expiry-reminders', function () {
    $n = 0;
    Membership::query()->with('user')
        ->where('status', MembershipStatus::Active->value)
        ->whereNull('expiry_reminder_sent_at')
        ->whereBetween('expires_at', [now(), now()->addDays(3)])
        ->each(function (Membership $m) use (&$n) {
            $m->user?->notify(new MembershipExpiringNotification($m));
            $m->forceFill(['expiry_reminder_sent_at' => now()])->saveQuietly();
            $n++;
        });
    $this->info("Sent {$n} expiry reminder(s).");
})->purpose('Warn members 3 days before expiry');

Artisan::command('memberships:verify-ledger', function () {
    // Integrity check: cached balances must equal the ledger sum.
    $bad = DB::table('memberships')
        ->leftJoin('membership_transactions', 'membership_transactions.membership_id', '=', 'memberships.id')
        ->groupBy('memberships.id', 'memberships.nights_balance')
        ->havingRaw('memberships.nights_balance <> COALESCE(SUM(membership_transactions.nights), 0)')
        ->select('memberships.id', 'memberships.nights_balance', DB::raw('COALESCE(SUM(membership_transactions.nights),0) as ledger'))
        ->get();
    foreach ($bad as $row) {
        Log::channel('membership')->critical('Ledger mismatch', (array) $row);
        $this->error("Membership #{$row->id}: cached {$row->nights_balance} vs ledger {$row->ledger}");
    }
    $this->info($bad->isEmpty() ? 'All membership balances match the ledger ('.MembershipTransaction::count().' entries).' : "{$bad->count()} mismatch(es) found.");
})->purpose('Verify membership balances against the transaction ledger');

Schedule::command('bookings:expire-holds')->everyMinute()->withoutOverlapping();
Schedule::command('memberships:expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bookings:complete')->dailyAt('03:10');
Schedule::command('bookings:send-reminders')->dailyAt('09:00');
Schedule::command('memberships:send-expiry-reminders')->dailyAt('10:00');
Schedule::command('memberships:verify-ledger')->dailyAt('02:30');
Schedule::command('queue:prune-failed --hours=720')->daily();
