<?php

use App\Domain\Operations\OperationalSignals;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Production runs `php artisan schedule:run` every minute. The database lock serializes publication; the short
// overlap lock is hygiene only (the 1,440-minute default could stall scheduled releases after a crashed run).
OperationalSignals::track(Schedule::command('vasey:publish-scheduled-site-release')->everyMinute()->withoutOverlapping(5), 'vasey:publish-scheduled-site-release');

// Deletes expired `cache` and `cache_locks` rows only. Laravel removes an expired row when that exact key is read and ships no bulk
// command, so an unread Filament flash notification (plain text, `session.lifetime` TTL) and stale throttle counters would stay
// forever. Hourly bounds that retention to the TTL plus one hour, the delete is a short indexed range delete in batches, and
// :23 keeps it off the minute-zero pile-up and clear of the 03:17 backup. The same scheduler process, `schedule:work` under
// supervisor on staging and in the production kit, runs it. Never replace it with `cache:clear`: that resets unexpired throttles,
// TOTP replay records and held locks. The overlap lock is hygiene only, as above.
OperationalSignals::track(Schedule::command('vasey:cache-prune-expired')->hourlyAt(23)->withoutOverlapping(10), 'vasey:cache-prune-expired');

// Removes contract renderer workspaces a parent killed mid-render left under `contracts/render-cache` (each may hold a signature
// copy). Only workspaces unmodified for an hour go (a render's child has a 60 s wall clock); links are never followed. :47 keeps it
// clear of :23 and the 03:17 backup. The overlap lock is hygiene only, as above.
OperationalSignals::track(Schedule::command('vasey:contract-render-cache-sweep')->hourlyAt(47)->withoutOverlapping(10), 'vasey:contract-render-cache-sweep');

// Purges the encrypted bytes of customer data exports left unused past `customer-data-rights.export_ttl_minutes` (default 15);
// requests and their events stay as evidence (FP-082, independent CX review F4). Every 15 minutes bounds an unused export's
// lifetime to about twice its window; :07/:22/:37/:52 keeps it off minute zero and the :23/:47 hourly jobs. Each run purges at
// most the command's default 100; the next run takes the rest. The overlap lock is hygiene only, as above.
OperationalSignals::track(Schedule::command('vasey:customer-data-exports-prune')->cron('7,22,37,52 * * * *')->withoutOverlapping(10), 'vasey:customer-data-exports-prune');

// FP-028 dashboard: one upserted row a minute proves the scheduler itself runs (OperationalSignals; "Scheduler last run" turns
// Needs attention after `reporting.health.scheduler_heartbeat_stale_seconds`). The tracked commands above record their latest
// exit outcome the same way, so a failing scheduled command shows Needs attention. No overlap lock: a heartbeat is one upsert.
Schedule::command('vasey:scheduler-heartbeat')->everyMinute();
