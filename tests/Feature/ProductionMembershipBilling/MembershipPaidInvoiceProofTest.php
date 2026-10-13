<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Memberships\Billing\BillingDatabaseClock;
use App\Domain\Memberships\Billing\BillingDatabaseInstant;
use App\Domain\Memberships\Billing\BillingEvidenceRecord;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Billing\BillingValues;
use App\Domain\Memberships\Billing\BillingVerdict;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPaidInvoiceProof;
use App\Domain\Memberships\Production\MembershipPaidInvoiceToken;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\SealedMembershipPaidInvoice;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipAwardFixtures;
use Tests\TestCase;

class MembershipPaidInvoiceProofTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipAwardFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->awardSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_real_retained_billing_proves_original_buyer_and_provider_paid_time(): void
    {
        $fixture = $this->awardFixture();
        $proof = DB::transaction(function () use ($fixture) {
            $rows = new MembershipRows;
            $authority = new SealedMembershipPaidInvoice;
            $proof = $authority->lock($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows);
            $authority->proveCurrent($proof, $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows);
            $binding = $proof->binding();
            $this->assertSame($fixture['invoice']['source_invoice_hash'], $binding->sourceInvoiceHash);
            $this->assertSame([F::AMOUNT, F::CURRENCY, BillingValues::utc(F::PERIOD_START), BillingValues::utc(F::PERIOD_END)],
                [$binding->amountMinor, $binding->currency, $binding->paidAt, $binding->periodEnd]);
            $this->assertSame($fixture['plan']['id'], $binding->planVersionId);
            $this->assertSame(CanonicalJson::encode($fixture['buyer']['binding']), CanonicalJson::encode($binding->originalBuyerBinding));
            $this->assertSame($fixture['observation']['freshness_deadline'], $binding->evidenceDeadline);

            return $proof;
        });
        $this->assertNotEmpty($proof->binding()->sourceGraphHash);
        $this->assertSame(0, DB::table('production_membership_paid_periods')->count());
        $this->refused(fn () => (new MembershipPolicy)->current(), 'source_capability_absent');
    }

    public function test_tokens_cannot_be_forged_copied_serialized_or_reused_on_another_frame(): void
    {
        $fixture = $this->awardFixture();
        $authority = new SealedMembershipPaidInvoice;
        $buyer = $fixture['buyer'];
        $proof = DB::transaction(function () use ($fixture, $authority, $buyer) {
            $rows = new MembershipRows;
            $proof = $authority->lock($fixture['invoice']['id'], $buyer['principal'], $buyer['user'], $rows);
            $this->refused(fn () => (new SealedMembershipPaidInvoice)->proveCurrent($proof, $buyer['principal'], $buyer['user'], $rows), 'foreign_invoice_proof');
            $this->refused(fn () => $authority->proveCurrent(MembershipPaidInvoiceToken::value($proof->binding()), $buyer['principal'], $buyer['user'], $rows), 'foreign_invoice_proof');
            $this->refused(fn () => clone $proof, 'clone_refused');
            $this->refused(fn () => serialize($proof), 'serialization_refused');
            $this->refused(fn () => json_encode($proof), 'serialization_refused');

            return $proof;
        });
        DB::transaction(fn () => $this->refused(fn () => $authority->proveCurrent($proof, $buyer['principal'], $buyer['user'], new MembershipRows), 'foreign_or_expired_invoice_proof'));
    }

    public function test_newer_nonsettled_observation_and_original_freshness_deadline_refuse_authority(): void
    {
        $fixture = $this->awardFixture();
        DB::transaction(function () use ($fixture): void {
            $rows = new MembershipRows;
            $authority = new SealedMembershipPaidInvoice;
            $buyer = $fixture['buyer'];
            $proof = $authority->lock($fixture['invoice']['id'], $buyer['principal'], $buyer['user'], $rows);
            $window = $authority->freshnessWindow($proof, $buyer['principal'], $buyer['user'], $rows);
            // Synthetic consumption of the real issued cap; this is not 600 seconds of native elapsed time.
            (new \ReflectionProperty($window, 'deadline'))->setValue($window, \hrtime(true) - 1);
            $this->refused(fn () => $authority->proveCurrent($proof, $buyer['principal'], $buyer['user'], $rows), 'expired_invoice_proof');
        });
        $this->awardObservation($fixture, new BillingVerdict('unknown', 'provider_unavailable', []));
        $this->refused(fn () => $this->proof($fixture), 'invoice_not_settled');
    }

    public function test_a_hint_after_retrieval_requires_a_new_retrieval_before_authority(): void
    {
        $fixture = $this->awardFixture();
        $received = $this->awardHint();
        $this->assertFalse($received['duplicate']);
        $this->refused(fn () => $this->proof($fixture), 'uncovered_invoice_hint');
        $this->awardObservation($fixture);
        $this->assertSame($fixture['invoice']['id'], $this->proof($fixture)->binding()->invoiceId);
    }

    public function test_identical_retrieval_changes_evidence_but_preserves_semantic_invoice_graph(): void
    {
        $fixture = $this->awardFixture();
        $first = $this->proof($fixture)->binding();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(1));
        $secondRow = $this->awardObservation($fixture);
        $second = $this->proof($fixture)->binding();
        $this->assertNotSame($fixture['observation']['id'], $secondRow['id']);
        $this->assertNotSame($first->observationId, $second->observationId);
        $this->assertNotSame($first->observationSeal, $second->observationSeal);
        $this->assertSame($first->sourceGraphHash, $second->sourceGraphHash);
    }

    public function test_existing_proof_refuses_a_new_observation_even_with_identical_payment_facts(): void
    {
        $fixture = $this->awardFixture();
        $ledger = new BillingLedger;
        $start = $ledger->startRetrieval();
        $end = $ledger->endRetrieval($start);
        DB::transaction(function () use ($fixture, $start, $end): void {
            $rows = new MembershipRows;
            $authority = new SealedMembershipPaidInvoice;
            $buyer = $fixture['buyer'];
            $proof = $authority->lock($fixture['invoice']['id'], $buyer['principal'], $buyer['user'], $rows);
            $instant = BillingDatabaseInstant::fromNativeValue($end->databaseAt, 0);
            $row = [...$fixture['observation'], ...$end->columns(), 'id' => BillingValues::id(), 'sequence' => 2,
                'retrieval_started_at' => $start->databaseAt, 'retrieved_at' => $instant->stamp19(),
                'freshness_deadline' => BillingValues::utc($instant->seconds() + BillingLedger::FRESHNESS_SECONDS),
                'created_at' => BillingDatabaseClock::sample($rows->identity(), DB::getDriverName())->stamp19(),
                'prior_seal' => $fixture['observation']['seal']];
            $payload = BillingValues::decrypt($row['payload_ciphertext']);
            unset($payload['schema_version'], $payload['row_binding']);
            $row['payload_ciphertext'] = BillingEvidenceRecord::encrypt($row, $payload);
            $row['seal'] = BillingValues::seal($row);
            $statement = $rows->identity()->prepare('INSERT INTO '.(new BillingSchema)->table(BillingSchema::TABLES[2])
                .' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
            $statement->execute(array_values($row));
            $this->assertSame($fixture['observation']['facts_hash'], $row['facts_hash']);
            $this->refused(fn () => $authority->proveCurrent($proof, $buyer['principal'], $buyer['user'], $rows), 'changed_invoice_facts');
        });
    }

    public function test_observation_envelope_binds_deadline_sequence_and_positions_beyond_the_outer_checksum(): void
    {
        $fixture = $this->awardFixture();
        $row = $fixture['observation'];
        foreach (['freshness_deadline' => '2026-10-14 12:00:00', 'retrieved_at' => '2026-10-13 12:01:00',
            'sequence' => 2, 'retrieval_position' => 9001, 'retrieval_end_position' => 9002, 'prior_seal' => str_repeat('a', 64)] as $key => $value) {
            $changed = [...$row, $key => $value];
            $changed['seal'] = BillingValues::seal($changed);
            $this->refused(fn () => BillingEvidenceRecord::verify($changed, 'production_membership_billing_observation'), 'legacy_or_changed_evidence');
        }
        $payload = BillingValues::decrypt($row['payload_ciphertext']);
        unset($payload['row_binding']);
        $payload['schema_version'] = 1;
        $row['payload_ciphertext'] = BillingValues::encrypt($payload);
        $row['seal'] = BillingValues::seal($row);
        $this->refused(fn () => BillingEvidenceRecord::verify($row, 'production_membership_billing_observation'), 'legacy_or_changed_evidence');
    }

    public function test_another_current_customer_cannot_use_the_approved_buyers_invoice(): void
    {
        $fixture = $this->awardFixture();
        $fixture['buyer'] = $this->enrollThroughLocalSmtp('different-member@example.test');
        $this->refused(fn () => $this->proof($fixture), 'invoice_owner_mismatch');
        $this->assertSame(0, DB::table('production_membership_paid_periods')->count());
    }

    private function proof(array $fixture): MembershipPaidInvoiceProof
    {
        return DB::transaction(fn () => (new SealedMembershipPaidInvoice)->lock($fixture['invoice']['id'],
            $fixture['buyer']['principal'], $fixture['buyer']['user'], new MembershipRows));
    }

    private function refused(callable $work, ?string $reason = null): void
    {
        try {
            $work();
            $this->fail('Expected retained invoice proof refusal.');
        } catch (MembershipException|BillingException|IdentityException $error) {
            $reason === null ? $this->assertNotEmpty($error->getMessage()) : $this->assertSame($reason, $error->reason);
        }
    }
}
