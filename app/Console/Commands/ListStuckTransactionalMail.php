<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only listing of order-ready deliveries left in "submitting" longer than a threshold (CX review F5). Such a row means a
 * worker stopped after claiming it, before or after the transport call; it is never resent, so an operator checks the
 * provider's logs for the delivery id before acting. Prints identifiers and times only, never a recipient.
 */
final class ListStuckTransactionalMail extends Command
{
    public const MAX_LISTED = 100;

    protected $signature = 'vasey:transactional-mail-stuck {--minutes=15 : Minimum time in submitting, 1 to 10080 minutes}'
        .' {--max=100 : Rows listed, 1 to 100; the count always covers every stuck delivery}';

    protected $description = 'List order-ready email deliveries stuck in submitting (read-only).';

    public function handle(): int
    {
        $minutes = filter_var($this->option('minutes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10080]]);
        if ($minutes === false) {
            $this->error('Use minutes from 1 to 10080.');

            return self::INVALID;
        }
        $max = filter_var($this->option('max'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_LISTED]]);
        if ($max === false) {
            $this->error('Use max from 1 to '.self::MAX_LISTED.'.');

            return self::INVALID;
        }
        $before = now()->utc()->subMinutes($minutes)->format('Y-m-d H:i:s');
        $stuck = fn () => DB::table('transactional_mail_deliveries')->where('state', 'submitting')->where('started_at', '<', $before);
        $total = $stuck()->count();
        $this->line('stuck='.$total.' minutes='.$minutes);
        foreach ($stuck()->orderBy('started_at')->orderBy('id')->limit($max)->get(['public_id', 'notice_public_id', 'started_at']) as $row) {
            $this->line('delivery='.$row->public_id.' notice='.$row->notice_public_id.' started_at='.$row->started_at.' UTC');
        }
        if ($total > $max) {
            $this->line('more='.($total - $max));
        }

        return self::SUCCESS;
    }
}
