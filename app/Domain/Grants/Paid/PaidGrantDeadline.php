<?php

namespace App\Domain\Grants\Paid;

/** One original monotonic budget may become shorter; no source, retry or render may extend it. */
final class PaidGrantDeadline
{
    private int $deadline;

    private function __construct(private readonly int $original)
    {
        $this->deadline = $original;
    }

    public static function start(int $seconds = 60): self
    {
        PaidGrantException::require($seconds >= 1 && $seconds <= 300);

        return new self(hrtime(true) + $seconds * 1_000_000_000);
    }

    public function shortenTo(int $deadline): void
    {
        $this->deadline = min($this->deadline, $deadline);
    }

    public function value(): int
    {
        return $this->deadline;
    }

    public function proveCurrent(): void
    {
        PaidGrantException::require(hrtime(true) <= $this->deadline, 410);
    }

    public function __serialize(): array
    {
        throw new \LogicException('Private paid observation budgets cannot be serialized.');
    }

    private function __clone() {}
}
