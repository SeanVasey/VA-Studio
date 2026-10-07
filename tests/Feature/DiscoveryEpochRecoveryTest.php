<?php

namespace Tests\Feature;

use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class DiscoveryEpochRecoveryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const MIGRATION = '2026_10_07_240000_catalog_discovery_epoch';

    public function test_failed_trigger_creation_retries_through_real_migrator_without_erasing_prior_data(): void
    {
        DB::table('tracks')->insert(['title' => 'Synthetic retained draft', 'slug' => 'retained-before-epoch']);
        $pdo = DB::connection()->getPdo();
        $this->removeFixtureEpoch($pdo);
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $before = $this->parentRows();
        $proxy = $this->faultPdo($pdo, function (string $operation, string $sql, string $when): void {
            if ($operation === 'exec' && $when === 'before' && str_starts_with($sql, 'CREATE TRIGGER cde_own_update ')) {
                throw new RuntimeException('Synthetic CREATE TRIGGER failure.');
            }
        });
        DB::connection()->setPdo($proxy);
        try {
            $this->migrate();
            $this->fail('Failure injection was not reached.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic CREATE TRIGGER failure.', $error->getMessage());
        } finally {
            DB::connection()->setPdo($pdo);
        }
        $this->assertSame($before, $this->parentRows());
        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        $this->assertSame(0, $this->migrate());
        $this->assertSame($before, $this->parentRows());
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        (new DiscoveryEpoch)->assertInstalled($pdo, DB::getDriverName());
    }

    public function test_every_creation_statement_failure_resumes_only_missing_parts_and_preserves_surviving_guards(): void
    {
        $pdo = DB::connection()->getPdo();
        DB::table('tracks')->insert(['title' => 'Synthetic preexisting draft', 'slug' => 'prefix-retained']);
        $parents = $this->parentRows();
        $original = $this->originalGuards($pdo);
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $total = count(DiscoveryEpoch::guards(DB::getDriverName())) + 2;
        foreach (['before', 'after'] as $boundary) {
            for ($stop = 1; $stop <= $total; $stop++) {
                $this->removeFixtureEpoch($pdo);
                $seen = 0;
                $this->interrupted($pdo, function (string $operation, string $sql, string $when) use (&$seen, $stop, $boundary): void {
                    if ($operation === 'exec' && $when === $boundary && ++$seen === $stop) {
                        throw new RuntimeException('Synthetic discovery interruption.');
                    }
                }, fn () => $migration->up());
                $survivors = $this->ownedGuards($pdo);
                $seed = Schema::hasTable(DiscoveryEpoch::TABLE) ? DB::table(DiscoveryEpoch::TABLE)->get()->map(fn ($row): array => (array) $row)->all() : [];
                $created = [];
                $proxy = $this->faultPdo($pdo, function (string $operation, string $sql, string $when) use (&$created): void {
                    if ($operation === 'exec' && $when === 'before') {
                        $created[] = $sql;
                    }
                });
                DB::connection()->setPdo($proxy);
                try {
                    $migration->up();
                    $this->assertCount($total - ($boundary === 'after' ? $stop : $stop - 1), $created, "{$boundary} statement {$stop}");
                    $created = [];
                    $migration->up();
                    $this->assertSame([], $created, 'An installed retry must perform no writes.');
                } finally {
                    DB::connection()->setPdo($pdo);
                }
                $resumed = $this->ownedGuards($pdo);
                foreach ($survivors as $name => $definition) {
                    $this->assertSame($definition, $resumed[$name], "Surviving guard {$name} changed at {$boundary} {$stop}.");
                }
                if ($seed !== []) {
                    $this->assertSame($seed, DB::table(DiscoveryEpoch::TABLE)->get()->map(fn ($row): array => (array) $row)->all());
                }
                $this->assertSame($parents, $this->parentRows());
                $this->assertSame($original, $this->originalGuards($pdo));
                $this->assertCount(count(DiscoveryEpoch::guards(DB::getDriverName())), $resumed);
                (new DiscoveryEpoch)->assertInstalled($pdo, DB::getDriverName());
                $this->assertSame(0, DB::transactionLevel());
            }
        }
        $this->assertInvalidEpochWrites($pdo);
    }

    public function test_final_assertion_failure_resumes_without_replacing_any_object_or_seed(): void
    {
        $pdo = DB::connection()->getPdo();
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        foreach (['entry', 'last read'] as $point) {
            foreach (['before', 'after'] as $boundary) {
                $this->removeFixtureEpoch($pdo);
                $lastCreated = false;
                $lastName = array_key_last(DiscoveryEpoch::guards(DB::getDriverName()));
                $lastRead = DB::getDriverName() === 'mysql' ? 'SHOW CREATE TABLE '.DiscoveryEpoch::TABLE : 'SELECT name, tbl_name FROM sqlite_temp_master';
                $this->interrupted($pdo, function (string $operation, string $sql, string $when) use (&$lastCreated, $lastName, $boundary, $point, $lastRead): void {
                    if ($operation === 'exec' && $when === 'after' && str_starts_with($sql, 'CREATE TRIGGER '.$lastName.' ')) {
                        $lastCreated = true;
                    }
                    if ($operation === 'query' && $lastCreated && $when === $boundary && ($point === 'entry' || $sql === $lastRead)) {
                        throw new RuntimeException('Synthetic discovery interruption.');
                    }
                }, fn () => $migration->up());
                $before = $this->ownedGuards($pdo);
                DB::table('tracks')->insert(['title' => 'Synthetic fully protected write', 'slug' => 'assertion-'.str_replace(' ', '-', $point).'-'.$boundary]);
                $epoch = (new DiscoveryEpoch)->current($pdo);
                $this->assertGreaterThan(0, $epoch);
                $migration->up();
                $this->assertSame($before, $this->ownedGuards($pdo));
                $this->assertSame($epoch, (new DiscoveryEpoch)->current($pdo));
                $this->assertInvalidEpochWrites($pdo);
            }
        }
    }

    public function test_real_final_assertion_drift_preserves_unlogged_objects_and_retry_refuses_before_ddl(): void
    {
        $pdo = DB::connection()->getPdo();
        DB::table('tracks')->insert(['title' => 'Synthetic retained final-check draft', 'slug' => 'final-drift-retained']);
        $this->removeFixtureEpoch($pdo);
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $parents = $this->parentRows();
        $originalGuards = $this->originalGuards($pdo);
        $lastName = array_key_last(DiscoveryEpoch::guards(DB::getDriverName()));
        DB::connection()->setPdo($this->faultPdo($pdo, function (string $operation, string $sql, string $when) use ($pdo, $lastName): void {
            if ($operation === 'exec' && $when === 'after' && str_starts_with($sql, 'CREATE TRIGGER '.$lastName.' ')) {
                $pdo->exec('ALTER TABLE '.DiscoveryEpoch::TABLE.' ADD COLUMN foreign_final_drift INTEGER');
            }
        }));
        try {
            $this->migrate();
            $this->fail('Final ownership assertion admitted table drift.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Discovery table', $error->getMessage());
        } finally {
            DB::connection()->setPdo($pdo);
        }
        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        $this->assertSame($parents, $this->parentRows());
        $this->assertSame($originalGuards, $this->originalGuards($pdo));
        $this->assertCount(count(DiscoveryEpoch::guards(DB::getDriverName())), $this->ownedGuards($pdo));
        $before = $this->databaseSnapshot($pdo);
        $writes = [];
        DB::connection()->setPdo($this->faultPdo($pdo, function (string $operation, string $sql, string $when) use (&$writes): void {
            if ($operation === 'exec' && $when === 'before') {
                $writes[] = $sql;
            }
        }));
        try {
            $this->migrate();
            $this->fail('Drifted final assertion residue was adopted.');
        } catch (LogicException) {
            $this->assertSame([], $writes);
        } finally {
            DB::connection()->setPdo($pdo);
        }
        $this->assertSame($before, $this->databaseSnapshot($pdo));
    }

    public function test_real_migrator_resumes_before_repository_log_and_uncertain_completed_log_is_not_duplicated(): void
    {
        $pdo = DB::connection()->getPdo();
        foreach (['migration ended', 'before log', 'after log'] as $failure) {
            $this->removeFixtureEpoch($pdo);
            DB::table('migrations')->where('migration', self::MIGRATION)->delete();
            $state = (object) ['active' => true];
            app('events')->listen(MigrationEnded::class, function (MigrationEnded $event) use ($state, $failure): void {
                if ($state->active && $failure === 'migration ended' && $event->method === 'up') {
                    throw new RuntimeException('Synthetic discovery interruption.');
                }
            });
            DB::connection()->beforeExecuting(function (string $sql) use ($state, $failure): void {
                if ($state->active && $failure === 'before log' && preg_match('/\Ainsert into [`"]migrations[`"] /i', $sql)) {
                    throw new RuntimeException('Synthetic discovery interruption.');
                }
            });
            DB::listen(function (QueryExecuted $query) use ($state, $failure): void {
                if ($state->active && $failure === 'after log' && preg_match('/\Ainsert into [`"]migrations[`"] /i', $query->sql)) {
                    throw new RuntimeException('Synthetic discovery interruption.');
                }
            });
            try {
                $this->migrate();
                $this->fail('Repository interruption was not reached.');
            } catch (RuntimeException $error) {
                $this->assertSame('Synthetic discovery interruption.', $error->getMessage());
            } finally {
                $state->active = false;
            }
            $this->assertSame($failure === 'after log' ? 1 : 0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
            $guards = $this->ownedGuards($pdo);
            DB::table('tracks')->insert(['title' => 'Synthetic post-installation draft', 'slug' => str_replace(' ', '-', $failure)]);
            $epoch = (new DiscoveryEpoch)->current($pdo);
            $parents = $this->parentRows();
            $this->assertSame(0, $this->migrate());
            $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
            $bookkeeping = DB::table('migrations')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
            $this->assertSame(0, $this->migrate());
            $this->assertSame($bookkeeping, DB::table('migrations')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
            $this->assertSame($parents, $this->parentRows());
            $this->assertSame($guards, $this->ownedGuards($pdo));
            $this->assertSame($epoch, (new DiscoveryEpoch)->current($pdo));
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public static function driftCases(): array
    {
        return array_combine(['column', 'extra index', 'changed guard', 'foreign guard', 'case guard', 'extra epoch guard', 'guard gap', 'missing seed', 'advanced partial epoch', 'temporary epoch', 'temporary dependency', 'case table'],
            array_map(fn (string $case): array => [$case], ['column', 'extra index', 'changed guard', 'foreign guard', 'case guard', 'extra epoch guard', 'guard gap', 'missing seed', 'advanced partial epoch', 'temporary epoch', 'temporary dependency', 'case table']));
    }

    #[DataProvider('driftCases')]
    public function test_drift_and_foreign_identities_are_refused_without_schema_row_or_guard_changes(string $case): void
    {
        $pdo = DB::connection()->getPdo();
        DB::table('tracks')->insert(['title' => 'Synthetic retained protected draft', 'slug' => 'negative-retained']);
        $this->removeFixtureEpoch($pdo);
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $this->interrupted($pdo, function (string $operation, string $sql, string $when): void {
            if ($operation === 'exec' && $when === 'before' && str_starts_with($sql, 'CREATE TRIGGER cde_1_update ')) {
                throw new RuntimeException('Synthetic discovery interruption.');
            }
        }, fn () => $migration->up());
        $driver = DB::getDriverName();
        $temporary = null;
        switch ($case) {
            case 'column':
                $pdo->exec('ALTER TABLE '.DiscoveryEpoch::TABLE.' ADD COLUMN foreign_extra INTEGER');
                break;
            case 'extra index':
                $pdo->exec('CREATE INDEX discovery_foreign_index ON '.DiscoveryEpoch::TABLE.' (epoch)');
                break;
            case 'changed guard':
                $pdo->exec('DROP TRIGGER cde_own_update');
                $pdo->exec(str_replace('NEW.epoch = OLD.epoch + 1', 'NEW.epoch = OLD.epoch + 2', DiscoveryEpoch::guards($driver)['cde_own_update']['sql']));
                break;
            case 'foreign guard':
            case 'case guard':
                $name = $case === 'case guard' ? 'CDE_1_UPDATE' : 'cde_1_update';
                $pdo->exec($driver === 'mysql' ? "CREATE TRIGGER {$name} BEFORE UPDATE ON tracks FOR EACH ROW SET @discovery_foreign_canary = 1"
                    : "CREATE TRIGGER {$name} BEFORE UPDATE ON tracks BEGIN SELECT 1; END");
                break;
            case 'extra epoch guard':
                $pdo->exec($driver === 'mysql' ? 'CREATE TRIGGER foreign_epoch_guard AFTER UPDATE ON '.DiscoveryEpoch::TABLE.' FOR EACH ROW SET @discovery_foreign_canary = 1'
                    : 'CREATE TRIGGER foreign_epoch_guard AFTER UPDATE ON '.DiscoveryEpoch::TABLE.' BEGIN SELECT 1; END');
                break;
            case 'guard gap':
                $pdo->exec('DROP TRIGGER cde_own_update');
                break;
            case 'missing seed':
                $pdo->exec('DROP TRIGGER cde_own_delete');
                $pdo->exec('DELETE FROM '.DiscoveryEpoch::TABLE);
                // Keep the exact prefix so seed refusal cannot be masked by a guard gap.
                $pdo->exec(DiscoveryEpoch::guards($driver)['cde_own_delete']['sql']);
                break;
            case 'advanced partial epoch':
                DB::table('tracks')->insert(['title' => 'Synthetic write while incomplete', 'slug' => 'incomplete-write']);
                break;
            case 'temporary epoch':
            case 'temporary dependency':
                $temporary = $case === 'temporary epoch' ? DiscoveryEpoch::TABLE : 'tracks';
                $pdo->exec('CREATE TEMPORARY TABLE '.$temporary.' (id INTEGER)');
                $pdo->exec('INSERT INTO '.$temporary.' (id) VALUES (9123)');
                break;
            case 'case table':
                $this->removeFixtureEpoch($pdo);
                $pdo->exec('CREATE TABLE CATALOG_DISCOVERY_EPOCH (id INTEGER)');
                $pdo->exec('INSERT INTO CATALOG_DISCOVERY_EPOCH (id) VALUES (9123)');
                break;
        }
        $before = $this->databaseSnapshot($pdo);
        $writes = [];
        DB::connection()->setPdo($this->faultPdo($pdo, function (string $operation, string $sql, string $when) use (&$writes): void {
            if ($operation === 'exec' && $when === 'before') {
                $writes[] = $sql;
            }
        }));
        try {
            $migration->up();
            $this->fail('Incompatible discovery residue was adopted.');
        } catch (LogicException) {
            $this->assertSame([], $writes);
        } finally {
            DB::connection()->setPdo($pdo);
        }
        try {
            $this->assertSame($before, $this->databaseSnapshot($pdo));
            if ($temporary !== null) {
                $this->assertSame([['id' => 9123]], $pdo->query('SELECT * FROM '.$temporary)->fetchAll(PDO::FETCH_ASSOC));
            }
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            if ($temporary !== null) {
                $pdo->exec($driver === 'mysql' ? 'DROP TEMPORARY TABLE '.$temporary : 'DROP TABLE temp.'.$temporary);
            }
        }
    }

    public function test_reserved_objects_cannot_hide_across_table_index_view_and_trigger_namespaces(): void
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        DB::table('tracks')->insert(['title' => 'Synthetic namespace preservation draft', 'slug' => 'namespace-retained']);
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $cases = [];
        foreach ($driver === 'sqlite' ? ['table', 'index', 'view'] : ['table'] as $type) {
            foreach (['cde_own_insert', 'CDE_OWN_INSERT'] as $name) {
                foreach ([true, false] as $foreignFirst) {
                    $cases[] = [$type, $name, $foreignFirst];
                }
            }
        }
        foreach ([DiscoveryEpoch::TABLE, strtoupper(DiscoveryEpoch::TABLE)] as $name) {
            foreach ([true, false] as $foreignFirst) {
                $cases[] = ['trigger', $name, $foreignFirst];
            }
        }
        foreach ($cases as [$type, $name, $foreignFirst]) {
            $this->removeFixtureEpoch($pdo);
            $foreign = match ($type) {
                'table' => 'CREATE TABLE '.$name.' (id INTEGER)',
                'index' => 'CREATE INDEX '.$name.' ON tracks (id)',
                'view' => 'CREATE VIEW '.$name.' AS SELECT 9123 AS id',
                'trigger' => $driver === 'mysql' ? 'CREATE TRIGGER '.$name.' BEFORE UPDATE ON tracks FOR EACH ROW SET @discovery_foreign_canary = 1'
                    : 'CREATE TRIGGER '.$name.' BEFORE UPDATE ON tracks BEGIN SELECT 1; END',
            };
            if ($foreignFirst) {
                $pdo->exec($foreign);
            }
            $pdo->exec(DiscoveryEpoch::tableSql($driver));
            $pdo->exec('INSERT INTO '.DiscoveryEpoch::TABLE.' (id, epoch, schema_version) VALUES (1, 0, 1)');
            $pdo->exec(DiscoveryEpoch::guards($driver)['cde_own_insert']['sql']);
            if (! $foreignFirst) {
                $pdo->exec($foreign);
            }
            if ($type === 'table') {
                $pdo->exec('INSERT INTO '.$name.' (id) VALUES (9123)');
            }
            try {
                $this->assertRecoveryRefusedWithSnapshot($pdo, fn () => $migration->up());
            } finally {
                // Only the explicitly constructed foreign namespace is removed.
                $pdo->exec('DROP '.strtoupper($type).' '.$name);
            }
        }

        if ($driver === 'mysql') {
            $aliases = [];
            foreach (['cde_own_insért', 'cde_own_ínsert', 'cde_own_úpdate', 'catalog_discovery_époch'] as $name) {
                $aliases[] = [$name, null];
                $aliases[] = [$name, 0];
            }
            $aliases[] = ['cde_own_úpdate', 1];
            $aliases[] = ['cde_1_updaté', 7];
            $aliases[] = ['catalog_discovery_époch', 7];
            foreach ($aliases as [$name, $prefix]) {
                $this->removeFixtureEpoch($pdo);
                if ($prefix !== null) {
                    $pdo->exec(DiscoveryEpoch::tableSql($driver));
                    $pdo->exec('INSERT INTO '.DiscoveryEpoch::TABLE.' (id, epoch, schema_version) VALUES (1, 0, 1)');
                    foreach (array_slice(DiscoveryEpoch::guards($driver), 0, $prefix) as $guard) {
                        $pdo->exec($guard['sql']);
                    }
                }
                $pdo->exec('CREATE TRIGGER '.$name.' BEFORE INSERT ON tracks FOR EACH ROW SET @discovery_foreign_canary = 1');
                try {
                    $this->assertRecoveryRefusedWithSnapshot($pdo, fn () => $migration->up());
                } finally {
                    $pdo->exec('DROP TRIGGER '.$name);
                }
            }
        }
    }

    private function assertRecoveryRefusedWithSnapshot(PDO $pdo, callable $operation): void
    {
        $before = $this->databaseSnapshot($pdo);
        $writes = [];
        DB::connection()->setPdo($this->faultPdo($pdo, function (string $operation, string $sql, string $when) use (&$writes): void {
            if ($operation === 'exec' && $when === 'before') {
                $writes[] = $sql;
            }
        }));
        try {
            $operation();
            $this->fail('Foreign reserved identity was adopted.');
        } catch (LogicException) {
            $this->assertSame([], $writes);
        } finally {
            DB::connection()->setPdo($pdo);
        }
        $this->assertSame($before, $this->databaseSnapshot($pdo));
    }

    private function databaseSnapshot(PDO $pdo): array
    {
        $driver = DB::getDriverName();
        // MySQL cannot qualify around a connection-local shadow. An independent
        // native reader proves permanent rows/DDL while the primary retains its shadow.
        $reader = $driver === 'mysql' ? DB::build(DB::connection()->getConfig())->getPdo() : $pdo;
        $objects = $driver === 'mysql'
            ? $reader->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN)
            : $reader->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $snapshot = ['tables' => [], 'guards' => $driver === 'mysql'
            ? $reader->query('SELECT * FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC)
            : $reader->query('SELECT * FROM sqlite_master ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC)];
        foreach ($objects as $table) {
            $wrapped = $driver === 'mysql' ? '`'.$table.'`' : 'main."'.$table.'"';
            $rows = $reader->query('SELECT * FROM '.$wrapped)->fetchAll(PDO::FETCH_ASSOC);
            usort($rows, fn (array $left, array $right): int => strcmp(serialize($left), serialize($right)));
            $snapshot['tables'][$table] = ['rows' => $rows];
            if ($driver === 'mysql') {
                $snapshot['tables'][$table]['definition'] = $reader->query('SHOW CREATE TABLE '.$wrapped)->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        return $snapshot;
    }

    private function interrupted(PDO $pdo, callable $fault, callable $operation): void
    {
        DB::connection()->setPdo($this->faultPdo($pdo, $fault));
        try {
            $operation();
            $this->fail('Discovery interruption was not reached.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic discovery interruption.', $error->getMessage());
        } finally {
            DB::connection()->setPdo($pdo);
        }
    }

    private function ownedGuards(PDO $pdo): array
    {
        $rows = DB::getDriverName() === 'mysql'
            ? $pdo->query('SELECT * FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC)
            : $pdo->query("SELECT * FROM sqlite_master WHERE type = 'trigger' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $found = [];
        foreach ($rows as $row) {
            $name = $row[DB::getDriverName() === 'mysql' ? 'TRIGGER_NAME' : 'name'];
            if (isset(DiscoveryEpoch::guards(DB::getDriverName())[$name])) {
                $found[$name] = $row;
            }
        }

        return $found;
    }

    private function originalGuards(PDO $pdo): array
    {
        $rows = DB::getDriverName() === 'mysql'
            ? $pdo->query('SELECT * FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC)
            : $pdo->query("SELECT * FROM sqlite_master WHERE type = 'trigger' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_filter($rows, fn (array $row): bool => ! isset(DiscoveryEpoch::guards(DB::getDriverName())[$row[DB::getDriverName() === 'mysql' ? 'TRIGGER_NAME' : 'name']])));
    }

    private function assertInvalidEpochWrites(PDO $pdo): void
    {
        foreach (['UPDATE '.DiscoveryEpoch::TABLE.' SET epoch = 0', 'DELETE FROM '.DiscoveryEpoch::TABLE,
            'INSERT INTO '.DiscoveryEpoch::TABLE.' (id, epoch, schema_version) VALUES (1, 0, 1)'] as $sql) {
            // A reset from zero is an allowed same-value fence; advance once before testing resets.
            if ((new DiscoveryEpoch)->current($pdo) === 0) {
                $pdo->exec('UPDATE '.DiscoveryEpoch::TABLE.' SET epoch = epoch + 1');
            }
            try {
                $pdo->exec($sql);
                $this->fail('Recovered epoch admitted invalid mutation.');
            } catch (\PDOException) {
                $this->assertSame(0, DB::transactionLevel());
            }
        }
    }

    private function migrate(): int
    {
        return Artisan::call('migrate', ['--path' => [database_path('migrations/'.self::MIGRATION.'.php')], '--realpath' => true, '--force' => true]);
    }

    private function removeFixtureEpoch(PDO $pdo): void
    {
        foreach (array_keys(DiscoveryEpoch::guards(DB::getDriverName())) as $name) {
            $pdo->exec('DROP TRIGGER IF EXISTS '.$name);
        }
        $pdo->exec('DROP TABLE IF EXISTS '.DiscoveryEpoch::TABLE);
    }

    private function parentRows(): array
    {
        $rows = [];
        foreach (['migrations', ...DiscoveryEpoch::DEPENDENCIES] as $table) {
            $query = DB::table($table);
            if ($table === 'migrations') {
                $query->where('migration', '!=', self::MIGRATION);
            }
            $rows[$table] = $query->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $rows;
    }

    /** Forward every operation to the genuine primary PDO; only the selected boundary throws. */
    private function faultPdo(PDO $pdo, callable $fault): PDO
    {
        return new class($pdo, $fault) extends PDO
        {
            public function __construct(private PDO $native, private $fault) {}

            public function exec(string $statement): int|false
            {
                ($this->fault)('exec', $statement, 'before');
                $result = $this->native->exec($statement);
                ($this->fault)('exec', $statement, 'after');

                return $result;
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                ($this->fault)('query', $query, 'before');
                $result = $fetchMode === null ? $this->native->query($query) : $this->native->query($query, $fetchMode, ...$fetchModeArgs);
                ($this->fault)('query', $query, 'after');

                return $result;
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return $this->native->prepare($query, $options);
            }

            public function getAttribute(int $attribute): mixed
            {
                return $this->native->getAttribute($attribute);
            }

            public function beginTransaction(): bool
            {
                return $this->native->beginTransaction();
            }

            public function commit(): bool
            {
                return $this->native->commit();
            }

            public function rollBack(): bool
            {
                return $this->native->rollBack();
            }

            public function inTransaction(): bool
            {
                return $this->native->inTransaction();
            }
        };
    }
}
