<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\CompleteIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityEvidence;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\IdentitySchema;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

/** Routine APP_KEY rotation keeps stored identity digests verifiable only while the old key stays configured. */
class ProductionIdentityKeyRotationTest extends TestCase
{
    use ProductionIdentityFixture;

    private const EMAIL = 'buyer@example.test';

    private const PASSWORD = 'MailboxPassword123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useKeys(self::key('1'));
        $this->identitySetup();
    }

    public function test_key_candidates_are_current_first_deduplicated_and_require_a_current_key(): void
    {
        $this->useKeys(self::key('2'), [self::key('1'), self::key('2'), self::key('1'), 'short-legacy-key', 42, null]);
        $this->assertSame([self::key('2'), self::key('1')], IdentityPolicy::keys());
        $this->useKeys(self::key('2'), 'not-a-list');
        $this->assertSame([self::key('2')], IdentityPolicy::keys());
        foreach (['', null] as $absent) {
            config(['app.key' => $absent, 'app.previous_keys' => [self::key('1')]]);
            foreach ([fn () => IdentityPolicy::keys(), fn () => IdentityPolicy::digest('owner', 'x'),
                fn () => IdentityPolicy::matches('owner', 'x', str_repeat('0', 64)), fn () => IdentityPolicy::digests('owner', 'x')] as $call) {
                try {
                    $call();
                    $this->fail('A missing current key must refuse every identity digest operation.');
                } catch (IdentityException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_matches_any_configured_key_and_refuses_unconfigured_removed_or_relabelled_digests(): void
    {
        $old = IdentityPolicy::digest('owner', 'owner-value');
        $this->assertSame(self::mac(self::key('1'), 'owner', 'owner-value'), $old);
        $this->useKeys(self::key('2'), [self::key('1')]);
        $current = IdentityPolicy::digest('owner', 'owner-value');
        $this->assertSame(self::mac(self::key('2'), 'owner', 'owner-value'), $current);
        $this->assertNotSame($old, $current);
        $this->assertSame([$current, $old], IdentityPolicy::digests('owner', 'owner-value'));
        $this->assertTrue(IdentityPolicy::matches('owner', 'owner-value', $old));
        $this->assertTrue(IdentityPolicy::matches('owner', 'owner-value', $current));
        $this->assertFalse(IdentityPolicy::matches('owner', 'other-value', $old));
        $this->assertFalse(IdentityPolicy::matches('recipient', 'owner-value', $old));
        $this->assertFalse(IdentityPolicy::matches('owner', 'owner-value', self::mac(self::key('3'), 'owner', 'owner-value')));
        $this->assertFalse(IdentityPolicy::matches('owner', 'owner-value', strtoupper($old)));
        $this->assertFalse(IdentityPolicy::matches('owner', 'owner-value', ''));
        $this->useKeys(self::key('2'));
        $this->assertFalse(IdentityPolicy::matches('owner', 'owner-value', $old));
        $this->assertSame([$current], IdentityPolicy::digests('owner', 'owner-value'));
    }

    public function test_enrolled_identity_reads_locks_proves_and_signs_in_after_rotation_without_rewriting_evidence(): void
    {
        $actor = $this->enroll();
        $before = $this->identityRows();
        $this->useKeys(self::key('2'), [self::key('1')]);
        $access = new ProductionCustomerAccess;
        $principal = $access->principal($actor);
        $binding = $access->durableBinding($principal);
        $proof = $access->current($principal, $actor);
        $this->assertSame(self::mac(self::key('2'), 'owner', $proof['account']['owner_key']), $proof['owner_digest']);
        $this->assertNotSame($proof['origin']['owner_digest'], $proof['owner_digest']);
        $database = DB::connection();
        $reader = new CurrentRows($database->getPdo(), $database->getDriverName());
        $database->transaction(function () use ($access, $principal, $actor, $reader, $binding): void {
            $locked = $access->lock($principal, $actor, $reader);
            $access->proveCurrent($principal, $actor, $reader, $locked);
            $historical = $access->verifyHistoricalBinding($binding, $reader);
            $access->proveHistoricalBindingCurrent($binding, $reader, $historical);
        });
        $this->assertNotNull((new ProductionCustomerSessions)->authenticate(self::EMAIL, self::PASSWORD));
        $this->assertSame($before, $this->identityRows());
    }

    public function test_removed_or_unconfigured_previous_key_refuses_existing_identity_until_restored(): void
    {
        $actor = $this->enroll();
        foreach ([[], [self::key('3')]] as $previous) {
            $this->useKeys(self::key('2'), $previous);
            $this->refused(fn () => (new ProductionCustomerAccess)->principal($actor));
            $this->assertNull((new ProductionCustomerSessions)->authenticate(self::EMAIL, self::PASSWORD));
        }
        $this->useKeys(self::key('2'), [self::key('3'), self::key('1')]);
        $this->assertSame($actor->id, (new ProductionCustomerAccess)->principal($actor)->userId);
    }

    public function test_request_and_completion_replays_after_rotation_find_the_original_rows(): void
    {
        $key = str_repeat('e', 64);
        $challenge = $this->requestIdentity('enroll', self::EMAIL, $key);
        $result = $this->completeIdentity($challenge);
        $before = $this->identityRows();
        $this->useKeys(self::key('2'), [self::key('1')]);
        $this->requestIdentity('enroll', self::EMAIL, $key);
        $this->assertSame($result, $this->completeIdentity($challenge));
        $this->assertSame($before, $this->identityRows());
        [$id, $proof] = $this->proofFor($challenge);
        $this->useKeys(self::key('2'));
        $this->refused(fn () => (new CompleteIdentity)->complete($id, $proof, self::PASSWORD, 'Declared buyer', str_repeat('b', 64)));
        $this->assertSame($before, $this->identityRows());
    }

    public function test_recovery_after_rotation_reuses_the_address_fence_and_original_credential_binding(): void
    {
        $actor = $this->enroll();
        $enrollment = (array) DB::table('production_identity_verifications')->sole();
        $origin = (array) DB::table('production_identity_origins')->sole();
        $this->useKeys(self::key('2'), [self::key('1')]);
        $recovery = $this->requestIdentity('recover');
        $this->assertSame('pending', $recovery['availability']);
        $this->assertSame(1, DB::table('production_identity_addresses')->count());
        $this->assertSame((int) $origin['address_id'], (int) $recovery['address_id']);
        $this->assertSame($enrollment['credential_binding'], $recovery['bound_credential_binding']);
        $this->assertSame(self::mac(self::key('2'), 'recipient', self::EMAIL), $recovery['recipient_hmac']);
        $this->completeIdentity($recovery, 'RecoveredPassword456', str_repeat('c', 64));
        $actor = User::findOrFail($actor->id);
        $observation = (array) DB::table('production_identity_verifications')->orderByDesc('id')->first();
        $this->assertSame(2, (int) $observation['sequence']);
        $this->assertSame(self::mac(self::key('2'), 'credential', $actor->password), $observation['credential_binding']);
        $access = new ProductionCustomerAccess;
        $principal = $access->principal($actor);
        $database = DB::connection();
        $reader = new CurrentRows($database->getPdo(), $database->getDriverName());
        $database->transaction(fn () => $access->proveCurrent($principal, $actor, $reader, $access->lock($principal, $actor, $reader)));
        $this->assertNotNull((new ProductionCustomerSessions)->authenticate(self::EMAIL, 'RecoveredPassword456'));
        $this->useKeys(self::key('2'));
        $this->refused(fn () => $access->principal($actor));
    }

    public function test_two_address_fences_selected_by_different_key_candidates_are_refused_not_picked(): void
    {
        $this->requestIdentity('enroll', self::EMAIL);
        DB::table('production_identity_addresses')->insert(['address_hash' => self::mac(self::key('2'), 'address', self::EMAIL)]);
        $before = $this->identityRows();
        $this->useKeys(self::key('2'), [self::key('1')]);
        $this->refused(fn () => $this->requestIdentity('enroll', self::EMAIL));
        $this->assertSame($before, $this->identityRows());
        $this->useKeys(self::key('2'));
        $this->requestIdentity('enroll', self::EMAIL);
        $this->assertSame(2, DB::table('production_identity_challenges')->count());
    }

    public function test_digest_and_evidence_forged_under_an_unconfigured_key_are_refused(): void
    {
        $this->enroll();
        $origin = (array) DB::table('production_identity_origins')->sole();
        $account = (array) DB::table('customer_accounts')->sole();
        $this->useKeys(self::key('3'));
        $forged = ['owner_digest' => IdentityPolicy::digest('owner', $account['owner_key'])] + $origin;
        $forged['origin_hash'] = IdentityEvidence::hash('origin', $forged);
        IdentityEvidence::verify('origin', $forged, 'origin_hash');
        $this->useKeys(self::key('2'), [self::key('1')]);
        IdentityEvidence::verify('origin', $origin, 'origin_hash');
        $this->assertTrue(IdentityPolicy::matches('owner', $account['owner_key'], $origin['owner_digest']));
        $this->assertFalse(IdentityPolicy::matches('owner', $account['owner_key'], $forged['owner_digest']));
        $this->refused(fn () => IdentityEvidence::verify('origin', $forged, 'origin_hash'));
        $relabelled = ['identity_policy_hash' => str_repeat('a', 64)] + $origin;
        $this->refused(fn () => IdentityEvidence::verify('origin', $relabelled, 'origin_hash'));
    }

    public function test_new_identity_writes_use_only_the_current_key(): void
    {
        $this->useKeys(self::key('2'), [self::key('1')]);
        $actor = $this->enroll();
        $address = (array) DB::table('production_identity_addresses')->sole();
        $challenge = (array) DB::table('production_identity_challenges')->sole();
        $origin = (array) DB::table('production_identity_origins')->sole();
        $observation = (array) DB::table('production_identity_verifications')->sole();
        $account = (array) DB::table('customer_accounts')->sole();
        $this->assertSame(self::mac(self::key('2'), 'address', self::EMAIL), $address['address_hash']);
        $this->assertSame(self::mac(self::key('2'), 'recipient', self::EMAIL), $challenge['recipient_hmac']);
        $this->assertSame(self::mac(self::key('2'), 'owner', $account['owner_key']), $origin['owner_digest']);
        $this->assertSame(self::mac(self::key('2'), 'credential', $actor->password), $observation['credential_binding']);
        $this->useKeys(self::key('2'));
        foreach (['challenge' => $challenge, 'origin' => $origin, 'verification' => $observation] as $kind => $row) {
            IdentityEvidence::verify($kind, $row, ['challenge' => 'challenge_hash', 'origin' => 'origin_hash', 'verification' => 'observation_hash'][$kind]);
        }
        $this->assertSame($actor->id, (new ProductionCustomerAccess)->principal($actor)->userId);
        $this->useKeys(self::key('1'), [self::key('3')]);
        $this->refused(fn () => (new ProductionCustomerAccess)->principal($actor));
    }

    /** Intentionally current-key-only: a server session marker is re-issued by signing in again. */
    public function test_pre_rotation_session_marker_is_refused_and_a_new_sign_in_reissues_it(): void
    {
        $this->enroll();
        $verified = (new ProductionCustomerSessions)->authenticate(self::EMAIL, self::PASSWORD);
        $marker = $verified['principal']->sessionBindingDigest();
        $this->useKeys(self::key('2'), [self::key('1')]);
        $this->refused(fn () => (new ProductionCustomerSessions)->principal($this->sessionRequest($verified['user'], $marker)));
        $again = (new ProductionCustomerSessions)->authenticate(self::EMAIL, self::PASSWORD);
        $this->assertNotSame($marker, $again['principal']->sessionBindingDigest());
        $principal = (new ProductionCustomerSessions)->principal($this->sessionRequest($again['user'], $again['principal']->sessionBindingDigest()));
        $this->assertSame($again['user']->id, $principal->userId);
    }

    private function enroll(): User
    {
        $result = $this->completeIdentity($this->requestIdentity('enroll', self::EMAIL));

        return User::findOrFail($result['user_id']);
    }

    private function sessionRequest(User $user, string $marker): Request
    {
        Auth::guard('customer')->setUser($user);
        $request = Request::create('/customer');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('_production_customer_identity', ['binding_digest' => $marker]);

        return $request;
    }

    private function identityRows(): array
    {
        $rows = [];
        foreach (['users', 'customer_accounts', ...array_keys(IdentitySchema::definitions())] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    private function refused(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected the identity layer to refuse.');
        } catch (IdentityException) {
            $this->addToAssertionCount(1);
        }
    }

    private function useKeys(?string $current, mixed $previous = []): void
    {
        config(['app.key' => $current, 'app.previous_keys' => $previous]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private static function key(string $fill): string
    {
        return 'base64:'.base64_encode(str_repeat($fill, 32));
    }

    private static function mac(string $key, string $purpose, string $value): string
    {
        return hash_hmac('sha256', IdentityPolicy::VERSION."\0".$purpose."\0".$value, $key);
    }
}
