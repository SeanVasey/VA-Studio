<?php

namespace App\Console\Commands;

use App\Domain\Inquiries\Notifications\InquiryNotificationWork;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only listing of inquiry alert intents an operator must check by hand (FP-013): a claim left in "processing" longer
 * than a threshold (a worker stopped before or after the transport call) and every "unknown" outcome (the transport raised
 * an error or the lease expired). Neither is ever resent, so check the mail provider's logs for the inquiry reference (the
 * `X-Vasey-Inquiry-Alert` header) before acting. It also counts, as `stale`, the unsent intents older than the alert window,
 * which are skipped for good. Prints identifiers, states and times only, never a recipient or content.
 */
final class ListStuckInquiryAlerts extends Command
{
    public const MAX_LISTED = 100;

    protected $signature = 'vasey:inquiry-alerts-stuck {--minutes=15 : Minimum time in processing, 1 to 10080 minutes}'
        .' {--max=100 : Rows listed, 1 to 100; the counts always cover every such intent}';

    protected $description = 'List inquiry alert intents stuck in processing or left unknown (read-only).';

    public function handle(InquiryNotificationWork $work): int
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
        // Intent timestamps are stored in the application clock, as InquiryNotificationWork writes them.
        $before = now()->subMinutes($minutes)->format('Y-m-d H:i:s');
        $stuck = fn () => DB::table('inquiry_notification_intents')->where('state', 'processing')->where('updated_at', '<', $before);
        $unknown = fn () => DB::table('inquiry_notification_intents')->where('state', 'unknown');
        [$stuckTotal, $unknownTotal] = [$stuck()->count(), $unknown()->count()];
        // Pending and retry intents older than the alert window (or all of them while the window is invalid) are never sent.
        $oldest = $work->oldestSendable();
        $stale = DB::table('inquiry_notification_intents')->whereIn('state', ['pending', 'retry'])
            ->when($oldest !== null, fn ($query) => $query->where('created_at', '<', $oldest->format('Y-m-d H:i:s')))->count();
        $this->line('stuck='.$stuckTotal.' unknown='.$unknownTotal.' stale='.$stale.' minutes='.$minutes);
        $rows = DB::table('inquiry_notification_intents as n')->join('customer_inquiries as i', 'i.id', '=', 'n.customer_inquiry_id')
            ->where(fn ($query) => $query->where('n.state', 'unknown')
                ->orWhere(fn ($claimed) => $claimed->where('n.state', 'processing')->where('n.updated_at', '<', $before)))
            ->orderByDesc('n.updated_at')->orderByDesc('n.id')->limit($max)
            ->get(['n.id', 'i.public_id', 'n.state', 'n.outcome', 'n.attempts', 'n.updated_at']);
        foreach ($rows as $row) {
            $this->line('intent='.$row->id.' inquiry='.$row->public_id.' state='.$row->state.' outcome='.($row->outcome ?? 'none')
                .' attempts='.$row->attempts.' updated_at='.$row->updated_at);
        }
        if ($stuckTotal + $unknownTotal > $max) {
            $this->line('more='.($stuckTotal + $unknownTotal - $max));
        }

        return self::SUCCESS;
    }
}
