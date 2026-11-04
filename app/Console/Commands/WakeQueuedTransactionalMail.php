<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Mail\TransactionalMailAdapter;
use App\Domain\Notifications\Mail\TransactionalMailPolicy;
use App\Domain\Notifications\Mail\TransactionalMailScanProgress;
use App\Domain\Notifications\NotificationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Manual bounded wake-ups, never a transport receipt or permission to retry terminal/submitting deliveries. */
final class WakeQueuedTransactionalMail extends Command
{
    protected $signature = 'vasey:transactional-mail-wake-queued {--limit=50 : Wake-ups requested, 1 to 100; at most four times as many queued rows considered}';

    protected $description = 'Request original jobs for retained queued mail only; never reopens a delivery.';

    public function handle(TransactionalMailAdapter $adapter, TransactionalMailPolicy $policy, TransactionalMailScanProgress $progress): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('Use a limit from 1 to 100.');

            return self::INVALID;
        }
        try {
            $policy->configuration();
        } catch (NotificationException $error) {
            $this->error('refused='.$error->reason);

            return self::FAILURE;
        }
        $requested = 0;
        $examined = 0;
        $skipped = [];
        $unexpected = false;
        try {
            $cycle = $progress->start('queued_wakeups');
            while ($requested < $limit && $examined < $limit * QueueTransactionalMail::SCAN_FACTOR) {
                $row = $progress->reserve('queued_wakeups', $cycle);
                if ($row === null) {
                    break;
                }
                $examined++;
                try {
                    if ($adapter->wakeQueued($row['public_id'])) {
                        $requested++;
                    } else {
                        $skipped['not_queued'] = ($skipped['not_queued'] ?? 0) + 1;
                    }
                } catch (NotificationException $error) {
                    $skipped[$error->reason] = ($skipped[$error->reason] ?? 0) + 1;
                } catch (Throwable $error) {
                    $unexpected = true;
                    $skipped['error'] = ($skipped['error'] ?? 0) + 1;
                    Log::warning('Queued mail wake-up could not be confirmed.', ['exception_class' => $error::class]);
                }
            }
        } catch (Throwable $error) {
            // A cursor failure ends this invocation; no uncertain reservation is retried or handed to a job.
            $unexpected = true;
            $skipped['error'] = ($skipped['error'] ?? 0) + 1;
            Log::warning('Queued mail wake-up could not be confirmed.', ['exception_class' => $error::class]);
        }
        ksort($skipped);
        $this->line('requested='.$requested.' skipped='.array_sum($skipped).($skipped === [] ? '' : ' reasons='.http_build_query($skipped, '', ',')));

        return $unexpected ? self::FAILURE : self::SUCCESS;
    }
}
