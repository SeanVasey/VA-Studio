<?php

namespace App\Console\Commands;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingHintSweep;
use App\Domain\Memberships\Billing\BillingPolicy;
use Illuminate\Console\Command;

/**
 * Operator tool, never scheduled. Refuses while the billing policy is off (the default). Lists retained webhook hints that no
 * definitive observation covers; with --dispatch it queues one retrieval per uncovered invoice. It prints hashes and binding ids,
 * never provider references.
 *
 * A page examines --limit hints in `(received_at, id)` order. When more hints follow, it prints a signed cursor; --after=<cursor>
 * continues from there (Codex P1 on PR #54, review L2-2). --all follows the cursors itself until the hints are exhausted or
 * BillingHintSweep::MAX_SWEEP_HINTS were examined, and then prints the cursor that continues it. Each run with --dispatch queues one
 * retrieval per distinct uncovered invoice across every page it examined. A cursor continues one pass; start each new pass without
 * --after so that hints received out of clock order behind an old cursor are examined too.
 */
final class SweepMembershipBillingHints extends Command
{
    protected $signature = 'membership-billing:sweep-hints {--dispatch : Queue one retrieval per uncovered invoice (default is a dry run)} {--limit=100 : Retained hints to examine per page, 1 to 1000} {--after= : Continue after the cursor an earlier page printed} {--all : Page through every retained hint, up to the overall bound}';

    protected $description = 'List retained membership billing webhook hints that no retrieval has covered; --dispatch re-queues their retrievals.';

    public function handle(BillingHintSweep $sweep): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > BillingHintSweep::MAX_HINTS) {
            $this->error('Invalid --limit.');

            return self::INVALID;
        }
        $after = $this->option('after');
        try {
            $policy = new BillingPolicy;
            $configuration = $policy->current();
            $after = $after === null ? null : (string) $after;
            $result = $this->option('all') ? $sweep->scanAll($limit, $configuration, $after) : [...$sweep->scan($limit, $configuration, $after), 'pages' => 1];
            foreach ($result['pending'] as $hint) {
                $this->line(sprintf('uncovered event=%s type=%s received=%s invoice=%s binding=%s', substr($hint['event_hash'], 0, 12), $hint['type'],
                    $hint['received_at'], substr($hint['invoice_hash'], 0, 12), $hint['binding_id']));
            }
            if ($result['next'] !== null) {
                $this->warn($this->option('all')
                    ? sprintf('Stopped at the overall bound of %d hint(s) after %d page(s); more retained hints follow. Run again with --all --after=<cursor> to continue.', $result['examined'], $result['pages'])
                    : sprintf('More retained hints follow the %d examined. Run again with --after=<cursor> to continue, or use --all.', $result['examined']));
                $this->line('Next cursor: '.$result['next']);
            } else {
                $this->line(sprintf('Scan complete: %d hint(s) examined in %d page(s).', $result['examined'], $result['pages']));
            }
            $invoices = count(array_unique(array_column($result['pending'], 'invoice_hash')));
            if (! $this->option('dispatch')) {
                $this->info(sprintf('Dry run: %d uncovered hint(s) across %d invoice(s); %d examined hint(s) need nothing. Pass --dispatch to queue %d retrieval(s).',
                    count($result['pending']), $invoices, $result['settled'], $invoices));

                return self::SUCCESS;
            }
            $queued = $sweep->dispatch($result['pending'], $configuration, $policy);
            $this->info(sprintf('Dispatched %d retrieval(s) for %d uncovered hint(s).', $queued, count($result['pending'])));

            return self::SUCCESS;
        } catch (BillingException $error) {
            $this->error('Refused ('.$error->reason.').');

            return self::FAILURE;
        }
    }
}
