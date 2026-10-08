<?php

// Reviewer probe worker (Addendum 1). Evidence only; not part of the suite. Run from the worktree root by
// Addendum1ProbeTest on native MySQL. Two of these processes race BillingReconciliation::retrieve for one
// invoice. A second barrier inside the first provider call guarantees both have passed existingInvoice()
// (both saw no identity) before either claims the identity, so the claim itself is what races.

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingProviderGateway;
use App\Domain\Memberships\Billing\BillingReconciliation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingStripeFixtures;
use Tests\Support\RehearsalBillingGateway;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || getenv('VA_REVIEW_A1_RACE_ONLY') !== '1') {
    throw new LogicException('Isolated native reviewer race probe only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
BillingStripeFixtures::configure();
$directory = getenv('VA_REVIEW_A1_RACE_DIRECTORY');
$worker = getenv('VA_REVIEW_A1_RACE_WORKER');
$pdo = DB::connection()->getPdo();
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$pdo->exec('SET SESSION innodb_lock_wait_timeout=15');

$wait = function (string $name) use ($directory): void {
    $deadline = microtime(true) + 40;
    while (! is_file($directory.'/'.$name)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Reviewer race barrier timed out: '.$name);
        }
        usleep(2000);
        clearstatcache();
    }
};
$signal = function (string $name) use ($directory): void {
    file_put_contents($directory.'/'.$name.'.tmp', '1');
    rename($directory.'/'.$name.'.tmp', $directory.'/'.$name);
};

$inner = new RehearsalBillingGateway(BillingStripeFixtures::graph($input['graph'] ?? []), $input['fail'] ?? null);
$gateway = new class($inner, $worker, $wait, $signal) implements BillingProviderGateway
{
    public bool $barrierPassed = false;

    public function __construct(private RehearsalBillingGateway $inner, private string $worker, private Closure $wait, private Closure $signal) {}

    public function provenance(): string
    {
        return $this->inner->provenance();
    }

    public function account(): array
    {
        // Both workers are now past existingInvoice() and outside any transaction; release together.
        ($this->signal)('io-'.$this->worker);
        ($this->wait)('io-0');
        ($this->wait)('io-1');
        $this->barrierPassed = true;

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
        return $this->inner->retrieveSubscription($ref);
    }
};

$signal('ready-'.$worker);
$wait('start');
try {
    $observation = (new BillingReconciliation($gateway))->retrieve($input['binding_id'], BillingStripeFixtures::INVOICE);
    $result = 'saved';
} catch (BillingException $error) {
    $result = 'denied';
    $reason = $error->reason;
} catch (Throwable $error) {
    $result = 'unexpected';
    $errorClass = $error::class;
    $errorMessage = substr($error->getMessage(), 0, 200);
}
echo json_encode(['worker' => (int) $worker, 'binding_id' => $input['binding_id'], 'result' => $result, 'sequence' => $observation['sequence'] ?? null,
    'outcome' => $observation['outcome'] ?? null, 'invoice_id' => $observation['invoice_id'] ?? null, 'reason' => $reason ?? null,
    'error_class' => $errorClass ?? null, 'error_message' => $errorMessage ?? null, 'barrier_passed' => $gateway->barrierPassed,
    'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
