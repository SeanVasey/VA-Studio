<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Grants\Paid\PaidGrantRequestBudget;

/** One original monotonic budget may become shorter; no source, retry or render may extend it. */
final class PaidTaxDeadline
{
    private int $deadline;

    private ?PaidGrantRequestBudget $requestBudget = null;

    private function __construct(private readonly int $original)
    {
        $this->deadline = $original;
    }

    public static function start(int $seconds = 60): self
    {
        PaidTaxException::require($seconds >= 1 && $seconds <= 300);

        return new self(hrtime(true) + $seconds * 1_000_000_000);
    }

    public function shortenTo(int $deadline): void
    {
        $this->deadline = min($this->deadline, $deadline);
    }

    /** The same request's live min-only ceiling; the ordinary 60 s allowance is unchanged. */
    public static function forRequest(PaidGrantRequestBudget $budget): self
    {
        $deadline = self::start();
        $deadline->requestBudget = $budget;

        return $deadline;
    }

    public function value(): int
    {
        return min($this->deadline, $this->requestBudget?->value() ?? $this->deadline);
    }

    public function proveCurrent(): void
    {
        PaidTaxException::require(hrtime(true) <= $this->value(), 410);
    }

    public function __serialize(): array
    {
        throw new \LogicException('Private paid observation budgets cannot be serialized.');
    }

    private function __clone() {}
}
