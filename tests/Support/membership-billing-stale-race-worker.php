<?php

// Isolated native worker for BillingNativeStaleRetrievalRaceTest (review R-6). Two of these processes retrieve the SAME invoice
// under the same binding, as two overlapping RetrieveMembershipInvoice jobs would.
//   role "stale": reads a SETTLED graph, signals "stale-read" after its last provider read, then waits until "fresh" has finished
//                 before it appends. Its retrieval began first.
//   role "fresh": waits for "stale-read", then reads a REFUNDED graph (so its retrieval began strictly later), appends, and signals
//                 "fresh-done".
// Both run outside any transaction (the wait sits inside the provider call), on their own MySQL connections.

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingProviderGateway;
use App\Domain\Memberships\Billing\BillingReconciliation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\RehearsalBillingGateway;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || getenv('VA_MEMBERSHIP_BILLING_STALE_RACE_ONLY') !== '1') {
    throw new LogicException('Isolated native membership billing stale-race fixture only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
F::configure();
$directory = getenv('VA_MEMBERSHIP_BILLING_STALE_RACE_DIRECTORY');
$worker = getenv('VA_MEMBERSHIP_BILLING_STALE_RACE_WORKER');
$role = $input['role'];
$pdo = DB::connection()->getPdo();
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
$wait = function (string $name) use ($directory): void {
    $deadline = microtime(true) + 60;
    while (! is_file($directory.'/'.$name)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Billing stale-race barrier timed out: '.$name);
        }
        usleep(2000);
        clearstatcache();
    }
};
$signal = function (string $name) use ($directory): void {
    file_put_contents($directory.'/'.$name.'.tmp', '1');
    rename($directory.'/'.$name.'.tmp', $directory.'/'.$name);
};
$inner = new RehearsalBillingGateway($role === 'stale' ? F::graph() : F::graph(['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]]));
$gateway = new class($inner, $role, $wait, $signal) implements BillingProviderGateway
{
    public function __construct(private RehearsalBillingGateway $inner, private string $role, private Closure $wait, private Closure $signal) {}

    public function provenance(): string
    {
        return $this->inner->provenance();
    }

    public function account(): array
    {
        return $this->inner->account();
    }

    public function retrieveInvoice(string $ref): array
    {
        return $this->inner->retrieveInvoice($ref);
    }

    public function listInvoicePayments(string $invoiceRef): array
    {
        return $this->inner->listInvoicePayments($invoiceRef);
    }

    public function retrievePaymentIntent(string $ref): array
    {
        return $this->inner->retrievePaymentIntent($ref);
    }

    public function retrieveCharge(string $ref): array
    {
        return $this->inner->retrieveCharge($ref);
    }

    public function retrieveBalanceTransaction(string $ref): array
    {
        return $this->inner->retrieveBalanceTransaction($ref);
    }

    public function retrieveSubscription(string $ref): array
    {
        // The last provider read of a retrieval.
        $subscription = $this->inner->retrieveSubscription($ref);
        if ($this->role === 'stale') {
            ($this->signal)('stale-read');
            ($this->wait)('fresh-done');
        }

        return $subscription;
    }
};

file_put_contents($directory.'/ready-'.$worker.'.tmp', (string) $connectionId);
rename($directory.'/ready-'.$worker.'.tmp', $directory.'/ready-'.$worker);
$wait('start');
if ($role === 'fresh') {
    // Begin strictly after the stale worker finished reading, so its retrieval started later on the same clock.
    $wait('stale-read');
}
try {
    $observation = (new BillingReconciliation($gateway))->retrieve($input['binding_id'], F::INVOICE);
    $result = 'saved';
} catch (BillingException $error) {
    $result = 'denied';
    $reason = $error->reason;
} catch (Throwable $error) {
    $result = 'unexpected';
    $errorClass = $error::class;
    $errorMessage = substr($error->getMessage(), 0, 200);
} finally {
    if ($role === 'fresh') {
        $signal('fresh-done');
    }
}
echo json_encode(['role' => $role, 'result' => $result, 'sequence' => $observation['sequence'] ?? null, 'outcome' => $observation['outcome'] ?? null,
    'started_at' => $observation['retrieval_started_at'] ?? null, 'reason' => $reason ?? null, 'error_class' => $errorClass ?? null,
    'error_message' => $errorMessage ?? null, 'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
