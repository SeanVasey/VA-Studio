<?php

namespace Tests\Canary;

use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchemaInstaller;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

final class ProductionCheckoutForeignRoutineCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_unowned_stored_routine_reference_is_refused_before_any_checkout_ddl(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        foreach (array_reverse(CheckoutSchema::TABLES) as $table) {
            DB::unprepared('DROP TABLE '.$table);
        }
        DB::unprepared('CREATE PROCEDURE synthetic_foreign_checkout_reader() SELECT COUNT(*) FROM production_checkout_orders');
        $writes = [];
        $tracking = (object) ['active' => true];
        DB::listen(function (QueryExecuted $event) use (&$writes, $tracking): void {
            if ($tracking->active && preg_match('/\A\s*(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE)\b/i', $event->sql)) {
                $words = preg_split('/\s+/', trim($event->sql));
                $writes[] = implode(' ', array_slice($words, 0, 3));
            }
        });
        $rejected = false;
        try {
            (new CheckoutSchemaInstaller)->up();
        } catch (\Throwable) {
            $rejected = true;
        } finally {
            $tracking->active = false;
            $retained = DB::selectOne("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='synthetic_foreign_checkout_reader'");
            DB::unprepared('DROP PROCEDURE synthetic_foreign_checkout_reader');
        }
        $this->assertTrue($rejected, 'A foreign stored routine reference was accepted.');
        $this->assertSame([], $writes, 'Preflight allowed owned DDL before proving all retained routine definitions.');
        $this->assertFalse(DB::getSchemaBuilder()->hasTable(CheckoutSchema::TABLES['authority']));
        $this->assertSame('synthetic_foreign_checkout_reader', $retained->ROUTINE_NAME);
    }
}
