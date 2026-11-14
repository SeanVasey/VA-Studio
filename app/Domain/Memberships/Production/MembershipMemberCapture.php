<?php

namespace App\Domain\Memberships\Production;

use JsonSerializable;

/** Opaque capture identity; only its exact reservation issuer owns the Context and phases. */
final class MembershipMemberCapture implements JsonSerializable
{
    private function __construct() {}

    public static function value(): self
    {
        return new self;
    }

    public function __clone(): never
    {
        throw new MembershipException('foreign_member_capture');
    }

    public function __serialize(): never
    {
        throw new MembershipException('foreign_member_capture');
    }

    public function jsonSerialize(): never
    {
        throw new MembershipException('foreign_member_capture');
    }

    public function __unserialize(array $data): void
    {
        throw new MembershipException('private_token');
    }

    public function __debugInfo(): array
    {
        return ['member_capture' => true];
    }
}
