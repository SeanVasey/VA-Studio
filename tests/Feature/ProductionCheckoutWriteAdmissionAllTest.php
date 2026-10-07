<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\CheckoutCommandCommitDispatcher;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use App\Domain\Commerce\ProductionCheckout\TaxExemptions;
use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutFixtures as F;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/** NEW intent/basis/authority writes share the one c6 commit observer; replays and reads install none. */
class ProductionCheckoutWriteAdmissionAllTest extends TestCase
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

    public function test_committing_offer_withdrawal_refuses_new_intent_rolls_back_and_restores_dispatcher(): void
    {
        $f = $this->payable();
        $trackId = $f['catalog']['items'][0]['trackId'];
        $delegate = DB::connection()->getEventDispatcher();
        app('events')->listen(TransactionCommitting::class, function () use ($trackId): void {
            DB::table('offers')->where('track_id', $trackId)->update(['is_active' => false]);
        });
        $this->assertRefused('write_source_changed', fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $this->assertSame([], $f['gateway']->creates);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 0);
        $this->assertSame(0, DB::table('offers')->where('track_id', $trackId)->where('is_active', false)->count());
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_committing_fresh_withdrawal_refuses_new_intent(): void
    {
        $f = $this->payable();
        app('events')->listen(TransactionCommitting::class, function (): void {
            config(['production_checkout.fresh_checkout_enabled' => false]);
        });
        $this->assertRefused(null, fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $this->assertSame([], $f['gateway']->creates);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 0);
    }

    public function test_only_the_new_intent_frame_holds_the_observer_and_retry_record_status_reconcile_hold_none(): void
    {
        $f = $this->payable();
        $f['gateway']->loseFirstResponse = true;
        $observed = [];
        app('events')->listen(TransactionCommitting::class, function () use (&$observed): void {
            $observed[] = DB::connection()->getEventDispatcher() instanceof CheckoutCommandCommitDispatcher;
        });
        $this->assertRefused('provider_uncertain', fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        // Only the NEW intent frame; the uncertain append and identity frames hold no observer.
        $this->assertTrue($observed[0]);
        $this->assertNotContains(true, array_slice($observed, 1));
        $observed = [];
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertNotContains(true, $observed);
        $this->assertGreaterThan(3, count($observed));
        $this->assertCount(2, $f['gateway']->creates);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_capability_closed_after_intent_commit_refuses_before_first_create(): void
    {
        $f = $this->payable();
        $candidate = (array) DB::table(CapabilityHistory::CANDIDATES)->where('id', $f['catalog']['candidate']->id)->first();
        $f['hosted'] = new HostedCheckout($f['access'], new AdmissionRacingGateway($f['gateway'], function () use ($candidate, $f): void {
            DB::table(CapabilityHistory::CLOSURES)->insert(['production_track_capability_candidate_id' => $candidate['id'],
                'closed_by' => $f['catalog']['actor']->id, 'candidate_hash' => $candidate['payload_hash'],
                'closure_ciphertext' => 'SYNTHETIC CLOSURE AFTER THE INTENT COMMIT', 'closure_hash' => hash('sha256', 'synthetic-closure'),
                'canonicalization_version' => 'vasey-json-v1', 'created_at' => $candidate['created_at']]);
        }));
        $this->assertRefused(null, fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $this->assertSame([], $f['gateway']->creates);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['observation'], 0);
    }

    public function test_fresh_withdrawal_after_intent_commit_refuses_before_first_create_with_disabled(): void
    {
        $f = $this->payable();
        $f['hosted'] = new HostedCheckout($f['access'], new AdmissionRacingGateway($f['gateway'], function (): void {
            config(['production_checkout.fresh_checkout_enabled' => false]);
        }));
        $this->assertRefused('disabled', fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $this->assertSame([], $f['gateway']->creates);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['observation'], 0);
    }

    public function test_offer_withdrawn_before_retried_create_refuses_second_provider_call(): void
    {
        $f = $this->payable();
        $f['gateway']->loseFirstResponse = true;
        $this->assertRefused('provider_uncertain', fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $trackId = $f['catalog']['items'][0]['trackId'];
        $f['hosted'] = new HostedCheckout($f['access'], new AdmissionRacingGateway($f['gateway'], function () use ($trackId): void {
            DB::table('offers')->where('track_id', $trackId)->update(['is_active' => false]);
        }));
        $this->assertRefused('changed', fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $this->assertCount(1, $f['gateway']->creates);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['session'], 0);
    }

    public function test_committing_authoring_withdrawal_refuses_new_authority(): void
    {
        $f = F::catalog();
        app('events')->listen(TransactionCommitting::class, function (): void {
            config(['production_checkout.exemption_authoring_enabled' => false]);
        });
        $this->assertRefused(null, fn () => app(ApproveExemptionAuthority::class)->approve($f['candidate']->id, F::exemptionPolicy($f), 'synthetic-owner-policy', $f['actor']));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['authority'], 0);
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
    }

    public function test_committing_capability_closure_refuses_new_authority(): void
    {
        $f = F::catalog();
        $candidate = (array) DB::table(CapabilityHistory::CANDIDATES)->where('id', $f['candidate']->id)->first();
        app('events')->listen(TransactionCommitting::class, function () use ($candidate, $f): void {
            DB::table(CapabilityHistory::CLOSURES)->insert(['production_track_capability_candidate_id' => $candidate['id'],
                'closed_by' => $f['actor']->id, 'candidate_hash' => $candidate['payload_hash'],
                'closure_ciphertext' => 'SYNTHETIC CLOSURE IN THE SAME PHYSICAL COMMIT', 'closure_hash' => hash('sha256', 'synthetic-closure'),
                'canonicalization_version' => 'vasey-json-v1', 'created_at' => $candidate['created_at']]);
        });
        $this->assertRefused('write_source_changed', fn () => app(ApproveExemptionAuthority::class)->approve($f['candidate']->id, F::exemptionPolicy($f), 'synthetic-owner-policy', $f['actor']));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['authority'], 0);
        $this->assertDatabaseCount(CapabilityHistory::CLOSURES, 0);
    }

    public function test_committing_owner_delegation_and_offer_withdrawals_refuse_new_basis(): void
    {
        $catalog = F::catalog();
        $buyer = $this->enrollThroughLocalSmtp();
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, F::exemptionPolicy($catalog), 'synthetic-owner-policy', $catalog['actor']);
        $trackId = $catalog['items'][0]['trackId'];
        $withdrawals = [
            fn () => config(['production_checkout.exemption_policy_owner_ids' => []]),
            fn () => DB::table('offers')->where('track_id', $trackId)->update(['is_active' => false]),
        ];
        foreach ($withdrawals as $i => $withdraw) {
            $active = true;
            app('events')->listen(TransactionCommitting::class, function () use ($withdraw, &$active): void {
                if ($active) {
                    $withdraw();
                }
            });
            $this->assertRefused(null, fn () => $this->qualify($catalog, $buyer, $authority, 'synthetic-qualified-buyer-'.$i));
            $active = false;
            config(['production_checkout.exemption_policy_owner_ids' => [$catalog['actor']->id]]);
            $this->assertDatabaseCount(CheckoutSchema::TABLES['basis'], 0);
            $this->assertSame(0, DB::table('offers')->where('track_id', $trackId)->where('is_active', false)->count());
        }
    }

    public function test_staff_replays_install_no_observer_and_positive_caller_write_survives(): void
    {
        $catalog = F::catalog();
        $buyer = $this->enrollThroughLocalSmtp();
        $delegate = DB::connection()->getEventDispatcher();
        $primary = DB::connection()->getRawPdo();
        $primary->exec('CREATE TABLE checkout_admission_marker (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
        $observed = [];
        app('events')->listen(TransactionCommitting::class, function () use (&$observed, $primary): void {
            $observed[] = DB::connection()->getEventDispatcher() instanceof CheckoutCommandCommitDispatcher;
            // An ordinary committing caller write in the same physical commit survives a positive admission.
            $primary->exec('INSERT INTO checkout_admission_marker (id, value) VALUES ('.count($observed).', 9133)');
        });
        $policy = F::exemptionPolicy($catalog);
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, $policy, 'synthetic-owner-policy', $catalog['actor']);
        $this->assertSame($authority, app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, $policy, 'synthetic-owner-policy', $catalog['actor']));
        $basis = $this->qualify($catalog, $buyer, $authority, 'synthetic-qualified-buyer');
        $this->assertSame($basis, $this->qualify($catalog, $buyer, $authority, 'synthetic-qualified-buyer'));
        $this->assertSame([true, false, true, false], $observed);
        $this->assertSame(4, (int) $primary->query('SELECT COUNT(*) FROM checkout_admission_marker WHERE value = 9133')->fetchColumn());
        $this->assertDatabaseCount(CheckoutSchema::TABLES['authority'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['basis'], 1);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
    }

    private ?array $attestation = null;

    private function qualify(array $catalog, array $buyer, array $authority, string $key): array
    {
        // One attestation per test, so an exact replay presents identical request bytes.
        $this->attestation ??= ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:buyer-bound-qualification',
            'source_sha256' => hash('sha256', 'NONBINDING SYNTHETIC BUYER EXEMPTION'),
            'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
            'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')];

        return (new TaxExemptions(new ProductionCustomerAccess))->qualify($buyer['principal'], $buyer['user'], $authority['public_id'],
            $catalog['items'], $this->attestation, $key, $catalog['actor']);
    }

    private function assertRefused(?string $reason, \Closure $command): void
    {
        try {
            $command();
            $this->fail('A withdrawn NEW checkout write was admitted.');
        } catch (CheckoutException $error) {
            if ($reason !== null) {
                $this->assertSame($reason, $error->reason);
            }
        }
        $this->assertSame(0, DB::transactionLevel());
    }
}

/** Synthetic fixture delegate; runs one ordinary write at the first gateway contact of this initiate(). */
final class AdmissionRacingGateway implements ProviderGateway
{
    private bool $raced = false;

    public function __construct(private readonly ProductionCheckoutGatewayFixture $inner, private readonly \Closure $race) {}

    public function provenance(ExecutionContextV1 $context): string
    {
        if (! $this->raced) {
            $this->raced = true;
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

    public function paymentIntent(ExecutionContextV1 $context, string $paymentId): array
    {
        return $this->inner->paymentIntent($context, $paymentId);
    }
}
