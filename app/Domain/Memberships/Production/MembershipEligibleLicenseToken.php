<?php

namespace App\Domain\Memberships\Production;

/** Only the issuing adapter's private object registry admits this value as proof. */
final class MembershipEligibleLicenseToken implements MembershipEligibleLicenseProof
{
    private function __construct(private readonly MembershipLicenseBinding $facts) {}

    public static function value(MembershipLicenseBinding $facts): self
    {
        return new self($facts);
    }

    public function binding(): MembershipLicenseBinding
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
        return ['eligible_license_proof' => true];
    }
}
