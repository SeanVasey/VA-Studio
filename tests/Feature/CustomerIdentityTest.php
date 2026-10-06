<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerIdentityChallenges;
use App\Domain\Customers\CustomerIdentityPolicy;
use App\Domain\Customers\CustomerSessions;
use App\Domain\Customers\Models\CustomerIdentityChallenge;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CustomerFixtures;
use Tests\Support\CustomerIdentityFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class CustomerIdentityTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        F::configure();
        Mail::fake();
    }

    public function test_enrollment_requires_proof_then_creates_one_normalized_identity_without_staff_or_guest_linking(): void
    {
        $body = F::request(email: ' New-Customer@EXAMPLE.TEST ');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customer_accounts', 0);
        $raw = DB::table('customer_identity_challenges')->sole();
        $this->assertSame(hash('sha256', $body['proof']), $raw->proof_hash);
        $this->assertStringNotContainsString('new-customer@example.test', json_encode($raw));
        $this->assertStringNotContainsString($body['proof'], json_encode($raw));
        F::complete($body);
        $user = User::sole();
        $principal = app(CustomerAccess::class)->principal($user);
        $this->assertSame('new-customer@example.test', $user->email);
        $this->assertFalse($user->is_admin);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check(F::PASSWORD, $user->password));
        $before = $user->password;
        F::complete($body);
        $this->assertSame($before, $user->fresh()->password);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customer_accounts', 1);
        $this->assertDatabaseCount('quote_owners', 1);
        $this->assertSame(1, AuditEvent::where('action', 'customer.test_identity.enroll')->count());
        $this->assertSame($principal->ownerKey, app(CustomerSessions::class)->authenticate('NEW-CUSTOMER@example.test', F::PASSWORD)['principal']->ownerKey);
        Mail::assertNothingSent();
    }

    public function test_recovery_preserves_original_account_orders_and_invalidates_old_principal_without_touching_staff(): void
    {
        $f = CustomerFixtures::account(['email' => 'retained@example.test']);
        $staff = User::factory()->create(['is_admin' => true]);
        $staffBefore = $staff->fresh()->getAttributes();
        $order = CustomerFixtures::prepared($f['user']);
        $orderBefore = $order->fresh()->getAttributes();
        $accountBefore = $f['account']->fresh()->getAttributes();
        $body = F::request('recover', $f['user']->email);
        $body['password'] = F::REPLACEMENT;
        F::complete($body);
        F::complete($body);
        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertSame($accountBefore, $f['account']->fresh()->getAttributes());
        $this->assertSame($staffBefore, $staff->fresh()->getAttributes());
        $this->assertNull(app(CustomerSessions::class)->authenticate($f['user']->email, CustomerFixtures::PASSWORD));
        $this->assertSame($f['principal']->ownerKey, app(CustomerSessions::class)->authenticate($f['user']->email, F::REPLACEMENT)['principal']->ownerKey);
        $this->expectException(CustomerAccessException::class);
        app(CustomerAccess::class)->current($f['principal']);
    }

    public function test_requests_cannot_adopt_existing_staff_unregistered_or_withdrawn_users(): void
    {
        $users = [User::factory()->create(['email' => 'staff@example.test', 'is_admin' => true]),
            User::factory()->create(['email' => 'old@example.test', 'is_admin' => false, 'email_verified_at' => null])];
        $f = CustomerFixtures::account(['email' => 'withdrawn@example.test']);
        CustomerFixtures::withdraw($f);
        $users[] = $f['user'];
        foreach ($users as $user) {
            foreach (['enroll', 'recover'] as $purpose) {
                app(CustomerIdentityChallenges::class)->request($purpose, $user->email, (string) Str::uuid(), F::OWNER);
            }
        }
        $this->assertSame([], Storage::disk('local')->files('customer-identity-capture'));
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('customer_accounts', 1);
        $this->assertFalse($f['account']->fresh()->active);
        Mail::assertNothingSent();
    }

    public function test_exact_request_resends_without_extending_ttl_and_new_requests_keep_separate_binding(): void
    {
        $key = (string) Str::uuid();
        $one = F::request(requestKey: $key);
        $before = CustomerIdentityChallenge::sole()->getAttributes();
        $this->travel(30)->seconds();
        $two = F::request(requestKey: $key);
        $this->assertSame($one['proof'], $two['proof']);
        $this->assertSame($before, CustomerIdentityChallenge::sole()->getAttributes());
        $nextKey = (string) Str::uuid();
        $three = F::request(requestKey: $nextKey);
        $this->assertNotSame($one['proof'], $three['proof']);
        app(CustomerIdentityChallenges::class)->request('enroll', 'other@example.test', $nextKey, F::OWNER);
        $this->assertDatabaseCount('customer_identity_challenges', 2);
        $this->assertCount(2, Storage::disk('local')->files('customer-identity-capture'));
        $this->travel(CustomerIdentityPolicy::TTL_SECONDS)->seconds();
        $this->refused(fn () => F::complete($one));
    }

    #[DataProvider('withdrawals')]
    public function test_current_authority_and_identity_binding_reject_old_recovery_proof(string $change): void
    {
        $f = CustomerFixtures::account(['email' => 'retained@example.test']);
        $body = F::request('recover', $f['user']->email);
        match ($change) {
            'withdraw' => CustomerFixtures::withdraw($f),
            'staff' => $f['user']->forceFill(['is_admin' => true])->save(),
            'verify' => $f['user']->forceFill(['email_verified_at' => null])->save(),
            'password' => $f['user']->forceFill(['password' => Hash::make('Another-password-42')])->save(),
            'email' => $f['user']->forceFill(['email' => 'changed@example.test'])->save(),
        };
        $before = $f['user']->fresh()->getAttributes();
        $this->refused(fn () => F::complete($body));
        $this->assertSame($before, $f['user']->fresh()->getAttributes());
        $this->assertSame('pending', CustomerIdentityChallenge::sole()->state);
    }

    public static function withdrawals(): array
    {
        return array_map(fn ($v) => [$v], ['withdraw', 'staff', 'verify', 'password', 'email']);
    }

    public function test_tampered_expired_replayed_changed_payload_and_withdrawn_feature_never_write_credentials(): void
    {
        $body = F::request();
        $bad = $body;
        $bad['proof'] = str_repeat('f', 64);
        $this->refused(fn () => F::complete($bad));
        $bad = $body;
        $bad['purpose'] = 'recover';
        $bad['name'] = '';
        $this->refused(fn () => F::complete($bad));
        config(['customer.test_identity_enabled' => false]);
        $this->refused(fn () => F::complete($body));
        config(['customer.test_identity_enabled' => true]);
        F::complete($body);
        $password = User::sole()->password;
        $body['password'] = F::REPLACEMENT;
        $this->refused(fn () => F::complete($body));
        $this->assertSame($password, User::sole()->password);
    }

    public function test_unknown_request_and_completion_commit_results_recover_without_duplicate_credentials_or_identity(): void
    {
        $armed = true;
        Event::listen(TransactionCommitted::class, function () use (&$armed): void {
            if ($armed && DB::transactionLevel() === 0) {
                $armed = false;
                throw new RuntimeException('Synthetic lost commit acknowledgement.');
            }
        });
        $key = (string) Str::uuid();
        try {
            F::request(requestKey: $key);
            $this->fail('Unknown request commit did not throw.');
        } catch (RuntimeException) {
        }
        $this->assertDatabaseCount('customer_identity_challenges', 1);
        $body = F::request(requestKey: $key);
        $armed = true;
        try {
            F::complete($body);
            $this->fail('Unknown completion commit did not throw.');
        } catch (RuntimeException) {
        }
        $this->assertSame('completed', CustomerIdentityChallenge::sole()->state);
        $hash = User::sole()->password;
        F::complete($body);
        $this->assertSame($hash, User::sole()->password);
        $this->assertDatabaseCount('customer_accounts', 1);
        $this->assertSame(1, AuditEvent::where('action', 'customer.test_identity.enroll')->count());
    }

    public function test_audit_failure_rolls_back_enrollment_and_permits_exact_retry(): void
    {
        $body = F::request();
        $fail = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$fail): void {
            if ($fail && str_starts_with($sql, 'insert into ') && str_contains($sql, 'audit_events')) {
                $fail = false;
                throw new RuntimeException('Synthetic audit failure');
            }
        });
        try {
            F::complete($body);
            $this->fail('Audit failure ignored.');
        } catch (RuntimeException) {
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertSame('pending', CustomerIdentityChallenge::sole()->state);
        F::complete($body);
        $this->assertDatabaseCount('customer_accounts', 1);
    }

    public function test_proof_cannot_cross_addresses_and_completed_replay_cannot_restore_a_later_reset(): void
    {
        $one = F::request(email: 'one@example.test');
        $two = F::request(email: 'two@example.test');
        $cross = $one;
        $cross['proof'] = $two['proof'];
        $this->refused(fn () => F::complete($cross));
        $this->assertDatabaseCount('users', 0);
        F::complete($one);
        $user = User::sole();
        $recovery = F::request('recover', $user->email);
        $recovery['password'] = F::REPLACEMENT;
        F::complete($recovery);
        $after = $user->fresh()->getAttributes();
        $this->refused(fn () => F::complete($one));
        $this->assertSame($after, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('customer_accounts', 1);
    }

    public function test_first_success_invalidates_sibling_enrollment_and_recovery_proofs(): void
    {
        $one = F::request();
        $two = F::request();
        F::complete($one);
        $before = User::sole()->getAttributes();
        $this->refused(fn () => F::complete($two));
        $this->assertSame($before, User::sole()->getAttributes());
        $resetOne = F::request('recover');
        $resetTwo = F::request('recover');
        $resetOne['password'] = F::REPLACEMENT;
        F::complete($resetOne);
        $after = User::sole()->getAttributes();
        $this->refused(fn () => F::complete($resetTwo));
        $this->assertSame($after, User::sole()->getAttributes());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'customer.test_identity.recover')->count());
        $this->assertDatabaseCount('customer_accounts', 1);
    }

    public function test_request_replay_is_bound_to_original_address_purpose_and_session_owner(): void
    {
        $key = (string) Str::uuid();
        $body = F::request(email: 'one@example.test', requestKey: $key);
        $before = CustomerIdentityChallenge::sole()->getAttributes();
        app(CustomerIdentityChallenges::class)->request('enroll', 'two@example.test', $key, F::OWNER);
        app(CustomerIdentityChallenges::class)->request('recover', 'one@example.test', $key, F::OWNER);
        $this->assertSame($before, CustomerIdentityChallenge::sole()->getAttributes());
        $this->assertCount(1, Storage::disk('local')->files('customer-identity-capture'));
        app(CustomerIdentityChallenges::class)->request('enroll', 'two@example.test', $key, str_repeat('b', 64));
        $this->assertDatabaseCount('customer_identity_challenges', 2);
        F::complete($body);
        $this->assertSame('one@example.test', User::sole()->email);
    }

    public function test_password_rules_reject_truncation_nul_and_weak_values_without_consuming_proof(): void
    {
        $body = F::request();
        foreach (['Short-42', 'OnlyLettersWithoutDigits', '123456789012345', str_repeat('a', 72).'42', "Synthetic-42\0hidden"] as $password) {
            $bad = [...$body, 'password' => $password];
            try {
                F::complete($bad);
                $this->fail('Invalid password was accepted.');
            } catch (CustomerAccessException|ValidationException) {
                $this->assertDatabaseCount('users', 0);
                $this->assertSame('pending', CustomerIdentityChallenge::sole()->state);
            }
        }
        F::complete($body);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_private_capture_failure_keeps_generic_request_and_replays_the_same_challenge(): void
    {
        config(['filesystems.disks.local.visibility' => 'public']);
        $key = (string) Str::uuid();
        app(CustomerIdentityChallenges::class)->request('enroll', 'new-customer@example.test', $key, F::OWNER);
        $before = CustomerIdentityChallenge::sole()->getAttributes();
        $this->assertSame([], Storage::disk('local')->files('customer-identity-capture'));
        config(['filesystems.disks.local.visibility' => 'private']);
        $body = F::request(requestKey: $key);
        $this->assertSame($before, CustomerIdentityChallenge::sole()->getAttributes());
        F::complete($body);
        $this->assertDatabaseCount('users', 1);
    }

    private function refused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unavailable identity accepted.');
        } catch (CustomerAccessException) {
            $this->addToAssertionCount(1);
        }
    }
}
