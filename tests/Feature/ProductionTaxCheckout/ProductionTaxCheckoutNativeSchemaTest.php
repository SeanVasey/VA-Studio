<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchemaInstaller;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Native MySQL dictionary text, typed-column and trigger behaviour. SQLite cannot prove these. */
class ProductionTaxCheckoutNativeSchemaTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual native trigger dictionary text, CHAR timestamp round trips and alias admission.');
        }
    }

    public function test_native_dictionary_matches_the_generated_guards_and_a_rerun_is_a_no_op(): void
    {
        $statements = TaxCheckoutSchema::statements('mysql');
        $tables = array_values(TaxCheckoutSchema::TABLES);
        $triggers = DB::select('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN (?, ?, ?, ?)', $tables);
        $this->assertCount(12, $triggers);
        foreach ($triggers as $trigger) {
            $expected = $statements[$trigger->TRIGGER_NAME];
            $this->assertSame([$expected['table'], $expected['operation'], 'BEFORE', $expected['body']],
                [$trigger->EVENT_OBJECT_TABLE, $trigger->EVENT_MANIPULATION, $trigger->ACTION_TIMING, $trigger->ACTION_STATEMENT]);
        }
        (new TaxCheckoutSchemaInstaller)->up();
        (new TaxCheckoutSchemaInstaller)->assertComplete();
    }

    public function test_native_timestamp_and_uuid_guards_refuse_malformed_text(): void
    {
        $row = ['public_id' => '11111111-1111-4111-8111-111111111111', 'created_at' => '2026-10-07T12:00:00Z', 'payload_ciphertext' => 'synthetic',
            'payload_hash' => str_repeat('a', 64), 'canonicalization_version' => 'vasey-json-v1', 'buyer_origin_id' => '22222222-2222-4222-8222-222222222222',
            'candidate_id' => 1, 'request_key' => str_repeat('b', 64), 'request_hash' => str_repeat('c', 64), 'currency' => 'USD', 'subtotal_minor' => 4999, 'line_count' => 1];
        foreach (['2026-02-30T12:00:00Z', '2026-10-07T24:00:00Z', '2026-10-07 12:00:00Z', '0000-00-00T00:00:00Z'] as $stamp) {
            $this->refused([...$row, 'created_at' => $stamp]);
        }
        foreach (['AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA', '11111111-1111-1111-8111-111111111111'] as $id) {
            $this->refused([...$row, 'public_id' => $id]);
        }
        DB::table(TaxCheckoutSchema::TABLES['order'])->insert($row);
        $this->assertSame(1, DB::table(TaxCheckoutSchema::TABLES['order'])->count());
    }

    public function test_native_case_alias_of_a_reserved_constraint_name_is_refused_before_ddl(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (array_reverse(array_values(TaxCheckoutSchema::TABLES)) as $table) {
                DB::statement('DROP TABLE '.$table);
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        DB::statement('CREATE TABLE foreign_tax_alias (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, CONSTRAINT PTX_O_PUBLIC UNIQUE (id))');
        try {
            (new TaxCheckoutSchemaInstaller)->up();
            $this->fail('An aliased reserved constraint was admitted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('foreign constraint dictionary identity', $error->getMessage());
        }
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?', ['production\_tax\_checkout\_%'])->n);
    }

    private function refused(array $row): void
    {
        try {
            DB::table(TaxCheckoutSchema::TABLES['order'])->insert($row);
            $this->fail('A malformed native row was accepted.');
        } catch (QueryException) {
            $this->assertSame(0, DB::table(TaxCheckoutSchema::TABLES['order'])->count());
        }
    }
}
