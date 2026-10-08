<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionPolicy\CapabilityMigrationOwnership;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Domain\Commerce\ProductionPreparation\ProductionBuyerAssentObservations;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Native server-wide dictionary scans: another schema's same-named tables, guards,
 * routines and views are not dependents of the selected schema, while any object that
 * actually reaches the selected schema is still refused with its original reason.
 */
class NativeSchemaIsolationTest extends TestCase
{
    /** 239 shares 238's ownership helper and, unlike 238, has no later dependent in the installed graph. */
    private const TABLES = ['capability' => ProductionBuyerAssentObservations::TABLE, 'inquiry' => 'inquiry_notification_intents', 'identity' => 'production_identity_origins'];

    private const MIGRATIONS = ['capability' => '2026_10_07_239000_production_buyer_assent_observations.php',
        'inquiry' => '2026_10_07_243000_inquiry_notification_intents.php', 'identity' => '2026_10_07_247000_production_customer_identity.php'];

    private static bool $migrated = false;

    private string $database;

    private string $peer;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Cross-schema dictionary isolation requires native MySQL.');
        }
        $this->assertTrue($this->app->environment('testing'));
        $this->database = DB::connection()->getPdo()->query('SELECT DATABASE()')->fetchColumn();
        $this->peer = $this->database.'_other';
        $this->assertLessThanOrEqual(64, strlen($this->peer));
        $this->assertSame(0, $this->schemaCount($this->peer), 'The disposable peer schema must not already exist.');
        // Registered only after proving absence, so this never drops a schema it did not create.
        $this->beforeApplicationDestroyed(fn () => DB::connection()->getPdo()->exec('DROP DATABASE IF EXISTS `'.$this->peer.'`'));
        if (! self::$migrated) {
            $this->migrateFresh();
        }
        // The migrator resolves the schema builder before any migration runs; direct invocation needs the same grammar.
        DB::connection()->getSchemaBuilder();
    }

    public function test_same_named_objects_in_a_parallel_schema_do_not_block_a_fresh_migration(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE DATABASE `'.$this->peer.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('CREATE TABLE `'.$this->peer.'`.`'.$table.'` LIKE `'.$table.'`');
        }
        // The parallel lane's guards keep their names and unqualified bodies, exactly as a concurrent native run holds them.
        foreach ($pdo->query('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY EVENT_OBJECT_TABLE, ACTION_ORDER')->fetchAll(PDO::FETCH_ASSOC) as $guard) {
            $pdo->exec('CREATE TRIGGER `'.$this->peer.'`.`'.$guard['TRIGGER_NAME'].'` '.$guard['ACTION_TIMING'].' '.$guard['EVENT_MANIPULATION']
                .' ON `'.$this->peer.'`.`'.$guard['EVENT_OBJECT_TABLE'].'` FOR EACH ROW '.$guard['ACTION_STATEMENT']);
        }
        foreach ([...self::TABLES, 'packets' => PacketEvidence::PACKETS] as $kind => $table) {
            $pdo->exec('CREATE PROCEDURE `'.$this->peer.'`.`isolation_'.$kind.'_routine`() SELECT COUNT(*) FROM `'.$table.'`');
            $pdo->exec('CREATE VIEW `'.$this->peer.'`.`isolation_'.$kind.'_view` AS SELECT id FROM `'.$this->peer.'`.`'.$table.'`');
        }
        $pdo->exec('ALTER TABLE `'.$this->peer.'`.`'.PacketEvidence::LINES.'` ADD CONSTRAINT isolation_peer_packet FOREIGN KEY (production_track_preparation_packet_id) REFERENCES `'
            .$this->peer.'`.`'.PacketEvidence::PACKETS.'` (id)');
        $peer = $this->catalog($this->peer);
        $this->assertContains('ptp_packet_insert', array_column($peer['triggers'], 'TRIGGER_NAME'));
        $this->assertContains('inquiry_notification_intents_insert', array_column($peer['triggers'], 'TRIGGER_NAME'));
        $this->assertContains('pi_origins_insert', array_column($peer['triggers'], 'TRIGGER_NAME'));
        $selected = $this->catalog($this->database);

        $this->migrateFresh();

        $this->assertSame($selected, $this->catalog($this->database));
        $this->assertSame($peer, $this->catalog($this->peer));
    }

    /**
     * MySQL stores a peer's view qualified with the peer's own name. A peer whose name only extends the
     * selected one ("-", "$" or a non-ASCII character) is another schema: its own objects name it, not
     * the selected schema, even though their text starts with the selected name.
     */
    #[DataProvider('extendedPeerSuffixes')]
    public function test_a_peer_schema_whose_name_extends_the_selected_name_does_not_block_a_fresh_migration(string $suffix): void
    {
        $pdo = DB::connection()->getPdo();
        $peer = $this->database.$suffix;
        $this->assertLessThanOrEqual(64, mb_strlen($peer));
        $this->assertSame(0, $this->schemaCount($peer), 'The disposable peer schema must not already exist.');
        $this->beforeApplicationDestroyed(fn () => DB::connection()->getPdo()->exec('DROP DATABASE IF EXISTS `'.$peer.'`'));
        $pdo->exec('CREATE DATABASE `'.$peer.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $tables = array_values(self::TABLES);
        foreach ($tables as $table) {
            $pdo->exec('CREATE TABLE `'.$peer.'`.`'.$table.'` LIKE `'.$table.'`');
        }
        // The same guards, with their unqualified bodies, as the selected schema holds on these tables.
        $statement = $pdo->prepare('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS '
            .'WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN (?, ?, ?) ORDER BY EVENT_OBJECT_TABLE, ACTION_ORDER');
        $statement->execute($tables);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $guard) {
            $pdo->exec('CREATE TRIGGER `'.$peer.'`.`'.$guard['TRIGGER_NAME'].'` '.$guard['ACTION_TIMING'].' '.$guard['EVENT_MANIPULATION']
                .' ON `'.$peer.'`.`'.$guard['EVENT_OBJECT_TABLE'].'` FOR EACH ROW '.$guard['ACTION_STATEMENT']);
        }
        // Views are the objects MySQL always stores qualified: `<selected>-2`.`table` contains the selected name followed by "-".
        foreach (self::TABLES as $kind => $table) {
            $pdo->exec('CREATE VIEW `'.$peer.'`.`isolation_'.$kind.'_view` AS SELECT id FROM `'.$peer.'`.`'.$table.'`');
            $pdo->exec('CREATE PROCEDURE `'.$peer.'`.`isolation_'.$kind.'_routine`() SELECT COUNT(*) FROM `'.$peer.'`.`'.$table.'`');
        }
        $definition = $pdo->prepare('SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $definition->execute([$peer, 'isolation_capability_view']);
        $this->assertStringContainsString('`'.$peer.'`.`'.self::TABLES['capability'].'`', $definition->fetchColumn());
        $this->assertContains('pi_origins_insert', array_column($this->catalog($peer)['triggers'], 'TRIGGER_NAME'));
        $selected = $this->catalog($this->database);
        $other = $this->catalog($peer);

        $this->migrateFresh();

        $this->assertSame($selected, $this->catalog($this->database));
        $this->assertSame($other, $this->catalog($peer));
    }

    /** @return array<string, array{0: string}> */
    public static function extendedPeerSuffixes(): array
    {
        return ['hyphen' => ['-2'], 'dollar' => ['$x'], 'non-ASCII' => ["\u{e9}"]];
    }

    #[DataProvider('dependencies')]
    public function test_dependencies_reaching_the_selected_schema_are_still_refused(string $guard, string $kind): void
    {
        $pdo = DB::connection()->getPdo();
        $table = self::TABLES[$guard];
        $this->admit($guard);
        $before = $this->catalog($this->database);
        [$statements, $local] = $this->dependency($kind, $table);
        if (str_starts_with($kind, 'peer')) {
            $pdo->exec('CREATE DATABASE `'.$this->peer.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            $migration = require database_path('migrations/'.self::MIGRATIONS[$guard]);
            // 239 has no operational teardown; its down() refuses unconditionally, so only its up() admission is meaningful.
            foreach ($guard === 'capability' ? ['up'] : ['up', 'down'] as $method) {
                try {
                    $migration->$method();
                    $this->fail("{$guard} {$method} admitted a {$kind}.");
                } catch (LogicException $error) {
                    $this->assertSame($this->reason($guard, $kind), $error->getMessage());
                }
                $this->assertSame($before, $this->catalog($this->database, $local));
            }
        } finally {
            foreach ($local as $object) {
                [$type, $name] = explode(' ', $object);
                $pdo->exec('DROP '.$type.' IF EXISTS '.$name);
            }
        }
        $this->assertSame($before, $this->catalog($this->database));
    }

    public static function dependencies(): array
    {
        $cases = [];
        foreach (array_keys(self::TABLES) as $guard) {
            foreach (['peer trigger naming this schema', 'peer routine naming this schema', 'peer view naming this schema', 'peer dynamic routine',
                'peer foreign key', 'local trigger', 'local routine', 'local view',
                'peer trigger naming this schema in upper case', 'peer routine naming this schema in upper case'] as $kind) {
                $cases[$guard.': '.$kind] = [$guard, $kind];
            }
        }

        return $cases;
    }

    /** @return array{0: list<string>, 1: list<string>} creating statements and the selected-schema objects to drop */
    private function dependency(string $kind, string $table): array
    {
        $peer = '`'.$this->peer.'`.';
        $qualified = '`'.$this->database.'`.`'.$table.'`';
        // A lower_case_table_names 1 or 2 server resolves this spelling to the selected schema; the guards must refuse it on every server.
        $upper = '`'.strtoupper($this->database).'`.`'.$table.'`';
        $this->assertNotSame($this->database, strtoupper($this->database));

        return match ($kind) {
            'peer trigger naming this schema' => [['CREATE TABLE '.$peer.'isolation_source (id BIGINT) ENGINE=InnoDB',
                'CREATE TRIGGER '.$peer.'isolation_dependency BEFORE INSERT ON '.$peer.'isolation_source FOR EACH ROW SET @isolation = (SELECT COUNT(*) FROM '.$qualified.')'], []],
            'peer routine naming this schema' => [['CREATE PROCEDURE '.$peer.'isolation_dependency() SELECT COUNT(*) FROM '.$qualified], []],
            'peer trigger naming this schema in upper case' => [['CREATE TABLE '.$peer.'isolation_source (id BIGINT) ENGINE=InnoDB',
                'CREATE TRIGGER '.$peer.'isolation_dependency BEFORE INSERT ON '.$peer.'isolation_source FOR EACH ROW SET @isolation = (SELECT COUNT(*) FROM '.$upper.')'], []],
            'peer routine naming this schema in upper case' => [['CREATE PROCEDURE '.$peer.'isolation_dependency() SELECT COUNT(*) FROM '.$upper], []],
            'peer view naming this schema' => [['CREATE VIEW '.$peer.'isolation_dependency AS SELECT id FROM '.$qualified], []],
            'peer dynamic routine' => [['CREATE PROCEDURE '.$peer."isolation_dependency() BEGIN SET @isolation = CONCAT('SELECT COUNT(*) FROM ', '".$table
                ."'); PREPARE isolation_statement FROM @isolation; EXECUTE isolation_statement; DEALLOCATE PREPARE isolation_statement; END"], []],
            'peer foreign key' => [['CREATE TABLE '.$peer.'isolation_dependency (id BIGINT UNSIGNED PRIMARY KEY, owned_id BIGINT UNSIGNED, FOREIGN KEY (owned_id) REFERENCES '
                .$qualified.' (id)) ENGINE=InnoDB'], []],
            'local trigger' => [['CREATE TABLE isolation_source (id BIGINT) ENGINE=InnoDB',
                'CREATE TRIGGER isolation_dependency BEFORE INSERT ON isolation_source FOR EACH ROW SET @isolation = (SELECT COUNT(*) FROM '.$table.')'],
                ['TRIGGER isolation_dependency', 'TABLE isolation_source']],
            'local routine' => [['CREATE PROCEDURE isolation_dependency() SELECT COUNT(*) FROM '.$table], ['PROCEDURE isolation_dependency']],
            'local view' => [['CREATE VIEW isolation_dependency AS SELECT id FROM '.$table], ['VIEW isolation_dependency']],
        };
    }

    /** Prove the unchanged installed graph is admitted, so each refusal is caused by the added dependency alone. */
    private function admit(string $guard): void
    {
        if ($guard === 'capability') {
            $migration = require database_path('migrations/'.self::MIGRATIONS[$guard]);
            $this->assertTrue((new CapabilityMigrationOwnership)->preflight((new ReflectionMethod($migration, 'definitions'))->invoke($migration),
                (new ReflectionMethod($migration, 'guards'))->invoke($migration)));
        } elseif ($guard === 'inquiry') {
            $migration = require database_path('migrations/'.self::MIGRATIONS[$guard]);
            $this->assertNull((new ReflectionMethod($migration, 'preflightExternalDependencies'))->invoke($migration));
        } else {
            $this->assertNotContains(false, (new IdentityMigrationOwnership)->inspect(DB::connection()->getPdo(), 'mysql')[0]);
        }
    }

    private function reason(string $guard, string $kind): string
    {
        if ($guard === 'identity') {
            return 'Unexpected production identity schema or retained evidence; refused before DDL.';
        }
        $part = match (true) {
            str_contains($kind, 'foreign key') => 'external foreign key reference',
            str_contains($kind, 'view') => 'external view reference',
            str_contains($kind, 'routine') => 'external routine reference',
            default => $guard === 'capability' ? 'external or additional table guard' : 'external trigger reference',
        };

        return $guard === 'capability'
            ? "Unexpected production capability {$part}; rollback refused before schema changes."
            : "Unexpected inquiry notification {$part}; existing schema, guards and evidence are unchanged. Investigate before retrying.";
    }

    private function migrateFresh(): void
    {
        self::$migrated = false;
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        self::$migrated = true;
    }

    private function schemaCount(string $schema): int
    {
        $statement = DB::connection()->getPdo()->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $statement->execute([$schema]);

        return (int) $statement->fetchColumn();
    }

    /** Owned definitions and guards of one schema, excluding this case's own synthetic local objects. */
    private function catalog(string $schema, array $local = []): array
    {
        $pdo = DB::connection()->getPdo();
        $ignored = array_map(fn (string $object): string => explode(' ', $object)[1], $local);
        $read = function (string $sql) use ($pdo, $schema): array {
            $statement = $pdo->prepare($sql);
            $statement->execute([$schema]);

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        };
        $tables = array_values(array_filter($read('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME'),
            fn (array $row): bool => ! in_array($row['TABLE_NAME'], $ignored, true)));
        $triggers = array_values(array_filter($read('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME'),
            fn (array $row): bool => ! in_array($row['TRIGGER_NAME'], $ignored, true)));
        $routines = array_values(array_filter($read('SELECT ROUTINE_NAME, ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_NAME'),
            fn (array $row): bool => ! in_array($row['ROUTINE_NAME'], $ignored, true)));
        $keys = $read('SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME');

        return ['tables' => $tables, 'triggers' => $triggers, 'routines' => $routines, 'keys' => $keys];
    }
}
