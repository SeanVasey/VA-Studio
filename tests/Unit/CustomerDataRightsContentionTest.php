<?php

namespace Tests\Unit;

use App\Domain\Customers\DataRights\CustomerDataRights;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Data-rights contention is judged by the driver's error code, never by message text: a query exception's message carries the
 * SQL and its bound values, so a staff reason or any stored text containing "deadlock" must not turn a defect into a retry.
 */
class CustomerDataRightsContentionTest extends TestCase
{
    private static function queryError(string $connection, array $errorInfo, string $sql = 'update "customer_data_requests" set "reason" = ?', array $bindings = []): QueryException
    {
        $driver = new PDOException((string) $errorInfo[2]);
        $driver->errorInfo = $errorInfo;

        return new QueryException($connection, $sql, $bindings, $driver);
    }

    public static function errors(): iterable
    {
        $phrase = 'Deadlock found when trying to get lock; try restarting transaction';
        yield 'mysql deadlock 1213' => [self::queryError('mysql', ['40001', 1213, $phrase]), true];
        yield 'mysql lock wait timeout 1205' => [self::queryError('mysql', ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction']), true];
        yield 'mysql serialization SQLSTATE 40001' => [self::queryError('mysql', ['40001', 0, 'serialization failure']), true];
        yield 'sqlite busy 5' => [self::queryError('sqlite', ['HY000', 5, 'database is locked']), true];
        yield 'sqlite locked 6' => [self::queryError('sqlite', ['HY000', 6, 'database table is locked']), true];
        yield 'wrapped by a nested-transaction deadlock exception' => [new DeadlockException('wrapped', 0, self::queryError('mysql', ['40001', 1213, $phrase])), true];
        yield 'mysql duplicate key whose bound value names a deadlock' => [self::queryError('mysql', ['23000', 1062, 'Duplicate entry'], bindings: [$phrase]), false];
        yield 'mysql error text naming a lock wait' => [self::queryError('mysql', ['42000', 1064, 'Lock wait timeout exceeded; try restarting transaction']), false];
        yield 'sqlite constraint whose message says database is locked' => [self::queryError('sqlite', ['23000', 19, 'database is locked']), false];
        yield 'sqlite busy code on a mysql connection' => [self::queryError('mysql', ['HY000', 5, 'database is locked']), false];
        yield 'no driver code' => [new QueryException('mysql', 'select 1', [], new PDOException($phrase)), false];
        yield 'not a database error' => [new RuntimeException($phrase), false];
    }

    #[DataProvider('errors')]
    public function test_only_driver_contention_codes_count(\Throwable $error, bool $expected): void
    {
        $this->assertSame($expected, CustomerDataRights::concurrencyError($error));
    }
}
