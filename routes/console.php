<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Production runs `php artisan schedule:run` every minute. The database lock serializes publication; the short
// overlap lock is hygiene only (the 1,440-minute default could stall scheduled releases after a crashed run).
Schedule::command('vasey:publish-scheduled-site-release')->everyMinute()->withoutOverlapping(5);

// Deletes expired `cache` and `cache_locks` rows only. Laravel removes an expired row when that exact key is read and ships no bulk
// command, so an unread Filament flash notification (plain text, `session.lifetime` TTL) and stale throttle counters would stay
// forever. Hourly bounds that retention to the TTL plus one hour, the delete is a short indexed range delete in batches, and
// :23 keeps it off the minute-zero pile-up and clear of the 03:17 backup. The same scheduler process, `schedule:work` under
// supervisor on staging and in the production kit, runs it. Never replace it with `cache:clear`: that resets unexpired throttles,
// TOTP replay records and held locks. The overlap lock is hygiene only, as above.
Schedule::command('vasey:cache-prune-expired')->hourlyAt(23)->withoutOverlapping(10);
