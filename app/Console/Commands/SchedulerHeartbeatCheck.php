<?php

namespace App\Console\Commands;

use App\Domain\Operations\SchedulerWatch;
use Illuminate\Console\Command;
use Throwable;

/**
 * The scheduler's dead-man's switch (FP-028), run by a host cron job and never by the scheduler itself. Read-only unless
 * --notify is given; with it, sends one email per stale episode and one when the heartbeat resumes, but only when
 * REPORTING_SCHEDULER_ALERT_TO names a recipient and the transactional-mail configuration admits sending.
 * Exit: 0 heartbeat fresh, 1 stale or never recorded, 3 unreadable (database or episode state); nothing is sent for 3.
 */
final class SchedulerHeartbeatCheck extends Command
{
    protected $signature = 'vasey:scheduler-heartbeat-check
        {--max-age=900 : Seconds without a heartbeat before the scheduler counts as stopped (60 to 86400)}
        {--notify : Send the once-per-episode alert and recovery note when a recipient is configured}';

    protected $description = 'Check the scheduler heartbeat and, with --notify, alert once per stale episode (dead-man\'s switch).';

    public function handle(SchedulerWatch $watch): int
    {
        $raw = $this->option('max-age');
        if (! is_string($raw) || preg_match('/\A[0-9]{2,5}\z/', $raw) !== 1 || (int) $raw < 60 || (int) $raw > 86400) {
            $this->error('--max-age must be a whole number of seconds from 60 to 86400.');

            return 3;
        }
        try {
            $result = $watch->run((int) $raw, (bool) $this->option('notify'));
        } catch (Throwable $error) {
            $this->error('UNKNOWN the heartbeat or the episode state could not be read ('.$error::class.').');

            return 3;
        }
        $assessment = $result['assessment'];
        if ($assessment['status'] === 'fresh') {
            $this->line('OK scheduler heartbeat '.$assessment['age'].'s old (limit '.$raw.'s)');
        } else {
            $this->line('STALE scheduler heartbeat: '.str_replace('_', ' ', $assessment['reason'])
                .($assessment['age'] !== null ? ', '.$assessment['age'].'s old' : '').' (limit '.$raw.'s)');
        }
        if ($this->option('notify')) {
            $this->line('alert: '.str_replace('_', ' ', $result['action']));
        }

        return $assessment['status'] === 'fresh' ? self::SUCCESS : self::FAILURE;
    }
}
