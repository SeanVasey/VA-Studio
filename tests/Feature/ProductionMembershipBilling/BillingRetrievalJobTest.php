<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingProviderGateway;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingValues;
use App\Jobs\RetrieveMembershipInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InterleavingBillingGateway;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/**
 * Addendum 1, A1-2 and A1-4. The retrieval job retries a bounded number of times: a first retrieval that ends unknown throws and
 * the queue retries it, and under an owned identity an unknown or incomplete outcome is appended (so every retry is visible in the
 * ledger) and the job is released while attempts remain. The serialized job carries no plaintext provider reference, and a
 * refusal's message names its reason code.
 */
class BillingRetrievalJobTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const T0 = F::PERIOD_START + 3600;

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        $this->at(0);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_job_makes_at_most_three_attempts_with_a_bounded_backoff(): void
    {
        $job = new RetrieveMembershipInvoice('binding-id', F::INVOICE);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 600], $job->backoff());
    }

    /** @return array<string, array{0: int, 1: ?int}> attempt number, expected release delay (null: the job completes) */
    public static function attempts(): array
    {
        return ['first attempt' => [1, 60], 'second attempt' => [2, 600], 'last attempt completes' => [3, null]];
    }

    #[DataProvider('attempts')]
    public function test_an_unknown_outcome_under_an_owned_identity_is_appended_then_released_while_attempts_remain(int $attempt, ?int $delay): void
    {
        $binding = F::binding();
        $this->handle($binding['id'], new RehearsalBillingGateway(F::graph()), 1, 0);
        $job = $this->handle($binding['id'], new RehearsalBillingGateway(F::graph(), 'charge'), $attempt, 10);

        $chain = (new BillingLedger)->observations((string) DB::table('production_membership_billing_invoices')->value('id'));
        $this->assertSame(['settled', 'unknown'], array_column($chain, 'outcome'), 'Every attempt is visible in the ledger.');
        if ($delay === null) {
            $job->assertNotReleased();
        } else {
            $job->assertReleased($delay);
        }
        $job->assertNotFailed();
    }

    public function test_a_provider_incomplete_outcome_under_an_owned_identity_is_appended_then_released(): void
    {
        $binding = F::binding();
        $this->handle($binding['id'], new RehearsalBillingGateway(F::graph()), 1, 0);
        $gateway = $this->incompleteGateway();
        $job = $this->handle($binding['id'], $gateway, 1, 10);

        $chain = (new BillingLedger)->observations((string) DB::table('production_membership_billing_invoices')->value('id'));
        $this->assertSame(['settled', 'refused'], array_column($chain, 'outcome'));
        $this->assertSame('provider_incomplete', BillingValues::decrypt($chain[1]['payload_ciphertext'])['reason']);
        $job->assertReleased(60);
    }

    /** @return array<string, array{0: string}> */
    public static function definitiveGraphs(): array
    {
        return ['settled' => ['settled'], 'not settled' => ['open'], 'reversed' => ['refunded'], 'refused' => ['refused']];
    }

    #[DataProvider('definitiveGraphs')]
    public function test_a_definitive_outcome_completes_the_job_without_a_release(string $kind): void
    {
        $binding = F::binding();
        $this->handle($binding['id'], new RehearsalBillingGateway(F::graph()), 1, 0);
        $graph = match ($kind) {
            'settled' => F::graph(),
            'open' => F::graph(['invoice' => ['status' => 'open']]),
            'refunded' => F::graph(['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]]),
            'refused' => F::graph(['invoice' => ['currency' => 'usd']]),
        };
        $job = $this->handle($binding['id'], new RehearsalBillingGateway($graph), 1, 10);
        $job->assertNotReleased();
        $job->assertNotFailed();
    }

    public function test_a_first_retrieval_that_ends_unknown_throws_so_the_queue_retries_and_writes_nothing(): void
    {
        $binding = F::binding();
        $job = (new RetrieveMembershipInvoice($binding['id'], F::INVOICE))->withFakeQueueInteractions();
        try {
            $job->handle(new BillingReconciliation(new RehearsalBillingGateway(F::graph(), 'invoice')));
            $this->fail('A first unknown retrieval is retried by the queue, not recorded.');
        } catch (BillingException $error) {
            $this->assertSame('provider_unavailable', $error->reason);
        }
        $this->assertSame([0, 0], [DB::table('production_membership_billing_invoices')->count(), DB::table('production_membership_billing_observations')->count()]);
        $job->assertNotReleased();
    }

    /**
     * Review R-6: the overtaken retrieval is a normal end, not a failure to retry. "Overtaken" means its read wholly preceded the
     * tail's: its end position committed before the newer retrieval's start (Codex P1 on PR #54, `BillingReconciliation.php:42`).
     * The newer retrieval therefore runs right after the stale one's end-position commit, not during its provider reads.
     */
    public function test_a_retrieval_overtaken_by_a_newer_one_completes_without_a_retry_and_records_nothing(): void
    {
        $binding = F::binding();
        $this->handle($binding['id'], new RehearsalBillingGateway(F::graph()), 1, 0);
        $this->at(10);
        $stale = new InterleavingBillingGateway(new RehearsalBillingGateway(F::graph()), function () use ($binding): void {
            $armed = true;
            Event::listen(TransactionCommitted::class, function () use (&$armed, $binding): void {
                if ($armed) {
                    $armed = false;
                    $this->handle($binding['id'], new RehearsalBillingGateway(F::graph(['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]])), 1, 20);
                    $this->at(30);
                }
            });
        });
        $job = (new RetrieveMembershipInvoice($binding['id'], F::INVOICE))->withFakeQueueInteractions();
        $job->handle(new BillingReconciliation($stale));

        $job->assertNotReleased();
        $job->assertNotFailed();
        $chain = (new BillingLedger)->observations((string) DB::table('production_membership_billing_invoices')->value('id'));
        $this->assertSame(['settled', 'reversed'], array_column($chain, 'outcome'));
    }

    /** @return array<string, array{0: int, 1: ?int}> attempt number, expected release delay (null: the last attempt fails the job) */
    public static function concurrentAttempts(): array
    {
        return ['first attempt' => [1, 60], 'second attempt' => [2, 600], 'last attempt fails visibly' => [3, null]];
    }

    /**
     * Codex P1 on PR #54 (`BillingReconciliation.php:42`): a read that overlapped the tail's read (it began before the tail's read
     * finished) is refused as `concurrent_retrieval`. The database cannot order the two reads, so it is not a normal end: the job is
     * released with its backoff and retries with a fresh interval, and the last attempt fails visibly. Nothing is appended.
     */
    #[DataProvider('concurrentAttempts')]
    public function test_a_retrieval_whose_read_overlapped_the_tails_read_is_released_with_the_backoff_and_records_nothing(int $attempt, ?int $delay): void
    {
        $binding = F::binding();
        $this->handle($binding['id'], new RehearsalBillingGateway(F::graph()), 1, 0);
        $this->at(10);
        $overlapping = new InterleavingBillingGateway(new RehearsalBillingGateway(F::graph()), function () use ($binding): void {
            $this->handle($binding['id'], new RehearsalBillingGateway(F::graph(['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]])), 1, 20);
            $this->at(30);
        });
        $job = (new RetrieveMembershipInvoice($binding['id'], F::INVOICE))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        try {
            $job->handle(new BillingReconciliation($overlapping));
            $this->assertNotNull($delay, 'The last attempt must fail visibly, not end normally.');
            $job->assertReleased($delay);
        } catch (BillingException $error) {
            $this->assertNull($delay, 'An overlapping read is released while attempts remain.');
            $this->assertSame('concurrent_retrieval', $error->reason);
            $job->assertNotReleased();
        }
        $chain = (new BillingLedger)->observations((string) DB::table('production_membership_billing_invoices')->value('id'));
        $this->assertSame(['settled', 'reversed'], array_column($chain, 'outcome'));
    }

    /** A1-4: the queue payload (and so `failed_jobs`) must not hold the plaintext provider invoice reference. */
    public function test_the_serialized_job_carries_no_plaintext_invoice_reference_and_still_retrieves_it(): void
    {
        $binding = F::binding();
        $job = new RetrieveMembershipInvoice($binding['id'], F::INVOICE);
        $serialized = serialize($job);
        $this->assertStringNotContainsString(F::INVOICE, $serialized);
        $this->assertStringNotContainsString(substr(F::INVOICE, 3), $serialized);
        $this->assertStringContainsString($binding['id'], $serialized);
        $this->assertDoesNotMatchRegularExpression('/\bin_[A-Za-z0-9]/', $serialized, 'No provider invoice reference shape survives in the payload.');

        $gateway = new RehearsalBillingGateway(F::graph());
        unserialize($serialized)->handle(new BillingReconciliation($gateway));
        $this->assertSame(1, DB::table('production_membership_billing_observations')->count());
        $this->assertContains('invoice', $gateway->calls);
    }

    /** A1-4: the failure a queue stores names the reason code (a code, never provider data). */
    public function test_a_first_retrieval_refusal_message_names_its_reason(): void
    {
        $binding = F::binding(['subscription_ref' => 'sub_WRONGSYNTHETIC']);
        $job = new RetrieveMembershipInvoice($binding['id'], F::INVOICE);
        try {
            $job->handle(new BillingReconciliation(new RehearsalBillingGateway(F::graph())));
            $this->fail('A first retrieval contradicting its binding is refused.');
        } catch (BillingException $error) {
            $this->assertSame('binding_refused_subscription', $error->reason);
            $this->assertStringContainsString('binding_refused_subscription', $error->getMessage());
            $this->assertStringNotContainsString(F::INVOICE, $error->getMessage());
            $this->assertStringNotContainsString($binding['id'], $error->getMessage());
        }
        try {
            BillingException::require(false, 'Bearer sk_test_LEAK');
        } catch (BillingException $error) {
            $this->assertStringNotContainsString('sk_test_LEAK', $error->getMessage(), 'Only code-shaped reasons enter a message.');
        }
    }

    private function handle(string $bindingId, BillingProviderGateway $gateway, int $attempt, int $offset): RetrieveMembershipInvoice
    {
        $this->at($offset);
        $job = (new RetrieveMembershipInvoice($bindingId, F::INVOICE))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        $job->handle(new BillingReconciliation($gateway));

        return $job;
    }

    /** A provider whose unbounded list the SDK gateway refuses with provider_incomplete. */
    private function incompleteGateway(): BillingProviderGateway
    {
        return new class(new RehearsalBillingGateway(F::graph())) implements BillingProviderGateway
        {
            public function __construct(private readonly RehearsalBillingGateway $inner) {}

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
                throw new BillingException('provider_incomplete');
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
    }

    private function at(int $offset): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(self::T0 + $offset));
    }
}
