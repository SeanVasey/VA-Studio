<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Commerce\ProductionCheckout\TaxExemptions;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Grants\Paid\PaidGrantDeadline;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantDownloads;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantFiles;
use App\Domain\Grants\Paid\PaidGrants;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\PaidGrantMonotonicClock;
use Tests\Support\ProductionCheckoutFixtures;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;
use Throwable;

// Loaded with this file, before any paid code runs in the process (see PaidGrantMonotonicClock).
class_exists(PaidGrantMonotonicClock::class);

/**
 * Codex P2 (comment 4222959609): completing a paid order re-verifies every line's original and assets. Each line's
 * physical verification now has its own 300 s bound (the render lease), and the final fulfillment frame a fresh 60 s
 * observation budget, so a large order that takes longer than 300 s in total can still be fulfilled. Verification time
 * is spent on the virtual paid-namespace monotonic clock (Tests\Support\PaidGrantMonotonicClock) by a container double of
 * PaidGrantFiles whose `verify()` is called once per line by completion only; no test sleeps. Synthetic rehearsal fixtures
 * only; no live payment or legal facts are certified.
 */
final class PaidGrantCompletionBudgetTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidGrantDependencyFixtures;
    use ProductionCheckoutJourneyFixture;

    protected function beforeRefreshingDatabase(): void
    {
        $this->preparePaidDependencies();
    }

    protected function setUp(): void
    {
        parent::setUp();
        PaidGrantMonotonicClock::reset();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.committed_read_receipts_enabled' => true,
            'production_checkout.committed_read_receipt_version' => 'production-checkout-committed-read-v1',
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => 'identity-historical-committed-receipt-v1',
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'paid-grants.rehearsal_enabled' => true,
            'paid-grants.delivery_policy' => ['schema_version' => 1, 'version' => 'explicit-synthetic-delivery-v1', 'purpose' => 'paid-original-delivery',
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        PaidGrantMonotonicClock::reset();
        parent::tearDown();
    }

    protected function smtp(string $mode, int $noticeId): array
    {
        $capture = tempnam(sys_get_temp_dir(), 'va-paid-synthetic-smtp-');
        $process = new Process(['python3', $this->paidDependencyPath('tests/Support/production_identity_smtp_sink.py'), $mode, $capture], timeout: 20);
        $process->start();
        try {
            $process->waitUntil(fn (): bool => preg_match('/\A[0-9]+\n/', $process->getOutput()) === 1);
            app()->instance(IdentityNoticeTransport::class, new LoopbackSmtp((int) trim($process->getOutput())));
            (new WorkIdentityNotice)->process($noticeId);
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            return filesize($capture) > 0 ? json_decode(file_get_contents($capture), true, 8, JSON_THROW_ON_ERROR) : [];
        } finally {
            $process->stop(0);
            unlink($capture);
        }
    }

    public function test_a_two_line_order_whose_verification_outlasts_one_lease_is_fulfilled_and_still_downloads(): void
    {
        $this->proveClockSeam();
        $f = $this->twoLineOrder();
        // 200 s per line: 400 s in total, past one 300 s lease, inside each line's own 300 s bound.
        $files = $this->slowVerification(200);

        $complete = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);

        $this->assertTrue($complete['fulfilled']);
        $this->assertSame(2, $files->verified, 'Completion verified each line once.');
        $this->assertCount(2, $complete['lines']);
        $this->assertSame([1, 1], array_column($complete['lines'], 'attempts'));
        $this->assertDatabaseCount('paid_originals', 2);
        $this->assertDatabaseCount('paid_fulfillments', 1);

        // The fulfilled order authorizes and redeems normally on every line.
        app()->forgetInstance(PaidGrantFiles::class);
        foreach ($complete['lines'] as $line) {
            $auth = (new PaidGrantDownloads)->authorize($complete['id'], $line['id'],
                ['requestKey' => (string) Str::uuid(), 'originHash' => $line['originHash'], 'kind' => 'master_wav', 'nonce' => bin2hex(random_bytes(32))],
                $f['buyer']['principal'], $f['buyer']['user']);
            $transfer = (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
            $bytes = '';
            $transfer->writeTo(function (string $chunk) use (&$bytes): void {
                $bytes .= $chunk;
            });
            $this->assertSame($transfer->sizeBytes, strlen($bytes));
            $this->assertSame($transfer->sha256, hash('sha256', $bytes));
        }
        $this->assertDatabaseCount('paid_redemptions', 2);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_a_line_exceeding_its_own_bound_is_refused_with_nothing_fulfilled_and_a_retry_resumes(): void
    {
        $f = $this->twoLineOrder();
        $files = $this->slowVerification(301);

        $this->assertSame(410, $this->refusedStatus(fn () => (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'])));
        $this->assertSame(1, $files->verified, 'The first line already overran its own bound.');
        $this->assertSame(['complete', 'complete'], DB::table('paid_document_work')->orderBy('id')->pluck('state')->all());
        $this->assertDatabaseCount('paid_originals', 2);
        $this->assertDatabaseCount('paid_fulfillments', 0);

        // Every line is already prepared, so the retry renders nothing new and only completes.
        app()->forgetInstance(PaidGrantFiles::class);
        $complete = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame([1, 1], array_column($complete['lines'], 'attempts'));
        $this->assertDatabaseCount('paid_originals', 2);
        $this->assertDatabaseCount('paid_fulfillments', 1);
    }

    public function test_a_retry_after_a_slow_completion_fulfils_without_restarting_from_an_expired_budget(): void
    {
        $f = $this->twoLineOrder();
        // First call: line 2 overruns its own bound after line 1 took 290 s; nothing is fulfilled.
        $files = $this->slowVerification(290, [2 => 301]);
        $this->assertSame(410, $this->refusedStatus(fn () => (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'])));
        $this->assertSame(2, $files->verified);
        $this->assertDatabaseCount('paid_fulfillments', 0);
        // Retry: 290 s per line (580 s in total) still completes, because each line and the final frame get fresh budgets.
        $files = $this->slowVerification(290);
        $complete = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame(2, $files->verified);
        $this->assertDatabaseCount('paid_fulfillments', 1);
        // An already fulfilled order re-verifies under the same per-line bounds and stays exactly fulfilled.
        $this->assertSame($complete, (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']));
        $this->assertDatabaseCount('paid_fulfillments', 1);
    }

    /** The virtual clock must reach paid code, or every scenario above would pass for the wrong reason. */
    private function proveClockSeam(): void
    {
        PaidGrantMonotonicClock::advance(1000);
        try {
            $this->assertGreaterThan(\hrtime(true) + 900_000_000_000, PaidGrantDeadline::start(1)->value(), 'The paid-namespace clock seam is not live.');
        } finally {
            PaidGrantMonotonicClock::reset();
        }
    }

    /**
     * Completion's per-line artifact verification takes `$seconds` virtual seconds (or `$per[n]` for the n-th call). The
     * double forwards every other call (the render path's `store()`) to the real, pinned PaidGrantFiles unchanged.
     *
     * @param  array<int, int>  $per
     */
    private function slowVerification(int $seconds, array $per = []): object
    {
        $files = new class(new PaidGrantFiles, $seconds, $per)
        {
            public int $verified = 0;

            public function __construct(private readonly PaidGrantFiles $files, private readonly int $seconds, private readonly array $per) {}

            public function verify(array|object $record): string
            {
                $bytes = $this->files->verify($record);
                $this->verified++;
                PaidGrantMonotonicClock::advance($this->per[$this->verified] ?? $this->seconds);

                return $bytes;
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->files->{$method}(...$arguments);
            }
        };
        app()->instance(PaidGrantFiles::class, $files);

        return $files;
    }

    /** Two paid lines, both prepared (rendered) with no time spent, then left unfulfilled for completion to verify. */
    private function twoLineOrder(): array
    {
        $catalog = ProductionCheckoutFixtures::catalog();
        $second = QuoteFixtures::selection(3499);
        $items = [...$catalog['items'], ...$second['items']];
        $buyer = $this->enrollThroughLocalSmtp();
        $access = new ProductionCustomerAccess;
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, ProductionCheckoutFixtures::exemptionPolicy($catalog), 'synthetic-two-lines-policy', $catalog['actor']);
        $basis = (new TaxExemptions($access))->qualify($buyer['principal'], $buyer['user'], $authority['public_id'], $items,
            ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:two-lines-buyer', 'source_sha256' => hash('sha256', 'NONBINDING TWO-LINE QUALIFICATION'),
                'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')], 'synthetic-two-lines-qualification', $catalog['actor']);
        $checkout = new ProductionCheckout($access);
        $review = $checkout->review($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $items, $basis['public_id'], ['legalName' => 'Declared synthetic buyer'], 'synthetic-two-lines-review');
        $order = $checkout->accept($buyer['principal'], $buyer['user'], $review['reviewId'], $review['reviewHash'], true, 'synthetic-two-lines-accept');
        $gateway = new ProductionCheckoutGatewayFixture;
        $hosted = new HostedCheckout($access, $gateway);
        $hosted->initiate($buyer['principal'], $buyer['user'], $order['orderId']);
        $gateway->paid = true;
        $this->assertSame('verified', $hosted->reconcile($buyer['principal'], $buyer['user'], $order['orderId'])['paymentStatus']);
        $batch = (new PaidGrants)->finalize($buyer['principal'], $buyer['user'], $order['orderId']);
        $this->assertCount(2, $batch['lines']);

        return compact('catalog', 'buyer', 'order', 'batch');
    }

    private function refusedStatus(Closure $call): int|string
    {
        try {
            $call();
        } catch (PaidGrantException $error) {
            return $error->status;
        } catch (Throwable $error) {
            return $error::class;
        }
        $this->fail('Expected a refusal.');
    }
}
