<?php

namespace Tests\Feature;

use App\Domain\Catalog\Discovery\CurrentEligibleTrackSnapshot;
use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use App\Domain\Catalog\Models\Track;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class DiscoveryEpochMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const MIGRATION = '2026_10_07_240000_catalog_discovery_epoch';

    public static function populations(): array
    {
        return ['empty' => [false], 'populated' => [true]];
    }

    #[DataProvider('populations')]
    public function test_real_artisan_rollback_preserves_data_guards_bookkeeping_and_forward_migrate(bool $populated): void
    {
        if ($populated) {
            Track::create(['title' => 'Synthetic retained draft', 'slug' => 'retained-draft']);
        }
        // Later migrations legitimately run after 240, so a fixed --step 1 selects the newest
        // migration, which is absent from --path ("Migration not found") and never reaches 240.
        // Derive the smallest step that reaches 240 in the repository's own rollback order.
        $order = array_column(app('migration.repository')->getMigrations(PHP_INT_MAX), 'migration');
        $position = array_search(self::MIGRATION, $order, true);
        $this->assertIsInt($position, 'The discovery epoch migration must be recorded before rollback is exercised.');
        $before = $this->rows();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        try {
            Artisan::call('migrate:rollback', ['--path' => [database_path('migrations/'.self::MIGRATION.'.php')], '--realpath' => true, '--step' => $position + 1, '--force' => true]);
            $this->fail('Operational teardown admitted.');
        } catch (LogicException $error) {
            $this->assertSame('Retain discovery epoch, guards and migration bookkeeping; operational teardown is unsupported.', $error->getMessage());
            $this->assertSame($before, $this->rows());
            (new DiscoveryEpoch)->assertInstalled(DB::connection()->getPdo(), DB::getDriverName());
        }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame($before, $this->rows());
        $this->assertFalse(collect($queries)->contains(fn ($sql) => preg_match('/\A(?:drop|alter|delete|update)\b/i', $sql) === 1));
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/discovery-forward-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $file = $directory.'/2099_01_01_000000_discovery_forward_probe.php';
        file_put_contents($file, '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\Schema::create("discovery_forward_probe", function ($t) { $t->id(); }); } public function down(): void {} };');
        try {
            $this->assertSame(0, Artisan::call('migrate', ['--path' => [$file], '--realpath' => true, '--force' => true]));
            $this->assertTrue(Schema::hasTable('discovery_forward_probe'));
            $this->assertSame(1, DB::table('migrations')->where('migration', '2099_01_01_000000_discovery_forward_probe')->count());
            $this->assertSame($before['epoch'], $this->rows()['epoch']);
        } finally {
            Schema::dropIfExists('discovery_forward_probe');
            DB::table('migrations')->where('migration', '2099_01_01_000000_discovery_forward_probe')->delete();
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function test_down_refuses_before_any_database_read_or_write(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        try {
            $migration->down();
            $this->fail('Teardown admitted.');
        } catch (LogicException) {
            $this->assertSame([], $queries);
        }
    }

    public function test_actual_insert_update_delete_upsert_replace_aba_and_rollback_invalidate_atomically(): void
    {
        $epoch = fn (): int => (new DiscoveryEpoch)->current(DB::connection()->getPdo());
        $n = $epoch();
        $id = DB::table('tracks')->insertGetId(['title' => 'Draft', 'slug' => 'draft']);
        $this->assertSame(++$n, $epoch());
        DB::table('tracks')->where('id', $id)->update(['title' => 'Changed']);
        $this->assertSame(++$n, $epoch());
        DB::table('tracks')->where('id', $id)->update(['title' => 'Draft']);
        $this->assertSame(++$n, $epoch());
        DB::table('tracks')->upsert([['id' => $id, 'title' => 'Upsert', 'slug' => 'draft']], ['id'], ['title']);
        $this->assertGreaterThan($n, $epoch());
        $n = $epoch();
        $prefix = DB::getDriverName() === 'mysql' ? 'REPLACE' : 'INSERT OR REPLACE';
        DB::insert($prefix.' INTO tracks (id, title, slug) VALUES (?, ?, ?)', [$id, 'Replaced', 'draft']);
        $this->assertGreaterThan($n, $epoch());
        $n = $epoch();
        DB::beginTransaction();
        DB::table('tracks')->where('id', $id)->update(['title' => 'Rolled back']);
        $this->assertGreaterThan($n, $epoch());
        DB::rollBack();
        $this->assertSame($n, $epoch());
        $this->assertSame('Replaced', DB::table('tracks')->where('id', $id)->value('title'));
        DB::table('tracks')->where('id', $id)->delete();
        $this->assertSame($n + 1, $epoch());
    }

    public function test_missing_changed_guard_and_modified_table_fail_closed(): void
    {
        $pdo = DB::connection()->getPdo();
        $guard = DiscoveryEpoch::guards(DB::getDriverName())['cde_1_update'];
        $pdo->exec('DROP TRIGGER cde_1_update');
        try {
            (new DiscoveryEpoch)->assertInstalled($pdo, DB::getDriverName());
            $this->fail('Missing guard admitted.');
        } catch (LogicException) {
            $this->assertSame(0, DB::transactionLevel());
        }
        $changed = str_replace('epoch = epoch + 1', 'epoch = epoch', $guard['sql']);
        $pdo->exec($changed);
        try {
            (new DiscoveryEpoch)->assertInstalled($pdo, DB::getDriverName());
            $this->fail('Changed guard admitted.');
        } catch (LogicException) {
            $this->assertSame(0, DB::transactionLevel());
        }
        $pdo->exec('DROP TRIGGER cde_1_update');
        $pdo->exec($guard['sql']);
        $pdo->exec('ALTER TABLE '.DiscoveryEpoch::TABLE.' ADD COLUMN foreign_extra INTEGER');
        $this->expectException(LogicException::class);
        app(CurrentEligibleTrackSnapshot::class)->capture();
    }

    public function test_overflow_refuses_parent_write_and_preserves_epoch_and_data(): void
    {
        $pdo = DB::connection()->getPdo();
        $guard = DiscoveryEpoch::guards(DB::getDriverName())['cde_own_update'];
        $pdo->exec('DROP TRIGGER cde_own_update');
        $pdo->exec('UPDATE '.DiscoveryEpoch::TABLE.' SET epoch = '.DiscoveryEpoch::MAX.' WHERE id = 1');
        $pdo->exec($guard['sql']);
        try {
            DB::table('tracks')->insert(['title' => 'Overflow', 'slug' => 'overflow']);
            $this->fail('Overflow parent write committed.');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('tracks')->count());
            $this->assertSame(DiscoveryEpoch::MAX, (int) DB::table(DiscoveryEpoch::TABLE)->value('epoch'));
        }
        $this->expectException(LogicException::class);
        app(CurrentEligibleTrackSnapshot::class)->capture();
    }

    public function test_exact_installed_migration_is_idempotent_and_preserves_retained_data(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $before = $this->rows();
        $migration->up();
        $this->assertSame($before, $this->rows());
        (new DiscoveryEpoch)->assertInstalled(DB::connection()->getPdo(), DB::getDriverName());
    }

    public function test_foreign_guard_on_absent_installation_is_not_adopted_or_replaced(): void
    {
        $pdo = DB::connection()->getPdo();
        foreach (DiscoveryEpoch::guards(DB::getDriverName()) as $name => $guard) {
            $pdo->exec('DROP TRIGGER '.$name);
        }
        $pdo->exec('DROP TABLE '.DiscoveryEpoch::TABLE);
        $foreign = DB::getDriverName() === 'mysql'
            ? 'CREATE TRIGGER cde_1_update BEFORE UPDATE ON tracks FOR EACH ROW SET @discovery_foreign_canary = 1'
            : 'CREATE TRIGGER cde_1_update BEFORE UPDATE ON tracks BEGIN SELECT 1; END';
        $pdo->exec($foreign);
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        try {
            $migration->up();
            $this->fail('Foreign identity adopted.');
        } catch (LogicException) {
            $this->assertFalse(Schema::hasTable(DiscoveryEpoch::TABLE));
            $count = DB::getDriverName() === 'mysql'
                ? (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'cde_%'")->fetchColumn()
                : (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'cde_%'")->fetchColumn();
            $this->assertSame(1, $count);
        }
    }

    public function test_connection_local_dependency_shadow_refuses_capture(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TEMPORARY TABLE tracks (id INTEGER)');
        try {
            app(CurrentEligibleTrackSnapshot::class)->capture();
            $this->fail('Temporary dependency shadow admitted.');
        } catch (LogicException) {
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            $pdo->exec(DB::getDriverName() === 'mysql' ? 'DROP TEMPORARY TABLE tracks' : 'DROP TABLE temp.tracks');
        }
    }

    private function rows(): array
    {
        return ['migrations' => DB::table('migrations')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(), 'epoch' => DB::table(DiscoveryEpoch::TABLE)->get()->map(fn ($row): array => (array) $row)->all(),
            'tracks' => DB::table('tracks')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()];
    }
}
