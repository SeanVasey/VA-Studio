<?php

namespace Tests\Feature;

use App\Domain\Memberships\CreditLedger;
use App\Domain\Memberships\MembershipPlans;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures as F;
use Tests\TestCase;

class MembershipCreditEngineTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Membership engine durability requires genuine MySQL with an altered session default.');
        }
    }

    public function test_an_altered_myisam_session_cannot_replace_membership_innodb_constraints_or_atomicity(): void
    {
        $tables = ['membership_credit_events', 'membership_credit_buckets', 'membership_plan_versions', 'membership_plans'];
        $original = DB::selectOne('SELECT @@SESSION.default_storage_engine AS engine')->engine;
        try {
            foreach ($tables as $table) {
                $this->assertSame(0, DB::table($table)->count());
                Schema::drop($table);
            }
            DB::statement("SET SESSION default_storage_engine = 'MyISAM'");
            $this->assertSame('MyISAM', DB::selectOne('SELECT @@SESSION.default_storage_engine AS engine')->engine);
            (require database_path('migrations/2026_10_06_230000_membership_credit_foundation.php'))->up();
            $engines = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->whereIn('TABLE_NAME', $tables)->orderBy('TABLE_NAME')->pluck('ENGINE', 'TABLE_NAME')->all();
            $this->assertCount(4, $engines);
            foreach ($engines as $engine) {
                $this->assertSame('InnoDB', $engine);
            }
            $constraints = DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->whereIn('TABLE_NAME', $tables)->whereNotNull('REFERENCED_TABLE_NAME')->get()
                ->map(fn ($row) => $row->TABLE_NAME.'.'.$row->COLUMN_NAME.' -> '.$row->REFERENCED_TABLE_NAME.'.'.$row->REFERENCED_COLUMN_NAME)->all();
            sort($constraints);
            $expected = [
                'membership_plans.created_by -> users.id',
                'membership_plan_versions.membership_plan_id -> membership_plans.id',
                'membership_plan_versions.created_by -> users.id',
                'membership_credit_buckets.customer_account_id -> customer_accounts.id',
                'membership_credit_buckets.membership_plan_version_id -> membership_plan_versions.id',
                'membership_credit_buckets.created_by -> users.id',
                'membership_credit_events.membership_credit_bucket_id -> membership_credit_buckets.id',
                'membership_credit_events.reservation_event_id -> membership_credit_events.id',
                'membership_credit_events.source_event_id -> membership_credit_events.id',
                'membership_credit_events.previous_event_id -> membership_credit_events.id',
                'membership_credit_events.actor_id -> users.id',
            ];
            sort($expected);
            $this->assertSame($expected, $constraints);
            $operator = F::operator();
            $before = $this->rows();
            AuditEvent::created(fn () => throw new RuntimeException('SYNTHETIC_ENGINE_ROLLBACK'));
            try {
                app(MembershipPlans::class)->createDraft(F::data(), $operator);
                $this->fail('The genuine injected audit failure must reach the caller.');
            } catch (RuntimeException $exception) {
                $this->assertSame('SYNTHETIC_ENGINE_ROLLBACK', $exception->getMessage());
            } finally {
                Event::forget('eloquent.created: '.AuditEvent::class);
            }
            $this->assertSame($before, $this->rows());
            $f = F::plan(operator: $operator) + CustomerFixtures::account();
            $before = $this->rows();
            AuditEvent::created(fn () => throw new RuntimeException('SYNTHETIC_ENGINE_ROLLBACK'));
            try {
                app(CreditLedger::class)->grantSynthetic($f['version'], $f['account'], 'synthetic:engine_atomic_award', $operator);
                $this->fail('The genuine injected audit failure must reach the caller.');
            } catch (RuntimeException $exception) {
                $this->assertSame('SYNTHETIC_ENGINE_ROLLBACK', $exception->getMessage());
            } finally {
                Event::forget('eloquent.created: '.AuditEvent::class);
            }
            $this->assertSame($before, $this->rows());
            $this->assertSame('MyISAM', DB::selectOne('SELECT @@SESSION.default_storage_engine AS engine')->engine);
        } finally {
            DB::statement('SET SESSION default_storage_engine = ?', [$original]);
        }
    }

    private function rows(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['users', 'customer_accounts', 'membership_plans', 'membership_plan_versions', 'membership_credit_buckets', 'membership_credit_events', 'audit_events']);
    }
}
