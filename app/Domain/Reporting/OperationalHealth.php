<?php

namespace App\Domain\Reporting;

use App\Domain\Commerce\Operations\ReadTestCommerceOperations;
use App\Domain\Operations\OperationalSignals;
use App\Models\User;
use App\Support\Environment\TestEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only operational health from rows the application already stores, including the scheduler heartbeat and backup
 * evidence written by App\Domain\Operations\OperationalSignals. Every metric is `ok`, `attention` or
 * `unavailable`; a source the application cannot read, or evidence it does not record, is never shown as zero.
 * Each section issues a fixed small number of aggregate queries (no per-row work) and takes no locks.
 */
final class OperationalHealth
{
    public const SECTIONS = ['queue', 'commerce', 'content', 'evidence'];

    public const TITLES = [
        'queue' => 'Background work',
        'commerce' => 'Commerce attention (test commerce)',
        'content' => 'Inquiries and media pipeline',
        'evidence' => 'Scheduler, backups and host',
    ];

    /** Labels for metrics that can be marked unavailable when their source cannot be read. */
    private const LABELS = [
        'queue_ready' => 'Jobs waiting', 'queue_oldest' => 'Oldest waiting job', 'queue_reserved' => 'Jobs running', 'queue_stalled' => 'Jobs stalled past the retry window',
        'failed_jobs' => 'Failed jobs', 'failed_jobs_recent' => 'Failed in the last 24 hours',
        'stripe_receipts_waiting' => 'Payment webhook receipts not yet processed', 'stripe_receipts_quarantined' => 'Payment webhook receipts quarantined',
        'payment_exceptions' => 'Payment exceptions awaiting review', 'payment_exceptions_flagged' => 'Exceptions marked for further review',
        'refund_attention' => 'Refund or dispute evidence needing attention', 'refund_open_disputes' => 'Open disputes', 'refund_lost_disputes' => 'Lost disputes',
        'refund_holds' => 'Orders with active download holds', 'refund_unallocated' => 'Succeeded refunds not fully allocated to lines',
        'refund_reversals' => 'Orders with a recorded refund reversal', 'inquiries_new' => 'Inquiries not yet read',
        'media_failed' => 'Media processing runs failed', 'media_stuck' => 'Media processing runs past their claim lease',
        'media_queued' => 'Media processing runs queued', 'media_quarantined' => 'Uploads still in quarantine',
        'scheduler_last_run' => 'Scheduler last run (seconds ago)', 'scheduled_task_failures' => 'Scheduled commands whose latest run failed',
        'backup_last_success' => 'Last successful backup (hours ago)', 'restore_drill_last_success' => 'Last successful restore drill (days ago)',
    ];

    /** A heartbeat this far in the future is clock skew, not a fresh run. */
    private const CLOCK_SKEW_SECONDS = 60;

    public function __construct(private readonly StaffReportingAuthority $authority) {}

    /** @return array{key: string, title: string, metrics: list<array{key: string, label: string, value: int|string|null, state: string, detail: string}>} */
    public function section(string $name, ?User $actor): array
    {
        $this->authority->staff($actor);
        ReportingException::require(in_array($name, self::SECTIONS, true), 'unknown_section');
        $metrics = match ($name) {
            'queue' => $this->queue(),
            'commerce' => $this->commerce(),
            'content' => $this->content(),
            'evidence' => $this->evidence(),
        };

        return ['key' => $name, 'title' => self::TITLES[$name], 'metrics' => $metrics];
    }

