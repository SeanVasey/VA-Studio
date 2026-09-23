<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Rights\LicenseDisclosure;

/** Buyer-facing projection only. Caller must establish current public eligibility first. */
final class PublicLicenseDisclosure
{
    public function fromRevision(OfferRevision $revision): array
    {
        $data = [
            'offerId' => (string) $revision->offer_id,
            'offerRevisionId' => (string) $revision->id,
        ] + app(LicenseDisclosure::class)->fromSnapshot($revision->snapshot['license']);

        return $revision->snapshot['schema_version'] === 2 ? $data + ['testOnly' => true] : $data;
    }
}
