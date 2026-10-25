<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Mail\TransactionalMailAdapter;
use App\Domain\Notifications\Mail\TransactionalMailPolicy;
use App\Domain\Notifications\NotificationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Queues one email for each private order-ready intent that has none yet (FP-075), in a bounded batch. Refuses, recording
 * nothing, unless the transactional mail configuration is admitted. Prints counts and fixed reasons only.
 */
final class QueueTransactionalMail extends Command
{
    protected $signature = 'vasey:transactional-mail-queue {--limit=50 : Intents considered in this run, 1 to 100}';

    protected $description = 'Queue order-ready emails for private test order-ready intents; refuses unless mail is configured.';

    public function handle(TransactionalMailAdapter $adapter, TransactionalMailPolicy $policy): int
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
        $pending = DB::table('transactional_notices as n')->leftJoin('transactional_mail_deliveries as d', 'd.notice_id', '=', 'n.id')
            ->whereNull('d.id')->where('n.notification_type', 'test_order_ready')->orderBy('n.id')->limit($limit)->pluck('n.public_id');
        $queued = 0;
        $skipped = [];
        foreach ($pending as $notice) {
            try {
                $adapter->queueOrderReady($notice)['replayed'] ? null : $queued++;
            } catch (NotificationException $error) {
                $skipped[$error->reason] = ($skipped[$error->reason] ?? 0) + 1;
            }
        }
        ksort($skipped);
        $this->line('queued='.$queued.' skipped='.array_sum($skipped).($skipped === [] ? '' : ' reasons='.http_build_query($skipped, '', ',')));

        return self::SUCCESS;
    }
}
