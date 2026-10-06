<?php

namespace Tests\Feature;

use App\Domain\Memberships\CreditLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures as F;
use Tests\TestCase;

class MembershipCreditMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const TABLES = ['membership_plans', 'membership_plan_versions', 'membership_credit_buckets', 'membership_credit_events'];

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        $this->travelTo(now()->utc()->startOfSecond());
    }

    private function rows(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), self::TABLES);
    }

    private function refused(callable $operation): void
    {
        $before = $this->rows();
        try {
            $operation();
            $this->fail('A raw invalid membership mutation was admitted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $this->assertSame($before, $this->rows());
    }

    public static function immutable(): array
    {
        $cases = [];
        foreach (self::TABLES as $table) {
            foreach (['update', 'delete', 'replace', 'ignore'] as $operation) {
                $cases[$table.' '.$operation] = [$table, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('immutable')]
    public function test_sql_retention_and_replace_guards_cover_every_membership_table(string $table, string $operation): void
    {
        F::bucket();
        $row = (array) DB::table($table)->first();
        $this->refused(function () use ($row, $table, $operation): void {
            if ($operation === 'update') {
                DB::table($table)->where('id', $row['id'])->update(['created_at' => now()->addSecond()]);
            } elseif ($operation === 'delete') {
                DB::table($table)->where('id', $row['id'])->delete();
            } elseif ($operation === 'ignore') {
                DB::table($table)->insertOrIgnore($row);
            } else {
                $grammar = DB::connection()->getQueryGrammar();
                $columns = implode(',', array_map($grammar->wrap(...), array_keys($row)));
                $prefix = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE';
                DB::insert("$prefix INTO ".$grammar->wrapTable($table)." ($columns) VALUES (".implode(',', array_fill(0, count($row), '?')).')', array_values($row));
            }
        });
    }

    public static function malformedMovement(): array
    {
        return ['negative balance' => ['negative'], 'minted balance' => ['minted'], 'wrong before' => ['before'],
            'missing field' => ['missing'], 'extra field' => ['extra'], 'float balance' => ['float'],
            'zero amount' => ['zero'], 'wrong amount' => ['amount'], 'wrong resource' => ['resource'],
            'missing reservation' => ['reservation'], 'wrong predecessor' => ['previous'], 'wrong predecessor hash' => ['hash'],
            'another grant' => ['grant'], 'duplicate sequence' => ['sequence'], 'wrong actor' => ['actor'],
            'stale account version' => ['access'], 'unknown kind' => ['kind'], 'foreign source' => ['source']];
    }

    #[DataProvider('malformedMovement')]
    public function test_sql_balance_floor_sequence_resource_and_current_ownership_guards(string $case): void
    {
        $f = F::bucket();
        $reserve = app(CreditLedger::class)->reserve($f['grant']['bucket_id'], 2, 'synthetic:raw-guard', 'reserved', $f['principal'], $f['user']);
        $last = (array) DB::table('membership_credit_events')->where('id', $reserve['event_id'])->first();
        $row = $last;
        unset($row['id']);
        $row['sequence'] = 3;
        $row['kind'] = 'consume';
        $row['reservation_event_id'] = $last['id'];
        $row['previous_event_id'] = $last['id'];
        $row['previous_hash'] = $last['event_hash'];
        $row['key_hash'] = hash('sha256', 'raw-invalid-new-key');
        $row['request_hash'] = hash('sha256', 'raw-invalid-request');
        $row['event_hash'] = hash('sha256', 'raw-invalid-hash');
        $row['before_balance'] = $last['after_balance'];
        $row['after_balance'] = json_encode(['available' => 1, 'reserved' => 0, 'consumed' => 2, 'expired' => 0]);
        switch ($case) {
            case 'negative': $row['after_balance'] = json_encode(['available' => -1, 'reserved' => 0, 'consumed' => 4, 'expired' => 0]);
                break;
            case 'minted': $row['after_balance'] = json_encode(['available' => 2, 'reserved' => 0, 'consumed' => 2, 'expired' => 0]);
                break;
            case 'before': $row['before_balance'] = json_encode(['available' => 3, 'reserved' => 0, 'consumed' => 0, 'expired' => 0]);
                break;
            case 'missing': $row['after_balance'] = json_encode(['available' => 1, 'reserved' => 0, 'consumed' => 2]);
                break;
            case 'extra': $row['after_balance'] = json_encode(['available' => 1, 'reserved' => 0, 'consumed' => 2, 'expired' => 0, 'bonus' => 1]);
                break;
            case 'float': $row['after_balance'] = '{"available":1.0,"reserved":0,"consumed":2,"expired":0}';
                break;
            case 'zero': $row['amount'] = 0;
                break;
            case 'amount': $row['amount'] = 1;
                break;
            case 'resource': $row['resource_hash'] = str_repeat('f', 64);
                break;
            case 'reservation': $row['reservation_event_id'] = null;
                break;
            case 'previous': $row['previous_event_id'] = $f['grant']['event_id'];
                break;
            case 'hash': $row['previous_hash'] = str_repeat('f', 64);
                break;
            case 'grant': $row['kind'] = 'grant';
                break;
            case 'sequence': $row['sequence'] = 2;
                break;
            case 'actor': $row['actor_id'] = $f['operator']->id;
                break;
            case 'access': $row['account_access_version'] = 999;
                break;
            case 'kind': $row['kind'] = 'gift';
                break;
            case 'source': $row['source_event_id'] = $f['grant']['event_id'];
                break;
        }
        $this->refused(fn () => DB::table('membership_credit_events')->insert($row));
    }

    public function test_partial_existing_schema_is_never_adopted_or_dropped_and_down_retains_evidence(): void
    {
        F::bucket();
        $before = $this->rows();
        $schema = $this->schema();
        $migration = require database_path('migrations/2026_10_06_230000_membership_credit_foundation.php');
        try {
            $migration->up();
            $this->fail('Existing membership tables were adopted.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $migration->down();
        $this->assertSame($before, $this->rows());
        $this->assertSame($schema, $this->schema());
    }

    public function test_temporary_shadow_or_case_variant_is_refused_before_any_new_object(): void
    {
        // Dispose only this isolated test's empty new child schema; never operational down().
        Schema::disableForeignKeyConstraints();
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::drop($table);
        }
        Schema::enableForeignKeyConstraints();
        DB::unprepared('CREATE TEMPORARY TABLE membership_plans (marker INTEGER)');
        DB::table('membership_plans')->insert(['marker' => 42]);
        $migration = require database_path('migrations/2026_10_06_230000_membership_credit_foundation.php');
        try {
            $migration->up();
            $this->fail('A temporary membership shadow was adopted.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $this->assertSame(42, (int) DB::table('membership_plans')->value('marker'));
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(0, DB::table('sqlite_master')->whereIn('name', self::TABLES)->count());
        } else {
            $this->assertSame(0, DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereIn('TABLE_NAME', self::TABLES)->count());
        }
        DB::unprepared('DROP TABLE membership_plans');
    }

    private function schema(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->whereIn('tbl_name', self::TABLES)->orderBy('name')->get()->toJson()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereIn('EVENT_OBJECT_TABLE', self::TABLES)->orderBy('TRIGGER_NAME')->get()->toJson();
    }
}
