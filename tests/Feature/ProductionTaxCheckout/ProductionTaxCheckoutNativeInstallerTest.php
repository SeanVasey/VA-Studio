<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutSchemaInstaller;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchemaInstaller;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/**
 * The installer's MySQL dictionary branches. ProductionTaxCheckoutMigrationTest proves the SQLite branches and skips on
 * MySQL by design, so this native-only class (listed in scripts/ci/database-sqlite-skips.json) is where each damaged
 * installation is shown to be refused before any DDL with the dictionary unchanged. Every damage object is synthetic and
 * removed in tearDown; the session SQL mode is restored there too.
 */
class ProductionTaxCheckoutNativeInstallerTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const CLEANUP = [
        'DROP VIEW IF EXISTS foreign_tax_reader', 'DROP TRIGGER IF EXISTS foreign_tax_hook', 'DROP PROCEDURE IF EXISTS foreign_tax_proc',
        'DROP TABLE IF EXISTS foreign_tax_child', 'DROP TEMPORARY TABLE IF EXISTS production_tax_checkout_orders',
    ];

    private ?string $sqlMode = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL installer dictionary branches; the SQLite branches are ProductionTaxCheckoutMigrationTest.');
        }
        $this->sqlMode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && DB::getDriverName() === 'mysql') {
            if ($this->sqlMode !== null) {
                self::setSqlMode($this->sqlMode);
            }
            foreach (self::CLEANUP as $sql) {
                DB::unprepared($sql);
            }
        }
        parent::tearDown();
    }

    private static function setSqlMode(string $mode): void
    {
        DB::unprepared('SET SESSION sql_mode = '.DB::getPdo()->quote($mode));
    }

    /** Every dictionary object the installer reads, minus counters that change without any DDL. */
    private static function dictionary(): array
    {
        $out = [];
        foreach (['TABLES' => 'TABLE_SCHEMA', 'TRIGGERS' => 'TRIGGER_SCHEMA', 'VIEWS' => 'TABLE_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA',
            'STATISTICS' => 'TABLE_SCHEMA', 'COLUMNS' => 'TABLE_SCHEMA', 'TABLE_CONSTRAINTS' => 'CONSTRAINT_SCHEMA'] as $catalog => $schema) {
            $rows = array_map(fn ($r): string => json_encode(array_diff_key((array) $r, array_flip(['TABLE_ROWS', 'AVG_ROW_LENGTH', 'DATA_LENGTH',
                'INDEX_LENGTH', 'DATA_FREE', 'AUTO_INCREMENT', 'UPDATE_TIME', 'CHECK_TIME', 'CARDINALITY', 'MAX_DATA_LENGTH', 'CREATE_TIME', 'LAST_ALTERED']))),
                DB::select('SELECT * FROM information_schema.'.$catalog.' WHERE '.$schema.' = DATABASE()'));
            sort($rows);
            $out[$catalog] = $rows;
        }

        return $out;
    }

    private static function validOrder(): array
    {
        return ['public_id' => '11111111-1111-4111-8111-111111111111', 'created_at' => '2026-10-07T12:00:00Z', 'payload_ciphertext' => 'synthetic',
            'payload_hash' => str_repeat('a', 64), 'canonicalization_version' => 'vasey-json-v1', 'buyer_origin_id' => '22222222-2222-4222-8222-222222222222',
            'candidate_id' => 1, 'request_key' => str_repeat('b', 64), 'request_hash' => str_repeat('c', 64), 'currency' => 'USD', 'subtotal_minor' => 4999, 'line_count' => 1];
    }

    /** Recreates one guard from its generated statement, so that only the named property differs from the installer's own. */
    private static function recreate(string $trigger, ?string $definer = null, ?string $mode = null): void
    {
        $statement = TaxCheckoutSchema::statements('mysql')[$trigger]['statement'];
        $original = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        DB::unprepared('DROP TRIGGER '.$trigger);
        try {
            if ($mode !== null) {
                self::setSqlMode($mode);
            }
            DB::unprepared($definer === null ? $statement : preg_replace('/^CREATE TRIGGER /', 'CREATE DEFINER='.$definer.' TRIGGER ', $statement, 1));
        } finally {
            self::setSqlMode($original);
        }
    }

    public static function damages(): array
    {
        return [
            'drifted guard body' => [function (): void {
                DB::unprepared('DROP TRIGGER ptx_s_insert');
                DB::unprepared('CREATE TRIGGER ptx_s_insert BEFORE INSERT ON production_tax_checkout_reviewed_sessions FOR EACH ROW BEGIN END');
            }, 'guard definition'],
            // Reviewer finding R-6: an identical-text guard that runs under another definer or SQL mode is not the installer's guard.
            'guard recreated under another definer' => [fn () => self::recreate('ptx_o_insert', definer: "'nobody'@'localhost'"), 'guard definition'],
            'guard recreated under another SQL mode' => [function (): void {
                $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
                self::recreate('ptx_o_insert', mode: $mode === '' ? 'ALLOW_INVALID_DATES' : $mode.',ALLOW_INVALID_DATES');
            }, 'guard definition'],
            'interior gap' => [fn () => DB::unprepared('DROP TRIGGER ptx_o_update'), 'installation gap'],
            'populated incomplete installation' => [function (): void {
                DB::unprepared('DROP TRIGGER ptx_s_delete');
                DB::table(TaxCheckoutSchema::TABLES['order'])->insert(self::validOrder());
            }, 'populated incomplete installation'],
            'temporary shadow' => [fn () => DB::unprepared('CREATE TEMPORARY TABLE production_tax_checkout_orders (id INT)'), 'temporary shadow'],
            'foreign view' => [fn () => DB::unprepared('CREATE VIEW foreign_tax_reader AS SELECT id FROM production_tax_checkout_reviewed_sessions'), 'foreign view'],
            'foreign trigger' => [fn () => DB::unprepared('CREATE TRIGGER foreign_tax_hook AFTER INSERT ON users FOR EACH ROW DELETE FROM production_tax_checkout_orders WHERE id = 0'), 'foreign guard reference'],
            'foreign routine' => [fn () => DB::unprepared('CREATE PROCEDURE foreign_tax_proc() SELECT COUNT(*) FROM production_tax_checkout_orders'), 'foreign or opaque routine reference'],
            'foreign key consumer' => [fn () => DB::unprepared('CREATE TABLE foreign_tax_child (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, o BIGINT UNSIGNED NOT NULL, CONSTRAINT foreign_tax_child_o FOREIGN KEY (o) REFERENCES production_tax_checkout_orders (id))'), 'foreign key consumer'],
            'additional owned index' => [fn () => DB::unprepared('CREATE INDEX extra_tax_index ON production_tax_checkout_orders (currency)'), 'indexes'],
            'additional owned guard' => [fn () => DB::unprepared('CREATE TRIGGER extra_tax_after AFTER INSERT ON production_tax_checkout_orders FOR EACH ROW SET @tax_probe = 1'), 'additional owned guard'],
            'column collation drift' => [fn () => DB::unprepared('ALTER TABLE production_tax_checkout_orders MODIFY currency CHAR(3) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL'), 'columns'],
        ];
    }

    #[DataProvider('damages')]
    public function test_native_installer_refuses_before_ddl(Closure $damage, string $reason): void
    {
        $damage();
        $before = self::dictionary();
        try {
            (new TaxCheckoutSchemaInstaller)->up();
            $this->fail('Damaged installation was adopted.');
        } catch (LogicException $error) {
            $this->assertSame('Production tax checkout migration refused before further DDL: '.$reason.'.', $error->getMessage());
        }
        $this->assertSame($before, self::dictionary(), 'Nothing was created, replaced or dropped.');
        try {
            (new TaxCheckoutSchemaInstaller)->assertComplete();
            $this->fail('Runtime admission accepted a damaged installation.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_native_frozen_v1_installer_reruns_with_tax255_present_and_tables_are_inert(): void
    {
        foreach (TaxCheckoutSchema::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' is empty after migrate');
        }
        $before = self::dictionary();
        (new CheckoutSchemaInstaller)->up();
        (new TaxCheckoutSchemaInstaller)->up();
        (new TaxCheckoutSchemaInstaller)->assertComplete();
        $this->assertSame($before, self::dictionary());
        $migration = require database_path('migrations/'.TaxCheckoutSchema::MIGRATION.'.php');
        try {
            $migration->down();
            $this->fail('Rollback ran.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Retain production tax checkout', $error->getMessage());
        }
        $this->assertSame($before, self::dictionary());
    }
}
