<?php

use App\Domain\Delivery\TestDeliveryAutoEnablePolicy;
use App\Domain\Operations\OperationalSignals;
use App\Domain\SoundKits\Purchases\SoundKitPurchasePolicy;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Production runs `php artisan schedule:run` every minute. The database lock serializes publication; the short
// overlap lock is hygiene only (the 1,440-minute default could stall scheduled releases after a crashed run).
OperationalSignals::track(Schedule::command('vasey:publish-scheduled-site-release')->everyMinute()->withoutOverlapping(5), 'vasey:publish-scheduled-site-release');

// Track schedules (FP-032). A due schedule whose publication guards fail stays scheduled and exits non-zero, which the tracked
// signal records as a failure on the operational dashboard. The database locks serialize activation; the overlap lock is hygiene.
OperationalSignals::track(Schedule::command('vasey:publish-scheduled-tracks')->everyMinute()->withoutOverlapping(5), 'vasey:publish-scheduled-tracks');

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

// FP-034: cleans the chunk files of resumable uploads (track media and sound kits) abandoned past expiry for at least the configured
// age (48 h by default), marking each retained row cancelled first. Off until a reviewed change sets media.upload_session_sweep.scheduled;
// never touches completed sessions, assets or originals. :41 keeps it clear of :07/:22/:37/:52, :23, :47 and the 03:17 backup. Its
// outcome is tracked like the other commands. The overlap lock is hygiene only, as above.
OperationalSignals::track(Schedule::command('vasey:upload-sessions-sweep --execute')->hourlyAt(41)->withoutOverlapping(10)
    ->when(fn (): bool => config('media.upload_session_sweep.scheduled') === true), 'vasey:upload-sessions-sweep');

// BL-13: grants paid Stripe-test kit orders whose grant failed after the payment committed (SoundKitPayments::settle). Runs only
// while SoundKitPurchasePolicy admits the kit lane (never in production). Every 10 minutes at :04/:14/…/:54 keeps it off minute zero
// and the other jobs' minutes; a still-refused order exits non-zero, which the tracked signal shows as Needs attention.
OperationalSignals::track(Schedule::command('vasey:finalize-test-kit-grants')->cron('4-59/10 * * * *')->withoutOverlapping(10)
    ->when(fn (): bool => app(SoundKitPurchasePolicy::class)->admitted()), 'vasey:finalize-test-kit-grants');

// BL-13: records the license contract original of every paid kit grant that has none, so a paid kit order reaches "contract issued"
// without an operator (the staff view and `vasey:issue-test-kit-contracts` do the same step on demand). The track contract command
// has no schedule to mirror; this uses the grant sweep's 10-minute cadence offset to :06/:16/…/:56, clear of the other jobs' minutes.
// Runs only while SoundKitPurchasePolicy admits the kit lane (never in production); a refused grant (for example the licensor line
// is unset) exits non-zero, which the tracked signal shows as Needs attention. Idempotent: a recorded original is never re-rendered.
OperationalSignals::track(Schedule::command('vasey:issue-test-kit-contracts')->cron('6-59/10 * * * *')->withoutOverlapping(10)
    ->when(fn (): bool => app(SoundKitPurchasePolicy::class)->admitted()), 'vasey:issue-test-kit-contracts');

// D-19 amendment (2026-11-12): optional automatic enabling of Stripe-test delivery for ready orders that never had a delivery control.
// Runs only while TestDeliveryAutoEnablePolicy admits it (default off; local, testing and staging, never production). Every minute,
// so a buyer waits about as long as the pipeline's activation sweep; with nothing ready it is two indexed selects. A person's block
// or enable is never overridden. A refused order exits non-zero, which the tracked signal shows as Needs attention.
OperationalSignals::track(Schedule::command('vasey:auto-enable-test-delivery')->everyMinute()->withoutOverlapping(5)
    ->when(fn (): bool => app(TestDeliveryAutoEnablePolicy::class)->admitted()), 'vasey:auto-enable-test-delivery');

// FP-028 dashboard: one upserted row a minute proves the scheduler itself runs (OperationalSignals; "Scheduler last run" turns
// Needs attention after `reporting.health.scheduler_heartbeat_stale_seconds`). The tracked commands above record their latest
// exit outcome the same way, so a failing scheduled command shows Needs attention. No overlap lock: a heartbeat is one upsert.
Schedule::command('vasey:scheduler-heartbeat')->everyMinute();
