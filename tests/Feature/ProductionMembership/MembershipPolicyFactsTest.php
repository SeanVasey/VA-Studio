<?php

namespace Tests\Feature\ProductionMembership;

use App\Domain\Memberships\Production\MembershipAwardTerms;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyDocument;
use App\Domain\Memberships\Production\MembershipPolicyFactsToken;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\SealedMembershipPolicyFacts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipPolicyFixtures;
use Tests\TestCase;

class MembershipPolicyFactsTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipPolicyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policySetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->app->detectEnvironment(fn () => 'testing');
        parent::tearDown();
    }

    public function test_real_staff_writer_retains_an_immutable_policy_and_the_approved_producer_proves_it(): void
    {
        $actor = $this->policyStaff();
        $created = $this->createPolicy($actor);
        $row = (array) DB::table(MembershipRecords::TABLE)->sole();
        $payload = MembershipRecords::verify($row);
        $this->assertSame('policy_version_authored', $payload['event']);
        $this->assertSame((int) $actor->id, $payload['author_user_id']);
        $this->assertStringNotContainsString('SYNTHETIC TEST TERMS', $row['payload_ciphertext']);
        $this->assertSame($created, $this->createPolicy($actor));
        $this->assertSame(1, DB::table(MembershipRecords::TABLE)->count());
        config(['production-memberships.enabled' => true, 'production-memberships.approved_policy_hash' => $created['policy_hash']]);
        DB::transaction(function () use ($created): void {
            $rows = new MembershipRows;
            $source = new SealedMembershipPolicyFacts;
            $proof = $source->lock($created['id'], $rows);
            $source->proveCurrent($proof, $rows);
            $binding = $proof->binding();
            $this->assertSame([$created['id'], 3, 1700, 'USD'], [$binding->planVersionId, $binding->creditAllowance, $binding->billingPriceMinor, $binding->billingCurrency]);
            $this->assertSame(['paid_period_award'], $binding->supportedOperations);
            $this->assertNull($binding->eligibleLicensePolicyHash);
            $this->assertNull($binding->reservationHonorPolicyHash);
            $this->assertSame('2026-11-02 00:00:00', $binding->awardTerms->expiresAt('2026-11-01 00:00:00'));
            $this->assertTrue($binding->awardTerms->admitsPayment('2026-11-01 00:00:00', '2026-11-01 00:59:59'));
            $this->assertFalse($binding->awardTerms->admitsPayment('2026-11-01 00:00:00', '2026-11-01 01:00:00'));
        });
        $this->assertSame(0, DB::table('production_membership_paid_periods')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
        $this->refused(fn () => (new MembershipPolicy)->current(), 'source_capability_absent');
        $this->assertSame($row, (array) DB::table(MembershipRecords::TABLE)->sole());
        foreach (['update', 'delete'] as $verb) {
            try {
                $query = DB::table(MembershipRecords::TABLE)->where('id', $created['id']);
                $verb === 'update' ? $query->update(['created_at' => '2026-01-01 00:00:00']) : $query->delete();
                $this->fail('Retained policy rows must be immutable.');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_different_policy_documents_create_distinct_immutable_versions(): void
    {
        $first = $this->createPolicy();
        $second = $this->createPolicy(overrides: ['credit_allowance' => 5]);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertNotSame($first['policy_hash'], $second['policy_hash']);
        $this->assertSame(2, DB::table(MembershipRecords::TABLE)->count());
    }

    public function test_authoring_is_separate_default_off_and_cannot_relabel_synthetic_provenance(): void
    {
        config(['production-memberships.authoring_enabled' => false]);
        $this->refused(fn () => $this->createPolicy(), 'disabled');
        config(['production-memberships.authoring_enabled' => true]);
        $this->refused(fn () => $this->createPolicy(overrides: ['provenance' => 'verified_production']), 'provenance');
        $this->app->detectEnvironment(fn () => 'production');
        $this->refused(fn () => $this->createPolicy(), 'provenance');
        $this->assertSame(0, DB::table(MembershipRecords::TABLE)->count());
    }

    public function test_nonstaff_unverified_unenrolled_and_stale_staff_cannot_author(): void
    {
        foreach ([[false, true, true], [true, false, true], [true, true, false]] as [$admin, $verified, $mfa]) {
            $actor = $this->policyStaff($mfa, ['is_admin' => $admin, 'email_verified_at' => $verified ? now() : null]);
            $this->refused(fn () => $this->createPolicy($actor), $admin && $verified ? 'mfa_required' : 'staff_refused');
        }
        $actor = $this->policyStaff();
        DB::table('users')->where('id', $actor->id)->update(['remember_token' => 'changed-synthetic-token']);
        $this->refused(fn () => $this->createPolicy($actor), 'staff_refused');
        $this->assertSame(0, DB::table(MembershipRecords::TABLE)->count());
    }

    public function test_gate_callback_revocation_rolls_back_the_policy_and_the_callback_write(): void
    {
        $actor = $this->policyStaff();
        $calls = 0;
        Gate::before(function () use ($actor, &$calls): ?bool {
            if (++$calls === 2) {
                DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
            }

            return null;
        });
        $this->refused(fn () => $this->createPolicy($actor));
        $this->assertSame(0, DB::table(MembershipRecords::TABLE)->count());
        $this->assertTrue((bool) DB::table('users')->where('id', $actor->id)->value('is_admin'));
        $this->assertGreaterThanOrEqual(2, $calls);
    }

    public function test_encryption_callback_withdrawal_refuses_before_the_policy_write(): void
    {
        $actor = $this->policyStaff();
        $real = Crypt::getFacadeRoot();
        $fake = Mockery::mock(\Illuminate\Contracts\Encryption\Encrypter::class);
        $fake->shouldReceive('encryptString')->once()->andReturnUsing(function ($plain) use ($real) {
            config(['production-memberships.authoring_enabled' => false]);

            return $real->encryptString($plain);
        });
        // Staff MFA decryption still uses the genuine encrypter.
        $fake->shouldReceive('decrypt')->andReturnUsing(fn (...$args) => $real->decrypt(...$args));
        $fake->shouldReceive('decryptString')->andReturnUsing(fn (...$args) => $real->decryptString(...$args));
        Crypt::swap($fake);
        $this->refused(fn () => $this->createPolicy($actor), 'changed_policy');
        $this->assertSame(0, DB::table(MembershipRecords::TABLE)->count());
    }

    public function test_writer_cannot_run_without_the_captured_outer_transaction(): void
    {
        $this->refused(fn () => new MembershipRows);
        DB::transaction(function (): void {
            DB::transaction(fn () => $this->refused(fn () => new MembershipRows));
        });
        $this->assertSame(0, DB::table(MembershipRecords::TABLE)->count());
    }

    public function test_unapproved_policy_and_changed_configuration_are_refused(): void
    {
        $created = $this->createPolicy();
        config(['production-memberships.enabled' => true, 'production-memberships.approved_policy_hash' => str_repeat('a', 64)]);
        DB::transaction(fn () => $this->refused(fn () => (new SealedMembershipPolicyFacts)->lock($created['id'], new MembershipRows), 'policy_not_approved'));
        config(['production-memberships.approved_policy_hash' => $created['policy_hash']]);
        DB::transaction(function () use ($created): void {
            $rows = new MembershipRows;
            $source = new SealedMembershipPolicyFacts;
            $proof = $source->lock($created['id'], $rows);
            config(['production-memberships.approved_policy_hash' => str_repeat('b', 64)]);
            $this->refused(fn () => $source->proveCurrent($proof, $rows), 'changed_policy');
        });
    }

    public function test_proofs_refuse_other_issuer_other_rows_forged_values_and_serialization(): void
    {
        $created = $this->createPolicy();
        config(['production-memberships.enabled' => true, 'production-memberships.approved_policy_hash' => $created['policy_hash']]);
        DB::transaction(function () use ($created): void {
            $rows = new MembershipRows;
            $source = new SealedMembershipPolicyFacts;
            $proof = $source->lock($created['id'], $rows);
            $this->refused(fn () => (new SealedMembershipPolicyFacts)->proveCurrent($proof, $rows), 'foreign_policy_proof');
            $this->refused(fn () => clone $source, 'clone_refused');
            $this->refused(fn () => $source->proveCurrent($proof, new MembershipRows), 'foreign_policy_proof');
            $this->refused(fn () => $source->proveCurrent(MembershipPolicyFactsToken::value($proof->binding()), $rows), 'foreign_policy_proof');
            $this->refused(fn () => clone $proof, 'clone_refused');
            $this->refused(fn () => serialize($proof), 'serialization_refused');
            $this->refused(fn () => json_encode($proof, JSON_THROW_ON_ERROR), 'serialization_refused');
            $source->proveCurrent($proof, $rows);
            $this->assertSame($created['id'], $proof->binding()->planVersionId);
        });
    }

    public function test_original_proof_deadline_is_not_renewed_by_revalidation(): void
    {
        $created = $this->createPolicy();
        config(['production-memberships.enabled' => true, 'production-memberships.approved_policy_hash' => $created['policy_hash']]);
        CarbonImmutable::setTestNow('2026-10-13 00:00:00 UTC');
        DB::transaction(function () use ($created): void {
            $rows = new MembershipRows;
            $source = new SealedMembershipPolicyFacts;
            $proof = $source->lock($created['id'], $rows);
            CarbonImmutable::setTestNow('2026-10-13 00:00:59 UTC');
            $source->proveCurrent($proof, $rows);
            CarbonImmutable::setTestNow('2026-10-13 00:01:00 UTC');
            $this->refused(fn () => $source->proveCurrent($proof, $rows), 'expired_policy_proof');
        });
    }

    public function test_a_commit_and_reopen_cannot_reuse_a_policy_proof(): void
    {
        $created = $this->createPolicy();
        config(['production-memberships.enabled' => true, 'production-memberships.approved_policy_hash' => $created['policy_hash']]);
        $source = new SealedMembershipPolicyFacts;
        DB::beginTransaction();
        $rows = new MembershipRows;
        $proof = $source->lock($created['id'], $rows);
        DB::commit();
        DB::beginTransaction();
        try {
            $this->refused(fn () => $source->proveCurrent($proof, $rows));
        } finally {
            DB::rollBack();
        }
    }

    public function test_retained_seals_ciphertexts_and_document_hashes_are_validated(): void
    {
        $created = $this->createPolicy();
        $original = (array) DB::table(MembershipRecords::TABLE)->sole();
        $variants = [];
        $variants[] = [...$original, 'seal' => str_repeat('0', 64)];
        $ciphertext = [...$original, 'payload_ciphertext' => 'not-valid-ciphertext'];
        $ciphertext['seal'] = MembershipRecords::seal($ciphertext);
        $variants[] = $ciphertext;
        $hash = [...$original, 'policy_hash' => str_repeat('b', 64)];
        $hash['seal'] = MembershipRecords::seal($hash);
        $variants[] = $hash;
        $provenance = [...$original, 'provenance' => 'verified_production'];
        $provenance['seal'] = MembershipRecords::seal($provenance);
        $variants[] = $provenance;
        foreach ($variants as $variant) {
            $this->refused(fn () => MembershipPolicyVersions::document($variant));
        }
        $this->assertSame($created['policy_hash'], MembershipPolicyVersions::document($original)->hash());
    }

    public function test_document_rejects_missing_unknown_unsupported_or_unbounded_facts(): void
    {
        $valid = $this->policyInput();
        $missing = $valid;
        unset($missing['credit_allowance']);
        $variants = [$missing, [...$valid, 'unknown' => true], [...$valid, 'credit_allowance' => '3'],
            [...$valid, 'billing_price_minor' => 0], [...$valid, 'billing_currency' => 'usd'], [...$valid, 'scope' => 'reserve'],
            [...$valid, 'award_terms' => ['expiry' => ['mode' => 'none', 'seconds' => 1], 'late_payment' => ['mode' => 'allow', 'seconds' => 0]]],
            [...$valid, 'award_terms' => ['expiry' => ['mode' => 'rollover', 'seconds' => 0], 'late_payment' => ['mode' => 'grace', 'seconds' => 0]]]];
        foreach ($variants as $variant) {
            $this->refused(fn () => MembershipPolicyDocument::from($variant));
        }
        $this->assertSame(0, DB::table(MembershipRecords::TABLE)->count());
    }

    public function test_award_terms_use_exact_provider_paid_time_and_explicit_expiry(): void
    {
        $terms = MembershipAwardTerms::from(['expiry' => ['mode' => 'period_end', 'seconds' => 0], 'late_payment' => ['mode' => 'before_period_end', 'seconds' => 0]]);
        $this->assertSame('2026-11-01 00:00:00', $terms->expiresAt('2026-11-01 00:00:00'));
        $this->assertTrue($terms->admitsPayment('2026-11-01 00:00:00', '2026-10-31 23:59:59'));
        $this->assertFalse($terms->admitsPayment('2026-11-01 00:00:00', '2026-11-01 00:00:00'));
        $terms = MembershipAwardTerms::from(['expiry' => ['mode' => 'none', 'seconds' => 0], 'late_payment' => ['mode' => 'allow', 'seconds' => 0]]);
        $this->assertNull($terms->expiresAt('2026-11-01 00:00:00'));
        $this->assertTrue($terms->admitsPayment('2026-11-01 00:00:00', '2027-11-01 00:00:00'));
        $this->refused(fn () => $terms->admitsPayment('2026-11-01 00:00:00', '2026-02-30 00:00:00'));
    }

    private function refused(callable $work, ?string $reason = null): void
    {
        try {
            $work();
            $this->fail('Expected a bounded membership refusal.');
        } catch (MembershipException $error) {
            $reason === null ? $this->assertNotEmpty($error->reason) : $this->assertSame($reason, $error->reason);
        }
    }
}
