<?php

// Reviewer probe worker (Addendum 1, Codex "stale settled after reversal"). Evidence only; not part of the suite. Two of these
// processes retrieve the SAME invoice under the same binding, as two overlapping RetrieveMembershipInvoice jobs would.
//   role "stale": reads a SETTLED graph at t_read, then stalls before its append until "fresh" has appended. Its snapshot time
//                 (retrieved_at) is the moment it finished reading (t_read); its append happens later, on the real clock.
//   role "fresh": waits until "stale" has read, waits past that second, reads a REVERSED (refunded) graph and appends.
// The test then audits which observation BillingLedger::currentSettled() names current.

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingProviderGateway;
use App\Domain\Memberships\Billing\BillingReconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\RehearsalBillingGateway;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || getenv('VA_REVIEW_A1_RACE_ONLY') !== '1') {
    throw new LogicException('Isolated native reviewer race probe only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
F::configure();
$directory = getenv('VA_REVIEW_A1_RACE_DIRECTORY');
$worker = getenv('VA_REVIEW_A1_RACE_WORKER');
$role = $input['role'];
$pdo = DB::connection()->getPdo();
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$pdo->exec('SET SESSION innodb_lock_wait_timeout=30');
$wait = function (string $name) use ($directory): void {
    $deadline = microtime(true) + 300;
    while (! is_file($directory.'/'.$name)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Reviewer stale race barrier timed out: '.$name);
        }
        usleep(2000);
        clearstatcache();
    }
};
$signal = function (string $name) use ($directory): void {
    file_put_contents($directory.'/'.$name.'.tmp', '1');
    rename($directory.'/'.$name.'.tmp', $directory.'/'.$name);
};
$graph = $role === 'stale' ? F::graph() : F::graph(['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]]);
$inner = new RehearsalBillingGateway($graph);
$armed = false;
Event::listen(TransactionBeginning::class, function () use (&$armed): void {
    if ($armed) {
        $armed = false;
        CarbonImmutable::setTestNow();
    }
});
$arm = function () use (&$armed): void {
    $armed = true;
};
$gateway = new class($inner, $role, $wait, $signal, $arm) implements BillingProviderGateway
{
    public ?int $readAt = null;

    public function __construct(private RehearsalBillingGateway $inner, private string $role, private Closure $wait, private Closure $signal, private Closure $arm) {}

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
            $this->readAt = time();
            ($this->signal)('stale-read');
            ($this->wait)('fresh-appended');
            // The snapshot time (retrieved_at, computed right after this call returns) is the moment this worker finished reading.
            // The next transaction to begin (the append's own DB::transaction; the identity read uses raw PDO and opens none) restores
            // the real clock, so the append is stamped on real time. Run 1 used a Carbon test-now closure instead; that worker was
            // SIGKILLed (memcg OOM kill of a php8.4 process logged at the same time), so the closure is no longer used.
            CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($this->readAt));
            ($this->arm)();
        }

        return $subscription;
    }
};

file_put_contents($directory.'/ready-'.$worker.'.tmp', (string) $connectionId);
rename($directory.'/ready-'.$worker.'.tmp', $directory.'/ready-'.$worker);
$wait('start');
if ($role === 'fresh') {
    $wait('stale-read');
    // Strictly later second than the stale worker's read.
    $target = time() + 1;
    while (time() < $target) {
        usleep(20000);
    }
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
        $signal('fresh-appended');
    }
}
CarbonImmutable::setTestNow();
echo json_encode(['worker' => (int) $worker, 'role' => $role, 'result' => $result, 'sequence' => $observation['sequence'] ?? null,
    'outcome' => $observation['outcome'] ?? null, 'retrieved_at' => $observation['retrieved_at'] ?? null, 'created_at' => $observation['created_at'] ?? null,
    'stale_read_at' => $gateway->readAt, 'reason' => $reason ?? null, 'error_class' => $errorClass ?? null, 'error_message' => $errorMessage ?? null,
    'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
