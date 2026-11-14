<?php

namespace App\Domain\Operations;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Writes the operational signals the FP-028 dashboard reads (App\Domain\Reporting\OperationalHealth): the scheduler
 * heartbeat, the latest outcome of each tracked scheduled command, and append-only backup and restore-drill evidence.
 *
 * Durable store, not the cache: the cache store can be changed per environment, `vasey:cache-prune-expired` and any
 * `cache:clear` remove entries, and an expired or evicted key would read as "never recorded" instead of "stale". Both
 * tables live in the application database the dashboard already reads.
 */
final class OperationalSignals
{
    /** The heartbeat command's own row in `scheduler_heartbeats`. */
    public const HEARTBEAT_TASK = 'vasey:scheduler-heartbeat';

    public const BACKUP_KINDS = ['daily', 'restore-drill'];

    public const BACKUP_STATUSES = ['ok', 'failed'];

    /** Largest byte count accepted: 2^53, far above any backup set, and exact in every client that reads it. */
    public const MAX_BYTES = 9007199254740992;

    public function recordHeartbeat(): void
    {
        $this->recordTaskOutcome(self::HEARTBEAT_TASK, true);
    }

    /** Upserts the task's latest outcome; the other outcome's last time is kept. */
    public function recordTaskOutcome(string $task, bool $succeeded): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9:._-]{0,99}\z/', $task) !== 1) {
            throw new InvalidArgumentException('Scheduled task names are short lowercase command names.');
        }
        $now = now()->utc()->format('Y-m-d H:i:s');
        $column = $succeeded ? 'last_success_at' : 'last_failure_at';
        DB::table('scheduler_heartbeats')->upsert(
            [['task' => $task, 'last_outcome' => $succeeded ? 'success' : 'failure', 'last_success_at' => $succeeded ? $now : null,
                'last_failure_at' => $succeeded ? null : $now, 'updated_at' => $now]],
            ['task'],
            ['last_outcome', $column, 'updated_at'],
        );
    }

    /**
     * Records each run of a scheduled command by its exit code. Recording is best effort: a failure to record is logged
     * and never changes the scheduled command's own result. A task that throws before it starts is not recorded; the
     * dashboard then shows its last recorded outcome, and the heartbeat still reports whether the scheduler runs at all.
     */
    public static function track(Event $event, string $task): Event
    {
        $record = static function (bool $succeeded) use ($task): void {
            try {
                app(self::class)->recordTaskOutcome($task, $succeeded);
            } catch (Throwable $exception) {
                Log::warning('A scheduled task outcome could not be recorded.', ['task' => $task, 'exception' => $exception::class]);
            }
        };

        return $event->onSuccess(fn () => $record(true))->onFailure(fn () => $record(false));
    }

    /**
     * Appends one backup or restore-drill result. Every input is validated here and again by the table's insert trigger.
     *
     * @throws InvalidArgumentException naming the refused field, never echoing its value
     */
    public function recordBackup(string $kind, string $status, ?string $bytes, ?string $sha256): void
    {
        if (! in_array($kind, self::BACKUP_KINDS, true)) {
            throw new InvalidArgumentException('kind');
        }
        if (! in_array($status, self::BACKUP_STATUSES, true)) {
            throw new InvalidArgumentException('status');
        }
        if ($bytes !== null && (preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/', $bytes) !== 1 || (int) $bytes > self::MAX_BYTES)) {
            throw new InvalidArgumentException('bytes');
        }
        if ($sha256 !== null && preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new InvalidArgumentException('sha256');
        }
        if ($kind === 'daily' && $status === 'ok' && ($bytes === null || $sha256 === null || $bytes === '0')) {
            throw new InvalidArgumentException('evidence');
        }
        DB::table('backup_evidence')->insert(['kind' => $kind, 'status' => $status, 'bytes' => $bytes === null ? null : (int) $bytes,
            'sha256' => $sha256, 'recorded_at' => now()->utc()->format('Y-m-d H:i:s')]);
    }
}
