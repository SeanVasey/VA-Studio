<?php

namespace Runtime\AwardUpgrade;

require_once __DIR__.'/AwardUpgradeRuntime.php';

use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Memberships\Billing\BillingDatabaseClock;
use App\Domain\Memberships\Billing\BillingEvidenceRecord;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingRetrievalSchema;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\SealedMembershipPaidInvoice;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\MembershipAwardFixtures;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/** Real new-source migration, authentication, reconciliation and public replay of the original award. */
final class NewWriterAwardReplayTest extends TestCase
{
    use AwardUpgradeRuntime;
    use MembershipAwardFixtures;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_forward_upgrade_reconciles_fresh_authority_and_replays_original_award_bytes(): void
    {
        $this->requirePrivateDatabase('/workspace/VA-Studio-billing-award-upgrade');
        $original = $this->readReceipt('old-context');
        $this->assertSame(0, DB::table('migrations')->where('migration', 'like', '%263000%')->count());
        $this->assertOriginalAwardRows();
        $this->assertOriginalIdentityRows();
        $this->assertOriginalBillingRows();
        $this->awardSetup();
        config(['production-membership-billing.approved_subscription_policy_hash' => $original['plan_policy_hash'],
            'production-memberships.enabled' => true, 'production-memberships.approved_policy_hash' => $original['plan_policy_hash']]);
        $this->assertSame($this->bytes($original['configuration_hashes']), $this->bytes($this->configurationHashes()), 'Same synthetic configuration and actual encryption key by hashes only.');
        $this->assertSame($original['synthetic_provider_graph_sha256'], CanonicalJson::hash(F::graph()));
        $this->awardTestCapabilities();
        $this->artisan('migrate', ['--force' => true,
            '--path' => 'database/migrations/2026_10_13_263000_membership_billing_retrieval_identity.php'])->assertExitCode(0);
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_10_13_263000_membership_billing_retrieval_identity')->count());
        (new BillingRetrievalSchema)->assertOwned(DB::connection()->getPdo());
        $this->assertOriginalAwardRows();
        $this->assertOriginalIdentityRows();
        $this->assertOriginalBillingRows();

        // Existing fixture credential authenticates the original retained buyer; no principal/binding is reconstructed.
        $buyer = (new ProductionCustomerSessions)->authenticate('buyer@example.test', 'MailboxPassword123');
        $this->assertIsArray($buyer);
        $this->assertSame($original['buyer_user_id'], $buyer['user']->getKey());
        $this->assertSame($original['buyer_account_id'], $buyer['principal']->accountId);
        $ledger = new BillingLedger;
        $this->assertNull($ledger->currentSettled($original['invoice_id']));
        $refusal = null;
        try {
            DB::transaction(fn () => (new SealedMembershipPaidInvoice)->lock($original['invoice_id'], $buyer['principal'], $buyer['user'], new MembershipRows));
        } catch (BillingException $error) {
            $refusal = $error->reason;
        }
        $this->assertSame('legacy_or_changed_evidence', $refusal, 'The real paid-invoice producer refuses the retained old observation as current authority.');
        $this->assertOriginalAwardRows();

        $before = BillingDatabaseClock::sample(DB::connection()->getPdo(), 'sqlite')->stamp19();
        $fresh = (new BillingReconciliation(new RehearsalBillingGateway(F::graph()), $ledger))
            ->retrieve($original['subscription_binding_id'], F::INVOICE);
        $after = BillingDatabaseClock::sample(DB::connection()->getPdo(), 'sqlite')->stamp19();
        $payload = BillingEvidenceRecord::verify($fresh, BillingEvidenceRecord::OBSERVATION);
        $this->assertSame(['settled', 2, 3], [$fresh['outcome'], $fresh['sequence'], $payload['schema_version']]);
        $this->assertSame($original['old_payload_facts_sha256'], CanonicalJson::hash($payload['facts']), 'Provider semantic facts, including paid_at, are identical.');
        $this->assertSame('2026-10-07 00:00:00', $payload['facts']['paid_at']);
        $this->assertGreaterThanOrEqual($before, $fresh['retrieved_at']);
        $this->assertLessThanOrEqual($after, $fresh['retrieved_at']);
        $this->assertSame($fresh['id'], $ledger->currentSettled($original['invoice_id'])['id']);
        $replayed = (new MembershipAwards)->award($original['invoice_id'], $buyer['principal'], $buyer['user']);

        $this->assertSame(file_get_contents(__DIR__.'/old-receipt.json'), $this->bytes($replayed), 'The new public writer returns exactly the old public award receipt.');
        $this->assertOriginalAwardRows();
        $this->assertOriginalIdentityRows();
        $this->assertOriginalBillingRows();
        $this->assertSame(file_get_contents(__DIR__.'/old-plans.json'), $this->bytes($this->rows('production_membership_plan_versions')));
        $this->assertSame(2, DB::table(BillingSchema::TABLES[2])->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->writeReceipt('new-receipt', $replayed);
        $this->writeReceipt('new-periods', $this->rows('production_membership_paid_periods'));
        $this->writeReceipt('new-events', $this->rows('production_membership_credit_events'));
        $this->writeReceipt('new-billing', $this->billingRows());
        $this->writeReceipt('new-identity', $this->identityRows());
        $this->writeReceipt('new-result', ['source' => '5dc1169156287bfa22ae91339aaeccbfe13674f7',
            'legacy_producer_refusal' => $refusal, 'reconciliation_outcome' => $fresh['outcome'], 'observation_schema_version' => $payload['schema_version'],
            'observation_sequence' => $fresh['sequence'], 'database_before' => $before, 'database_after' => $after,
            'fresh_retrieved_at' => $fresh['retrieved_at'], 'provider_paid_at' => $payload['facts']['paid_at'],
            'configuration_hashes' => $this->configurationHashes(), 'original_public_receipt_identical' => true,
            'original_period_and_event_bytes_identical' => true, 'original_identity_bytes_identical' => true,
            'original_billing_columns_identical' => true]);
    }
}
