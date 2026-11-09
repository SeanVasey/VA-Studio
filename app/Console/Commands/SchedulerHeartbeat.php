<?php

namespace App\Console\Commands;

use App\Domain\Operations\OperationalSignals;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduled every minute (routes/console.php): records that the scheduler ran, for the FP-028 dashboard's "Scheduler last
 * run" metric. Writes one upserted row and prints no data.
 */
final class SchedulerHeartbeat extends Command
{
    protected $signature = 'vasey:scheduler-heartbeat';

    protected $description = 'Record that the scheduler ran (operational health dashboard).';

    public function handle(OperationalSignals $signals): int
    {
        try {
            $signals->recordHeartbeat();
        } catch (Throwable) {
            $this->error('The scheduler heartbeat could not be recorded.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
