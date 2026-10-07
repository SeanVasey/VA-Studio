<?php

namespace Tests\Canary;

use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchemaInstaller;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

final class ProductionCheckoutForeignConstraintCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_foreign_schema_global_fk_identity_is_refused_before_any_checkout_ddl(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        foreach (array_reverse(CheckoutSchema::TABLES) as $table) {
            DB::unprepared('DROP TABLE '.$table);
        }
        DB::unprepared('CREATE TABLE synthetic_foreign_fk_holder (marker INT NOT NULL, owner_id BIGINT UNSIGNED NULL, CONSTRAINT pco_3_f_review_id FOREIGN KEY (owner_id) REFERENCES users(id)) ENGINE=InnoDB');
        DB::table('synthetic_foreign_fk_holder')->insert(['marker' => 9123, 'owner_id' => null]);
        $writes = [];
        DB::listen(function (QueryExecuted $event) use (&$writes): void {
            if (preg_match('/\A\s*(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        $rejected = false;
        try {
            (new CheckoutSchemaInstaller)->up();
        } catch (\Throwable) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'A foreign FK identity was adopted.');
        $this->assertSame([], $writes, 'Preflight allowed owned DDL before the foreign constraint collision.');
        $this->assertFalse(DB::getSchemaBuilder()->hasTable(CheckoutSchema::TABLES['authority']));
        $this->assertSame(9123, DB::table('synthetic_foreign_fk_holder')->value('marker'));
    }
}
