<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantTransactions;
use Illuminate\Config\Repository;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * A1-6: a native cap race could surface a raw `PDOException` 1213 (deadlock) from a Free256 command. Deadlocks and lock
 * wait timeouts now roll the command back and refuse with the fixed reason `contention`; there is no retry. The driver
 * exception is simulated in-process from inside the transaction, after the command has already written its rows.
 */
final class ProductionFreeGrantContentionTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
    }

    /** @return array<string, array{0:string,1:int,2:string}> */
    public static function driverErrors(): array
    {
        return [
            'mysql deadlock 1213' => ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'],
            'mysql lock wait timeout 1205' => ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'],
            'serialization failure 40001' => ['40001', 0, 'could not serialize access due to concurrent update'],
        ];
    }

    #[DataProvider('driverErrors')]
    public function test_a_deadlock_after_the_staff_write_is_a_fixed_refusal_and_nothing_is_written(string $state, int $code, string $message): void
    {
        $author = $this->staff();
        $calls = $this->contendAfterWrite('production_free_definitions', $state, $code, $message);

        $this->refuses(fn () => (new ProductionFreeGrantDefinitions)->propose($this->definitionInput(), $author));

        $this->assertSame(1, $calls(), 'The command is refused, not retried.');
        $this->assertSame(0, DB::table('production_free_definitions')->count());
        $this->assertSame(0, DB::table('production_free_reviews')->count());
    }

    #[DataProvider('driverErrors')]
    public function test_a_deadlock_after_the_customer_write_is_a_fixed_refusal_and_nothing_is_written(string $state, int $code, string $message): void
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
        $input = $this->assentInput($review);
        $calls = $this->contendAfterWrite('production_free_origins', $state, $code, $message);

        $this->refuses(fn () => $grants->accept($definition['id'], $input, $owner['principal'], $owner['user']));

        $this->assertSame(1, $calls());
        $this->assertSame(0, DB::table('production_free_origins')->count());
        // Once the contention is gone the same request key succeeds, exactly once.
        $this->assertSame('pending', $grants->accept($definition['id'], $input, $owner['principal'], $owner['user'])['documentStatus']);
        $this->assertSame(1, DB::table('production_free_origins')->count());
    }

    public function test_only_deadlocks_and_lock_waits_are_contention(): void
    {
        $deadlock = $this->driverException('40001', 1213, 'Deadlock found');
        $this->assertTrue(ProductionFreeGrantTransactions::contended($deadlock));
        $this->assertTrue(ProductionFreeGrantTransactions::contended($this->driverException('HY000', 1205, 'Lock wait timeout exceeded')));
        $this->assertTrue(ProductionFreeGrantTransactions::contended(new QueryException('mysql', 'select 1', [], $deadlock)));
        $this->assertTrue(ProductionFreeGrantTransactions::contended(new DeadlockException('wrapped', 0, $deadlock)));
        $this->assertFalse(ProductionFreeGrantTransactions::contended($this->driverException('23000', 1062, 'Duplicate entry')));
        $this->assertFalse(ProductionFreeGrantTransactions::contended($this->driverException('HY000', 1045, 'Access denied')));
        $this->assertFalse(ProductionFreeGrantTransactions::contended(new \RuntimeException('Deadlock in a message is not a driver error')));
    }

    /**
     * Throws from the first policy re-proof that follows a write to `$table`, which every command runs last inside its
     * transaction. Returns a counter of how often it threw.
     */
    private function contendAfterWrite(string $table, string $state, int $code, string $message): \Closure
    {
        $threw = 0;
        $hook = function () use (&$threw, $table, $state, $code, $message): void {
            if ($threw === 0 && DB::table($table)->count() > 0) {
                $threw++;
                throw $this->driverException($state, $code, $message);
            }
        };
        $original = $this->app->make('config');
        $this->app->instance('config', new class($original->all(), $hook) extends Repository
        {
            public function __construct(array $items, private readonly \Closure $hook)
            {
                parent::__construct($items);
            }

            public function get($key, $default = null)
            {
                if ($key === 'production-free-grants') {
                    ($this->hook)();
                }

                return parent::get($key, $default);
            }
        });
        Config::clearResolvedInstance('config');
        $this->beforeApplicationDestroyed(fn () => Config::clearResolvedInstance('config'));

        return function () use (&$threw): int {
            return $threw;
        };
    }

    private function driverException(string $state, int $code, string $message): PDOException
    {
        $error = new PDOException($message);
        $error->errorInfo = [$state, $code, $message];

        return $error;
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Must refuse contention');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('contention', $error->reason);
        }
    }
}
