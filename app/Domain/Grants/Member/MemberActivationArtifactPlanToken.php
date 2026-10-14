<?php

namespace App\Domain\Grants\Member;

/** Provisional values only; public original readiness never accepts this distinct token. */
final readonly class MemberActivationArtifactPlanToken implements MemberOriginalArtifactReceipt
{
    private function __construct(private MemberOriginalArtifactManifest $manifest) {}

    public static function value(MemberOriginalArtifactManifest $manifest): self
    {
        return new self($manifest);
    }

    public function binding(): MemberOriginalArtifactManifest
    {
        return $this->manifest;
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_proof');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_proof');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_token');
    }

    public function jsonSerialize(): never
    {
        throw new MemberGrantException('private_proof');
    }

    public function __debugInfo(): array
    {
        return ['provisional_member_artifact' => true];
    }
}
