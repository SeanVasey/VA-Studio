<?php

// Isolated native worker for BillingNativeCrossInvoiceClaimTest (Codex P2 on PR #54, `BillingLedger.php:173`). While the parent
// process holds SELECT ... FOR UPDATE on invoice A's identity row (as BillingLedger::append()'s lockIdentity() does), this worker,
// on its own MySQL connection with a short innodb_lock_wait_timeout, (1) claims the identity row of an unrelated invoice B and
// (2) runs a complete first retrieval of another unrelated invoice C (claim and append). Neither touches invoice A's row, so
// neither may wait for A's lock. Each step reports its elapsed time and outcome; it never runs inside a transaction of its own.

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingReconciliation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\RehearsalBillingGateway;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || getenv('VA_MEMBERSHIP_BILLING_CROSS_INVOICE_ONLY') !== '1') {
    throw new LogicException('Isolated native membership billing cross-invoice fixture only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$timeout = $input['lock_wait_timeout_seconds'] ?? null;
if (! is_int($timeout) || $timeout < 1 || $timeout > 10) {
    throw new LogicException('Bounded lock wait timeout only.');
}
F::configure();
$directory = getenv('VA_MEMBERSHIP_BILLING_CROSS_INVOICE_DIRECTORY');
$pdo = DB::connection()->getPdo();
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$pdo->exec('SET SESSION innodb_lock_wait_timeout='.$timeout);
$wait = function (string $name) use ($directory): void {
    $deadline = microtime(true) + 60;
    while (! is_file($directory.'/'.$name)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Billing cross-invoice barrier timed out: '.$name);
        }
        usleep(2000);
        clearstatcache();
    }
};
$signal = function (string $name) use ($directory): void {
    file_put_contents($directory.'/'.$name.'.tmp', '1');
    rename($directory.'/'.$name.'.tmp', $directory.'/'.$name);
};
$run = function (Closure $work): array {
    $begin = microtime(true);
    try {
        $outcome = $work();
    } catch (BillingException $error) {
        $outcome = 'refused '.$error->reason;
    } catch (Throwable $error) {
        $outcome = 'error '.$error::class.': '.substr($error->getMessage(), 0, 160);
    }

    return [round(microtime(true) - $begin, 3), $outcome];
};

file_put_contents($directory.'/ready.tmp', (string) $connectionId);
rename($directory.'/ready.tmp', $directory.'/ready');
$wait('locked');
[$claimSeconds, $claim] = $run(function () use ($input): string {
    $ledger = new BillingLedger;
    $row = $ledger->invoice($ledger->binding($input['binding_id'], (new BillingPolicy)->current()), $input['claim_invoice']);

    return 'claimed '.strlen($row['id']);
});
[$retrieveSeconds, $retrieve] = $run(function () use ($input): string {
    $invoice = $input['retrieve_invoice'];
    $observation = (new BillingReconciliation(new RehearsalBillingGateway(F::graph(['invoice' => ['id' => $invoice]]))))->retrieve($input['binding_id'], $invoice);

    // The rehearsal graph's payments name F::INVOICE, so the verdict for another invoice may be a recorded refusal; any appended
    // observation shows the claim and the append both completed.
    return 'saved sequence '.$observation['sequence'];
});
$signal('worker-done');
echo json_encode(['claim_seconds' => $claimSeconds, 'claim' => $claim, 'retrieve_seconds' => $retrieveSeconds, 'retrieve' => $retrieve,
    'lock_wait_timeout' => (int) $pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn(),
    'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
