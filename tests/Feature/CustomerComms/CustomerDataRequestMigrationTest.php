<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\DataRights\CustomerDataRights;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\CustomerFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Additive, forward-only customer data request schema (2026_10_24_268200). */
class CustomerDataRequestMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const TABLES = ['customer_data_requests', 'customer_data_request_events', 'customer_data_exports'];

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_24_268200_customer_data_requests.php');
    }

    /** Installed guard names in byte order: MySQL's information_schema collation orders "_" differently from SQLite. */
    private function triggers(): array
    {
        $names = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', self::TABLES)->pluck('name')->all()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereIn('EVENT_OBJECT_TABLE', self::TABLES)
                ->pluck('TRIGGER_NAME')->all();
        sort($names, SORT_STRING);

        return $names;
    }

    public function test_schema_installs_every_guard_and_refuses_rollback_and_reinstallation_even_when_empty(): void
    {
        $expected = ['customer_data_exports_delete', 'customer_data_exports_insert', 'customer_data_exports_update',
            'customer_data_request_events_delete', 'customer_data_request_events_insert', 'customer_data_request_events_update',
            'customer_data_requests_delete', 'customer_data_requests_insert', 'customer_data_requests_update'];
        $this->assertSame($expected, $this->triggers());
        $migration = $this->migration();
        foreach (['down' => 'forward-only', 'up' => 'Unexpected customer data request objects'] as $operation => $message) {
            try {
                $migration->{$operation}();
                $this->fail("Migration {$operation} was accepted.");
            } catch (LogicException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
        }
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertSame($expected, $this->triggers());
    }

    public function test_populated_rollback_is_refused_and_keeps_every_row(): void
    {
        F::configure();
        config(['customer-data-rights.enabled' => true]);
        $customer = F::account();
        app(CustomerDataRights::class)->requestDeletion(app(CustomerAccess::class)->principal($customer['user']), $customer['user']);
        $before = DB::table('customer_data_requests')->get()->all();
        try {
            $this->migration()->down();
            $this->fail('Populated rollback was accepted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('forward-only', $error->getMessage());
        }
        $this->assertEquals($before, DB::table('customer_data_requests')->get()->all());
        $this->assertCount(9, $this->triggers());
    }
}
