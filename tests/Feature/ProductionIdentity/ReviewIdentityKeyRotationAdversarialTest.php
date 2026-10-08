<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityEvidence;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\IdentitySchema;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

/**
 * Independent review of PR #57 (identity APP_KEY rotation): adversarial cases the PR's own tests do not cover.
 * Runs on SQLite; the trigger-dropping tamper cases simulate a database-level attacker without any configured key.
 */
class ReviewIdentityKeyRotationAdversarialTest extends TestCase
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

    public function test_principal_minted_before_rotation_is_refused_by_lock_and_current_after_rotation(): void
    {
        $actor = $this->enroll();
        $access = new ProductionCustomerAccess;
        $stale = $access->principal($actor);
        $this->useKeys(self::key('2'), [self::key('1')]);
        $this->refused(fn () => $access->current($stale, $actor));
        $this->refused(fn () => $this->transaction(fn (CurrentRows $reader) => $access->lock($stale, $actor, $reader)));
        $fresh = $access->principal($actor);
        $this->assertSame($stale->durableBinding(), $fresh->durableBinding(), 'Durable binding carries no key-dependent value.');
        $this->transaction(fn (CurrentRows $reader) => $access->proveCurrent($fresh, $actor, $reader, $access->lock($fresh, $actor, $reader)));
    }

    public function test_rotation_between_lock_and_prove_current_refuses(): void
    {
        $actor = $this->enroll();
        $access = new ProductionCustomerAccess;
        $principal = $access->principal($actor);
        $this->refused(fn () => $this->transaction(function (CurrentRows $reader) use ($access, $principal, $actor): void {
            $raw = $access->lock($principal, $actor, $reader);
            $this->useKeys(self::key('2'), [self::key('1')]);
            $access->proveCurrent($principal, $actor, $reader, $raw);
        }));
        // A principal minted after rotation and proved under a rolled-back configuration also refuses.
        $this->useKeys(self::key('2'), [self::key('1')]);
        $rotated = $access->principal($actor);
        $this->refused(fn () => $this->transaction(function (CurrentRows $reader) use ($access, $rotated, $actor): void {
            $raw = $access->lock($rotated, $actor, $reader);
            $this->useKeys(self::key('1'), [self::key('2')]);
            $access->proveCurrent($rotated, $actor, $reader, $raw);
        }));
    }

    public function test_evidence_reforged_in_the_database_under_an_unconfigured_key_is_refused_everywhere(): void
    {
        $actor = $this->enroll();
        $this->useKeys(self::key('2'), [self::key('1')]);
        $observation = (array) DB::table('production_identity_verifications')->sole();
        // Database-level attacker: no configured key, rewrites the credential binding and the observation hash under K3.
        $forged = ['credential_binding' => self::mac(self::key('3'), 'credential', $actor->password)] + $observation;
        $forged['observation_hash'] = hash_hmac('sha256', IdentityPolicy::VERSION."\0evidence-verification\0".$this->evidenceValue($forged), self::key('3'));
        $this->tamper('production_identity_verifications', (int) $observation['id'], $forged);
        $this->refused(fn () => (new ProductionCustomerAccess)->principal($actor));
        $this->assertNull((new ProductionCustomerSessions)->authenticate(self::EMAIL, self::PASSWORD));
        $this->refused(fn () => $this->requestIdentity('recover'));
        // The same forgery verifies only if its key is configured; that is key membership, not a bypass.
        $this->useKeys(self::key('2'), [self::key('1'), self::key('3')]);
        $this->assertSame($actor->id, (new ProductionCustomerAccess)->principal($actor)->userId);
    }

    public function test_recovery_issued_before_rotation_completes_after_rotation_and_the_chain_verifies(): void
    {
        $actor = $this->enroll();
        $recovery = $this->requestIdentity('recover');
        $this->assertSame(self::mac(self::key('1'), 'credential', $actor->password), $recovery['bound_credential_binding']);
        $this->useKeys(self::key('2'), [self::key('1')]);
        $this->completeIdentity($recovery, 'RecoveredPassword456', str_repeat('c', 64));
        $this->assertNotNull((new ProductionCustomerSessions)->authenticate(self::EMAIL, 'RecoveredPassword456'));
        $this->assertNull((new ProductionCustomerSessions)->authenticate(self::EMAIL, self::PASSWORD));
    }

    public function test_copied_binding_is_the_last_verified_observation_and_a_changed_credential_refuses_the_other_pending_recovery(): void
    {
        $actor = $this->enroll();
        $this->useKeys(self::key('2'), [self::key('1')]);
        $first = $this->requestIdentity('recover', self::EMAIL, str_repeat('1', 64));
        $second = $this->requestIdentity('recover', self::EMAIL, str_repeat('2', 64));
        $enrollment = (array) DB::table('production_identity_verifications')->sole();
        $this->assertSame($enrollment['credential_binding'], $first['bound_credential_binding']);
        $this->assertSame($enrollment['credential_binding'], $second['bound_credential_binding']);
        $this->completeIdentity($first, 'RecoveredPassword456', str_repeat('c', 64));
        $before = $this->identityRows();
        // Same access_version (recovery does not bump it): only the credential binding stops the stale recovery.
        $this->assertSame((int) $second['bound_access_version'], (int) DB::table('customer_accounts')->value('access_version'));
        $this->refused(fn () => $this->completeIdentity($second, 'AttackerPassword789', str_repeat('d', 64)));
        $this->assertSame($before, $this->identityRows());
        $latest = (array) DB::table('production_identity_verifications')->orderByDesc('id')->first();
        $third = $this->requestIdentity('recover', self::EMAIL, str_repeat('3', 64));
        $this->assertSame($latest['credential_binding'], $third['bound_credential_binding']);
        $this->assertSame(self::mac(self::key('2'), 'credential', User::findOrFail($actor->id)->password), $third['bound_credential_binding']);
    }

    public function test_historical_byte_comparison_refuses_a_bound_binding_recomputed_or_relabelled_under_another_value(): void
    {
        $actor = $this->enroll();
        $enrolledPassword = $actor->password;
        $this->useKeys(self::key('2'), [self::key('1')]);
        $enrollment = (array) DB::table('production_identity_verifications')->sole();
        $this->completeIdentity($this->requestIdentity('recover'), 'RecoveredPassword456', str_repeat('c', 64));
        $actor = User::findOrFail($actor->id);
        $this->assertSame($actor->id, (new ProductionCustomerAccess)->principal($actor)->userId);
        $original = [(array) DB::table('production_identity_challenges')->where('purpose', 'recover')->sole(),
            (array) DB::table('production_identity_verifications')->orderByDesc('id')->first()];
        $this->assertSame($enrollment['credential_binding'], $original[0]['bound_credential_binding']);
        // 1: the pre-fix value (same credential, current key); 2: a valid old-key binding of a different credential.
        foreach ([self::mac(self::key('2'), 'credential', $enrolledPassword),
            self::mac(self::key('1'), 'credential', '$2y$04$'.str_repeat('x', 53))] as $bound) {
            [$challenge, $observation] = $original;
            $this->assertNotSame($enrollment['credential_binding'], $bound);
            $challenge['bound_credential_binding'] = $bound;
            $challenge['challenge_hash'] = IdentityEvidence::hash('challenge', $challenge);
            $observation['challenge_hash'] = $challenge['challenge_hash'];
            $observation['observation_hash'] = IdentityEvidence::hash('verification', $observation);
            $this->tamper('production_identity_challenges', (int) $challenge['id'], $challenge);
            $this->tamper('production_identity_verifications', (int) $observation['id'], $observation);
            // Every row still carries valid current-key evidence; only historical()'s byte comparison can refuse.
            IdentityEvidence::verify('challenge', $challenge, 'challenge_hash');
            IdentityEvidence::verify('verification', $observation, 'observation_hash');
            $this->refused(fn () => (new ProductionCustomerAccess)->principal($actor));
            $this->assertNull((new ProductionCustomerSessions)->authenticate(self::EMAIL, 'RecoveredPassword456'));
        }
        $this->tamper('production_identity_challenges', (int) $original[0]['id'], $original[0]);
        $this->tamper('production_identity_verifications', (int) $original[1]['id'], $original[1]);
        $this->assertSame($actor->id, (new ProductionCustomerAccess)->principal($actor)->userId);
    }

    /**
     * Finding (mixed configuration): during a rolling deploy or rollback, a process that has only the old key and a
     * process that has the new key (old key listed) each create their own fence for a never-seen address. Afterwards
     * every identity request for that address is refused (generic 422) until the data is repaired or a key is retired.
     */
    public function test_mixed_configuration_window_creates_two_fences_and_then_refuses_every_request_for_the_address(): void
    {
        $email = 'fresh@example.test';
        $this->useKeys(self::key('2'), [self::key('1')]);
        $this->requestIdentity('enroll', $email, str_repeat('a', 64));
        $this->useKeys(self::key('1'));
        $this->requestIdentity('enroll', $email, str_repeat('b', 64));
        $this->assertSame(2, DB::table('production_identity_addresses')->count());
        $before = $this->identityRows();
        $this->useKeys(self::key('2'), [self::key('1')]);
        $this->refused(fn () => $this->requestIdentity('enroll', $email, str_repeat('c', 64)));
        $this->refused(fn () => $this->requestIdentity('enroll', $email, str_repeat('a', 64)));
        $this->assertSame($before, $this->identityRows());
        // An unrelated address in the same window is unaffected, and an address first used before the window is shared.
        $this->requestIdentity('enroll', 'other@example.test', str_repeat('d', 64));
        $this->assertSame(3, DB::table('production_identity_addresses')->count());
    }

    public function test_previous_key_order_and_duplicates_do_not_change_verification_or_the_selected_fence(): void
    {
        $this->enroll();
        $fence = (array) DB::table('production_identity_addresses')->sole();
        foreach ([[self::key('1'), self::key('3')], [self::key('3'), self::key('1')], [self::key('1'), self::key('1'), self::key('2')]] as $previous) {
            $this->useKeys(self::key('2'), $previous);
            $this->requestIdentity('recover', self::EMAIL);
            $this->assertSame([$fence], DB::table('production_identity_addresses')->get()->map(fn ($row) => (array) $row)->all());
        }
        $this->assertSame(3, DB::table('production_identity_challenges')->where('purpose', 'recover')->where('address_id', $fence['id'])->count());
        // A previous key shorter than 32 bytes is never a candidate, even when it is the old key byte-for-byte.
        $this->useKeys('short-current-key');
        $digest = IdentityPolicy::digest('owner', 'x');
        $this->useKeys(self::key('2'), ['short-current-key']);
        $this->assertFalse(IdentityPolicy::matches('owner', 'x', $digest));
        $this->assertSame([self::key('2')], IdentityPolicy::keys());
    }

    private function enroll(): User
    {
        $result = $this->completeIdentity($this->requestIdentity('enroll', self::EMAIL));

        return User::findOrFail($result['user_id']);
    }

    private function transaction(callable $work): mixed
    {
        $database = DB::connection();
        $reader = new CurrentRows($database->getPdo(), $database->getDriverName());

        return $database->transaction(fn () => $work($reader));
    }

    private function tamper(string $table, int $id, array $row): void
    {
        $driver = DB::getDriverName();
        $trigger = 'pi_'.str_replace('production_identity_', '', $table).'_update';
        $guard = IdentitySchema::guards($driver)[$trigger];
        DB::statement('DROP TRIGGER `'.$trigger.'`');
        try {
            DB::table($table)->where('id', $id)->update(array_diff_key($row, ['id' => true]));
        } finally {
            DB::unprepared($guard['sql']);
        }
    }

    private function evidenceValue(array $row): string
    {
        return (new \ReflectionMethod(IdentityEvidence::class, 'value'))->invoke(null, 'verification', $row);
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
