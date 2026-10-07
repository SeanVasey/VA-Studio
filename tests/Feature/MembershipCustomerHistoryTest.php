<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Memberships\CreditLedger;
use App\Domain\Memberships\MembershipPlans;
use App\Domain\Memberships\Models\MembershipCreditBucket;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures as F;
use Tests\TestCase;

class MembershipCustomerHistoryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_customer_history_preserves_original_policy_and_each_credit_balance_without_private_identifiers_or_writes(): void
    {
        $f = F::bucket();
        $ledger = app(CreditLedger::class);
        $reserve = $ledger->reserve($f['grant']['bucket_id'], 2, 'synthetic:history', 'history-reserve', $f['principal'], $f['user']);
        $consume = $ledger->consume($reserve['event_id'], 'history-consume', $f['principal'], $f['user']);
        $ledger->reverse($consume['event_id'], 'history-reverse', $f['operator']);
        $plans = app(MembershipPlans::class);
        $plans->applyReviewedRevision($plans->reviewRevision($f['plan'], F::data(['allowance' => 9]), $f['operator']), $f['operator']);
        $before = $this->rows();
        $this->actingAs($f['user']);
        $history = $this->history($f);
        $this->assertTrue($history['test_only']);
        $this->assertSame($f['user']->id, auth()->id());
        $this->assertSame(1, $history['plan']['number']);
        $this->assertSame(3, $history['plan']['policy']['allowance']);
        $this->assertSame(['grant', 'reserve', 'consume', 'reverse'], array_column($history['events'], 'kind'));
        $this->assertSame([1, 2, 3, 4], array_column($history['events'], 'sequence'));
        $this->assertSame([3, 1, 1, 3], array_column(array_column($history['events'], 'balance'), 'available'));
        $this->assertSame(3, $history['spendable_credits']);
        $this->assertSame(['bucket_id', 'plan_version_id', 'unit', 'expires_at', 'last_event_id', 'balance', 'spendable_credits', 'test_only', 'plan', 'events'], array_keys($history));
        $this->assertSame(['id', 'sequence', 'kind', 'amount', 'created_at', 'balance'], array_keys($history['events'][0]));
        $encoded = json_encode($history, JSON_THROW_ON_ERROR);
        foreach ([$f['user']->email, $f['user']->password, $f['account']->owner_key, 'actor_id', 'resource_hash', 'key_hash', 'source_event_hash', 'request_hash'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->assertSame($before, $this->rows());
    }

    public function test_expired_history_retains_balance_without_writing_expiry(): void
    {
        $this->travelTo(now()->utc()->startOfSecond());
        $f = F::bucket(['validity_seconds' => 1]);
        $this->travel(1)->seconds();
        $before = $this->rows();
        $history = $this->history($f);
        $this->assertSame(0, $history['spendable_credits']);
        $this->assertSame(3, $history['balance']['available']);
        $this->assertSame(['grant'], array_column($history['events'], 'kind'));
        $this->assertSame($before, $this->rows());
    }

    public function test_different_customer_cannot_read_bucket_history(): void
    {
        $f = F::bucket();
        $other = CustomerFixtures::account();
        $before = $this->rows();
        $this->refused(fn () => app(CreditLedger::class)->customerHistory($f['grant']['bucket_id'], $other['principal'], $other['user']));
        $this->assertSame($before, $this->rows());
    }

    public static function boundaries(): array
    {
        return array_map(fn ($case) => [$case], array_combine(['production', 'disabled', 'customer-disabled', 'stale', 'unverified'], ['production', 'disabled', 'customer-disabled', 'stale', 'unverified']));
    }

    #[DataProvider('boundaries')]
    public function test_current_customer_and_synthetic_boundaries_are_required(string $case): void
    {
        $f = F::bucket();
        match ($case) {
            'production' => $this->app->detectEnvironment(fn () => 'production'),
            'disabled' => config(['memberships.test_mode_enabled' => false]),
            'customer-disabled' => config(['customer.test_accounts_enabled' => false]),
            'stale' => $f['principal'] = new CustomerPrincipal($f['principal']->accountId, $f['principal']->userId, $f['principal']->ownerKey, $f['principal']->accessVersion + 1, $f['principal']->credentialStamp),
            'unverified' => DB::table('users')->where('id', $f['user']->id)->update(['email_verified_at' => null]),
        };
        $before = $this->rows();
        $this->refused(fn () => $this->history($f));
        $this->assertSame($before, $this->rows());
        $this->app->detectEnvironment(fn () => 'testing');
    }

    public static function finalWithdrawals(): array
    {
        return ['account' => ['account'], 'credentials' => ['credentials'], 'membership switch' => ['membership'], 'customer switch' => ['customer']];
    }

    #[DataProvider('finalWithdrawals')]
    public function test_authority_withdrawal_at_final_history_query_cannot_escape_primary_proof(string $case): void
    {
        $f = F::bucket();
        $before = $this->rows();
        $seen = 0;
        $fired = false;
        DB::listen(function ($query) use ($case, $f, &$seen, &$fired): void {
            if (! str_contains($query->sql, 'membership_credit_events') || ! str_contains($query->sql, 'sequence') || ++$seen !== 2) {
                return;
            }
            $fired = true;
            match ($case) {
                'account' => DB::connection()->getPdo()->prepare('UPDATE customer_accounts SET active = 0, access_version = access_version + 1 WHERE id = ?')->execute([$f['account']->id]),
                'credentials' => DB::connection()->getPdo()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute(['changed', $f['user']->id]),
                'membership' => config(['memberships.test_mode_enabled' => false]),
                'customer' => config(['customer.test_accounts_enabled' => false]),
            };
        });
        $this->refused(fn () => $this->history($f));
        $this->assertTrue($fired);
        $this->assertSame($before, $this->rows());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_history_clock_crossing_refuses_stale_spendability_and_read_keeps_original_shape(): void
    {
        $this->travelTo(now()->utc()->startOfSecond());
        $f = F::bucket(['validity_seconds' => 1]);
        $read = app(CreditLedger::class)->read($f['grant']['bucket_id'], $f['principal'], $f['user']);
        $this->assertSame(['bucket_id', 'plan_version_id', 'unit', 'expires_at', 'last_event_id', 'balance', 'spendable_credits'], array_keys($read));
        $seen = 0;
        $fired = false;
        DB::listen(function ($query) use (&$seen, &$fired): void {
            if (str_contains($query->sql, 'membership_credit_events') && str_contains($query->sql, 'sequence') && ++$seen === 2) {
                $fired = true;
                $this->travel(1)->seconds();
            }
        });
        $before = $this->rows();
        $this->refused(fn () => $this->history($f));
        $this->assertTrue($fired);
        $this->assertSame($before, $this->rows());
    }

    public function test_extra_audit_appended_after_final_query_cannot_escape_history_proof(): void
    {
        $f = F::bucket();
        $before = $this->rows();
        $seen = 0;
        $fired = false;
        DB::listen(function ($query) use ($f, &$seen, &$fired): void {
            if (! str_contains($query->sql, 'membership_credit_events') || ! str_contains($query->sql, 'sequence') || ++$seen !== 2) {
                return;
            }
            $fired = true;
            DB::connection()->getPdo()->prepare('INSERT INTO audit_events (actor_id, action, subject_type, subject_id, context, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$f['operator']->id, 'membership.test_credit.grant', MembershipCreditBucket::class,
                    $f['grant']['bucket_id'], '{"synthetic":"unbound_extra_audit"}', now()->utc()->startOfSecond()->format('Y-m-d H:i:s')]);
        });
        $this->refused(fn () => $this->history($f));
        $this->assertTrue($fired);
        $this->assertSame($before, $this->rows());
    }

    private function history(array $f): array
    {
        return app(CreditLedger::class)->customerHistory($f['grant']['bucket_id'], $f['principal'], $f['user']);
    }

    private function refused(callable $call): void
    {
        try {
            $call();
            $this->fail('Customer history returned unavailable evidence.');
        } catch (AuthorizationException|ValidationException|CustomerAccessException) {
            $this->assertTrue(true);
        }
    }

    private function rows(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['users', 'customer_accounts', 'membership_plans', 'membership_plan_versions', 'membership_credit_buckets', 'membership_credit_events', 'audit_events']);
    }
}
