<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Rights\LicenseSourceVariables;

/** Buyer-facing projection only. Caller must establish current public eligibility first. */
final class PublicLicenseDisclosure
{
    public function fromRevision(OfferRevision $revision): array
    {
        $license = $revision->snapshot['license'];
        $source = $license['authored_source'];
        $terms = $license['structured_terms'];

        return [
            'offerId' => (string) $revision->offer_id,
            'offerRevisionId' => (string) $revision->id,
            'licenseVersionId' => (string) $license['id'],
            'name' => $license['name'],
            'version' => $license['version'],
            'type' => $license['type'],
            'features' => $license['features'],
            'deliverableRoles' => $license['required_asset_roles'],
            'termsText' => $terms['schema_version'] === 1 ? $source : app(LicenseSourceVariables::class)->render($source, $terms),
        ];
    }
}
