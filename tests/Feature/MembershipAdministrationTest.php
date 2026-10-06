<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Memberships\CreditLedger;
use App\Domain\Memberships\MembershipAdministration;
use App\Domain\Memberships\MembershipPlans;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures as F;
use Tests\TestCase;

class MembershipAdministrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_plan_history_retains_immutable_policies_and_returns_only_the_review_projection(): void
    {
        $f = F::plan();
        $first = $f['version']->getAttributes();
        $plans = app(MembershipPlans::class);
        $plans->applyReviewedRevision($plans->reviewRevision($f['plan'], F::data(['allowance' => 7]), $f['operator']), $f['operator']);
        $before = $this->rows();
        $history = app(MembershipAdministration::class)->planHistory($f['plan']->id, $f['operator']);
        $this->assertSame([1, 2], array_column($history['versions'], 'number'));
        $this->assertSame([3, 7], array_column(array_column($history['versions'], 'policy'), 'allowance'));
        $this->assertSame($first, $f['version']->fresh()->getAttributes());
        $this->assertSame(['plan_id', 'plan_hash', 'version_hash', 'history_hash', 'audit_id', 'versions'], array_keys($history));
        $this->assertSame(['version_id', 'number', 'title', 'policy', 'created_at'], array_keys($history['versions'][0]));
        $this->assertSame($before, $this->rows());
    }

    public function test_credit_inspection_uses_the_original_policy_without_impersonation_or_writes(): void
    {
        $f = F::bucket();
        $ledger = app(CreditLedger::class);
        $reservation = $ledger->reserve($f['grant']['bucket_id'], 2, 'synthetic:inspection', 'inspection-reserve', $f['principal'], $f['user']);
        $ledger->consume($reservation['event_id'], 'inspection-consume', $f['principal'], $f['user']);
        $plans = app(MembershipPlans::class);
        $plans->applyReviewedRevision($plans->reviewRevision($f['plan'], F::data(['allowance' => 9]), $f['operator']), $f['operator']);
        $this->actingAs($f['operator']);
        $before = $this->rows();
        $history = app(MembershipAdministration::class)->creditHistory($f['account']->id, $f['grant']['bucket_id'], $f['operator']);
        $this->assertSame($f['operator']->id, auth()->id());
        $this->assertSame(['available' => 1, 'reserved' => 0, 'consumed' => 2, 'expired' => 0], $history['balance']);
        $this->assertSame(1, $history['spendable_credits']);
        $this->assertSame(['grant', 'reserve', 'consume'], array_column($history['events'], 'kind'));
        $this->assertSame(['id', 'kind', 'amount', 'created_at'], array_keys($history['events'][0]));
        $encoded = json_encode($history, JSON_THROW_ON_ERROR);
        foreach ([$f['user']->email, $f['user']->password, $f['account']->owner_key, 'resource_hash', 'key_hash', 'request_hash', 'actor_id'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->assertSame($before, $this->rows());
    }

    public function test_expired_credit_inspection_reports_zero_spendable_without_synthesizing_expiry(): void
    {
        $this->travelTo(now()->utc()->startOfSecond());
        $f = F::bucket(['validity_seconds' => 1]);
        $this->travel(1)->seconds();
        $before = $this->rows();
        $history = app(MembershipAdministration::class)->creditHistory($f['account']->id, $f['grant']['bucket_id'], $f['operator']);
        $this->assertSame(0, $history['spendable_credits']);
        $this->assertSame(3, $history['balance']['available']);
        $this->assertSame(['grant'], array_column($history['events'], 'kind'));
        $this->assertSame($before, $this->rows());
    }

    public function test_credit_inspection_refuses_a_different_account_and_inactive_customer(): void
    {
        $f = F::bucket();
        $other = CustomerFixtures::account();
        $this->assertRefused(fn () => app(MembershipAdministration::class)->creditHistory($other['account']->id, $f['grant']['bucket_id'], $f['operator']));
        DB::table('customer_accounts')->where('id', $f['account']->id)->update(['active' => false, 'access_version' => 2]);
        $this->assertRefused(fn () => app(MembershipAdministration::class)->creditHistory($f['account']->id, $f['grant']['bucket_id'], $f['operator']));
    }

    public function test_account_options_are_minimized_hints_for_current_eligible_accounts(): void
    {
        $operator = F::operator();
        $eligible = CustomerFixtures::account();
        $inactive = CustomerFixtures::account();
        $unverified = CustomerFixtures::account();
        DB::table('customer_accounts')->where('id', $inactive['account']->id)->update(['active' => false, 'access_version' => 2]);
        DB::table('users')->where('id', $unverified['user']->id)->update(['email_verified_at' => null]);
        $options = app(MembershipAdministration::class)->accounts($operator);
        $this->assertSame([$eligible['account']->id => 'Test account #'.$eligible['account']->id], $options);
    }

    public function test_private_admin_requires_enrolled_mfa_when_the_local_panel_is_optional(): void
    {
        $operator = F::operator();
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: false);
        DB::table('users')->where('id', $operator->id)->update(['app_authentication_secret' => null]);
        $this->assertRefused(fn () => app(MembershipAdministration::class)->authorize($operator));
        $this->assertFalse($panel->isMultiFactorAuthenticationRequired(), 'Inspection must not mutate shared panel configuration.');
    }

    public static function boundaryCases(): array
    {
        return ['production' => ['production'], 'disabled' => ['disabled'], 'customer disabled' => ['customer-disabled']];
    }

    #[DataProvider('boundaryCases')]
    public function test_private_admin_cannot_enable_real_membership_behavior(string $boundary): void
    {
        $f = F::bucket();
        if ($boundary === 'production') {
            $this->app->detectEnvironment(fn () => 'production');
        } elseif ($boundary === 'disabled') {
            config(['memberships.test_mode_enabled' => false]);
        } else {
            config(['customer.test_accounts_enabled' => false]);
        }
        $this->assertRefused(fn () => app(MembershipAdministration::class)->creditHistory($f['account']->id, $f['grant']['bucket_id'], $f['operator']));
        $this->app->detectEnvironment(fn () => 'testing');
    }

    public static function authorityCases(): array
    {
        $cases = [];
        foreach (['plan', 'accounts', 'credits'] as $read) {
            foreach (['role', 'email', 'mfa'] as $withdrawal) {
                $cases["$read $withdrawal"] = [$read, $withdrawal];
            }
        }

        return $cases;
    }

    #[DataProvider('authorityCases')]
    public function test_terminal_query_withdrawals_cannot_return_authorized_admin_evidence(string $read, string $withdrawal): void
    {
        $f = F::bucket();
        $before = $this->rows();
        $fired = false;
        DB::listen(function ($query) use ($read, $withdrawal, $f, &$fired): void {
            $target = match ($read) {
                'plan' => str_contains($query->sql, 'membership_plan_versions') && str_contains($query->sql, 'order by'),
                'accounts' => str_contains($query->sql, 'customer_accounts') && str_contains($query->sql, 'join'),
                'credits' => str_contains($query->sql, 'membership_credit_events') && str_contains($query->sql, 'sequence'),
            };
            if (! $target || $fired) {
                return;
            }
            $fired = true;
            [$field, $value] = match ($withdrawal) {
                'role' => ['is_admin', 0], 'email' => ['email_verified_at', null], 'mfa' => ['app_authentication_secret', null],
            };
            DB::connection()->getPdo()->prepare("UPDATE users SET $field = ? WHERE id = ?")->execute([$value, $f['operator']->id]);
        });
        $this->assertRefused(fn () => match ($read) {
            'plan' => app(MembershipAdministration::class)->planHistory($f['plan']->id, $f['operator']),
            'accounts' => app(MembershipAdministration::class)->accounts($f['operator']),
            'credits' => app(MembershipAdministration::class)->creditHistory($f['account']->id, $f['grant']['bucket_id'], $f['operator']),
        });
        $this->assertTrue($fired);
        $this->assertSame($before, $this->rows());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_clock_crossing_during_credit_read_refuses_an_expired_spendability_projection(): void
    {
        $this->travelTo(now()->utc()->startOfSecond());
        $f = F::bucket(['validity_seconds' => 1]);
        $seen = 0;
        $fired = false;
        DB::listen(function ($query) use (&$seen, &$fired): void {
            if (! str_contains($query->sql, 'membership_credit_events') || ! str_contains($query->sql, 'sequence')) {
                return;
            }
            if (++$seen === 4) { // The final admin scope follows the ledger's two own reads.
                $fired = true;
                $this->travel(1)->seconds();
            }
        });
        $this->assertRefused(fn () => app(MembershipAdministration::class)->creditHistory($f['account']->id, $f['grant']['bucket_id'], $f['operator']));
        $this->assertTrue($fired);
    }

    public function test_operator_withdrawal_during_the_delegated_ledger_read_cannot_escape_the_final_admin_fence(): void
    {
        $f = F::bucket();
        $seen = 0;
        $fired = false;
        DB::listen(function ($query) use ($f, &$seen, &$fired): void {
            if (! str_contains($query->sql, 'membership_credit_events') || ! str_contains($query->sql, 'sequence')) {
                return;
            }
            if (++$seen === 2) {
                $fired = true;
                DB::connection()->getPdo()->prepare('UPDATE users SET is_admin = 0 WHERE id = ?')->execute([$f['operator']->id]);
            }
        });
        $this->assertRefused(fn () => app(MembershipAdministration::class)->creditHistory($f['account']->id, $f['grant']['bucket_id'], $f['operator']));
        $this->assertTrue($fired);
        $this->assertDatabaseCount('membership_credit_events', 1);
        $this->assertSame(0, DB::transactionLevel());
    }

    private function assertRefused(callable $read): void
    {
        try {
            $read();
            $this->fail('Private administration returned unavailable evidence.');
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
