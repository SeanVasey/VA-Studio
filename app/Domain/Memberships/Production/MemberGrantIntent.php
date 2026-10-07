<?php

namespace App\Domain\Memberships\Production;

use JsonSerializable;
use LogicException;

/** Server-only immutable request, not authority. Every captured producer token must be re-proved. */
final readonly class MemberGrantIntent implements JsonSerializable
{
    public const FAMILY = 'production-member-grant-v1';

    public const PURPOSE = 'production-member-license-grant-v1';

    public function __construct(
        public MembershipPaidInvoiceProof $invoice,
        public MembershipEligibleLicenseProof $license,
        public MembershipReservationProof $reservation,
    ) {}

    public function __serialize(): never
    {
        throw new LogicException('Member grant requests must not be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Member grant requests are not HTTP projections.');
    }

    private function __clone() {}

    public function __debugInfo(): array
    {
        return ['family' => self::FAMILY, 'purpose' => self::PURPOSE];
    }
}
