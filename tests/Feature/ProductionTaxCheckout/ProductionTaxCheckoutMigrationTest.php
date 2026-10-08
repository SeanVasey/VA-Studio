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

/** Installer admission on SQLite plus driver-pure statement text for both drivers. */
class ProductionTaxCheckoutMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function owned(): array
    {
        $names = array_keys(TaxCheckoutSchema::statements(DB::getDriverName()));
        if (DB::getDriverName() !== 'sqlite') {
            return $names;
        }
        $placeholders = implode(', ', array_fill(0, count($names), '?'));

        return array_map(fn ($row): array => (array) $row, DB::select('SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name IN ('.$placeholders.') ORDER BY name', $names));
    }

    public function test_migration_installs_exactly_four_tables_and_twelve_guards_and_a_rerun_is_a_no_op(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite dictionary text; the native class covers MySQL.');
        }
        $statements = TaxCheckoutSchema::statements('sqlite');
        $this->assertCount(4, array_filter($statements, fn (array $s): bool => $s['type'] === 'table'));
        $this->assertCount(12, array_filter($statements, fn (array $s): bool => $s['type'] === 'trigger'));
        $owned = $this->owned();
        $this->assertCount(count($statements), $owned);
        foreach ($owned as $object) {
            $this->assertSame($statements[$object['name']]['statement'], $object['sql']);
        }
        $this->assertSame(1, DB::table('migrations')->where('migration', TaxCheckoutSchema::MIGRATION)->count());
        (new TaxCheckoutSchemaInstaller)->up();
        (new TaxCheckoutSchemaInstaller)->assertComplete();
        $this->assertSame($owned, $this->owned());
        // The frozen V1 installer still resumes on a database that contains Tax255: no foreign reference to V1 exists.
        (new CheckoutSchemaInstaller)->up();
    }

    public function test_both_drivers_guard_text_is_generated_and_never_names_a_v1_checkout_table(): void
    {
        foreach (['sqlite', 'mysql'] as $driver) {
            $this->assertFalse(TaxCheckoutSchemaInstaller::referencesV1($driver), $driver);
            foreach (TaxCheckoutSchema::statements($driver) as $name => $statement) {
                $this->assertStringNotContainsString('production_checkout_', $statement['statement'], $name);
                $this->assertStringNotContainsString('payment_intent', $statement['statement'], $name);
            }
        }
        // A distinct namespace: the production-track capabilities family already owns the `ptc_` prefix.
        foreach (['sqlite', 'mysql'] as $driver) {
            foreach (TaxCheckoutSchema::reservedNames($driver) as $name) {
                $this->assertMatchesRegularExpression('/\A(?:ptx_[obrs]_|production_tax_checkout_)/', $name);
            }
        }
        $mysql = TaxCheckoutSchema::statements('mysql');
        $sqlite = TaxCheckoutSchema::statements('sqlite');
        $this->assertSame("BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable production tax checkout evidence'; END", $mysql['ptx_s_update']['body']);
        $this->assertSame('CREATE TRIGGER ptx_o_delete BEFORE DELETE ON production_tax_checkout_orders BEGIN SELECT RAISE(ABORT, \'Invalid or immutable production tax checkout evidence\'); END', $sqlite['ptx_o_delete']['statement']);
        foreach (['ptx_o_insert', 'ptx_r_insert', 'ptx_b_insert', 'ptx_s_insert'] as $guard) {
            $this->assertStringContainsString("REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\$'", $mysql[$guard]['body']);
            $this->assertStringContainsString("DATE_FORMAT(STR_TO_DATE(NEW.created_at, '%Y-%m-%dT%H:%i:%sZ'), '%Y-%m-%dT%H:%i:%sZ') = NEW.created_at", $mysql[$guard]['body']);
            $this->assertStringContainsString("STRFTIME('%Y-%m-%dT%H:%M:%SZ', NEW.created_at) = NEW.created_at", $sqlite[$guard]['statement']);
            $this->assertStringContainsString("SUBSTR(NEW.created_at, 12, 2) < '24'", $sqlite[$guard]['statement']);
        }
        $this->assertStringContainsString("NEW.idempotency_key = CONCAT('va-production-tax-checkout-v2-', NEW.public_id)", $mysql['ptx_r_insert']['body']);
        $this->assertStringContainsString("NEW.idempotency_key = ('va-production-tax-checkout-v2-' || NEW.public_id)", $sqlite['ptx_r_insert']['statement']);
        $this->assertStringContainsString('NEW.amount_tax_minor * 10000 <= NEW.amount_subtotal_minor * r.maximum_rate_bps', $mysql['ptx_s_insert']['body']);
        $this->assertStringContainsString("TYPEOF(NEW.amount_tax_minor) = 'integer'", $sqlite['ptx_s_insert']['statement']);
        $this->assertStringNotContainsString('TYPEOF', $mysql['ptx_s_insert']['body']);
        $this->assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $mysql['production_tax_checkout_reviewed_sessions']['statement']);
        $this->assertStringContainsString('CONSTRAINT ptx_s_f_binding_id FOREIGN KEY (binding_id) REFERENCES production_tax_checkout_bindings (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
            $mysql['production_tax_checkout_reviewed_sessions']['statement']);
    }

    public static function refusals(): array
    {
        return [
            'drifted guard' => [function (): void {
                DB::unprepared('DROP TRIGGER ptx_s_insert');
                DB::unprepared('CREATE TRIGGER ptx_s_insert BEFORE INSERT ON production_tax_checkout_reviewed_sessions BEGIN SELECT 1; END');
            }, 'object identity or definition'],
            'interior gap' => [fn () => DB::unprepared('DROP TRIGGER ptx_o_update'), 'installation gap'],
            'populated incomplete installation' => [function (): void {
                DB::unprepared('DROP TRIGGER ptx_s_delete');
                DB::table(TaxCheckoutSchema::TABLES['order'])->insert(['public_id' => '11111111-1111-4111-8111-111111111111', 'created_at' => '2026-10-07T12:00:00Z',
                    'payload_ciphertext' => 'synthetic', 'payload_hash' => str_repeat('a', 64), 'canonicalization_version' => 'vasey-json-v1',
                    'buyer_origin_id' => '22222222-2222-4222-8222-222222222222', 'candidate_id' => 1, 'request_key' => str_repeat('b', 64),
                    'request_hash' => str_repeat('c', 64), 'currency' => 'USD', 'subtotal_minor' => 4999, 'line_count' => 1]);
            }, 'populated incomplete installation'],
            'temporary shadow' => [fn () => DB::unprepared('CREATE TEMP TABLE production_tax_checkout_orders (id INTEGER)'), 'temporary shadow'],
            'foreign view' => [fn () => DB::unprepared('CREATE VIEW foreign_tax_reader AS SELECT * FROM production_tax_checkout_reviewed_sessions'), 'foreign reference'],
            'foreign trigger' => [fn () => DB::unprepared('CREATE TRIGGER foreign_tax_hook AFTER INSERT ON users BEGIN DELETE FROM production_tax_checkout_orders; END'), 'foreign reference'],
            'additional owned index' => [fn () => DB::unprepared('CREATE INDEX extra_tax_index ON production_tax_checkout_orders (currency)'), 'additional owned object'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_installer_refuses_drift_gaps_populated_partials_shadows_and_foreign_references(Closure $damage, string $reason): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite namespace injection; the native class covers MySQL dictionary admission.');
        }
        $damage();
        $before = $this->owned();
        try {
            (new TaxCheckoutSchemaInstaller)->up();
            $this->fail('Damaged installation was adopted.');
        } catch (LogicException $error) {
            $this->assertSame('Production tax checkout migration refused before further DDL: '.$reason.'.', $error->getMessage());
        }
        $this->assertSame($before, $this->owned(), 'Nothing was created, replaced or dropped.');
    }

    public function test_rollback_is_refused_before_any_query(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $migration = require database_path('migrations/'.TaxCheckoutSchema::MIGRATION.'.php');
        try {
            $migration->down();
            $this->fail('Rollback ran.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Retain production tax checkout', $error->getMessage());
        }
        $this->assertSame(0, $queries);
    }
}
