<?php

// Reviewer native worker (lane 2, R-6 lock). Two of these retrieve the same invoice. "first" begins first and reads SETTLED;
// "second" begins after "first" signalled from its first provider call and reads REFUNDED. Both stop after their last provider
// read and append only when the parent releases "go", so the two appends contend for the identity-row lock at the same moment.
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingProviderGateway;
use App\Domain\Memberships\Billing\BillingReconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\RehearsalBillingGateway;

require __DIR__.'/lane2-worker-common.php';
$role = $input['role'];
$inner = new RehearsalBillingGateway($role === 'first' ? F::graph() : F::graph(['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]]));
$gateway = new class($inner, $role, $wait, $signal) implements BillingProviderGateway
{
    public function __construct(private RehearsalBillingGateway $inner, private string $role, private Closure $wait, private Closure $signal) {}

    public function provenance(): string { return $this->inner->provenance(); }

    public function account(): array
    {
        if ($this->role === 'first') {
            ($this->signal)('first-started');
        }

        return $this->inner->account();
    }

    public function retrieveInvoice(string $ref): array { return $this->inner->retrieveInvoice($ref); }

    public function listInvoicePayments(string $invoiceRef): array { return $this->inner->listInvoicePayments($invoiceRef); }

    public function retrievePaymentIntent(string $ref): array { return $this->inner->retrievePaymentIntent($ref); }

    public function retrieveCharge(string $ref): array { return $this->inner->retrieveCharge($ref); }

    public function retrieveBalanceTransaction(string $ref): array { return $this->inner->retrieveBalanceTransaction($ref); }

    public function retrieveSubscription(string $ref): array
    {
        $subscription = $this->inner->retrieveSubscription($ref);
        ($this->signal)('read-'.$this->role);
        ($this->wait)('go');

        return $subscription;
    }
};
$ready();
$wait('start');
if ($role === 'second') {
    $wait('first-started');
    usleep(20000);
}
$began = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
try {
    $observation = (new BillingReconciliation($gateway))->retrieve($input['binding_id'], F::INVOICE);
    $result = 'saved';
} catch (BillingException $error) {
    $result = 'denied';
    $reason = $error->reason;
} catch (Throwable $error) {
    $result = 'unexpected';
    $reason = $error::class.': '.substr($error->getMessage(), 0, 160);
}
echo json_encode(['role' => $role, 'result' => $result, 'reason' => $reason ?? null, 'sequence' => $observation['sequence'] ?? null,
    'outcome' => $observation['outcome'] ?? null, 'started_at' => $observation['retrieval_started_at'] ?? null, 'began' => $began,
    'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
