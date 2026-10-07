<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\CompleteIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

class ProductionIdentityJourneyTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identitySetup();
    }

    public function test_reusable_operative_fixture_uses_received_smtp_proof_and_current_authenticated_principal(): void
    {
        $identity = $this->enrollThroughLocalSmtp();
        $this->assertSame('synthetic_rehearsal', $identity['binding']['provenance']);
        $this->assertSame($identity['user']->id, $identity['principal']->userId);
        $this->assertSame(1, DB::table('production_identity_verifications')->count());
        $this->assertSame('accepted', DB::table('production_identity_outcomes')->value('status'));
    }

    public function test_actual_smtp_enrollment_creates_only_mailbox_control_and_typed_current_authority(): void
    {
        $challenge = $this->requestIdentity();
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('production_identity_origins')->count());
        $notice = DB::table('production_identity_notices')->first();
        $mail = $this->smtp('accept', $notice->id);
        $this->assertStringContainsString('http://localhost/customer/access#enroll.', $mail['data']);
        $this->assertSame('accepted', DB::table('production_identity_outcomes')->value('status'));
        $this->assertSame(0, DB::table('production_identity_verifications')->count()); // SMTP 250 is not verification.
        $result = $this->completeIdentity($challenge);
        $actor = User::findOrFail($result['user_id']);
        $access = new ProductionCustomerAccess;
        $principal = $access->principal($actor);
        $binding = $access->durableBinding($principal);
        $this->assertInstanceOf(ProductionCustomerPrincipal::class, $principal);
        $this->assertSame('synthetic_rehearsal', $binding['provenance']);
        $this->assertSame(10, count($binding));
        foreach (['password', 'email', 'owner_key', 'credential_binding', 'proof_hash'] as $secret) {
            $this->assertArrayNotHasKey($secret, $binding);
        }
        $database = DB::connection();
        $reader = new CurrentRows($database->getPdo(), $database->getDriverName());
        $database->transaction(function () use ($access, $principal, $actor, $reader): void {
            $proof = $access->lock($principal, $actor, $reader);
            $access->proveCurrent($principal, $actor, $reader, $proof);
            $this->assertSame(1, count($proof['verification_history']));
        });
        $this->assertTrue(Hash::check('MailboxPassword123', $actor->password));
    }

    public function test_recovery_preserves_original_origin_and_historical_act_and_revokes_old_principal(): void
    {
        $result = $this->completeIdentity($this->requestIdentity());
        $actor = User::findOrFail($result['user_id']);
        $access = new ProductionCustomerAccess;
        $old = $access->principal($actor);
        $binding = $access->durableBinding($old);
        $reader = new CurrentRows(DB::connection()->getPdo(), DB::getDriverName());
        $historic = DB::transaction(fn () => $access->verifyHistoricalBinding($binding, $reader));
        $owner = DB::table('customer_accounts')->value('owner_key');
        $recovery = $this->requestIdentity('recover');
        $this->completeIdentity($recovery, 'RecoveredPassword456');
        $this->assertSame($owner, DB::table('customer_accounts')->value('owner_key'));
        $this->assertSame(1, DB::table('production_identity_origins')->count());
        $this->assertSame(2, DB::table('production_identity_verifications')->count());
        DB::transaction(fn () => $access->proveHistoricalBindingCurrent($binding, $reader, $historic));
        $new = $access->principal($actor->fresh());
        $this->assertSame($binding['origin_id'], $access->durableBinding($new)['origin_id']);
        $this->assertNotSame($binding['verification_observation_id'], $access->durableBinding($new)['verification_observation_id']);
        $this->expectException(IdentityException::class);
        $access->current($old, $actor);
    }

    public function test_exact_completion_replay_has_no_new_identity_and_changed_replay_fails(): void
    {
        $challenge = $this->requestIdentity();
        $first = $this->completeIdentity($challenge);
        $again = $this->completeIdentity($challenge);
        $this->assertSame($first, $again);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('production_identity_verifications')->count());
        $this->expectException(IdentityException::class);
        $this->completeIdentity($challenge, 'DifferentPassword789');
    }

    public function test_existing_verified_legacy_account_is_never_adopted_and_requests_are_replay_safe(): void
    {
        User::factory()->create(['email' => 'buyer@example.test', 'email_verified_at' => now()]);
        $key = str_repeat('c', 64);
        $challenge = $this->requestIdentity(key: $key);
        $this->requestIdentity(key: $key);
        $this->assertSame('unavailable', $challenge['availability']);
        $this->assertSame(1, DB::table('production_identity_challenges')->count());
        $this->assertSame(0, DB::table('production_identity_notices')->count());
        $this->assertSame(0, DB::table('production_identity_origins')->count());
        $this->expectException(IdentityException::class);
        $this->completeIdentity($challenge);
    }

    public function test_expired_or_wrong_mailbox_proof_never_creates_a_user(): void
    {
        $challenge = $this->requestIdentity();
        [$id] = $this->proofFor($challenge);
        try {
            (new CompleteIdentity)->complete($id, str_repeat('f', 64), 'MailboxPassword123', 'Buyer', str_repeat('a', 64));
            $this->fail();
        } catch (IdentityException) {
        }
        $this->assertSame(0, DB::table('users')->count());
        $this->travel(601)->seconds();
        $this->expectException(IdentityException::class);
        $this->completeIdentity($challenge);
    }

    public function test_default_off_production_rehearsal_mismatch_and_outer_transaction_are_refused(): void
    {
        config(['production-customer-identity.enabled' => false]);
        try {
            $this->requestIdentity();
            $this->fail();
        } catch (IdentityException) {
        }
        config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => 'verified_production']);
        try {
            $this->requestIdentity();
            $this->fail();
        } catch (IdentityException) {
        }
        config(['production-customer-identity.provenance' => 'synthetic_rehearsal']);
        DB::beginTransaction();
        try {
            $this->requestIdentity();
            $this->fail();
        } catch (IdentityException) {
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, DB::table('production_identity_challenges')->count());
    }

    public function test_retained_identity_and_mail_objects_cannot_be_serialized(): void
    {
        $result = $this->completeIdentity($this->requestIdentity());
        $principal = (new ProductionCustomerAccess)->principal(User::findOrFail($result['user_id']));
        try {
            serialize($principal);
            $this->fail();
        } catch (\LogicException) {
        }
        try {
            json_encode($principal, JSON_THROW_ON_ERROR);
            $this->fail();
        } catch (\LogicException) {
        }
        $mail = new IdentityMail('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'enroll', 'synthetic_rehearsal', 'buyer@example.test', 'http://localhost/customer/access#private-proof');
        $this->assertStringNotContainsString('private-proof', print_r($mail, true));
        $this->expectException(\LogicException::class);
        serialize($mail);
    }
}
