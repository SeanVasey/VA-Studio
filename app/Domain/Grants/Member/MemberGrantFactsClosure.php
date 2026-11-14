<?php

namespace App\Domain\Grants\Member;

/** Empty handle; all authority stays in the one concrete issuer's private weak registry. */
final class MemberGrantFactsClosure implements \JsonSerializable
{
    public function __clone(): void
    {
        throw new MemberGrantException('clone_refused');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('serialization_refused');
    }

    public function jsonSerialize(): never
    {
        throw new MemberGrantException('serialization_refused');
    }

    public function __debugInfo(): array
    {
        return ['member_facts_closure' => 'opaque'];
    }
}
