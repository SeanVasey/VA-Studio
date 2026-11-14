<?php

namespace App\Domain\Memberships\Billing;

use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use PDOStatement;

/** Native UTC only, on the already captured primary. No Carbon or framework clock callback. */
final class BillingDatabaseClock
{
    public static function expression(string $driver): string
    {
        return match ($driver) {
            'mysql' => 'UTC_TIMESTAMP(6)',
            'sqlite' => "strftime('%Y-%m-%d %H:%M:%f','now') || '000'",
            default => throw new BillingException('database_clock'),
        };
    }

    public static function assertNative(PDO $pdo, string $driver): void
    {
        BillingException::require(in_array(get_class($pdo), [PDO::class, Sqlite::class, Mysql::class], true)
            && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === $driver
            && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class], 'database_clock');
        self::expression($driver);
        if ($driver === 'sqlite') {
            $functions = $pdo->query('PRAGMA function_list')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($functions as $function) {
                BillingException::require(! (in_array(strtolower((string) $function['name']), ['strftime', 'substr'], true)
                    && (int) $function['builtin'] !== 1), 'database_clock');
            }
        }
    }

    public static function sample(PDO $pdo, string $driver): BillingDatabaseInstant
    {
        self::assertNative($pdo, $driver);
        $expression = self::expression($driver);
        // Query and network time consume the resulting lifetime; they never extend it.
        $anchorNs = hrtime(true);
        $stamp = $pdo->query('SELECT '.$expression)->fetchColumn();
        BillingException::require(is_string($stamp), 'database_clock');

        return BillingDatabaseInstant::fromNativeValue($stamp, $anchorNs);
    }
}
