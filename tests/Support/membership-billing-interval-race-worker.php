<?php

// Isolated native worker for BillingNativeIntervalRetrievalRaceTest (Codex P1 on PR #54, `BillingReconciliation.php:42`). Two of
// these processes retrieve the SAME invoice under the same binding, as two overlapping RetrieveMembershipInvoice jobs would.
//   role "a": takes its start position, signals "a-started" and stalls BEFORE its first provider read; it then reads a REFUNDED
//             graph (the provider reversed the payment while it was stalled), appends, and signals "a-done".
//   role "b": waits for "a-started" (so its start position is strictly later), reads a SETTLED graph, appends, and signals "b-done".
// Scenario "reversal_first": A waits for "b-read"; B signals "b-read" after its last provider read and waits for "a-done" before
//                            its end position and append. A appends first; B's older settled read must not become the tail.
// Scenario "settled_first":  A waits for "b-done"; B runs to completion. A's reversal overlaps B's read and is refused for retry.
// Both run outside any transaction (every wait sits inside a provider call), on their own MySQL connections.

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
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || getenv('VA_MEMBERSHIP_BILLING_INTERVAL_RACE_ONLY') !== '1') {
    throw new LogicException('Isolated native membership billing interval-race fixture only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$role = $input['role'] ?? null;
$scenario = $input['scenario'] ?? null;
if (! in_array($role, ['a', 'b'], true) || ! in_array($scenario, ['reversal_first', 'settled_first'], true)) {
    throw new LogicException('Known role and scenario only.');
}
F::configure();
$directory = getenv('VA_MEMBERSHIP_BILLING_INTERVAL_RACE_DIRECTORY');
$worker = getenv('VA_MEMBERSHIP_BILLING_INTERVAL_RACE_WORKER');
$pdo = DB::connection()->getPdo();
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
$wait = function (string $name) use ($directory): void {
    $deadline = microtime(true) + 60;
    while (! is_file($directory.'/'.$name)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Billing interval-race barrier timed out: '.$name);
        }
        usleep(2000);
        clearstatcache();
    }
};
$signal = function (string $name) use ($directory): void {
    file_put_contents($directory.'/'.$name.'.tmp', '1');
    rename($directory.'/'.$name.'.tmp', $directory.'/'.$name);
};
$inner = new RehearsalBillingGateway($role === 'a' ? F::graph(['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]]) : F::graph());
$gateway = new class($inner, $role, $scenario, $wait, $signal) implements BillingProviderGateway
{
    public function __construct(private RehearsalBillingGateway $inner, private string $role, private string $scenario, private Closure $wait, private Closure $signal) {}

    public function provenance(): string
    {
        return $this->inner->provenance();
    }

    public function account(): array
    {
        // The first provider read of a retrieval; its start position is already committed.
        if ($this->role === 'a') {
            ($this->signal)('a-started');
            ($this->wait)($this->scenario === 'reversal_first' ? 'b-read' : 'b-done');
        }

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
        if ($this->role === 'b' && $this->scenario === 'reversal_first') {
            ($this->signal)('b-read');
            ($this->wait)('a-done');
        }

        return $subscription;
    }
};

file_put_contents($directory.'/ready-'.$worker.'.tmp', (string) $connectionId);
rename($directory.'/ready-'.$worker.'.tmp', $directory.'/ready-'.$worker);
$wait('start');
if ($role === 'b') {
    // Begin strictly after A committed its start position.
    $wait('a-started');
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
    $signal($role === 'a' ? 'a-done' : 'b-done');
}
echo json_encode(['role' => $role, 'scenario' => $scenario, 'result' => $result, 'sequence' => $observation['sequence'] ?? null,
    'outcome' => $observation['outcome'] ?? null, 'position' => $observation['retrieval_position'] ?? null,
    'end_position' => $observation['retrieval_end_position'] ?? null, 'reason' => $reason ?? null, 'error_class' => $errorClass ?? null,
    'error_message' => $errorMessage ?? null, 'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
