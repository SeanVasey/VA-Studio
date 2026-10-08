<?php

// Reviewer native worker (lane 2, lock scope). While the parent holds SELECT ... FOR UPDATE on the invoice identity row, this runs
// a duplicate webhook delivery (coverage + dispatch, queue faked), the sweep scan, a claim + append for ANOTHER invoice, then a
// retrieval of the SAME invoice. It signals "readers-done" after the first three and reports each elapsed time.
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingHintSweep;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\RehearsalBillingGateway;

require __DIR__.'/lane2-worker-common.php';
$time = function (Closure $work): array {
    $begin = microtime(true);
    try {
        $outcome = $work();
    } catch (BillingException $error) {
        $outcome = 'refused '.$error->reason;
    } catch (Throwable $error) {
        $outcome = 'error '.$error::class.': '.substr($error->getMessage(), 0, 120);
    }

    return [round(microtime(true) - $begin, 2), $outcome];
};
$ready();
$wait('start');
$wait('locked');
Queue::fake();
[$intakeS, $intake] = $time(function () use ($input) {
    $r = (new BillingWebhookIntake)->receive($input['payload'], $input['signature']);

    return 'duplicate='.($r['duplicate'] ? 'yes' : 'no').' scheduled='.($r['scheduled'] === null ? 'null' : 'dispatched');
});
[$sweepS, $sweep] = $time(function () {
    $r = (new BillingHintSweep)->scan(100, (new BillingPolicy)->current());

    return 'examined='.$r['examined'].' pending='.count($r['pending']);
});
[$otherS, $other] = $time(function () use ($input) {
    $o = (new BillingReconciliation(new RehearsalBillingGateway(F::graph(['invoice' => ['id' => 'in_SYNTHETICOTHERLOCK']]))))->retrieve($input['binding_id'], 'in_SYNTHETICOTHERLOCK');

    return 'saved '.$o['outcome'].' seq='.$o['sequence'];
});
$signal('readers-done');
[$sameS, $same] = $time(function () use ($input) {
    $o = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($input['binding_id'], F::INVOICE);

    return 'saved '.$o['outcome'].' seq='.$o['sequence'];
});
echo json_encode(['intake_s' => $intakeS, 'intake' => $intake, 'sweep_s' => $sweepS, 'sweep' => $sweep, 'other_s' => $otherS, 'other' => $other,
    'same_s' => $sameS, 'same' => $same, 'connection_id' => $connectionId, 'pid' => getmypid()], JSON_THROW_ON_ERROR);
