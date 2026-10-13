<?php

namespace App\Domain\Memberships\Billing;

use PDO;

/**
 * Technical lifetime, never paid authority. Original receipt identity is immutable; the private
 * checkpoint can only advance database time and shorten the original monotonic allowance.
 */
final class BillingFreshnessWindow implements \JsonSerializable
{
    private int $latestMicros;

    private int $deadline;

    private bool $retired = false;

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $driver,
        private readonly array $observation,
        private readonly array $start,
        private readonly array $end,
        private readonly int $readEndMicros,
        private readonly int $expiresMicros,
        public readonly int $originalDeadlineNs,
        BillingDatabaseInstant $sample,
    ) {
        $this->latestMicros = $sample->utcMicros;
        $this->deadline = $originalDeadlineNs;
        $this->accept($sample);
    }

    public static function capture(PDO $pdo, string $driver, array $observation, array $start, array $end, int $originalDeadlineNs): self
    {
        BillingRetrievalInterval::verifyRows($observation, $start, $end);
        $readEnd = BillingDatabaseInstant::fromNativeValue($end['database_at'], 0);
        $expires = BillingDatabaseInstant::fromNativeValue($observation['freshness_deadline'].'.000000', 0);

        return new self($pdo, $driver, $observation, $start, $end, $readEnd->utcMicros, $expires->utcMicros,
            $originalDeadlineNs, BillingDatabaseClock::sample($pdo, $driver));
    }

    /** Exact receipt identity, including both original physical endpoint allocations. */
    public function assertCurrent(PDO $pdo, string $driver, array $observation, array $start, array $end): void
    {
        BillingException::require($this->observation === $observation && $this->start === $start && $this->end === $end, 'changed_invoice_facts');
        $this->assertLive($pdo, $driver);
    }

    /** Native time is intentionally outside the immutable retained-row replay footprint. */
    public function assertLive(PDO $pdo, string $driver): void
    {
        BillingException::require($this->pdo === $pdo && $this->driver === $driver, 'changed_primary');
        $this->assertDeadline();
        $this->accept(BillingDatabaseClock::sample($pdo, $driver));
    }

    public function deadlineNs(): int
    {
        return $this->deadline;
    }

    /** Call again after all native row/configuration/frame work; no query can renew this cap. */
    public function assertDeadline(): void
    {
        if ($this->retired || hrtime(true) >= $this->deadline) {
            $this->retired = true;
            throw new BillingException('expired_invoice_proof');
        }
    }

    private function accept(BillingDatabaseInstant $sample): void
    {
        try {
            $this->assertDeadline();
            BillingException::require($sample->utcMicros >= $this->latestMicros, 'database_clock_regressed');
            BillingException::require($sample->utcMicros >= $this->readEndMicros && $sample->utcMicros < $this->expiresMicros, 'expired_invoice_proof');
            $remainingMicros = $this->expiresMicros - $sample->utcMicros;
            BillingException::require($sample->anchorNs >= 0 && $remainingMicros <= intdiv(PHP_INT_MAX - $sample->anchorNs, 1000), 'database_clock');
            $this->latestMicros = $sample->utcMicros;
            $this->deadline = min($this->deadline, $sample->anchorNs + $remainingMicros * 1000);
            $this->assertDeadline();
        } catch (BillingException $error) {
            // A later database rollback cannot resurrect a window already observed expired/invalid.
            $this->retired = true;
            throw $error;
        }
    }

    public function __clone(): never
    {
        throw new BillingException('clone_refused');
    }

    public function __serialize(): never
    {
        throw new BillingException('serialization_refused');
    }

    public function __unserialize(array $data): never
    {
        throw new BillingException('serialization_refused');
    }

    public function jsonSerialize(): never
    {
        throw new BillingException('serialization_refused');
    }
}
