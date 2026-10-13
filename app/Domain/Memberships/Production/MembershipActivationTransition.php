<?php

namespace App\Domain\Memberships\Production;

/** Opaque identity only. A shaped object never populates its issuer's private registry. */
final class MembershipActivationTransition implements \JsonSerializable
{
    private function __construct() {}

    public static function value(): self
    {
        return new self;
    }

    public function __clone(): never
    {
        throw new MembershipException('private_activation_evidence');
    }

    public function __serialize(): never
    {
        throw new MembershipException('private_activation_evidence');
    }

    public function __unserialize(array $data): void
    {
        throw new MembershipException('private_activation_evidence');
    }

    public function jsonSerialize(): never
    {
        throw new MembershipException('private_activation_evidence');
    }

    public function __debugInfo(): array
    {
        return ['private_activation_evidence' => true];
    }
}
