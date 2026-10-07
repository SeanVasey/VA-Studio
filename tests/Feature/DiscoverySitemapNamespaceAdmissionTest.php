<?php

namespace Tests\Feature;

use App\Domain\Catalog\DiscoverySitemap\SitemapException;
use App\Domain\Catalog\DiscoverySitemap\SitemapSchema;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use Tests\TestCase;

/** Actual native namespaces and SQLite generated indexes, using disposable prefixed rows. */
final class DiscoverySitemapNamespaceAdmissionTest extends TestCase
{
    private string $priorPrefix;

    private const PREFIX = 'dss_ns_';

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorPrefix = DB::connection()->getTablePrefix();
        DB::connection()->setTablePrefix(self::PREFIX);
        $this->beforeApplicationDestroyed(function (): void {
            $pdo = DB::connection()->getPdo();
            foreach (array_reverse(SitemapSchema::TABLES) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS '.(new SitemapSchema)->table($table));
            }
            foreach (['foreign_child', 'foreign_parent'] as $table) {
                $pdo->exec('DROP TABLE IF EXISTS '.self::PREFIX.$table);
            }
            DB::connection()->setTablePrefix($this->priorPrefix);
        });
    }

    private function native(): PDO
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native CHECK/FK name collation and separate table-local index namespace required.');
        }

        return DB::connection()->getPdo();
    }

    private function symbol(string $suffix): string
    {
        return self::PREFIX.SitemapSchema::TABLES[1].$suffix;
    }

    private function catalog(PDO $pdo): array
    {
        return [
            'tables' => $pdo->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA=BINARY DATABASE() ORDER BY BINARY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'guards' => $pdo->query('SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA=BINARY DATABASE() ORDER BY BINARY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'constraints' => $pdo->query('SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE BINARY CONSTRAINT_SCHEMA=BINARY DATABASE() ORDER BY BINARY TABLE_NAME,BINARY CONSTRAINT_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'indexes' => $pdo->query('SELECT TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE BINARY TABLE_SCHEMA=BINARY DATABASE() ORDER BY BINARY TABLE_NAME,BINARY INDEX_NAME,SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function refusesWithoutDdl(PDO $pdo): void
    {
        $before = $this->catalog($pdo);
        try {
            (new SitemapSchema)->up();
            $this->fail('Foreign reserved native constraint was admitted.');
        } catch (SitemapException $error) {
            $this->assertSame('schema_namespace', $error->reason);
        }
        $this->assertSame($before, $this->catalog($pdo));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($pdo->inTransaction());
    }

    public function test_native_foreign_case_variant_check_is_refused_without_any_ddl(): void
    {
        $pdo = $this->native();
        $symbol = strtoupper($this->symbol('_bounds'));
        $pdo->exec('CREATE TABLE '.self::PREFIX.'foreign_parent (id INTEGER PRIMARY KEY, CONSTRAINT `'.$symbol.'` CHECK (id >= 0)) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO '.self::PREFIX.'foreign_parent VALUES (9123)');
        $definition = $pdo->query('SHOW CREATE TABLE '.self::PREFIX.'foreign_parent')->fetchAll(PDO::FETCH_ASSOC);
        $this->refusesWithoutDdl($pdo);
        $this->assertSame($definition, $pdo->query('SHOW CREATE TABLE '.self::PREFIX.'foreign_parent')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame([['id' => 9123]], $pdo->query('SELECT id FROM '.self::PREFIX.'foreign_parent')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function test_native_foreign_fk_reserved_symbol_is_refused_before_owned_tables_or_guards(): void
    {
        $pdo = $this->native();
        $pdo->exec('CREATE TABLE '.self::PREFIX.'foreign_parent (id INTEGER PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO '.self::PREFIX.'foreign_parent VALUES (9123)');
        $pdo->exec('CREATE TABLE '.self::PREFIX.'foreign_child (id INTEGER PRIMARY KEY, parent_id INTEGER, CONSTRAINT `'.$this->symbol('_owner').'` FOREIGN KEY (parent_id) REFERENCES '.self::PREFIX.'foreign_parent(id)) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO '.self::PREFIX.'foreign_child VALUES (1,9123)');
        $this->refusesWithoutDdl($pdo);
        $this->assertSame([['id' => 1, 'parent_id' => 9123]], $pdo->query('SELECT * FROM '.self::PREFIX.'foreign_child')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function test_native_fk_collision_preserves_complete_existing_prefix_and_retry_after_foreign_removal(): void
    {
        $pdo = $this->native();
        $schema = new SitemapSchema;
        $logical = SitemapSchema::TABLES[0];
        $definition = (new ReflectionMethod($schema, 'definition'))->invoke($schema, $logical, 'mysql');
        $pdo->exec('CREATE TABLE '.$schema->table($logical).' ('.$definition['sql'].') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        $guards = (new ReflectionMethod($schema, 'guards'))->invoke($schema, $logical, 'mysql', self::PREFIX.$logical, $schema->table($logical));
        foreach ($guards as $guard) {
            $pdo->exec($guard['sql']);
        }
        $pdo->exec('INSERT INTO '.$schema->table($logical)." (id,header,seal) VALUES ('".str_repeat('a', 32)."','SYNTHETIC-RETAINED','".str_repeat('b', 64)."')");
        $pdo->exec('CREATE TABLE '.self::PREFIX.'foreign_parent (id INTEGER PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE '.self::PREFIX.'foreign_child (id INTEGER PRIMARY KEY, parent_id INTEGER, CONSTRAINT `'.strtoupper($this->symbol('_owner')).'` FOREIGN KEY (parent_id) REFERENCES '.self::PREFIX.'foreign_parent(id)) ENGINE=InnoDB');
        $before = $pdo->query('SELECT * FROM '.$schema->table($logical))->fetchAll(PDO::FETCH_ASSOC);
        $this->refusesWithoutDdl($pdo);
        $this->assertSame($before, $pdo->query('SELECT * FROM '.$schema->table($logical))->fetchAll(PDO::FETCH_ASSOC));
        $pdo->exec('DROP TABLE '.self::PREFIX.'foreign_child');
        $schema->up();
        $schema->assertOwned($pdo);
        $this->assertSame($before, $pdo->query('SELECT * FROM '.$schema->table($logical))->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM '.$schema->table(SitemapSchema::TABLES[2]))->fetchColumn());
    }

    public function test_native_foreign_table_local_unique_names_do_not_reserve_owned_constraint_names(): void
    {
        $pdo = $this->native();
        $pdo->exec('CREATE TABLE '.self::PREFIX.'foreign_parent (id INTEGER PRIMARY KEY, marker INTEGER NOT NULL, UNIQUE KEY `'.$this->symbol('_bounds').'` (marker), KEY `'.$this->symbol('_owner').'` (marker)) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO '.self::PREFIX.'foreign_parent VALUES (1,9123)');
        $before = $pdo->query('SHOW CREATE TABLE '.self::PREFIX.'foreign_parent')->fetchAll(PDO::FETCH_ASSOC);
        $schema = new SitemapSchema;
        $schema->up();
        $schema->assertOwned($pdo);
        $this->assertSame($before, $pdo->query('SHOW CREATE TABLE '.self::PREFIX.'foreign_parent')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame([['id' => 1, 'marker' => 9123]], $pdo->query('SELECT * FROM '.self::PREFIX.'foreign_parent')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function test_native_check_and_fk_names_have_separate_constraint_namespaces(): void
    {
        $pdo = $this->native();
        $pdo->exec('CREATE TABLE '.self::PREFIX.'foreign_parent (id INTEGER PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO '.self::PREFIX.'foreign_parent VALUES (9123)');
        $pdo->exec('CREATE TABLE '.self::PREFIX.'foreign_child (id INTEGER PRIMARY KEY, parent_id INTEGER, CONSTRAINT `'.$this->symbol('_owner').'` CHECK (id >= 0), CONSTRAINT `'.$this->symbol('_bounds').'` FOREIGN KEY (parent_id) REFERENCES '.self::PREFIX.'foreign_parent(id)) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO '.self::PREFIX.'foreign_child VALUES (1,9123)');
        $before = $pdo->query('SHOW CREATE TABLE '.self::PREFIX.'foreign_child')->fetchAll(PDO::FETCH_ASSOC);
        $schema = new SitemapSchema;
        $schema->up();
        $schema->assertOwned($pdo);
        $this->assertSame($before, $pdo->query('SHOW CREATE TABLE '.self::PREFIX.'foreign_child')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame([['id' => 1, 'parent_id' => 9123]], $pdo->query('SELECT * FROM '.self::PREFIX.'foreign_child')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function test_sqlite_named_inline_check_and_fk_clauses_do_not_reserve_foreign_index_names(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite clauses and schema-global index namespace required.');
        }
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE '.self::PREFIX.'foreign_parent (id INTEGER PRIMARY KEY, marker INTEGER)');
        $pdo->exec('INSERT INTO '.self::PREFIX.'foreign_parent VALUES (1,9123)');
        $pdo->exec('CREATE UNIQUE INDEX '.$this->symbol('_bounds').' ON '.self::PREFIX.'foreign_parent(marker)');
        $schema = new SitemapSchema;
        $schema->up();
        $schema->assertOwned($pdo);
        $this->assertSame(9123, (int) $pdo->query('SELECT marker FROM '.self::PREFIX.'foreign_parent')->fetchColumn());
        foreach (array_slice(SitemapSchema::TABLES, 0, 2) as $logical) {
            $name = self::PREFIX.$logical;
            $s = $pdo->prepare('SELECT name,type,tbl_name,sql FROM main.sqlite_master WHERE name=?');
            $s->execute(['sqlite_autoindex_'.$name.'_1']);
            $this->assertSame([['name' => 'sqlite_autoindex_'.$name.'_1', 'type' => 'index', 'tbl_name' => $name, 'sql' => null]], $s->fetchAll(PDO::FETCH_ASSOC));
        }
    }
}
