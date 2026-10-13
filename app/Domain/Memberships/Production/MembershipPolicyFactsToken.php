<?php

namespace App\Domain\Memberships\Production;

/** Values alone are not authority: only the issuing producer's private object map admits this instance. */
final class MembershipPolicyFactsToken implements MembershipPolicyFactsProof
{
    private function __construct(private readonly MembershipPolicyBinding $facts) {}

    /** @internal Minting a value does not register it with any authority. */
    public static function value(MembershipPolicyBinding $facts): self
    {
        return new self($facts);
    }

    public function binding(): MembershipPolicyBinding
    {
        return $this->facts;
    }

    public function __clone(): void
    {
        throw new MembershipException('clone_refused');
    }

    public function __serialize(): never
    {
        throw new MembershipException('serialization_refused');
    }

    public function __unserialize(array $data): never
    {
        throw new MembershipException('serialization_refused');
    }

    public function jsonSerialize(): never
    {
        throw new MembershipException('serialization_refused');
    }

    public function __debugInfo(): array
    {
        return ['policy_proof' => true];
    }
}
