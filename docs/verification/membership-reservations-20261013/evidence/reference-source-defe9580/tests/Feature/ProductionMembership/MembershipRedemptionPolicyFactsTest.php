<?php

namespace Tests\Feature\ProductionMembership;

use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyDocument;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\SealedMembershipPolicyFacts;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipPolicyFixtures;
use Tests\TestCase;

class MembershipRedemptionPolicyFactsTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipPolicyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policySetup();
    }

    public function test_current_mfa_author_retains_v2_and_real_producer_preserves_v1_without_mutating_or_upgrading_it(): void
    {
        $author = $this->policyStaff();
        $v1 = $this->createPolicy($author);
        $old = (array) DB::table(MembershipRecords::TABLE)->where('id', $v1['id'])->sole();
        $v2 = $this->createPolicy($author, $this->creditInput());
        $this->assertNotSame($v1['id'], $v2['id']);
        $this->assertNotSame($v1['policy_hash'], $v2['policy_hash']);
        $this->assertSame($v2, $this->createPolicy($author, $this->creditInput()));
        $this->assertSame(2, DB::table(MembershipRecords::TABLE)->count());
        foreach ([$v1, $v2] as $retained) {
            config(['production-memberships.enabled' => true, 'production-memberships.approved_policy_hash' => $retained['policy_hash']]);
            DB::transaction(function () use ($retained, $v2): void {
                $rows = new MembershipRows;
                $source = new SealedMembershipPolicyFacts;
                $proof = $source->lock($retained['id'], $rows);
                $source->proveCurrent($proof, $rows);
                $binding = $proof->binding();
                $this->assertSame($retained['policy_hash'], $binding->policyHash);
                $this->assertTrue($binding->supports('paid_period_award'));
                $this->assertSame($retained === $v2, $binding->supports('reserve'));
                $this->assertFalse($binding->supports('consume'));
                if ($retained === $v2) {
                    $this->assertSame(2, $binding->redemptionTerms->rule('synthetic-wav')['credit_amount']);
                    $this->assertSame('2026-10-13 00:30:00', $binding->redemptionTerms->honorDeadline('2026-10-13 00:00:00', '2026-10-13 00:30:00'));
                } else {
                    $this->assertNull($binding->redemptionTerms);
                    $this->assertNull($binding->eligibleLicensePolicyHash);
                    $this->assertNull($binding->reservationHonorPolicyHash);
                }
            });
        }
        $this->assertSame($old, (array) DB::table(MembershipRecords::TABLE)->where('id', $v1['id'])->sole());
        $new = (array) DB::table(MembershipRecords::TABLE)->where('id', $v2['id'])->sole();
        $document = MembershipPolicyVersions::document($new);
        $this->assertSame(CanonicalJson::encode($this->creditInput()['redemption_terms']), CanonicalJson::encode($document->redemptionTerms->facts));
        $this->assertStringNotContainsString('synthetic-wav', $new['payload_ciphertext']);
        foreach (['production_membership_paid_periods', 'production_membership_redemptions', 'production_membership_credit_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        try {
            (new MembershipPolicy)->current();
            $this->fail('Authoring real terms cannot replace the complete operative capability gate.');
        } catch (MembershipException $error) {
            $this->assertSame('source_capability_absent', $error->reason);
        }
        foreach (['update', 'delete'] as $verb) {
            try {
                $query = DB::table(MembershipRecords::TABLE)->where('id', $v2['id']);
                $verb === 'update' ? $query->update(['created_at' => '2026-01-01 00:00:00']) : $query->delete();
                $this->fail('Retained v2 policy remains immutable.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_v2_authoring_requires_current_staff_mfa_and_does_not_gain_an_exception_from_explicit_terms(): void
    {
        foreach ([$this->policyStaff(false), $this->policyStaff(true, ['is_admin' => false])] as $author) {
            try {
                $this->createPolicy($author, $this->creditInput());
                $this->fail('Explicit terms never replace current staff authorization.');
            } catch (MembershipException $error) {
                $this->assertContains($error->reason, ['mfa_required', 'staff_refused']);
            }
        }
        $this->assertSame(0, DB::table(MembershipRecords::TABLE)->count());
    }

    public function test_a_different_credit_policy_is_not_approved_by_the_old_hash_and_new_approval_does_not_upgrade_v1(): void
    {
        $v1 = $this->createPolicy();
        $v2 = $this->createPolicy(overrides: $this->creditInput());
        foreach ([[$v1['policy_hash'], $v2['id']], [$v2['policy_hash'], $v1['id']]] as [$approved, $id]) {
            config(['production-memberships.enabled' => true, 'production-memberships.approved_policy_hash' => $approved]);
            try {
                DB::transaction(fn () => (new SealedMembershipPolicyFacts)->lock($id, new MembershipRows));
                $this->fail('A different retained version cannot inherit approval.');
            } catch (MembershipException $error) {
                $this->assertSame('policy_not_approved', $error->reason);
            }
        }
        $row = (array) DB::table(MembershipRecords::TABLE)->where('id', $v1['id'])->sole();
        $this->assertNull(MembershipPolicyVersions::document($row)->redemptionTerms);
        $this->assertSame(2, DB::table(MembershipRecords::TABLE)->count());
    }

    private function creditInput(): array
    {
        return ['schema_version' => MembershipPolicyDocument::CREDIT_VERSION, 'scope' => 'paid_period_credit_lifecycle',
            'redemption_terms' => ['eligibility_rules' => [['rule_id' => 'synthetic-wav', 'track_id' => 1, 'offer_id' => 2,
                'offer_revision_id' => 3, 'license_version_id' => 4, 'license_manifest_hash' => str_repeat('a', 64),
                'asset_manifest_hash' => str_repeat('b', 64), 'original_terms_hash' => str_repeat('c', 64), 'credit_amount' => 2]],
                'honor' => ['duration_seconds' => 3600, 'expiry_mode' => 'cap_at_credit_expiry'],
                'release' => ['before_credit_expiry' => 'available', 'at_or_after_credit_expiry' => 'expired'],
                'available_expiry' => ['mode' => 'expire_available_at_credit_expiry']]];
    }
}
