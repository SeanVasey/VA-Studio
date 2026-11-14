<?php

namespace App\Domain\Grants\Member;

/** The DTO never proves readiness. The exact issuer, source, producers and live frame do. */
final readonly class MemberOriginalReadyToken implements MemberOriginalArtifactReceipt
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
        return ['member_original_ready' => true];
    }
}