    /** @return list<array<string, mixed>> */
    private function queue(): array
    {
        $metrics = [];
        $default = (string) config('queue.default');
        if ($default !== 'database') {
            foreach (['queue_ready', 'queue_oldest', 'queue_reserved', 'queue_stalled'] as $key) {
                $metrics[] = self::unavailable($key, self::LABELS[$key], "The queue connection is '{$default}'; only the database queue can be read by the application.");
            }
        } else {
            $metrics = array_merge($metrics, $this->guarded(['queue_ready', 'queue_oldest', 'queue_reserved', 'queue_stalled'], 'jobs', function (): array {
                $db = DB::connection(config('queue.connections.database.connection'));
                $table = (string) config('queue.connections.database.table', 'jobs');
                $now = now()->utc()->timestamp;
                // The database queue treats a reservation at or before now minus retry_after as expired (its worker is presumed
                // gone) and hands the job out again; the same window and default as the queue connector, so the two never disagree.
                $retryAfter = (int) (config('queue.connections.database.retry_after') ?? 60);
                $expired = $now - $retryAfter;
                $row = $db->table($table)->selectRaw('COUNT(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 END) AS ready,'
                    .' COUNT(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 END) AS scheduled_later,'
                    .' COUNT(CASE WHEN reserved_at > ? THEN 1 END) AS reserved,'
                    .' COUNT(CASE WHEN reserved_at <= ? THEN 1 END) AS stalled,'
                    .' MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN available_at END) AS oldest_ready', [$now, $now, $expired, $expired, $now])->first();
                $age = $row->oldest_ready === null ? 0 : max(0, $now - (int) $row->oldest_ready);
                $limit = (int) config('reporting.health.queue_age_attention_seconds');

                return [
                    self::metric('queue_ready', 'Jobs waiting', (int) $row->ready, (int) $row->ready > 0 && $age > $limit ? 'attention' : 'ok',
                        (int) $row->scheduled_later.' more are scheduled for later.'),
                    self::metric('queue_oldest', 'Oldest waiting job', $age, $age > $limit ? 'attention' : 'ok',
                        $row->oldest_ready === null ? 'Nothing is waiting.' : 'Seconds since it became available; attention above '.$limit.' seconds.'),
                    self::metric('queue_reserved', 'Jobs running', (int) $row->reserved, 'ok', 'Reserved by a worker within the last '.$retryAfter.' seconds (the retry window).'),
                    self::metric('queue_stalled', 'Jobs stalled past the retry window', (int) $row->stalled, (int) $row->stalled > 0 ? 'attention' : 'ok',
                        'Reserved '.$retryAfter.' or more seconds ago and not finished: the worker likely stopped. The next worker to poll the queue takes these again.'),
                ];
            }));
        }
        $failedDriver = (string) config('queue.failed.driver');
        if (! in_array($failedDriver, ['database', 'database-uuids'], true)) {
            $metrics[] = self::unavailable('failed_jobs', self::LABELS['failed_jobs'], "The failed-job store is '{$failedDriver}'; only the database store can be read.");
        } else {
            $metrics = array_merge($metrics, $this->guarded(['failed_jobs', 'failed_jobs_recent'], 'failed jobs', function (): array {
                $db = DB::connection(config('queue.failed.database'));
                $row = $db->table((string) config('queue.failed.table', 'failed_jobs'))
                    ->selectRaw('COUNT(*) AS total, COUNT(CASE WHEN failed_at >= ? THEN 1 END) AS recent, MAX(failed_at) AS latest',
                        [now()->utc()->subDay()->format('Y-m-d H:i:s')])->first();

                return [
                    self::metric('failed_jobs', 'Failed jobs', (int) $row->total, (int) $row->total > 0 ? 'attention' : 'ok',
                        $row->latest === null ? 'None recorded.' : 'Most recent failure at '.$row->latest.' UTC.'),
                    self::metric('failed_jobs_recent', 'Failed in the last 24 hours', (int) $row->recent, (int) $row->recent > 0 ? 'attention' : 'ok', 'Counted by the failure time.'),
                ];
            }));
        }
        $metrics = array_merge($metrics, $this->guarded(['stripe_receipts_waiting', 'stripe_receipts_quarantined'], 'stripe_receipt_work', function (): array {
            $rows = DB::table('stripe_receipt_work')->selectRaw('state, COUNT(*) AS total, MIN(created_at) AS oldest')->groupBy('state')->get()->keyBy('state');
            $waiting = (int) ($rows['pending']->total ?? 0) + (int) ($rows['retry']->total ?? 0) + (int) ($rows['processing']->total ?? 0);
            $oldest = collect([$rows['pending']->oldest ?? null, $rows['retry']->oldest ?? null, $rows['processing']->oldest ?? null])->filter()->min();
            $age = $oldest === null ? 0 : max(0, now()->utc()->timestamp - CarbonImmutable::parse($oldest, 'UTC')->timestamp);
            $quarantined = (int) ($rows['quarantined']->total ?? 0);
            $limit = (int) config('reporting.health.receipt_age_attention_seconds');

            return [
                self::metric('stripe_receipts_waiting', 'Payment webhook receipts not yet processed', $waiting, $waiting > 0 && $age > $limit ? 'attention' : 'ok',
                    $waiting === 0 ? 'No receipt is waiting.' : 'Oldest has waited '.$age.' seconds; attention above '.$limit.' seconds.'),
                self::metric('stripe_receipts_quarantined', 'Payment webhook receipts quarantined', $quarantined, $quarantined > 0 ? 'attention' : 'ok',
                    'Quarantined receipts exhausted their attempts and need an operator.'),
            ];
        }));

        return $metrics;
    }

