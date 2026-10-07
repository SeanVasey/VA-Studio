<?php

namespace Tests\Feature;

use App\Domain\Memberships\CreditLedger;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures as F;
use Tests\TestCase;

class MembershipCreditMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const TABLES = ['membership_plans', 'membership_plan_versions', 'membership_credit_buckets', 'membership_credit_events'];

    private const MIGRATION = '2026_10_06_230000_membership_credit_foundation';

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
        try {
            $migration->down();
            $this->fail('Retained membership rollback reported success.');
        } catch (LogicException $exception) {
            $this->assertSame('Membership schema rollback is refused; retain its migration record, data and guards.', $exception->getMessage());
        }
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

    public static function rollbackSchemas(): array
    {
        return ['empty retained schema' => [false], 'populated retained schema' => [true]];
    }

    #[DataProvider('rollbackSchemas')]
    public function test_actual_artisan_rollback_preserves_migration_bookkeeping_and_exact_retained_evidence(bool $populated): void
    {
        if ($populated) {
            $f = F::bucket();
            app(CreditLedger::class)->reserve($f['grant']['bucket_id'], 1, 'synthetic:rollback_retained_resource', 'rollback-retained-reserve', $f['principal'], $f['user']);
        }
        // The actual Migrator must select 230000, rather than silently examining a later child.
        $batch = (int) DB::table('migrations')->max('batch') + 1;
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->update(['batch' => $batch]));
        $record = (array) DB::table('migrations')->where('migration', self::MIGRATION)->sole();
        $this->assertSame(self::MIGRATION, app('migration.repository')->getLast()[0]->migration);
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertSame($populated ? 2 : 0, DB::table('membership_credit_events')->count());
        $this->assertSame(12, DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', self::TABLES)->count()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereIn('EVENT_OBJECT_TABLE', self::TABLES)->count());
        $before = $this->retainedSnapshot();
        $refused = false;
        try {
            Artisan::call('migrate:rollback', ['--path' => [database_path('migrations/'.self::MIGRATION.'.php')],
                '--realpath' => true, '--step' => 1, '--force' => true]);
        } catch (LogicException $exception) {
            $this->assertSame('Membership schema rollback is refused; retain its migration record, data and guards.', $exception->getMessage());
            $refused = true;
        }
        // An empty successful down() fails here: Migrator deletes the repository row.
        $this->assertSame($before, $this->retainedSnapshot());
        $this->assertTrue($refused, 'Retaining tables alone is not a successful rollback.');
        $this->assertSame($record, (array) DB::table('migrations')->where('migration', self::MIGRATION)->sole());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame($before, $this->retainedSnapshot(), 'Ordinary migrate must preserve the recorded retained schema without attempting to reinstall it.');

        // Prove useful forward migration, not only a command that reports nothing pending.
        $directory = storage_path('framework/testing/membership-rollback-'.Str::uuid());
        (new Filesystem)->ensureDirectoryExists($directory);
        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($directory));
        $probe = '2099_01_01_000000_membership_rollback_forward_probe';
        file_put_contents($directory.'/'.$probe.'.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_rollback_forward_probe', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->unsignedInteger('synthetic_marker');
        });
    }

    public function down(): void
    {
        throw new LogicException('Disposable probe cleanup belongs to the testing lifecycle.');
    }
};
PHP);
        $this->assertSame(0, Artisan::call('migrate', ['--path' => [$directory], '--realpath' => true, '--force' => true]));
        $this->assertTrue(Schema::hasTable('membership_rollback_forward_probe'));
        $this->assertSame(1, DB::table('migrations')->where('migration', $probe)->count());
        DB::table('membership_rollback_forward_probe')->insert(['synthetic_marker' => 42]);
        $this->assertSame(42, (int) DB::table('membership_rollback_forward_probe')->value('synthetic_marker'));
        $after = $this->retainedSnapshot();
        $after['migration_records'] = array_values(array_filter($after['migration_records'], fn ($row) => $row['migration'] !== $probe));
        $this->assertSame($before, $after, 'Forward migration must leave the complete retained membership evidence unchanged.');
        $this->assertSame(0, DB::transactionLevel());
    }

    private function retainedSnapshot(): array
    {
        $definitions = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->whereIn('tbl_name', self::TABLES)->orderBy('name')->get()->map(fn ($row) => (array) $row)->all()
            : array_map(fn ($table) => (array) DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table)), self::TABLES);

        return ['migration_records' => DB::table('migrations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'table_definitions' => $definitions, 'guards' => $this->schema(), 'membership_rows' => $this->rows(),
            'users' => DB::table('users')->orderBy('id')->get()->toJson(),
            'accounts' => DB::table('customer_accounts')->orderBy('id')->get()->toJson(),
            'audits' => DB::table('audit_events')->orderBy('id')->get()->toJson()];
    }

    private function schema(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->whereIn('tbl_name', self::TABLES)->orderBy('name')->get()->toJson()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereIn('EVENT_OBJECT_TABLE', self::TABLES)->orderBy('TRIGGER_NAME')->get()->toJson();
    }
}
