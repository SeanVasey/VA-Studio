<?php

namespace App\Domain\Grants\Paid;

use Carbon\CarbonImmutable;
use LogicException;

/** Request timing only; neither the admitted instant nor elapsed time establishes download authority. */
final class PaidGrantRequestBudget
{
    private int $deadline;

    private function __construct(public readonly PaidGrantRequestInstant $receivedAt, private readonly int $initial,
        private readonly int $snapshotSeconds, private readonly int $original)
    {
        $this->deadline = $original;
    }

    public static function capture(mixed $requestTime): self
    {
        // Capture before instant validation, policy resolution or identity work. Server-start age is deducted once.
        $started = hrtime(true);
        $now = CarbonImmutable::now('UTC');
        $receivedAt = PaidGrantRequestInstant::capture($requestTime);
        $ageMicros = max(0, (int) $now->format('U') * 1_000_000 + (int) $now->format('u')
            - (int) $receivedAt->at->format('U') * 1_000_000 - (int) $receivedAt->at->format('u'));
        $snapshotSeconds = app(PaidGrantPolicy::class)->snapshotSeconds();
        $initial = $started + 60_000_000_000 - $ageMicros * 1000;

        return new self($receivedAt, $initial, $snapshotSeconds, $initial + ($snapshotSeconds + 60) * 1_000_000_000);
    }

    public function initialDeadline(): PaidGrantDeadline
    {
        $deadline = PaidGrantDeadline::start();
        $deadline->shortenTo(min($this->initial, $this->deadline));
        $deadline->proveCurrent();

        return $deadline;
    }

    public function snapshotDeadline(): int
    {
        $seconds = min($this->snapshotSeconds, app(PaidGrantPolicy::class)->snapshotSeconds());
        $this->proveCurrent();

        return min(hrtime(true) + $seconds * 1_000_000_000, $this->deadline);
    }

    public function commitDeadline(): PaidGrantDeadline
    {
        $deadline = PaidGrantDeadline::start();
        $deadline->shortenTo($this->deadline);
        $deadline->proveCurrent();

        return $deadline;
    }

    /** Trusted callers may only reduce the original request allowance. */
    public function shortenTo(int $deadline): void
    {
        $this->deadline = min($this->deadline, $deadline);
    }

    public function proveCurrent(): void
    {
        PaidGrantException::require(hrtime(true) <= $this->deadline, 410);
    }

    public function __serialize(): array
    {
        throw new LogicException('Private paid request budgets cannot be serialized.');
    }

    private function __clone() {}
}
