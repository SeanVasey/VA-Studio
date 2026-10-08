<?php

// Reviewer native worker (lane 2, cross-invoice lock scope). Mode "split": while the parent holds FOR UPDATE on invoice A's identity
// row, time (1) the claim of a DIFFERENT invoice's identity row and (2) an append to that other invoice (its identity now exists).
// Mode "storm": with sibling workers released together, each claims and then appends repeatedly to its OWN invoice; every outcome
// and error is reported.
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingReconciliation;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\RehearsalBillingGateway;

require __DIR__.'/lane2-worker-common.php';
$run = function (Closure $work): array {
    $begin = microtime(true);
    try {
        $outcome = $work();
    } catch (BillingException $error) {
        $outcome = 'refused '.$error->reason;
    } catch (Throwable $error) {
        $outcome = 'error '.$error::class.': '.substr($error->getMessage(), 0, 160);
    }

    return [round(microtime(true) - $begin, 2), $outcome];
};
$invoice = $input['invoice'];
$graph = F::graph(['invoice' => ['id' => $invoice]]);
$ready();
$wait('start');
if ($input['mode'] === 'split') {
    $wait('locked');
    [$claimS, $claim] = $run(function () use ($input, $invoice) {
        $ledger = new BillingLedger;
        $row = $ledger->invoice($ledger->binding($input['binding_id'], (new BillingPolicy)->current()), $invoice);

        return 'claimed '.substr($row['id'], 0, 8);
    });
    [$appendS, $append] = $run(function () use ($input, $invoice, $graph) {
        $o = (new BillingReconciliation(new RehearsalBillingGateway($graph)))->retrieve($input['binding_id'], $invoice);

        return 'saved '.$o['outcome'].' seq='.$o['sequence'];
    });
    $signal('worker-done');
    echo json_encode(['claim_s' => $claimS, 'claim' => $claim, 'append_s' => $appendS, 'append' => $append, 'pid' => getmypid(), 'connection_id' => $connectionId], JSON_THROW_ON_ERROR);
    exit(0);
}
$outcomes = [];
foreach (range(1, (int) $input['rounds']) as $round) {
    [$s, $o] = $run(function () use ($input, $invoice, $graph) {
        $r = (new BillingReconciliation(new RehearsalBillingGateway($graph)))->retrieve($input['binding_id'], $invoice);

        return 'saved seq='.$r['sequence'];
    });
    $outcomes[] = $o.' ('.$s.'s)';
}
echo json_encode(['invoice' => $invoice, 'outcomes' => $outcomes, 'pid' => getmypid(), 'connection_id' => $connectionId], JSON_THROW_ON_ERROR);
