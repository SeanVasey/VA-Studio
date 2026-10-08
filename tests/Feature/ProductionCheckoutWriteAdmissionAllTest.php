<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\CheckoutCommandCommitDispatcher;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\CheckoutStaffWriteAdmission;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use App\Domain\Commerce\ProductionCheckout\TaxExemptions;
use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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
        // The frame's retained config snapshot refuses before the capsule's own fresh admission.
        $this->assertRefused('write_frame', fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $this->assertSame([], $f['gateway']->creates);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 0);
    }

    public function test_only_new_intent_and_pre_create_frames_hold_the_observer_and_record_status_reconcile_hold_none(): void
    {
        $f = $this->payable();
        $f['gateway']->loseFirstResponse = true;
        $observed = [];
        app('events')->listen(TransactionCommitting::class, function () use (&$observed): void {
            $observed[] = DB::connection()->getEventDispatcher() instanceof CheckoutCommandCommitDispatcher;
        });
        $this->assertRefused('provider_uncertain', fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        // The NEW intent frame and the proveCreatable() frame before the create hold the observer;
        // the uncertain append and identity frames hold none.
        $this->assertTrue($observed[0]);
        $this->assertSame(2, count(array_filter($observed)));
        $observed = [];
        // A retry writes no intent, but its final pre-create re-proof is still admitted at commit.
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame(1, count(array_filter($observed)));
        $observed = [];
        $f['gateway']->paid = true;
        $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertNotContains(true, $observed);
        $this->assertGreaterThan(1, count($observed));
        $this->assertCount(2, $f['gateway']->creates);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public static function reproveWithdrawals(): array
    {
        return ['offer, first initiate' => ['offer', false], 'capability, first initiate' => ['capability', false],
            'offer, retry' => ['offer', true], 'capability, retry' => ['capability', true]];
    }

    #[DataProvider('reproveWithdrawals')]
    public function test_withdrawal_in_the_pre_create_reprove_commit_refuses_before_provider_create(string $withdrawal, bool $retry): void
    {
        $f = $this->payable();
        $creates = 0;
        if ($retry) {
            $f['gateway']->loseFirstResponse = true;
            $this->assertRefused('provider_uncertain', fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
            $creates = 1;
        }
        $trackId = $f['catalog']['items'][0]['trackId'];
        $candidate = (array) DB::table(CapabilityHistory::CANDIDATES)->where('id', $f['catalog']['candidate']->id)->first();
        $delegate = DB::connection()->getEventDispatcher();
        $armed = false;
        $acted = 0;
        // Act on proveCreatable()'s own admitted read-only frame: the first observed commit after provenance().
        app('events')->listen(TransactionCommitting::class, function () use (&$armed, &$acted, $withdrawal, $trackId, $candidate, $f): void {
            if ($armed && $acted === 0 && DB::connection()->getEventDispatcher() instanceof CheckoutCommandCommitDispatcher) {
                $acted++;
                match ($withdrawal) {
                    'offer' => DB::table('offers')->where('track_id', $trackId)->update(['is_active' => false]),
                    'capability' => $this->closeCapability($candidate, $f['catalog']['actor']->id),
                };
            }
        });
        $f['hosted'] = new HostedCheckout($f['access'], new AdmissionRacingGateway($f['gateway'], function () use (&$armed): void {
            $armed = true;
        }));
        $this->assertRefused('write_source_changed', fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $this->assertSame(1, $acted);
        $this->assertCount($creates, $f['gateway']->creates);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['session'], 0);
        $this->assertDatabaseCount(CapabilityHistory::CLOSURES, 0);
        $this->assertSame(0, DB::table('offers')->where('track_id', $trackId)->where('is_active', false)->count());
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
    }

    #[DataProvider('reproveWithdrawals')]
    public function test_admitted_reproof_is_the_terminal_commit_before_provider_create(string $withdrawal, bool $retry): void
    {
        $f = $this->payable();
        $creates = 1;
        if ($retry) {
            $f['gateway']->loseFirstResponse = true;
            $this->assertRefused('provider_uncertain', fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
            $creates = 2;
        }
        $trackId = $f['catalog']['items'][0]['trackId'];
        $candidate = (array) DB::table(CapabilityHistory::CANDIDATES)->where('id', $f['catalog']['candidate']->id)->first();
        $state = ['armed' => false, 'observed' => false, 'admitted' => false, 'after' => 0, 'acted' => 0, 'at_create' => null];
        // Any framework transaction committing after the admitted re-proof and before create withdraws the selection.
        app('events')->listen(TransactionCommitting::class, function () use (&$state, $withdrawal, $trackId, $candidate, $f): void {
            $state['observed'] = DB::connection()->getEventDispatcher() instanceof CheckoutCommandCommitDispatcher;
            if ($state['admitted'] && ! $state['observed'] && $state['at_create'] === null) {
                $state['acted']++;
                match ($withdrawal) {
                    'offer' => DB::table('offers')->where('track_id', $trackId)->update(['is_active' => false]),
                    'capability' => $this->closeCapability($candidate, $f['catalog']['actor']->id),
                };
            }
        });
        app('events')->listen(TransactionCommitted::class, function () use (&$state): void {
            if ($state['armed'] && $state['at_create'] === null) {
                if ($state['observed']) {
                    $state['admitted'] = true;
                    $state['after'] = 0;
                } elseif ($state['admitted']) {
                    $state['after']++;
                }
            }
        });
        $f['hosted'] = new HostedCheckout($f['access'], new AdmissionTerminalProbeGateway($f['gateway'], function () use (&$state): void {
            $state['armed'] = true;
        }, function () use (&$state, $trackId): void {
            $state['at_create'] = [DB::table('offers')->where('track_id', $trackId)->where('is_active', true)->count(),
                DB::table(CapabilityHistory::CLOSURES)->count()];
        }));
        $result = $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame('unverified', $result['paymentStatus']);
        $this->assertTrue($state['admitted']);
        $this->assertSame(0, $state['after'], 'A framework transaction committed between the admitted re-proof and create.');
        $this->assertSame(0, $state['acted']);
        $this->assertSame([1, 0], $state['at_create']);
        $this->assertCount($creates, $f['gateway']->creates);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['session'], 1);
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
        // proveCreatable()'s retained raw history comparison refuses with the existing reason code.
        $this->assertRefused('changed', fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
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
        // The frame's retained config snapshot refuses first; the capsule's own check is proven separately below.
        $this->assertRefused('write_frame', fn () => app(ApproveExemptionAuthority::class)->approve($f['candidate']->id, F::exemptionPolicy($f), 'synthetic-owner-policy', $f['actor']));
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

    public static function basisWithdrawals(): array
    {
        return ['owner delegation' => ['owner', 'write_frame'], 'offer' => ['offer', 'write_source_changed']];
    }

    #[DataProvider('basisWithdrawals')]
    public function test_committing_owner_delegation_or_offer_withdrawal_refuses_new_basis(string $withdrawal, string $reason): void
    {
        $catalog = F::catalog();
        $buyer = $this->enrollThroughLocalSmtp();
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, F::exemptionPolicy($catalog), 'synthetic-owner-policy', $catalog['actor']);
        $trackId = $catalog['items'][0]['trackId'];
        app('events')->listen(TransactionCommitting::class, function () use ($withdrawal, $trackId): void {
            match ($withdrawal) {
                'owner' => config(['production_checkout.exemption_policy_owner_ids' => []]),
                'offer' => DB::table('offers')->where('track_id', $trackId)->update(['is_active' => false]),
            };
        });
        $this->assertRefused($reason, fn () => $this->qualify($catalog, $buyer, $authority, 'synthetic-qualified-buyer'));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['basis'], 0);
        $this->assertSame(0, DB::table('offers')->where('track_id', $trackId)->where('is_active', false)->count());
    }

    public function test_staff_capsule_fresh_admission_itself_refuses_authoring_and_owner_withdrawal(): void
    {
        $f = F::catalog();
        $refusals = [];
        app('events')->listen(TransactionCommitting::class, function () use (&$refusals): void {
            $observer = DB::connection()->getEventDispatcher();
            if (! $observer instanceof CheckoutCommandCommitDispatcher) {
                return;
            }
            // Test-only reach into the sealed observer: prove the capsule's own pure config check, which the
            // frame's config snapshot otherwise pre-empts during an ordinary commit.
            $admission = (new \ReflectionProperty(CheckoutCommandCommitDispatcher::class, 'admission'))->getValue($observer);
            $this->assertInstanceOf(CheckoutStaffWriteAdmission::class, $admission);
            $original = config('production_checkout');
            foreach ([['exemption_authoring_enabled' => false], ['exemption_policy_owner_ids' => []]] as $withdrawal) {
                config(['production_checkout' => [...$original, ...$withdrawal]]);
                try {
                    $admission->proveFresh();
                    $refusals[] = 'admitted';
                } catch (CheckoutException $error) {
                    $refusals[] = $error->reason;
                }
                config(['production_checkout' => $original]);
            }
            $admission->proveFresh();
        });
        app(ApproveExemptionAuthority::class)->approve($f['candidate']->id, F::exemptionPolicy($f), 'synthetic-owner-policy', $f['actor']);
        $this->assertSame(['authority', 'authority'], $refusals);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['authority'], 1);
    }

    public function test_committing_capability_closure_refuses_new_intent_and_provider_io(): void
    {
        $f = $this->payable();
        $candidate = (array) DB::table(CapabilityHistory::CANDIDATES)->where('id', $f['catalog']['candidate']->id)->first();
        $active = true;
        app('events')->listen(TransactionCommitting::class, function () use ($candidate, $f, &$active): void {
            if ($active) {
                $active = false;
                $this->closeCapability($candidate, $f['catalog']['actor']->id);
            }
        });
        $this->assertRefused('write_source_changed', fn () => $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $this->assertSame([], $f['gateway']->creates);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 0);
        $this->assertDatabaseCount(CapabilityHistory::CLOSURES, 0);
    }

    public static function staffWithdrawals(): array
    {
        return [
            'role' => [['is_admin' => false], 'is_admin', true],
            'mfa' => [['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null], 'app_authentication_secret', 'SYNTHETIC-ENROLLED-SECRET'],
        ];
    }

    #[DataProvider('staffWithdrawals')]
    public function test_committing_qualifier_staff_withdrawal_refuses_new_basis(array $withdrawal, string $column, mixed $original): void
    {
        $catalog = F::catalog();
        $buyer = $this->enrollThroughLocalSmtp();
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, F::exemptionPolicy($catalog), 'synthetic-owner-policy', $catalog['actor']);
        $this->enrollStaffMfa($catalog['actor']->id);
        $this->withdrawStaffDuringCommit($catalog['actor']->id, $withdrawal);
        $this->assertRefused('write_source_changed', fn () => $this->qualify($catalog, $buyer, $authority, 'synthetic-qualified-buyer'));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['basis'], 0);
        $this->assertStaffRestored($catalog['actor']->id, $column, $original);
    }

    #[DataProvider('staffWithdrawals')]
    public function test_committing_owner_staff_withdrawal_refuses_new_authority(array $withdrawal, string $column, mixed $original): void
    {
        $catalog = F::catalog();
        $this->enrollStaffMfa($catalog['actor']->id);
        $this->withdrawStaffDuringCommit($catalog['actor']->id, $withdrawal);
        $this->assertRefused('write_source_changed', fn () => app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id,
            F::exemptionPolicy($catalog), 'synthetic-owner-policy', $catalog['actor']));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['authority'], 0);
        $this->assertStaffRestored($catalog['actor']->id, $column, $original);
    }

    public function test_new_basis_admission_is_capped_at_the_selected_license_expiry(): void
    {
        // The only selected license expires shortly after qualify() seals its admission. Its rows stay
        // byte-identical, so only the original deadline cap can refuse the commit once it has expired.
        $until = CarbonImmutable::now('UTC')->addMinutes(10)->startOfSecond();
        $catalog = F::catalog($until->format('Y-m-d\TH:i:s\Z'));
        $buyer = $this->enrollThroughLocalSmtp();
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, F::exemptionPolicy($catalog), 'synthetic-owner-policy', $catalog['actor']);
        $slept = false;
        app('events')->listen(TransactionCommitting::class, function () use (&$slept): void {
            if (! $slept && DB::connection()->getEventDispatcher() instanceof CheckoutCommandCommitDispatcher) {
                $slept = true;
                // Real monotonic time passes the license expiry inside the same physical commit.
                usleep(2_500_000);
            }
        });
        CarbonImmutable::setTestNow($until->subMilliseconds(1500));
        try {
            $this->assertRefused('write_frame', fn () => $this->qualify($catalog, $buyer, $authority, 'synthetic-qualified-buyer'));
        } finally {
            CarbonImmutable::setTestNow();
        }
        $this->assertTrue($slept);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['basis'], 0);
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
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

    private function enrollStaffMfa(int $userId): void
    {
        DB::table('users')->where('id', $userId)->update(['app_authentication_secret' => 'SYNTHETIC-ENROLLED-SECRET',
            'app_authentication_recovery_codes' => 'SYNTHETIC-RECOVERY']);
    }

    /** One ordinary committing listener withdraws staff authority in the same physical commit as the NEW write. */
    private function withdrawStaffDuringCommit(int $userId, array $withdrawal): void
    {
        $active = true;
        app('events')->listen(TransactionCommitting::class, function () use ($userId, $withdrawal, &$active): void {
            if ($active) {
                $active = false;
                DB::table('users')->where('id', $userId)->update($withdrawal);
            }
        });
    }

    private function assertStaffRestored(int $userId, string $column, mixed $original): void
    {
        $value = DB::table('users')->where('id', $userId)->value($column);
        $this->assertSame($original, is_bool($original) ? (bool) $value : $value);
    }

    private function closeCapability(array $candidate, int $actorId): void
    {
        DB::table(CapabilityHistory::CLOSURES)->insert(['production_track_capability_candidate_id' => $candidate['id'],
            'closed_by' => $actorId, 'candidate_hash' => $candidate['payload_hash'],
            'closure_ciphertext' => 'SYNTHETIC CLOSURE IN THE SAME PHYSICAL COMMIT', 'closure_hash' => hash('sha256', 'synthetic-closure'),
            'canonicalization_version' => 'vasey-json-v1', 'created_at' => $candidate['created_at']]);
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

    private function assertRefused(string $reason, \Closure $command): void
    {
        try {
            $command();
            $this->fail('A withdrawn NEW checkout write was admitted.');
        } catch (CheckoutException $error) {
            $this->assertSame($reason, $error->reason);
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

/** Synthetic fixture delegate; arms at provenance() and probes the database immediately before create(). */
final class AdmissionTerminalProbeGateway implements ProviderGateway
{
    private bool $armed = false;

    public function __construct(private readonly ProductionCheckoutGatewayFixture $inner, private readonly \Closure $arm, private readonly \Closure $probe) {}

    public function provenance(ExecutionContextV1 $context): string
    {
        if (! $this->armed) {
            $this->armed = true;
            ($this->arm)();
        }

        return $this->inner->provenance($context);
    }

    public function account(ExecutionContextV1 $context): array
    {
        return $this->inner->account($context);
    }

    public function create(ExecutionContextV1 $context, array $params, string $key): array
    {
        ($this->probe)();

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
