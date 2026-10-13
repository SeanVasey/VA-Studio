<?php

namespace App\Domain\Grants\Member;

use JsonSerializable;

/** One original admission budget, not a promise to interrupt a blocked syscall. */
final class MemberOriginalBudget implements JsonSerializable
{
    private const WORKFLOW_NS = 300_000_000_000;

    private function __construct(private int $deadline) {}

    public static function start(): self
    {
        return new self(\hrtime(true) + self::WORKFLOW_NS);
    }

    public function deadlineNs(): int
    {
        return $this->deadline;
    }

    public function shortenTo(int $deadlineNs): void
    {
        $this->deadline = min($this->deadline, $deadlineNs);
    }

    public function assertCurrent(): void
    {
        MemberGrantException::require(\hrtime(true) < $this->deadline, 'workflow_deadline');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_budget');
    }

    public function jsonSerialize(): never
    {
        throw new MemberGrantException('private_budget');
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_budget');
    }

    public function __debugInfo(): array
    {
        return ['member_original_budget' => true];
    }
}
