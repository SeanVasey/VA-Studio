<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Rights\LicenseDisclosure;

/** Buyer-facing projection only. Caller must establish current public eligibility first. */
final class PublicLicenseDisclosure
{
    public function fromRevision(OfferRevision $revision): array
    {
        return [
            'offerId' => (string) $revision->offer_id,
            'offerRevisionId' => (string) $revision->id,
        ] + app(LicenseDisclosure::class)->fromSnapshot($revision->snapshot['license']);
    }
}
