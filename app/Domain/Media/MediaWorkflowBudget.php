<?php

namespace App\Domain\Media;

/** Shared by every subprocess in one worker attempt; the queue retains 60 seconds for persistence and cleanup. */
class MediaWorkflowBudget
{
    public const SECONDS = 840;

    private ?int $deadline = null;

    /** Start an attempt without extending an enclosing attempt. Return the deadline to restore in finally. */
    public function enter(): ?int
    {
        $previous = $this->deadline;
        $deadline = $this->now() + self::SECONDS * 1000000000;
        $this->deadline = $previous === null ? $deadline : min($previous, $deadline);

        return $previous;
    }

    public function leave(?int $previous): void
    {
        $this->deadline = $previous;
    }

    /** Never start another process when less than one whole second remains. */
    public function limit(int $requested): int
    {
        if ($this->deadline === null) {
            return $requested;
        }
        $remaining = (int) floor(($this->deadline - $this->now()) / 1000000000);
        if ($remaining < 1) {
            throw new MediaFailure('processor_timeout', 'Media processing exceeded its workflow time limit.');
        }

        return $requested > 0 ? min($requested, $remaining) : $remaining;
    }

    public function assertRemaining(): void
    {
        $this->limit(1);
    }

    protected function now(): int
    {
        return hrtime(true);
    }
}
