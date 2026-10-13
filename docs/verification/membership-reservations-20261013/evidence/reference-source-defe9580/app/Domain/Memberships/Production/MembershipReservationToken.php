<?php

namespace App\Domain\Memberships\Production;

/** A value token is admitted only by its issuing producer's private object registry. */
final class MembershipReservationToken implements MembershipReservationProof
{
    private function __construct(private readonly MembershipReservationBinding $facts) {}

    public static function value(MembershipReservationBinding $facts): self
    {
        return new self($facts);
    }

    public function binding(): MembershipReservationBinding
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
        return ['reservation_proof' => true];
    }
}
