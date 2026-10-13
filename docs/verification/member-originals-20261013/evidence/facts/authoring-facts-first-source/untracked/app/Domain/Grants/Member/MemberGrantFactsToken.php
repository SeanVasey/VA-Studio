<?php

namespace App\Domain\Grants\Member;

/** Public value construction is harmless: only the concrete issuer's private registration admits it. */
final readonly class MemberGrantFactsToken implements MemberGrantFactsProof
{
    public function __construct(private MemberGrantFactsBinding $value) {}

    public function binding(): MemberGrantFactsBinding
    {
        return $this->value;
    }

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
        return ['member_facts' => 'opaque'];
    }
}
