<?php

namespace App\Domain\Grants\Member;

use JsonSerializable;

/** Its value methods are not admission; the original source issuer must retain this exact object. */
final readonly class MemberOriginalSourceClaim implements JsonSerializable
{
    private function __construct(private MemberOriginalSourceValues $source, private MemberOriginalBudget $originalBudget) {}

    public static function value(MemberOriginalSourceValues $source, MemberOriginalBudget $budget): self
    {
        return new self($source, $budget);
    }

    public function values(): MemberOriginalSourceValues
    {
        return $this->source;
    }

    public function budget(): MemberOriginalBudget
    {
        return $this->originalBudget;
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_proof');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_proof');
    }

    public function jsonSerialize(): never
    {
        throw new MemberGrantException('private_proof');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_token');
    }

    public function __debugInfo(): array
    {
        return ['member_original_source_claim' => true];
    }
}
