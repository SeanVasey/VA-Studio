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
 */
final class SweepMembershipBillingHints extends Command
{
    protected $signature = 'membership-billing:sweep-hints {--dispatch : Queue one retrieval per uncovered invoice (default is a dry run)} {--limit=100 : Retained hints to examine, 1 to 1000}';

    protected $description = 'List retained membership billing webhook hints that no retrieval has covered; --dispatch re-queues their retrievals.';

    public function handle(BillingHintSweep $sweep): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > BillingHintSweep::MAX_HINTS) {
            $this->error('Invalid --limit.');

            return self::INVALID;
        }
        try {
            $policy = new BillingPolicy;
            $configuration = $policy->current();
            $result = $sweep->scan($limit, $configuration);
            foreach ($result['pending'] as $hint) {
                $this->line(sprintf('uncovered event=%s type=%s received=%s invoice=%s binding=%s', substr($hint['event_hash'], 0, 12), $hint['type'],
                    $hint['received_at'], substr($hint['invoice_hash'], 0, 12), $hint['binding_id']));
            }
            if ($result['truncated']) {
                $this->warn('More retained hints exist than --limit examined; run again with a higher --limit.');
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
