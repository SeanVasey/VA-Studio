<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOStatement;
use Tests\TestCase;

/**
 * IdentityRows and IdentityHistoricalPlainRows run a complete ownership inspection on every
 * identity assertion, inside fixed paid/identity deadlines. One native inspection must stay a
 * small fixed number of statements; its refusal floors are proved by the migration tests.
 */
class IdentityInspectionCostTest extends TestCase
{
    /** Statements sent by one MySQL inspection of the complete installed graph. */
    private const STATEMENT_BOUND = 150;

    public function test_one_native_inspection_issues_a_small_fixed_number_of_statements(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The statement volume is a native MySQL dictionary cost.');
        }
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        $config = DB::connection()->getConfig();
        $counted = new class('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]) extends PDO
        {
            public int $statements = 0;

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                $this->statements++;

                return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->statements++;

                return parent::prepare($query, $options);
            }

            public function exec(string $statement): int|false
            {
                $this->statements++;

                return parent::exec($statement);
            }
        };
        $counted->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
        $expected = (new IdentityMigrationOwnership)->inspect(DB::connection()->getPdo(), 'mysql');
        $this->assertNotContains(false, $expected[0]);
        $this->assertFalse($expected[1]);

        $counted->statements = 0;
        $started = hrtime(true);
        $actual = (new IdentityMigrationOwnership)->inspect($counted, 'mysql');
        $elapsed = (hrtime(true) - $started) / 1e6;

        $this->assertSame($expected, $actual);
        fwrite(STDERR, sprintf("identity inspection: %d statements, %.1f ms\n", $counted->statements, $elapsed));
        $this->assertLessThanOrEqual(self::STATEMENT_BOUND, $counted->statements);
    }
}
