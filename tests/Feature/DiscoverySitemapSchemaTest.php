<?php

namespace Tests\Feature;

use App\Domain\Catalog\DiscoverySitemap\SitemapException;
use App\Domain\Catalog\DiscoverySitemap\SitemapSchema;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use ReflectionMethod;
use Tests\TestCase;

/** Disposable, prefixed synthetic ownership probes; operational down never erases history. */
class DiscoverySitemapSchemaTest extends TestCase
{
    private string $priorPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorPrefix = DB::connection()->getTablePrefix();
        DB::connection()->setTablePrefix('dss_test_');
        $this->beforeApplicationDestroyed(function (): void {
            $pdo = DB::connection()->getPdo();
            foreach (array_reverse(SitemapSchema::TABLES) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS '.(new SitemapSchema)->table($table));
            }
            DB::connection()->setTablePrefix($this->priorPrefix);
        });
    }

    private function definition(int $index): string
    {
        $schema = new SitemapSchema;
        $definition = (new ReflectionMethod($schema, 'definition'))->invoke($schema, SitemapSchema::TABLES[$index], DB::getDriverName());

        return 'CREATE TABLE '.$schema->table(SitemapSchema::TABLES[$index]).' ('.$definition['sql'].')'.(DB::getDriverName() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '');
    }

    private function refuses(callable $action): void
    {
        try {
            $action();
            $this->fail('Unowned schema was adopted or retained rows repaired.');
        } catch (SitemapException) {
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public function test_fresh_schema_and_exact_complete_unlogged_retry_preserve_singleton(): void
    {
        $schema = new SitemapSchema;
        $schema->up();
        $schema->up();
        $schema->assertOwned(DB::connection()->getPdo());
        $this->assertSame(1, (int) DB::connection()->getPdo()->query('SELECT COUNT(*) FROM '.$schema->table(SitemapSchema::TABLES[2]))->fetchColumn());
    }

    public function test_atomic_first_table_partial_ddl_and_exact_trailing_guard_retry_are_owned(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec($this->definition(0));
        $schema = new SitemapSchema;
        $guards = (new ReflectionMethod($schema, 'guards'))->invoke($schema, SitemapSchema::TABLES[0], DB::getDriverName(), 'dss_test_discovery_sitemap_generations', $schema->table(SitemapSchema::TABLES[0]));
        $pdo->exec(array_values($guards)[0]['sql']);
        $schema->up();
        (new SitemapSchema)->assertOwned($pdo);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM '.(new SitemapSchema)->table(SitemapSchema::TABLES[2]))->fetchColumn());
    }

    public function test_all_existing_prefix_is_preflighted_before_any_earlier_ddl_mutation(): void
    {
        $schema = new SitemapSchema;
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE '.$schema->table(SitemapSchema::TABLES[1]).' (unowned INTEGER)');
        $this->refuses(fn () => $schema->up());
        try {
            $pdo->query('SELECT * FROM '.$schema->table(SitemapSchema::TABLES[0]));
            $this->fail('Earlier owned table was created before later drift was rejected.');
        } catch (\PDOException) {
            $this->assertTrue(true);
        }
    }

    public function test_retained_missing_guard_is_refused_and_original_rows_remain(): void
    {
        $schema = new SitemapSchema;
        $schema->up();
        $pdo = DB::connection()->getPdo();
        $s = $pdo->prepare('INSERT INTO '.$schema->table(SitemapSchema::TABLES[0]).' (id, header, seal) VALUES (?, ?, ?)');
        $s->execute([str_repeat('b', 32), 'SYNTHETIC-UNCHANGED', str_repeat('c', 64)]);
        $pdo->exec('DROP TRIGGER dss_test_discovery_sitemap_generations_update');
        $this->refuses(fn () => $schema->up());
        $this->assertSame('SYNTHETIC-UNCHANGED', $pdo->query('SELECT header FROM '.$schema->table(SitemapSchema::TABLES[0]))->fetchColumn());
    }

    public function test_raw_updates_deletes_replace_and_pointer_revision_rollback_are_guarded(): void
    {
        $schema = new SitemapSchema;
        $schema->up();
        $pdo = DB::connection()->getPdo();
        $generation = $schema->table(SitemapSchema::TABLES[0]);
        $windows = $schema->table(SitemapSchema::TABLES[1]);
        $current = $schema->table(SitemapSchema::TABLES[2]);
        $id = str_repeat('a', 32);
        $hash = str_repeat('0', 64);
        $pdo->exec("INSERT INTO {$generation} (id, header, seal) VALUES ('{$id}', 'SYNTHETIC', '{$hash}')");
        $pdo->exec("INSERT INTO {$windows} (generation_id, ordinal, after_id, end_id, more, prior_hash, body, seal) VALUES ('{$id}',1,0,0,0,'{$hash}','SYNTHETIC','{$hash}')");
        foreach (["UPDATE {$generation} SET header = 'ALTERED'", "DELETE FROM {$generation}", "REPLACE INTO {$generation} (id,header,seal) VALUES ('{$id}','ALTERED','{$hash}')", "UPDATE {$windows} SET body = 'ALTERED'", "DELETE FROM {$windows}", "UPDATE {$current} SET revision = 0", "DELETE FROM {$current}", "REPLACE INTO {$current} (id,revision,generation_id,certificate,seal) VALUES (1,0,NULL,NULL,'{$hash}')", "INSERT INTO {$windows} (generation_id,ordinal,after_id,end_id,more,prior_hash,body,seal) VALUES ('{$id}',2,0,0,0,'{$hash}','ALTERED','{$hash}')"] as $sql) {
            try {
                $pdo->exec($sql);
                $this->fail('Guard failed: '.$sql);
            } catch (\PDOException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('SYNTHETIC', $pdo->query('SELECT header FROM '.$generation)->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM '.$windows)->fetchColumn());
    }

    public function test_empty_hole_before_later_guard_is_not_an_owned_creation_prefix(): void
    {
        $schema = new SitemapSchema;
        $schema->up();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('DROP TRIGGER dss_test_discovery_sitemap_generations_update');
        $this->refuses(fn () => $schema->up());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM '.$schema->table(SitemapSchema::TABLES[2]))->fetchColumn());
    }

    public function test_reserved_guard_name_cannot_mask_second_dictionary_object(): void
    {
        $schema = new SitemapSchema;
        $schema->up();
        $pdo = DB::connection()->getPdo();
        $name = 'dss_test_discovery_sitemap_generations_update';
        $quote = DB::getDriverName() === 'mysql' ? '`' : '"';
        $pdo->exec('CREATE TABLE '.$quote.$name.$quote.' (marker INTEGER)');
        $pdo->exec('INSERT INTO '.$quote.$name.$quote.' VALUES (9123)');
        try {
            $this->refuses(fn () => $schema->assertOwned($pdo));
            $this->assertSame(9123, (int) $pdo->query('SELECT marker FROM '.$quote.$name.$quote)->fetchColumn());
        } finally {
            $pdo->exec('DROP TABLE '.$quote.$name.$quote);
        }
    }

    public function test_connection_local_shadow_is_refused_before_schema_adoption(): void
    {
        $schema = new SitemapSchema;
        $pdo = DB::connection()->getPdo();
        $name = 'dss_test_discovery_sitemap_generations';
        $pdo->exec('CREATE TEMPORARY TABLE '.$name.' (foreign_payload INTEGER)');
        try {
            $this->refuses(fn () => $schema->up());
            $this->assertSame([], $pdo->query('SELECT * FROM '.$name)->fetchAll(PDO::FETCH_ASSOC));
        } finally {
            $pdo->exec('DROP TABLE '.$name);
        }
    }

    public function test_native_case_variant_reserved_name_refuses_admission(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Case-sensitive native schema with case-insensitive dictionary comparison is required.');
        }
        $schema = new SitemapSchema;
        $pdo = DB::connection()->getPdo();
        $name = 'DSS_TEST_DISCOVERY_SITEMAP_GENERATIONS_UPDATE';
        $pdo->exec('CREATE TABLE `'.$name.'` (marker INTEGER)');
        try {
            $this->refuses(fn () => $schema->up());
            $this->assertSame([], $pdo->query('SELECT * FROM `'.$name.'`')->fetchAll());
        } finally {
            $pdo->exec('DROP TABLE `'.$name.'`');
        }
    }

    public function test_down_refuses_before_any_schema_or_singleton_mutation(): void
    {
        $schema = new SitemapSchema;
        $schema->up();
        try {
            $schema->down();
            $this->fail('Operational rollback erased retained evidence.');
        } catch (LogicException) {
            $schema->assertOwned(DB::connection()->getPdo());
            $this->assertSame(1, (int) DB::connection()->getPdo()->query('SELECT COUNT(*) FROM '.$schema->table(SitemapSchema::TABLES[2]))->fetchColumn());
        }
    }
}
