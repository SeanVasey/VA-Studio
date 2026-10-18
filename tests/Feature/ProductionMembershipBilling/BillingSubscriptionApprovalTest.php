<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Billing\BillingSubscriptionApprovals;
use App\Domain\Memberships\Billing\BillingSubscriptionRecord;
use App\Domain\Memberships\Billing\BillingValues;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipPolicyFixtures;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

/** Real local identity and approval writers; synthetic terms and provider references only. */
class BillingSubscriptionApprovalTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipPolicyFixtures;
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policySetup();
        config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
        F::configure();
    }

    public function test_current_staff_approves_policy_derived_terms_and_original_buyer_without_awarding(): void
    {
        [$plan, $buyer, $staff] = $this->fixture();
        $created = $this->approve($plan, $buyer, $staff);
        $row = (array) DB::table(BillingSchema::TABLES[0])->sole();
        $verified = BillingSubscriptionRecord::verify($row, (new BillingPolicy)->current());
        $document = MembershipPolicyVersions::document((array) DB::table(MembershipRecords::TABLE)->sole());
        $this->assertSame([2, 1700, 'USD', $document->hash(), $document->termsHash(), (int) $staff->id],
            [$verified['payload']['schema_version'], $verified['expectation']->amountMinor, $verified['expectation']->currency,
                $verified['payload']['plan_policy_hash'], $verified['payload']['original_terms_hash'], $verified['payload']['author_user_id']]);
        $this->assertSame(CanonicalJson::encode($buyer['binding']), CanonicalJson::encode($verified['payload']['original_buyer_binding']));
        $this->assertEquals(BillingSubscriptionRecord::record($row), $verified['payload']['record']);
        $this->assertSame($created['approval_binding_hash'], BillingSubscriptionRecord::approvalHash($verified['payload']));
        $this->assertStringNotContainsString(F::CUSTOMER, $row['payload_ciphertext']);
        $this->assertEquals($verified, (new BillingLedger)->binding($created['id'], (new BillingPolicy)->current()));
        $this->assertSame(0, DB::table('production_membership_paid_periods')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_identical_retry_keeps_original_author_row_and_creation_time(): void
    {
        [$plan, $buyer, $staff] = $this->fixture();
        $first = $this->approve($plan, $buyer, $staff);
        $original = (array) DB::table(BillingSchema::TABLES[0])->sole();
        $this->assertSame($first, $this->approve($plan, $buyer, $this->policyStaff()));
        $this->assertSame($original, (array) DB::table(BillingSchema::TABLES[0])->sole());
        $this->assertSame((int) $staff->id, BillingSubscriptionRecord::verify($original, (new BillingPolicy)->current())['payload']['author_user_id']);
    }

    public function test_changed_customer_price_buyer_or_policy_cannot_reassign_a_subscription(): void
    {
        [$plan, $buyer, $staff] = $this->fixture();
        $this->approve($plan, $buyer, $staff);
        $original = (array) DB::table(BillingSchema::TABLES[0])->sole();
        $this->refused(fn () => $this->approve($plan, $buyer, $staff, customer: 'cus_OTHER'), 'conflicting_approval');
        $this->refused(fn () => $this->approve($plan, $buyer, $staff, price: 'price_OTHER'), 'conflicting_approval');
        $other = $this->enrollThroughLocalSmtp('other@example.test');
        $this->refused(fn () => $this->approve($plan, $other, $staff), 'conflicting_approval');
        $changed = $this->createPolicy($staff, ['credit_allowance' => 4]);
        config(['production-membership-billing.approved_subscription_policy_hash' => $changed['policy_hash']]);
        $this->refused(fn () => $this->approve($changed, $buyer, $staff), 'binding_mismatch');
        $this->assertSame($original, (array) DB::table(BillingSchema::TABLES[0])->sole());
    }

    public function test_legacy_binding_remains_readable_but_cannot_be_adopted_as_approved_authority(): void
    {
        [$plan, $buyer, $staff] = $this->fixture();
        $legacy = F::binding();
        $configuration = (new BillingPolicy)->current();
        $this->assertSame(1, (new BillingLedger)->binding($legacy['id'], $configuration)['payload']['schema_version']);
        $this->refused(fn () => BillingSubscriptionRecord::verify($legacy, $configuration), 'legacy_approval');
        $this->refused(fn () => $this->approve($plan, $buyer, $staff), 'legacy_approval');
        $this->assertSame(CanonicalJson::encode($legacy), CanonicalJson::encode((array) DB::table(BillingSchema::TABLES[0])->sole()));
    }

    public function test_approval_requires_enabled_authoring_and_exact_approved_policy(): void
    {
        [$plan, $buyer, $staff] = $this->fixture();
        config(['production-memberships.authoring_enabled' => false]);
        $this->refused(fn () => $this->approve($plan, $buyer, $staff), 'disabled');
        config(['production-memberships.authoring_enabled' => true, 'production-membership-billing.enabled' => false]);
        $this->refused(fn () => $this->approve($plan, $buyer, $staff), 'disabled');
        config(['production-membership-billing.enabled' => true, 'production-membership-billing.approved_subscription_policy_hash' => str_repeat('a', 64)]);
        $this->refused(fn () => $this->approve($plan, $buyer, $staff), 'policy_not_approved');
        $this->assertSame(0, DB::table(BillingSchema::TABLES[0])->count());
    }

    public function test_nonstaff_unenrolled_stale_and_revoked_staff_are_refused_even_on_retry(): void
    {
        [$plan, $buyer, $staff] = $this->fixture();
        $this->approve($plan, $buyer, $staff);
        $this->refused(fn () => $this->approve($plan, $buyer, $buyer['user']), 'staff_refused');
        $this->refused(fn () => $this->approve($plan, $buyer, $this->policyStaff(false)), 'mfa_required');
        $stale = $this->policyStaff();
        DB::table('users')->where('id', $stale->id)->update(['remember_token' => 'changed-synthetic-token']);
        $this->refused(fn () => $this->approve($plan, $buyer, $stale), 'staff_refused');
        DB::table('users')->where('id', $staff->id)->update(['is_admin' => false]);
        $this->refused(fn () => $this->approve($plan, $buyer, $staff), 'staff_refused');
        $this->assertSame(1, DB::table(BillingSchema::TABLES[0])->count());
    }

    public function test_authenticated_record_binds_all_columns_even_when_outer_checksum_is_recomputed(): void
    {
        [$plan, $buyer, $staff] = $this->fixture();
        $this->approve($plan, $buyer, $staff);
        $row = (array) DB::table(BillingSchema::TABLES[0])->sole();
        $configuration = (new BillingPolicy)->current();
        $changes = ['id' => BillingValues::id(), 'account_id' => $row['account_id'] + 1, 'user_id' => $row['user_id'] + 1,
            'identity_origin_id' => $row['identity_origin_id'] + 1, 'provider_account_hash' => str_repeat('a', 64), 'mode' => 'live',
            'customer_ref_hash' => str_repeat('a', 64), 'subscription_ref_hash' => str_repeat('a', 64), 'price_ref_hash' => str_repeat('a', 64),
            'plan_version_id' => BillingValues::id(), 'approval_binding_hash' => str_repeat('a', 64), 'provenance' => 'verified_production',
            'created_at' => '2026-01-01 00:00:00'];
        foreach ($changes as $column => $value) {
            $changed = [...$row, $column => $value];
            $changed['seal'] = BillingValues::seal($changed);
            $this->refused(fn () => BillingSubscriptionRecord::verify($changed, $configuration));
        }
        $payload = BillingValues::decrypt($row['payload_ciphertext']);
        $payload['amount_minor']++;
        $changed = [...$row, 'payload_ciphertext' => BillingValues::encrypt($payload)];
        $changed['seal'] = BillingValues::seal($changed);
        $this->refused(fn () => BillingSubscriptionRecord::verify($changed, $configuration), 'binding_mismatch');
        $this->assertSame($row, (array) DB::table(BillingSchema::TABLES[0])->sole());
    }

    public function test_encryption_callback_staff_revocation_rolls_back_without_an_approval(): void
    {
        [$plan, $buyer, $staff] = $this->fixture();
        $this->encryptionCallback(function () use ($staff): void {
            DB::table('users')->where('id', $staff->id)->update(['is_admin' => false]);
        });
        $this->refused(fn () => $this->approve($plan, $buyer, $staff));
        $this->assertSame(0, DB::table(BillingSchema::TABLES[0])->count());
        $this->assertTrue((bool) DB::table('users')->where('id', $staff->id)->value('is_admin'));
    }

    public function test_encryption_callback_policy_withdrawal_refuses_before_write(): void
    {
        [$plan, $buyer, $staff] = $this->fixture();
        $this->encryptionCallback(fn () => config(['production-membership-billing.enabled' => false]));
        $this->refused(fn () => $this->approve($plan, $buyer, $staff), 'changed_policy');
        $this->assertSame(0, DB::table(BillingSchema::TABLES[0])->count());
    }

    public function test_encryption_callback_cannot_remove_a_billing_guard_before_the_write(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite permits transactional DDL for this callback regression.');
        }
        [$plan, $buyer, $staff] = $this->fixture();
        $this->encryptionCallback(fn () => DB::connection()->getPdo()->exec('DROP TRIGGER production_membership_billing_subscriptions_update'));
        $this->refused(fn () => $this->approve($plan, $buyer, $staff), 'schema_prefix');
        $this->assertSame(0, DB::table(BillingSchema::TABLES[0])->count());
        (new BillingSchema)->assertOwned(DB::connection()->getPdo());
    }

    public function test_native_encryption_callback_ddl_cannot_write_an_approval_after_its_implicit_commit(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL DDL implicitly commits; the transactional SQLite case is the sibling SQLite-only test.');
        }
        [$plan, $buyer, $staff] = $this->fixture();
        $pdo = DB::connection()->getPdo();
        $this->encryptionCallback(fn () => $pdo->exec('DROP TRIGGER production_membership_billing_subscriptions_update'));
        // The implicit commit destroys the MembershipRows savepoint marker, so the frame refuses before the write.
        $this->refused(fn () => $this->approve($plan, $buyer, $staff), 'changed_primary_or_schema');
        $this->assertSame(0, DB::table(BillingSchema::TABLES[0])->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($pdo->inTransaction());
        // Unlike SQLite, the dropped guard cannot be rolled back; the schema stays refused rather than silently repaired.
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'production_membership_billing_subscriptions_update'")->fetchColumn());
        $this->refused(fn () => (new BillingSchema)->assertOwned($pdo), 'schema_prefix');
        $this->refused(fn () => $this->approve($plan, $buyer, $staff), 'schema_prefix');
        $this->assertSame(0, DB::table(BillingSchema::TABLES[0])->count());
    }

    public function test_result_is_provisional_and_caller_rollback_retains_no_approval(): void
    {
        [$plan, $buyer, $staff] = $this->fixture();
        DB::beginTransaction();
        try {
            $result = $this->writer($plan, $buyer, $staff, new MembershipRows);
            $this->assertSame($plan['id'], $result['plan_version_id']);
            $this->assertSame(1, DB::table(BillingSchema::TABLES[0])->count());
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, DB::table(BillingSchema::TABLES[0])->count());
        $this->refused(fn () => new MembershipRows);
    }

    private function fixture(): array
    {
        $staff = $this->policyStaff();
        $plan = $this->createPolicy($staff);
        config(['production-membership-billing.approved_subscription_policy_hash' => $plan['policy_hash']]);

        return [$plan, $this->enrollThroughLocalSmtp(), $staff];
    }

    private function approve(array $plan, array $buyer, User $staff, string $customer = F::CUSTOMER, string $price = F::PRICE): array
    {
        return DB::transaction(fn () => $this->writer($plan, $buyer, $staff, new MembershipRows, $customer, $price));
    }

    private function writer(array $plan, array $buyer, User $staff, MembershipRows $rows, string $customer = F::CUSTOMER, string $price = F::PRICE): array
    {
        return (new BillingSubscriptionApprovals)->approve($plan['id'], $customer, F::SUBSCRIPTION, $price,
            $buyer['principal'], $buyer['user'], $staff, $rows);
    }

    private function encryptionCallback(callable $callback): void
    {
        $real = Crypt::getFacadeRoot();
        $fake = Mockery::mock(Encrypter::class);
        $fake->shouldReceive('encryptString')->once()->andReturnUsing(function ($plain) use ($real, $callback) {
            $callback();

            return $real->encryptString($plain);
        });
        $fake->shouldReceive('decrypt')->andReturnUsing(fn (...$args) => $real->decrypt(...$args));
        $fake->shouldReceive('decryptString')->andReturnUsing(fn (...$args) => $real->decryptString(...$args));
        Crypt::swap($fake);
    }

    private function refused(callable $work, ?string $reason = null): void
    {
        try {
            $work();
            $this->fail('Expected subscription approval refusal.');
        } catch (BillingException|MembershipException|IdentityException $error) {
            $reason === null ? $this->assertNotEmpty($error->getMessage()) : $this->assertSame($reason, $error->reason);
        }
    }
}
