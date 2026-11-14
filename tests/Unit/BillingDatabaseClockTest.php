<?php

namespace Tests\Unit;

use App\Domain\Memberships\Billing\BillingDatabaseClock;
use App\Domain\Memberships\Billing\BillingDatabaseInstant;
use App\Domain\Memberships\Billing\BillingException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BillingDatabaseClockTest extends TestCase
{
    public function test_native_sqlite_clock_is_bracketed_and_its_anchor_precedes_query_completion(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $sql = 'SELECT '.BillingDatabaseClock::expression('sqlite');
        $beforeStamp = $pdo->query($sql)->fetchColumn();
        $beforeNs = hrtime(true);
        $instant = BillingDatabaseClock::sample($pdo, 'sqlite');
        $afterNs = hrtime(true);
        $afterStamp = $pdo->query($sql)->fetchColumn();
        $this->assertLessThanOrEqual($instant->stamp26, $beforeStamp);
        $this->assertGreaterThanOrEqual($instant->stamp26, $afterStamp);
        $this->assertGreaterThanOrEqual($beforeNs, $instant->anchorNs);
        $this->assertLessThanOrEqual($afterNs, $instant->anchorNs);
        $this->assertSame(substr($instant->stamp26, 0, 19), $instant->stamp19());
        $this->assertSame(intdiv($instant->utcMicros, 1_000_000), $instant->seconds());
    }

    public function test_explicit_utc_parsing_preserves_microseconds_under_another_php_timezone(): void
    {
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $instant = BillingDatabaseInstant::fromNativeValue('2026-10-07 00:00:00.123456', 123);
            $this->assertSame(1791331200123456, $instant->utcMicros);
            $this->assertSame(123, $instant->anchorNs);
            $this->assertSame('UTC_TIMESTAMP(6)', BillingDatabaseClock::expression('mysql'));
        } finally {
            date_default_timezone_set($original);
        }
    }

    public static function invalidInstants(): array
    {
        return ['invalid day' => ['2026-02-30 00:00:00.000000', 0], 'no micros' => ['2026-10-07 00:00:00', 0],
            'milliseconds only' => ['2026-10-07 00:00:00.123', 0], 'timezone suffix' => ['2026-10-07 00:00:00.000000Z', 0],
            'epoch' => ['1970-01-01 00:00:00.000001', 0], 'out of range' => ['10000-01-01 00:00:00.000000', 0],
            'negative anchor' => ['2026-10-07 00:00:00.000000', -1]];
    }

    #[DataProvider('invalidInstants')]
    public function test_noncanonical_or_unbounded_native_values_refuse(string $stamp, int $anchor): void
    {
        $this->expectException(BillingException::class);
        BillingDatabaseInstant::fromNativeValue($stamp, $anchor);
    }

    public static function overriddenFunctions(): array
    {
        return [['strftime'], ['substr']];
    }

    #[DataProvider('overriddenFunctions')]
    public function test_application_defined_sqlite_clock_function_is_refused_before_invocation(string $function): void
    {
        $pdo = new PDO('sqlite::memory:');
        $calls = 0;
        $pdo->sqliteCreateFunction($function, function () use (&$calls): string {
            $calls++;

            return '2030-01-01 00:00:00.000';
        });
        try {
            BillingDatabaseClock::sample($pdo, 'sqlite');
            $this->fail('Overridden clock must not be used.');
        } catch (BillingException $error) {
            $this->assertSame('database_clock', $error->reason);
            $this->assertSame(0, $calls);
        }
    }

    public function test_custom_statement_class_cannot_interpose_a_clock_read(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BillingClockTestStatement::class]);
        $this->expectException(BillingException::class);
        BillingDatabaseClock::sample($pdo, 'sqlite');
    }

    public function test_declared_driver_must_match_the_actual_native_connection(): void
    {
        $this->expectException(BillingException::class);
        BillingDatabaseClock::sample(new PDO('sqlite::memory:'), 'mysql');
    }
}

class BillingClockTestStatement extends PDOStatement
{
    protected function __construct() {}
}
