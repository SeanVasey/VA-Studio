<?php

namespace App\Domain\Grants\Member;

/** Opaque identity only. A shaped object never populates its issuer's private registry. */
final class MemberLicenseAuthorizationClaim implements \JsonSerializable
{
    private function __construct() {}

    public static function value(): self
    {
        return new self;
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_activation_evidence');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_activation_evidence');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_activation_evidence');
    }

    public function jsonSerialize(): never
    {
        throw new MemberGrantException('private_activation_evidence');
    }

    public function __debugInfo(): array
    {
        return ['private_activation_evidence' => true];
    }
}
