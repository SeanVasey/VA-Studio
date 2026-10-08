<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** Append-only DDL, per-driver guards and strict empty-prefix recovery. Native-only cases skip on SQLite. */
final class ProductionFreeGrantSchemaTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
    }

    public function test_migration_installs_nine_tables_with_three_guards_each_and_retry_is_a_no_op(): void
    {
        $before = $this->objects();
        $this->assertCount(36, $before);
        (new ProductionFreeGrantSchema)->up();
        (new ProductionFreeGrantSchema)->up();
        $this->assertSame($before, $this->objects());
    }

    #[DataProvider('prefixes')]
    public function test_every_contiguous_empty_installation_prefix_resumes_to_the_exact_schema(int $prefix): void
    {
        $this->sqliteOnlyRecovery();
        $full = $this->objects();
        $steps = $this->steps();
        $this->wipeOwned();
        foreach (array_slice($steps, 0, $prefix) as $sql) {
            DB::connection()->getPdo()->exec($sql);
        }
        (new ProductionFreeGrantSchema)->up();
        (new ProductionFreeGrantSchema)->up();
        $this->assertSame($full, $this->objects());
    }

    public static function prefixes(): array
    {
        return array_map(fn (int $prefix): array => [$prefix], range(0, 36));
    }

    public function test_an_earlier_guard_hole_with_later_objects_is_not_owned_and_is_not_repaired(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('DROP TRIGGER production_free_reviews_update');
        $this->refuses(fn () => (new ProductionFreeGrantSchema)->up(), 'schema_prefix');
        $this->assertFalse($this->exists('production_free_reviews_update'));
        $this->assertTrue($this->exists('production_free_redemptions_delete'));
    }

    public function test_a_data_bearing_table_missing_a_guard_is_never_adopted_or_deleted(): void
    {
        $journey = $this->journey();
        $pdo = DB::connection()->getPdo();
        foreach (array_reverse(array_slice(ProductionFreeGrantSchema::TABLES, 1)) as $table) {
            $pdo->exec('DROP TABLE '.$table);
        }
        $pdo->exec('DROP TRIGGER production_free_definitions_delete');
        $this->refuses(fn () => (new ProductionFreeGrantSchema)->up(), 'retained_unguarded_schema');
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM production_free_definitions')->fetchColumn());
        $this->assertFalse($this->exists('production_free_reviews'));
        $this->assertNotEmpty($journey);
    }

    public function test_a_foreign_object_holding_a_reserved_guard_name_is_refused_before_any_ddl(): void
    {
        $this->wipeOwned();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE production_free_origins_update (marker INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO production_free_origins_update VALUES (9256)');
        $this->refuses(fn () => (new ProductionFreeGrantSchema)->up(), 'schema_namespace');
        $this->assertFalse($this->exists('production_free_definitions'));
        $this->assertSame(9256, (int) $pdo->query('SELECT marker FROM production_free_origins_update')->fetchColumn());
    }

    public function test_an_external_dependent_on_owned_evidence_is_refused(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec(DB::getDriverName() === 'mysql'
            ? 'CREATE TABLE foreign_free_dependent (id INT PRIMARY KEY, origin_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, FOREIGN KEY (origin_id) REFERENCES production_free_origins (id)) ENGINE=InnoDB'
            : 'CREATE TABLE foreign_free_dependent (id INTEGER PRIMARY KEY, origin_id VARCHAR(36) REFERENCES production_free_origins (id))');
        $this->refuses(fn () => (new ProductionFreeGrantSchema)->up(), 'external_dependent');
        $pdo->exec('DROP TABLE foreign_free_dependent');
        $pdo->exec('CREATE VIEW foreign_free_view AS SELECT id FROM production_free_originals');
        try {
            $this->refuses(fn () => (new ProductionFreeGrantSchema)->up(), 'external_dependent');
        } finally {
            // A persistent native schema keeps views across migrate:fresh; never leak this probe to later cases.
            $pdo->exec('DROP VIEW foreign_free_view');
        }
    }

    public function test_a_temporary_shadow_of_a_parent_or_owned_table_is_refused(): void
    {
        $this->sqliteOnlyRecovery();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TEMP TABLE users (id INTEGER PRIMARY KEY)');
        $this->refuses(fn () => (new ProductionFreeGrantSchema)->up(), 'parent_floor');
        $pdo->exec('DROP TABLE temp.users');
        $pdo->exec('CREATE TEMP TABLE production_free_origins (id INTEGER PRIMARY KEY)');
        $this->refuses(fn () => (new ProductionFreeGrantSchema)->up(), 'shadowed_schema');
    }

    public function test_parent_floor_drift_is_refused_before_the_first_owned_ddl(): void
    {
        $this->sqliteOnlyRecovery();
        $this->wipeOwned();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('PRAGMA legacy_alter_table = ON');
        $pdo->exec('ALTER TABLE customer_accounts RENAME TO customer_accounts_moved');
        $pdo->exec('CREATE TABLE customer_accounts (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, active VARCHAR(1) NOT NULL)');
        $this->refuses(fn () => (new ProductionFreeGrantSchema)->up(), 'parent_floor');
        $this->assertFalse($this->exists('production_free_definitions'));
    }

    public function test_down_refuses_before_touching_retained_originals(): void
    {
        $this->journey();
        try {
            (new ProductionFreeGrantSchema)->down();
            $this->fail('Rollback cannot discard originals.');
        } catch (LogicException) {
            $this->assertSame(1, (int) DB::connection()->getPdo()->query('SELECT COUNT(*) FROM production_free_originals')->fetchColumn());
        }
    }

    public function test_every_table_refuses_update_and_delete_red_green(): void
    {
        $this->journey(revoke: true);
        $pdo = DB::connection()->getPdo();
        foreach (ProductionFreeGrantSchema::TABLES as $table) {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
            $this->assertGreaterThan(0, $count, $table);
            $this->pdoRefuses(fn () => $pdo->exec('UPDATE '.$table.' SET created_at = created_at'), $table.' update');
            $this->pdoRefuses(fn () => $pdo->exec('DELETE FROM '.$table), $table.' delete');
            $this->assertSame($count, (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(), $table);
        }
    }

    public function test_every_table_refuses_a_relabelled_copy_and_byte_shape_drift_on_insert(): void
    {
        $this->journey(revoke: true);
        $pdo = DB::connection()->getPdo();
        foreach (ProductionFreeGrantSchema::TABLES as $table) {
            $row = $pdo->query('SELECT * FROM '.$table.' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            // Same lineage under a new identity collides with the one-original/one-use/append-chain rules.
            $this->pdoRefuses(fn () => $this->insert($table, [...$row, 'id' => (string) Str::uuid()]), $table.' copy');
            foreach (['id' => strtoupper((string) Str::uuid()), 'seal' => strtoupper(str_repeat('a', 64)),
                'created_at' => '2026-10-07T00:00:00'] as $column => $value) {
                $this->pdoRefuses(fn () => $this->insert($table, [...$row, 'id' => (string) Str::uuid(), $column => $value]), $table.' '.$column);
            }
            $this->pdoRefuses(fn () => $this->insert($table, [...$row, 'id' => substr((string) Str::uuid(), 0, 35)."\0"]), $table.' nul');
        }
    }

    public function test_review_guard_refuses_self_review_and_availability_guard_refuses_a_gap(): void
    {
        $this->journey();
        $pdo = DB::connection()->getPdo();
        $definition = $pdo->query('SELECT * FROM production_free_definitions')->fetch(PDO::FETCH_ASSOC);
        $review = $pdo->query('SELECT * FROM production_free_reviews')->fetch(PDO::FETCH_ASSOC);
        $event = $pdo->query('SELECT * FROM production_free_availability')->fetch(PDO::FETCH_ASSOC);
        $second = [...$definition, 'id' => (string) Str::uuid(), 'definition_hash' => hash('sha256', 'second')];
        $this->insert('production_free_definitions', $second);
        $this->pdoRefuses(fn () => $this->insert('production_free_reviews', [...$review, 'id' => (string) Str::uuid(),
            'definition_id' => $second['id'], 'definition_hash' => $second['definition_hash'], 'reviewer_user_id' => $definition['author_user_id']]));
        $this->insert('production_free_reviews', [...$review, 'id' => (string) Str::uuid(), 'definition_id' => $second['id'],
            'definition_hash' => $second['definition_hash']]);
        $this->pdoRefuses(fn () => $this->insert('production_free_availability', [...$event, 'id' => (string) Str::uuid(), 'ordinal' => 2]));
        $this->pdoRefuses(fn () => $this->insert('production_free_availability', [...$event, 'id' => (string) Str::uuid(), 'ordinal' => 1]));
        $this->insert('production_free_availability', [...$event, 'id' => (string) Str::uuid(), 'ordinal' => 1, 'kind' => 'closed']);
    }

    public function test_native_schema_global_check_symbol_collision_is_refused_before_the_first_owned_ddl(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native schema-global CHECK namespace required.');
        }
        $this->wipeOwned();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE foreign_free_check (marker INT PRIMARY KEY, CONSTRAINT production_free_origins_bounds CHECK (marker > 0)) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO foreign_free_check VALUES (5)');
        $this->refuses(fn () => (new ProductionFreeGrantSchema)->up(), 'schema_namespace');
        $this->assertFalse($this->exists('production_free_definitions'));
        $this->assertSame(5, (int) $pdo->query('SELECT marker FROM foreign_free_check')->fetchColumn());
    }

    public function test_native_temporary_shadow_of_an_owned_table_is_refused(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native session temporary table resolution required.');
        }
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TEMPORARY TABLE production_free_origins (id INT PRIMARY KEY)');
        $this->refuses(fn () => (new ProductionFreeGrantSchema)->up(), 'shadowed_schema');
    }

    private function journey(bool $revoke = false): array
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
        $origin = $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $detail = (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user']);
        $downloads = new ProductionFreeGrantDownloads;
        $authorization = $downloads->authorize($origin['id'], ['originSeal' => $detail['originSeal'], 'role' => 'contract'], $owner['principal'], $owner['user']);
        $downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user'])->close();
        if ($revoke) {
            $grants->revoke($origin['id'], ['originSeal' => $detail['originSeal'], 'reason' => 'Synthetic rehearsal revocation.'], $this->staff());
        }

        return $origin;
    }

    private function objects(): array
    {
        $pdo = DB::connection()->getPdo();
        if (DB::getDriverName() === 'sqlite') {
            return $pdo->query("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name LIKE 'production_free_%' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        }

        return $pdo->query("SELECT TABLE_NAME AS name, 'table' AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'production\\_free\\_%'"
            ." UNION ALL SELECT TRIGGER_NAME, 'trigger' FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'production\\_free\\_%' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The installer's own order: each table, then its insert/update/delete guards. */
    private function steps(): array
    {
        $pdo = DB::connection()->getPdo();
        $steps = [];
        foreach (ProductionFreeGrantSchema::TABLES as $table) {
            $steps[] = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '".$table."'")->fetchColumn();
            foreach (['insert', 'update', 'delete'] as $event) {
                $steps[] = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = '".$table.'_'.$event."'")->fetchColumn();
            }
        }

        return $steps;
    }

    private function wipeOwned(): void
    {
        $pdo = DB::connection()->getPdo();
        foreach (array_reverse(ProductionFreeGrantSchema::TABLES) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS '.$table);
        }
    }

    private function exists(string $name): bool
    {
        $pdo = DB::connection()->getPdo();
        if (DB::getDriverName() === 'sqlite') {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM sqlite_master WHERE name = ?');
        } else {
            $statement = $pdo->prepare('SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?)'
                .' + (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?)');
            $statement->execute([$name, $name]);

            return (int) $statement->fetchColumn() > 0;
        }
        $statement->execute([$name]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function insert(string $table, array $row): void
    {
        $quote = DB::getDriverName() === 'mysql' ? '`' : '"';
        $statement = DB::connection()->getPdo()->prepare('INSERT INTO '.$table.' ('.implode(', ', array_map(fn ($c) => $quote.$c.$quote, array_keys($row)))
            .') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
    }

    private function sqliteOnlyRecovery(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite catalog statements; the native counterpart is listed as untested in the evidence README.');
        }
    }

    private function refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Must refuse: '.$reason);
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    private function pdoRefuses(callable $operation, string $message = ''): void
    {
        try {
            $operation();
            $this->fail('Guard must refuse '.$message);
        } catch (PDOException) {
            $this->assertTrue(true);
        }
    }
}