    /** @return list<array<string, mixed>> */
    private function commerce(): array
    {
        $keys = ['payment_exceptions', 'payment_exceptions_flagged', 'refund_attention', 'refund_open_disputes', 'refund_lost_disputes',
            'refund_holds', 'refund_unallocated', 'refund_reversals'];
        if (! TestEnvironment::admitsTestCommerce()) {
            return array_map(fn (string $key): array => self::unavailable($key, self::LABELS[$key], 'Test commerce is not admitted in this environment.'), $keys);
        }
        $account = ReportingQueries::testAccount();
        if ($account === null) {
            return array_map(fn (string $key): array => self::unavailable($key, self::LABELS[$key], 'The Stripe test account is not configured, so no test order is in scope.'), $keys);
        }

        return array_merge($this->guarded(['payment_exceptions', 'payment_exceptions_flagged'], 'payment exceptions', function (): array {
            $awaiting = app(ReadTestCommerceOperations::class)->exceptions()
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('test_refund_resolutions as rr')->whereColumn('rr.order_finalization_id', 'order_finalizations.id'));
            $total = (clone $awaiting)->count();
            $oldest = (clone $awaiting)->min('order_finalizations.finalized_at');
            $flagged = (clone $awaiting)->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('test_payment_exception_events as e')
                ->whereColumn('e.order_finalization_id', 'order_finalizations.id')->where('e.kind', 'disposition')->where('e.outcome', 'needs_review')
                ->whereRaw('e.sequence = (SELECT MAX(e2.sequence) FROM test_payment_exception_events e2 WHERE e2.order_finalization_id = e.order_finalization_id AND e2.kind = \'disposition\')'))
                ->count();

            return [
                self::metric('payment_exceptions', 'Payment exceptions awaiting review', $total, $total > 0 ? 'attention' : 'ok',
                    $oldest === null ? 'No paid exception is open.' : 'Oldest recorded '.$oldest.' UTC. Paid exceptions have no license until reviewed.'),
                self::metric('payment_exceptions_flagged', 'Exceptions marked for further review', $flagged, $flagged > 0 ? 'attention' : 'ok',
                    'Open exceptions whose latest operator status is "needs further review".'),
            ];
        }), $this->refundDisputeMetrics($account));
    }

    /** @return list<array<string, mixed>> */
    private function refundDisputeMetrics(string $account): array
    {
        return $this->guarded(['refund_attention', 'refund_open_disputes', 'refund_lost_disputes', 'refund_holds', 'refund_unallocated', 'refund_reversals'], 'refund and dispute ledger', function () use ($account): array {
            $scope = fn (string $alias = 'f'): Builder => DB::table('order_finalizations as '.$alias)
                ->join('verified_payments as p', 'p.id', '=', $alias.'.verified_payment_id')
                ->where($alias.'.mode', 'test')->where($alias.'.outcome', 'paid')->where('p.mode', 'test')->where('p.account_id', $account)
                ->whereColumn('p.order_id', $alias.'.order_id');
            $latestObserved = static fn (Builder $query): Builder => $query->join('refund_dispute_observations as r', 'r.order_id', '=', 'f.order_id')
                ->where('r.order_source', 'test')->where('r.outcome', 'observed')
                ->whereRaw('r.sequence = (SELECT MAX(r2.sequence) FROM refund_dispute_observations r2 WHERE r2.order_id = r.order_id AND r2.order_source = \'test\' AND r2.outcome = \'observed\')');
            $attention = $scope()->join('refund_dispute_observations as r', 'r.order_id', '=', 'f.order_id')->where('r.order_source', 'test')->where('r.outcome', 'attention')
                ->whereRaw('r.sequence = (SELECT MAX(r2.sequence) FROM refund_dispute_observations r2 WHERE r2.order_id = r.order_id AND r2.order_source = \'test\')')->count();
            $open = $latestObserved($scope())->where('r.dispute_state', 'open')->count();
            $lost = $latestObserved($scope())->where('r.dispute_state', 'lost')->count();
            $holds = $scope()->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('refund_dispute_holds as h')
                ->whereColumn('h.order_id', 'f.order_id')->where('h.order_source', 'test')->whereNull('h.released_at'))->count();
            $unallocated = $latestObserved($scope())->where('r.succeeded_refund_minor', '>', 0)
                ->whereRaw('r.succeeded_refund_minor > COALESCE((SELECT SUM(a.amount_minor) FROM refund_line_allocations a WHERE a.order_id = f.order_id AND a.order_source = \'test\'), 0)')->count();
            $reversals = $scope()->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('refund_dispute_effects as x')
                ->whereColumn('x.order_id', 'f.order_id')->where('x.order_source', 'test')->where('x.kind', 'refund_reversed_review_required'))->count();

            return [
                self::metric('refund_attention', 'Refund or dispute evidence needing attention', $attention, $attention > 0 ? 'attention' : 'ok',
                    'Orders whose latest provider check was rejected as inconsistent.'),
                self::metric('refund_open_disputes', 'Open disputes', $open, $open > 0 ? 'attention' : 'ok', 'Latest accepted provider check shows an open dispute.'),
                self::metric('refund_lost_disputes', 'Lost disputes', $lost, $lost > 0 ? 'attention' : 'ok', 'Latest accepted provider check shows a lost dispute.'),
                self::metric('refund_holds', 'Orders with active download holds', $holds, $holds > 0 ? 'attention' : 'ok', 'Holds stay until released after review.'),
                self::metric('refund_unallocated', 'Succeeded refunds not fully allocated to lines', $unallocated, $unallocated > 0 ? 'attention' : 'ok',
                    'Compared with raw allocation totals, so a reversed refund whose allocation was retained can hide here; see reversals.'),
                self::metric('refund_reversals', 'Orders with a recorded refund reversal', $reversals, $reversals > 0 ? 'attention' : 'ok',
                    'A succeeded refund later failed. No resolution marker exists, so every such order stays listed.'),
            ];
        });
    }

    /** @return list<array<string, mixed>> */
    private function content(): array
    {
        $limits = config('reporting.health');
        $metrics = $this->guarded(['inquiries_new'], 'customer inquiries', function () use ($limits): array {
            $row = DB::table('customer_inquiries')->where('state', 'new')->selectRaw('COUNT(*) AS total, MIN(created_at) AS oldest')->first();
            $age = $row->oldest === null ? 0 : max(0, now()->utc()->timestamp - CarbonImmutable::parse($row->oldest, 'UTC')->timestamp);

            return [self::metric('inquiries_new', 'Inquiries not yet read', (int) $row->total, (int) $row->total > 0 && $age > (int) $limits['inquiry_age_attention_seconds'] ? 'attention' : 'ok',
                (int) $row->total === 0 ? 'No new inquiry.' : 'Oldest received '.$row->oldest.' UTC; attention after '.intdiv((int) $limits['inquiry_age_attention_seconds'], 3600).' hours.')];
        });

        return array_merge($metrics, $this->guarded(['media_failed', 'media_stuck', 'media_queued', 'media_quarantined'], 'media processing', function () use ($limits): array {
            $now = now()->utc()->toImmutable();
            $stuckBefore = $now->subSeconds((int) config('media.claim_lease_seconds'))->format('Y-m-d H:i:s');
            $runs = DB::table('media_processing_runs')->selectRaw('COUNT(CASE WHEN status = \'failed\' THEN 1 END) AS failed,'
                .' COUNT(CASE WHEN status = \'processing\' AND started_at < ? THEN 1 END) AS stuck,'
                .' COUNT(CASE WHEN status = \'queued\' THEN 1 END) AS queued, MIN(CASE WHEN status = \'queued\' THEN created_at END) AS oldest_queued', [$stuckBefore])->first();
            $quarantine = DB::table('media_assets')->where('status', 'quarantined')->selectRaw('COUNT(*) AS total, MIN(created_at) AS oldest')->first();
            $queuedAge = $runs->oldest_queued === null ? 0 : max(0, $now->timestamp - CarbonImmutable::parse($runs->oldest_queued, 'UTC')->timestamp);
            $quarantineAge = $quarantine->oldest === null ? 0 : max(0, $now->timestamp - CarbonImmutable::parse($quarantine->oldest, 'UTC')->timestamp);

            return [
                self::metric('media_failed', 'Media processing runs failed', (int) $runs->failed, (int) $runs->failed > 0 ? 'attention' : 'ok', 'A failed run keeps its failure code until retried.'),
                self::metric('media_stuck', 'Media processing runs past their claim lease', (int) $runs->stuck, (int) $runs->stuck > 0 ? 'attention' : 'ok',
                    'Still marked processing after '.(int) config('media.claim_lease_seconds').' seconds.'),
                self::metric('media_queued', 'Media processing runs queued', (int) $runs->queued, (int) $runs->queued > 0 && $queuedAge > (int) $limits['queued_media_age_attention_seconds'] ? 'attention' : 'ok',
                    (int) $runs->queued === 0 ? 'Nothing is queued.' : 'Oldest has waited '.$queuedAge.' seconds.'),
                self::metric('media_quarantined', 'Uploads still in quarantine', (int) $quarantine->total, (int) $quarantine->total > 0 && $quarantineAge > (int) $limits['quarantine_age_attention_seconds'] ? 'attention' : 'ok',
                    'Quarantine is the normal first state; attention only when the oldest has waited over '.intdiv((int) $limits['quarantine_age_attention_seconds'], 3600).' hours.'),
            ];
        }));
    }

    /**
     * Scheduler and backup signals recorded by App\Domain\Operations\OperationalSignals. Never recorded is Unavailable, not OK:
     * a fresh install, a scheduler that never started and a backup script that never called back all look the same.
     *
     * @return list<array<string, mixed>>
     */
    private function evidence(): array
    {
        $limits = config('reporting.health');
        $metrics = $this->guarded(['scheduler_last_run', 'scheduled_task_failures'], 'scheduler heartbeat', function () use ($limits): array {
            $rows = DB::table('scheduler_heartbeats')->select(['task', 'last_outcome', 'last_success_at', 'last_failure_at'])->get()->keyBy('task');
            $heartbeat = $rows->get(OperationalSignals::HEARTBEAT_TASK);
            $limit = (int) $limits['scheduler_heartbeat_stale_seconds'];
            if ($limit < 1) {
                $scheduler = self::unavailable('scheduler_last_run', self::LABELS['scheduler_last_run'], 'The heartbeat threshold is not a positive number of seconds (REPORTING_SCHEDULER_HEARTBEAT_STALE_SECONDS).');
            } elseif ($heartbeat === null || $heartbeat->last_success_at === null) {
                $scheduler = self::unavailable('scheduler_last_run', self::LABELS['scheduler_last_run'], 'No scheduler heartbeat has ever been recorded: the scheduler has not run `vasey:scheduler-heartbeat` on this database.');
            } elseif (($ahead = -self::signedAgeSeconds($heartbeat->last_success_at)) > self::CLOCK_SKEW_SECONDS) {
                // A heartbeat from the future is not proof the scheduler runs now: the clocks disagree, so it cannot be OK.
                $scheduler = self::metric('scheduler_last_run', self::LABELS['scheduler_last_run'], -$ahead, 'attention',
                    'Last heartbeat at '.$heartbeat->last_success_at.' UTC is '.$ahead.' seconds in the future: the scheduler host and this server disagree on the time.');
            } else {
                $age = self::ageSeconds($heartbeat->last_success_at);
                $scheduler = self::metric('scheduler_last_run', self::LABELS['scheduler_last_run'], $age, $age > $limit ? 'attention' : 'ok',
                    'Last heartbeat at '.$heartbeat->last_success_at.' UTC; attention after '.$limit.' seconds without one.');
            }
            // Only commands the scheduler still runs: a removed or renamed command's last row would otherwise flag forever.
            $tasks = $rows->only(self::scheduledTasks())->except(OperationalSignals::HEARTBEAT_TASK);
            if ($tasks->isEmpty()) {
                $failures = self::unavailable('scheduled_task_failures', self::LABELS['scheduled_task_failures'], 'No tracked scheduled command has recorded an outcome yet.');
            } else {
                $failed = $tasks->filter(fn (object $row): bool => $row->last_outcome === 'failure')->keys()->sort()->values()->all();
                $failures = self::metric('scheduled_task_failures', self::LABELS['scheduled_task_failures'], count($failed), $failed === [] ? 'ok' : 'attention',
                    $failed === [] ? 'The latest run of each of the '.$tasks->count().' commands that have reported succeeded.'
                        : 'Latest run failed: '.implode(', ', $failed).'. The scheduler log on the host has the output.');
            }

            return [$scheduler, $failures];
        });
        $metrics = array_merge($metrics, $this->guarded(['backup_last_success', 'restore_drill_last_success'], 'backup evidence', fn (): array => [
            $this->backupMetric('backup_last_success', 'daily', 3600, (int) $limits['backup_stale_hours'], 'hours', 'REPORTING_BACKUP_STALE_HOURS',
                'No backup run has been recorded. The host backup script records each nightly run with `vasey:backup-record`.'),
            $this->backupMetric('restore_drill_last_success', 'restore-drill', 86400, (int) $limits['restore_drill_stale_days'], 'days', 'REPORTING_RESTORE_DRILL_STALE_DAYS',
                'No restore drill has been recorded. Record each drill with `vasey:backup-record --kind=restore-drill`.'),
        ]));
        $metrics[] = self::unavailable('host_resources', 'Host disk, memory and process health',
            'Not read by the application: Forge server monitoring and ops/monitoring/host-baseline.sh cover the host (docs/ops/monitoring-and-host-baseline.md).');

        return $metrics;
    }

    /** @return array<string, mixed> */
    private function backupMetric(string $key, string $kind, int $unit, int $limit, string $unitName, string $envKey, string $never): array
    {
        if ($limit < 1) {
            return self::unavailable($key, self::LABELS[$key], "The threshold is not a positive number of {$unitName} ({$envKey}).");
        }
        $latest = DB::table('backup_evidence')->where('kind', $kind)->orderByDesc('id')->first(['status', 'recorded_at']);
        if ($latest === null) {
            return self::unavailable($key, self::LABELS[$key], $never);
        }
        $success = DB::table('backup_evidence')->where('kind', $kind)->where('status', 'ok')->orderByDesc('id')->first(['recorded_at']);
        $age = $success === null ? null : self::ageSeconds($success->recorded_at);
        $value = $age === null ? 'never' : intdiv($age, $unit);
        $since = $success === null ? 'No successful run has been recorded.' : 'Last success recorded '.$success->recorded_at.' UTC.';
        if ($latest->status !== 'ok') {
            return self::metric($key, self::LABELS[$key], $value, 'attention', 'The latest run, recorded '.$latest->recorded_at.' UTC, failed. '.$since);
        }

        return self::metric($key, self::LABELS[$key], $value, $age > $limit * $unit ? 'attention' : 'ok', $since.' Attention after '.$limit.' '.$unitName.'.');
    }

    private static function ageSeconds(string $at): int
    {
        return max(0, self::signedAgeSeconds($at));
    }

    private static function signedAgeSeconds(string $at): int
    {
        return now()->utc()->timestamp - CarbonImmutable::parse($at, 'UTC')->timestamp;
    }

    /**
     * Artisan command names the scheduler registers (routes/console.php) and whose `when` gates pass now, so a command gated
     * off by configuration (such as `vasey:upload-sessions-sweep`) never flags from a row left before it was turned off. A
     * web request does not load the console routes, so the console kernel is bootstrapped first; it loads them once per
     * process. `skip` rejects are not evaluated: `withoutOverlapping` is one, and a running command must still be counted.
     *
     * @return list<string>
     */
    private static function scheduledTasks(): array
    {
        app(ConsoleKernel::class)->bootstrap();
        $prefix = Artisan::formatCommandString('');
        // Event::filtersPass() without its rejects, through the event's own callback invoker.
        $gatesPass = fn (Event $event): bool => (fn (): bool => array_all($this->filters, fn ($gate): bool => (bool) $this->callEventCallback(app(), $gate)))->call($event);

        return collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_starts_with((string) $event->command, $prefix) && $gatesPass($event))
            ->map(fn (Event $event): string => explode(' ', substr((string) $event->command, strlen($prefix)), 2)[0])
            ->unique()->values()->all();
    }

    /**
     * Runs one source read; any failure (missing table, unreachable connection) marks only its metrics unavailable.
     *
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function guarded(array $keys, string $source, callable $read): array
    {
        try {
            return $read();
        } catch (Throwable) {
            return array_map(fn (string $key): array => self::unavailable($key, self::LABELS[$key], "The {$source} source could not be read."), $keys);
        }
    }

    /** @return array{key: string, label: string, value: int|string|null, state: string, detail: string} */
    private static function metric(string $key, string $label, int|string|null $value, string $state, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'state' => $state, 'detail' => $detail];
    }

    /** @return array{key: string, label: string, value: null, state: string, detail: string} */
    private static function unavailable(string $key, string $label, string $reason): array
    {
        return self::metric($key, $label, null, 'unavailable', $reason);
    }
}
