<?php

namespace App\Domain\Grants\Member;

use Throwable;
use WeakMap;

/** Existing originals only; current owner and complete retained graph proof belong to the operation. */
final class MemberRetainedOriginals
{
    private WeakMap $observations;

    public function __construct(private readonly MemberOriginalFiles $files)
    {
        $this->observations = new WeakMap;
    }

    public function observe(MemberActivatedOriginal $original, MemberOriginalIoFrame $idle,
        MemberOriginalBudget $budget): MemberRetainedOriginalObservation
    {
        $observation = $this->files->readRetained($original, $idle, $budget);
        $deadline = $observation->observationStartedNs + 60_000_000_000;
        $budget->shortenTo($deadline);
        $this->observations[$observation] = [$original, $budget, $deadline];
        try {
            $this->assertCurrent($original, $observation, $budget);
        } catch (Throwable $error) {
            unset($this->observations[$observation]);
            throw $error;
        }

        return $observation;
    }

    public function verify(MemberActivatedOriginal $original, MemberRetainedOriginalObservation $observation,
        MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): void
    {
        $this->assertCurrent($original, $observation, $budget);
        try {
            $this->files->readRetained($original, $idle, $budget, $observation);
            $this->assertCurrent($original, $observation, $budget);
        } catch (Throwable $error) {
            unset($this->observations[$observation]);
            throw $error;
        }
    }

    /** Terminal-safe private registration and original monotonic caps; no callback or physical work. */
    public function assertCurrent(MemberActivatedOriginal $original, MemberRetainedOriginalObservation $observation,
        MemberOriginalBudget $budget): void
    {
        $entry = $this->observations[$observation] ?? null;
        MemberGrantException::require(is_array($entry) && $entry[0] === $original && $entry[1] === $budget,
            'foreign_retained_observation');
        $budget->assertCurrent();
        MemberGrantException::require(\hrtime(true) < $entry[2], 'physical_observation_deadline');
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_retained_observer');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_retained_observer');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_retained_observer');
    }
}
