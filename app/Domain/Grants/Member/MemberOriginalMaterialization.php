<?php

namespace App\Domain\Grants\Member;

use JsonSerializable;

/** Opaque identity only. An exact private issuer registration is the authority. */
final class MemberOriginalMaterialization implements JsonSerializable
{
    private function __construct() {}

    public static function value(): self
    {
        return new self;
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
        return ['member_original_proof' => true];
    }
}
