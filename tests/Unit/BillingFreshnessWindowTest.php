<?php

namespace Tests\Unit;

use App\Domain\Memberships\Billing\BillingDatabaseInstant;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingFreshnessWindow;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Pure synthetic paired-clock arithmetic; no database query or physical elapsed-time claim. */
class BillingFreshnessWindowTest extends TestCase
{
    public function test_remaining_microseconds_are_anchored_before_the_query_and_never_rounded_up(): void
    {
        $anchor = hrtime(true) + 60_000_000_000;
        $window = $this->window('2026-10-13 12:09:59.999999', $anchor, $anchor + 60_000_000_000);
        $this->assertSame($anchor + 1000, $window->deadlineNs());
    }

    public function test_original_operation_or_producer_cap_remains_an_independent_upper_bound(): void
    {
        $anchor = hrtime(true) + 60_000_000_000;
        $original = $anchor + 500_000_000;
        $window = $this->window('2026-10-13 12:09:00.000000', $anchor, $original);
        $this->assertSame($original, $window->originalDeadlineNs);
        $this->assertSame($original, $window->deadlineNs());
    }

    public function test_exact_deadline_and_later_database_time_are_expired(): void
    {
        foreach (['2026-10-13 12:10:00.000000', '2026-10-13 12:10:00.000001'] as $now) {
            $anchor = hrtime(true) + 60_000_000_000;
            $this->refused(fn () => $this->window($now, $anchor, $anchor + 60_000_000_000), 'expired_invoice_proof');
        }
    }

    public function test_time_spent_waiting_for_the_initial_clock_query_consumes_its_remaining_lifetime(): void
    {
        // Simulated query started two seconds ago and returned a sample with only one second left.
        $this->refused(fn () => $this->window('2026-10-13 12:09:59.000000', hrtime(true) - 2_000_000_000,
            hrtime(true) + 60_000_000_000), 'expired_invoice_proof');
    }

    public function test_rechecking_with_a_later_anchor_cannot_renew_the_first_cap(): void
    {
        $anchor = hrtime(true) + 60_000_000_000;
        $window = $this->window('2026-10-13 12:09:50.000000', $anchor, $anchor + 60_000_000_000);
        $first = $window->deadlineNs();
        $this->accept($window, '2026-10-13 12:09:50.000001', $anchor + 5_000_000_000);
        $this->assertSame($first, $window->deadlineNs());
        $this->accept($window, '2026-10-13 12:09:59.000000', $anchor + 5_000_000_000);
        $this->assertSame($anchor + 6_000_000_000, $window->deadlineNs());
    }

    public function test_database_time_cannot_regress_after_any_successful_sample(): void
    {
        $anchor = hrtime(true) + 60_000_000_000;
        $window = $this->window('2026-10-13 12:09:50.000000', $anchor, $anchor + 60_000_000_000);
        $this->accept($window, '2026-10-13 12:09:55.000000', $anchor + 1_000_000_000);
        $this->refused(fn () => $this->accept($window, '2026-10-13 12:09:54.999999', $anchor + 2_000_000_000), 'database_clock_regressed');
    }

    public function test_an_expired_database_sample_permanently_retires_the_original_window(): void
    {
        $anchor = hrtime(true) + 60_000_000_000;
        $window = $this->window('2026-10-13 12:09:50.000000', $anchor, $anchor + 60_000_000_000);
        $this->refused(fn () => $this->accept($window, '2026-10-13 12:10:00.000000', $anchor + 1_000_000_000), 'expired_invoice_proof');
        $this->refused(fn () => $this->accept($window, '2026-10-13 12:09:55.000000', $anchor + 2_000_000_000), 'expired_invoice_proof');
    }

    public function test_read_end_is_a_lower_bound_and_arithmetic_overflow_refuses(): void
    {
        $anchor = hrtime(true) + 60_000_000_000;
        $this->refused(fn () => $this->window('2026-10-13 11:59:59.999999', $anchor, PHP_INT_MAX), 'expired_invoice_proof');
        $this->refused(fn () => $this->window('2026-10-13 12:09:59.000000', PHP_INT_MAX - 999, PHP_INT_MAX), 'database_clock');
    }

    public function test_technical_window_cannot_be_copied_serialized_or_rebound_to_another_handle(): void
    {
        $anchor = hrtime(true) + 60_000_000_000;
        $window = $this->window('2026-10-13 12:09:50.000000', $anchor, $anchor + 60_000_000_000);
        $this->refused(fn () => clone $window, 'clone_refused');
        $this->refused(fn () => serialize($window), 'serialization_refused');
        $this->refused(fn () => json_encode($window), 'serialization_refused');
        $this->refused(fn () => $window->assertLive($this->unconnectedHandle(), 'sqlite'), 'changed_primary');
    }

    private function window(string $now, int $anchor, int $original): BillingFreshnessWindow
    {
        // Reflection reaches only technical arithmetic. This is neither ledger issuance nor a paid proof.
        // The future synthetic anchor makes the real runtime check lenient while exact math is asserted.
        $class = new ReflectionClass(BillingFreshnessWindow::class);
        $window = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($window, $this->unconnectedHandle(), 'sqlite', [], [], [],
            BillingDatabaseInstant::fromNativeValue('2026-10-13 12:00:00.000000', 0)->utcMicros,
            BillingDatabaseInstant::fromNativeValue('2026-10-13 12:10:00.000000', 0)->utcMicros,
            $original, BillingDatabaseInstant::fromNativeValue($now, $anchor));

        return $window;
    }

    private function accept(BillingFreshnessWindow $window, string $now, int $anchor): void
    {
        (new ReflectionMethod($window, 'accept'))->invoke($window, BillingDatabaseInstant::fromNativeValue($now, $anchor));
    }

    private function unconnectedHandle(): PDO
    {
        return new class extends PDO
        {
            public function __construct() {}
        };
    }

    private function refused(callable $work, string $reason): void
    {
        try {
            $work();
            $this->fail('Expected '.$reason);
        } catch (BillingException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
