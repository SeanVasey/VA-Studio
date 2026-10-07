<?php

namespace Tests\Feature;

use App\Domain\Memberships\MembershipCustomerHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures as F;
use Tests\TestCase;

class MembershipCustomerHistoryHttpTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_real_customer_session_discovers_owned_bucket_and_reads_retained_history_without_staff_login_or_writes(): void
    {
        $f = F::bucket();
        $other = F::bucket(source: 'synthetic:other-buyer');
        $this->login($f);
        $before = $this->rows();
        $this->get('/account/membership-credits')->assertOk()
            ->assertExactJson(['history' => ['test_only' => true, 'bucket_ids' => [$f['grant']['bucket_id']]]])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get($this->url($f))->assertOk()->assertJsonPath('history.plan.version_id', $f['version']->id)
            ->assertJsonPath('history.events.0.kind', 'grant')->assertJsonPath('history.test_only', true)
            ->assertDontSee($f['account']->owner_key, false)->assertDontSee($f['user']->email, false)
            ->assertDontSee($other['user']->email, false)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertSame($before, $this->rows());
    }

    public function test_empty_account_discovery_is_authorized_and_empty_without_invented_benefits(): void
    {
        F::configure();
        $f = CustomerFixtures::account();
        $this->login($f);
        $this->get('/account/membership-credits')->assertOk()
            ->assertExactJson(['history' => ['test_only' => true, 'bucket_ids' => []]]);
    }

    public function test_guest_staff_and_different_customer_cannot_read_bucket(): void
    {
        $f = F::bucket();
        $this->get($this->url($f))->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($f['operator'], 'web')->get($this->url($f))->assertForbidden();
        $this->login(CustomerFixtures::account());
        $this->get($this->url($f))->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(1, DB::table('membership_credit_events')->count());
    }

    public static function boundaries(): array
    {
        return ['production' => ['production'], 'disabled' => ['disabled'], 'customer disabled' => ['customer-disabled'],
            'withdrawn' => ['withdrawn'], 'credentials' => ['credentials']];
    }

    #[DataProvider('boundaries')]
    public function test_current_server_session_and_synthetic_boundaries_cannot_be_overridden_by_client(string $case): void
    {
        $f = F::bucket();
        $this->login($f);
        match ($case) {
            'production' => $this->app->detectEnvironment(fn () => 'production'),
            'disabled' => config(['memberships.test_mode_enabled' => false]),
            'customer-disabled' => config(['customer.test_accounts_enabled' => false]),
            'withdrawn' => CustomerFixtures::withdraw($f),
            'credentials' => DB::table('users')->where('id', $f['user']->id)->update(['password' => 'changed-synthetic']),
        };
        $before = $this->rows();
        $this->get('/account/membership-credits')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->get($this->url($f))->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $this->rows());
        $this->app->detectEnvironment(fn () => 'testing');
    }

    public static function badRequests(): array
    {
        return ['account query' => ['?account_id=1', []], 'owner query' => ['?owner_key=synthetic', []],
            'range' => ['', ['Range' => 'bytes=0-1']], 'cross origin' => ['', ['Sec-Fetch-Site' => 'cross-site']]];
    }

    #[DataProvider('badRequests')]
    public function test_request_override_and_cross_origin_forms_are_refused_privately(string $suffix, array $headers): void
    {
        $f = F::bucket();
        $this->login($f);
        $this->get($this->url($f).$suffix, $headers)->assertStatus($suffix === '' && isset($headers['Sec-Fetch-Site']) ? 403 : 422)
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($f['account']->owner_key, false);
    }

    public function test_get_body_and_invalid_bucket_identity_are_refused(): void
    {
        $f = F::bucket();
        $this->login($f);
        $this->call('GET', $this->url($f), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(422);
        foreach (['0', '01', '-1', '9999999999999999999', 'not-a-bucket'] as $id) {
            $this->get('/account/membership-credits/'.$id)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_discovery_refuses_extra_bucket_appended_after_retained_range_query(): void
    {
        $f = F::bucket();
        $this->login($f);
        $before = $this->rows();
        $fired = false;
        DB::listen(function ($query) use ($f, &$fired): void {
            if ($fired || ! str_contains($query->sql, 'membership_credit_buckets') || ! str_contains($query->sql, 'order by')) {
                return;
            }
            $fired = true;
            $this->duplicateBucket($f, 'terminal-extra');
        });
        $this->get('/account/membership-credits')->assertForbidden();
        $this->assertTrue($fired);
        $this->assertSame($before, $this->rows());
    }

    public function test_discovery_withdrawal_at_range_query_cannot_return_owned_ids(): void
    {
        $f = F::bucket();
        $this->login($f);
        $before = $this->rows();
        $fired = false;
        DB::listen(function ($query) use ($f, &$fired): void {
            if ($fired || ! str_contains($query->sql, 'membership_credit_buckets') || ! str_contains($query->sql, 'order by')) {
                return;
            }
            $fired = true;
            DB::connection()->getPdo()->prepare('UPDATE customer_accounts SET active = 0, access_version = access_version + 1 WHERE id = ?')->execute([$f['account']->id]);
        });
        $this->get('/account/membership-credits')->assertForbidden();
        $this->assertTrue($fired);
        $this->assertSame($before, $this->rows());
    }

    public function test_max_plus_one_discovery_refuses_entire_result_instead_of_omitting_buckets(): void
    {
        $f = F::bucket();
        $this->login($f);
        for ($i = 1; $i < MembershipCustomerHistory::MAX_BUCKETS; $i++) {
            $this->duplicateBucket($f, 'bound-'.$i);
        }
        $this->get('/account/membership-credits')->assertOk()->assertJsonCount(MembershipCustomerHistory::MAX_BUCKETS, 'history.bucket_ids');
        $this->duplicateBucket($f, 'over-bound');
        $before = $this->rows();
        $this->get('/account/membership-credits')->assertForbidden()->assertJsonMissingPath('history');
        $this->assertSame($before, $this->rows());
    }

    public function test_http_history_rejects_expiry_crossing_during_terminal_proof(): void
    {
        $this->travelTo(now()->utc()->startOfSecond());
        $f = F::bucket(['validity_seconds' => 1]);
        $this->login($f);
        $seen = 0;
        $fired = false;
        DB::listen(function ($query) use (&$seen, &$fired): void {
            if (str_contains($query->sql, 'membership_credit_events') && str_contains($query->sql, 'sequence') && ++$seen === 2) {
                $fired = true;
                $this->travel(1)->seconds();
            }
        });
        $before = $this->rows();
        $this->get($this->url($f))->assertForbidden()->assertJsonMissingPath('history')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertTrue($fired);
        $this->assertSame($before, $this->rows());
    }

    public function test_unknown_and_foreign_bucket_have_the_same_private_failure(): void
    {
        $f = F::bucket();
        $this->login(CustomerFixtures::account());
        $foreign = $this->get($this->url($f))->assertForbidden()->json();
        $unknown = $this->get('/account/membership-credits/999999')->assertForbidden()->json();
        $this->assertSame($foreign, $unknown);
    }

    private function duplicateBucket(array $f, string $suffix): void
    {
        // Synthetic selection hints only; these intentionally unawarded buckets confer no benefit.
        $pdo = DB::connection()->getPdo();
        $row = $pdo->query('SELECT * FROM membership_credit_buckets WHERE id = '.(int) $f['grant']['bucket_id'])->fetch(\PDO::FETCH_ASSOC);
        unset($row['id']);
        $row['source_event_hash'] = hash('sha256', 'synthetic:'.$suffix);
        $row['source_request_hash'] = hash('sha256', 'synthetic:unawarded-'.$suffix);
        $pdo->prepare('INSERT INTO membership_credit_buckets ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')')->execute(array_values($row));
    }

    private function login(array $f): void
    {
        $this->postJson('/account/sign-in', ['email' => $f['user']->email, 'password' => CustomerFixtures::PASSWORD])->assertOk();
    }

    private function url(array $f): string
    {
        return '/account/membership-credits/'.$f['grant']['bucket_id'];
    }

    private function rows(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['users', 'customer_accounts', 'membership_plans', 'membership_plan_versions', 'membership_credit_buckets', 'membership_credit_events', 'audit_events']);
    }
}
