<?php

namespace Tests\Canary;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * Codex P1 r4208264406: an offer or capability withdrawn in the SAME physical commit as the NEW
 * provider intent must not leave a committed intent, and initiate() must not reach provider I/O.
 * The third case is the listener-free race between prepare()'s commit and the first create.
 * Provider evidence is the synthetic gateway fixture only; no real Stripe call is possible.
 */
final class HostedIntentCommitAdmissionCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('j', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true]);
        Queue::fake();
    }

    public function test_committing_offer_deactivation_refuses_new_intent_and_provider_io(): void
    {
        $f = $this->payable();
        $trackId = $f['catalog']['items'][0]['trackId'];
        $callbacks = 0;
        app('events')->listen(TransactionCommitting::class, function () use ($trackId, &$callbacks): void {
            $callbacks++;
            DB::table('offers')->where('track_id', $trackId)->update(['is_active' => false]);
        });
        $refused = $this->initiate($f);
        $this->snapshot('offer', $f, $callbacks, $refused);
        $this->assertGreaterThan(0, $callbacks);
        $this->assertSame([], $f['gateway']->creates);
        $this->assertSame(CheckoutException::class, $refused);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 0);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['observation'], 0);
        $this->assertSame(0, DB::table('offers')->where('track_id', $trackId)->where('is_active', false)->count());
    }

    public function test_committing_capability_closure_refuses_new_intent_and_provider_io(): void
    {
        $f = $this->payable();
        $candidate = (array) DB::table(CapabilityHistory::CANDIDATES)->where('id', $f['catalog']['candidate']->id)->first();
        $callbacks = 0;
        app('events')->listen(TransactionCommitting::class, function () use ($candidate, $f, &$callbacks): void {
            if (++$callbacks === 1) {
                DB::table(CapabilityHistory::CLOSURES)->insert(['production_track_capability_candidate_id' => $candidate['id'],
                    'closed_by' => $f['catalog']['actor']->id, 'candidate_hash' => $candidate['payload_hash'],
                    'closure_ciphertext' => 'SYNTHETIC CLOSURE IN THE SAME PHYSICAL COMMIT', 'closure_hash' => hash('sha256', 'synthetic-closure'),
                    'canonicalization_version' => 'vasey-json-v1', 'created_at' => $candidate['created_at']]);
            }
        });
        $refused = $this->initiate($f);
        $this->snapshot('capability', $f, $callbacks, $refused);
        $this->assertGreaterThan(0, $callbacks);
        $this->assertSame([], $f['gateway']->creates);
        $this->assertSame(CheckoutException::class, $refused);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 0);
        $this->assertDatabaseCount(CapabilityHistory::CLOSURES, 0);
    }

    public function test_offer_withdrawn_after_intent_commit_before_first_create_refuses_provider_io(): void
    {
        $f = $this->payable();
        $trackId = $f['catalog']['items'][0]['trackId'];
        $gateway = new CanaryRacingGateway($f['gateway'], function () use ($trackId): void {
            // No transaction listener: an ordinary concurrent staff write lands after prepare() committed.
            DB::table('offers')->where('track_id', $trackId)->update(['is_active' => false]);
        });
        $f['hosted'] = new HostedCheckout($f['access'], $gateway);
        $refused = $this->initiate($f);
        $this->snapshot('race', $f, $gateway->raced, $refused);
        $this->assertSame(1, $gateway->raced);
        $this->assertSame([], $f['gateway']->creates);
        $this->assertSame(CheckoutException::class, $refused);
        // The request row was durably and validly committed before the withdrawal; it carries no session or payment.
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['session'], 0);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['observation'], 0);
    }

    /** Returns the refusal class (null when initiate() returned); assertions require a CheckoutException. */
    private function initiate(array $f): ?string
    {
        try {
            $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        } catch (\Throwable $error) {
            return $error::class;
        }

        return null;
    }

    private function snapshot(string $case, array $f, int $callbacks, ?string $refused): void
    {
        $counts = [];
        foreach (['intent', 'session', 'observation', 'payment'] as $kind) {
            $counts[$kind] = DB::table(CheckoutSchema::TABLES[$kind])->count();
        }
        $directory = sys_get_temp_dir().'/va-checkout-write-admission-all';
        @mkdir($directory, 0700, true);
        file_put_contents($directory.'/intent-'.$case.'.json', json_encode(['case' => $case, 'driver' => DB::getDriverName(),
            'callbacks' => $callbacks, 'refused' => $refused, 'provider_creates' => count($f['gateway']->creates), 'counts' => $counts], JSON_PRETTY_PRINT)."\n");
    }
}

/** Delegates to the synthetic fixture; runs one ordinary write at the first gateway contact after prepare() commits. */
final class CanaryRacingGateway implements ProviderGateway
{
    public int $raced = 0;

    public function __construct(private readonly ProductionCheckoutGatewayFixture $inner, private readonly \Closure $race) {}

    public function provenance(ExecutionContextV1 $context): string
    {
        if ($this->raced++ === 0) {
            ($this->race)();
        }

        return $this->inner->provenance($context);
    }

    public function account(ExecutionContextV1 $context): array
    {
        return $this->inner->account($context);
    }

    public function create(ExecutionContextV1 $context, array $params, string $key): array
    {
        return $this->inner->create($context, $params, $key);
    }

    public function retrieve(ExecutionContextV1 $context, string $sessionId): array
    {
        return $this->inner->retrieve($context, $sessionId);
    }

    public function paymentIntent(ExecutionContextV1 $context, string $id): array
    {
        return $this->inner->paymentIntent($context, $id);
    }
}
