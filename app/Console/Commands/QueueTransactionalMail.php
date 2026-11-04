<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Mail\TransactionalMailAdapter;
use App\Domain\Notifications\Mail\TransactionalMailPolicy;
use App\Domain\Notifications\Mail\TransactionalMailScanProgress;
use App\Domain\Notifications\NotificationException;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues one email for each private order-ready intent that has none yet (FP-075), in a bounded batch. Refuses, recording
 * nothing, unless the transactional mail configuration is admitted. Prints counts and fixed reasons only.
 * Finishes the batch and prints its summary before returning failure for any unexpected per-intent error.
 *
 * An intent bound to a customer access version that has since been superseded (access withdrawn or the account erased; the
 * version only ever increases) can never verify again, so it leaves the pending set without any row being written. Every other
 * refusal stays pending. Durable finite passes consider later intents and revisit refusals after wraparound.
 */
final class QueueTransactionalMail extends Command
{
    /** Pending intents examined per run, as a multiple of --limit, so refusals cannot hold a run's batch forever. */
    public const SCAN_FACTOR = 4;

    protected $signature = 'vasey:transactional-mail-queue {--limit=50 : Emails queued in this run, 1 to 100; at most four times as many intents are examined}';

    protected $description = 'Queue order-ready emails for private test order-ready intents; refuses unless mail is configured.';

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
        $queued = 0;
        $hadUnexpectedError = false;
        $handled = 0;
        $examined = 0;
        $skipped = [];
        $budget = $limit * self::SCAN_FACTOR;
        $cycle = null;
        try {
            $cycle = $progress->start('new_intents');
        } catch (Throwable $error) {
            $hadUnexpectedError = true;
            $skipped['error'] = 1;
            Log::warning('Order-ready intent could not be queued.', ['exception_class' => $error::class]);
        }
        while ($cycle !== null && $handled < $limit && $examined < $budget) {
            try {
                $notice = $progress->reserve('new_intents', $cycle);
            } catch (Throwable $error) {
                $hadUnexpectedError = true;
                $skipped['error'] = ($skipped['error'] ?? 0) + 1;
                Log::warning('Order-ready intent could not be queued.', ['exception_class' => $error::class]);
                break;
            }
            if ($notice === null) {
                break;
            }
            $examined++;
            try {
                $adapter->queueOrderReady($notice['public_id'])['replayed'] ? null : $queued++;
                $handled++;
            } catch (NotificationException $error) {
                // Durable consideration advances even on refusal; the next finite pass revisits it.
                $skipped[$error->reason] = ($skipped[$error->reason] ?? 0) + 1;
            } catch (Throwable $error) {
                // A post-commit dispatch error may have retained queued work; queued recovery owns that gap.
                $hadUnexpectedError = true;
                Log::warning('Order-ready intent could not be queued.', ['exception_class' => $error::class]);
                $skipped['error'] = ($skipped['error'] ?? 0) + 1;
            }
        }
        $superseded = $this->withoutDelivery()->whereColumn('a.access_version', '>', 'n.access_version')->count();
        ksort($skipped);
        $this->line('queued='.$queued.' skipped='.array_sum($skipped).($skipped === [] ? '' : ' reasons='.http_build_query($skipped, '', ','))
            .' superseded='.$superseded);

        return $hadUnexpectedError ? self::FAILURE : self::SUCCESS;
    }

    /** Order-ready intents with no delivery row, joined to the customer account they were captured for. */
    private function withoutDelivery(): Builder
    {
        return DB::table('transactional_notices as n')->leftJoin('transactional_mail_deliveries as d', 'd.notice_id', '=', 'n.id')
            ->leftJoin('customer_accounts as a', 'a.id', '=', 'n.account_id')->whereNull('d.id')->where('n.notification_type', 'test_order_ready');
    }
}
