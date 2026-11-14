<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\CurrentCatalog;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * IdentityRows and IdentityHistoricalPlainRows run a complete ownership inspection on every
 * identity assertion, inside fixed paid/identity deadlines. One native inspection must stay a
 * small fixed number of statements; its refusal floors are proved by the migration tests.
 */
class IdentityInspectionCostTest extends TestCase
{
    /** Statements sent by one MySQL inspection of the complete installed graph. */
    private const STATEMENT_BOUND = 110;

    public function test_one_native_inspection_issues_a_small_fixed_number_of_statements(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The statement volume is a native MySQL dictionary cost.');
        }
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        // Native canonical writer; a PDO subclass cannot stand in for the factory admission boundary.
        $counted = DB::connection()->getPdo();
        $expected = (new IdentityMigrationOwnership)->inspect(DB::connection()->getPdo(), 'mysql');
        $this->assertNotContains(false, $expected[0]);
        $this->assertFalse($expected[1]);

        $beforeCompanion = CurrentCatalog::accounting();
        $beforePrimary = (int) $counted->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
        $started = hrtime(true);
        $actual = (new IdentityMigrationOwnership)->inspect($counted, 'mysql');
        $elapsed = (hrtime(true) - $started) / 1e6;

        $afterPrimary = (int) $counted->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
        $afterCompanion = CurrentCatalog::accounting();
        // Exclude only the terminal Questions measurement itself; every primary proof/context SQL remains.
        $primaryStatements = $afterPrimary - $beforePrimary - 1;
        $companionStatements = $afterCompanion['companion_sql'] - $beforeCompanion['companion_sql'];
        $total = $primaryStatements + $companionStatements;
        $this->assertSame($expected, $actual);
        $this->assertGreaterThan(0, $primaryStatements);
        $this->assertGreaterThan(0, $companionStatements);
        fwrite(STDERR, sprintf("identity inspection: %d total SQL (%d primary + %d companion), %d native handshakes/%.1f ms, %.1f ms total\n",
            $total, $primaryStatements, $companionStatements, $afterCompanion['connections'] - $beforeCompanion['connections'],
            ($afterCompanion['connect_ns'] - $beforeCompanion['connect_ns']) / 1e6, $elapsed));
        $this->assertLessThanOrEqual(self::STATEMENT_BOUND, $total);
    }
}
