<?php

namespace App\Domain\Commerce\ProductionFinancialInspection;

use JsonSerializable;
use LogicException;

/** Unattested input consistency only. Never payment, refund, access, rights or inventory authority. */
final readonly class FinancialCandidate implements JsonSerializable
{
    private function __construct(private array $summary) {}

    public static function capture(FinancialExpectation $expectation, array $raw): self
    {
        return new self(FinancialObservation::normalize($expectation, $raw));
    }

    public function summary(): array
    {
        FinancialInspectionPolicy::capture()->prove();

        return $this->summary;
    }

    public function __clone(): never
    {
        throw new LogicException('Financial candidates cannot be cloned.');
    }

    public function __serialize(): never
    {
        throw new LogicException('Financial candidates cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Financial candidates cannot be reconstructed.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Use the bounded unattested financial summary.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => FinancialInspectionPolicy::PURPOSE, 'providerAuthenticated' => false];
    }
}
