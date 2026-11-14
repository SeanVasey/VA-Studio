<?php

namespace App\Domain\Grants\Member;

/** Opaque handle. Only the issuer's private registry gives this object meaning. */
final class MemberGrantSchemaSnapshot implements \JsonSerializable
{
    public function __clone(): void
    {
        throw new MemberGrantException('schema_snapshot');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('schema_snapshot');
    }

    public function __unserialize(array $data): never
    {
        throw new MemberGrantException('schema_snapshot');
    }

    public function jsonSerialize(): never
    {
        throw new MemberGrantException('schema_snapshot');
    }

    public function __debugInfo(): array
    {
        return ['member_schema_snapshot' => true];
    }
}
