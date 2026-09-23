<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Models\Quote;
use App\Domain\Rights\LicenseDisclosure;
use App\Support\CanonicalJson;

/** Projection only. Callers must authorize and verify the quote before exposing it. */
final class QuoteLicenseDisclosure
{
    public function fromLine(Quote $quote, array $line): array
    {
        $disclosure = [
            'disclosureSchema' => $quote->snapshot['schema_version'] === 2 ? 2 : 1, 'quoteId' => $quote->public_id,
            'expiresAt' => $quote->expires_at->utc()->toISOString(),
            'offerId' => (string) $line['offer_id'], 'offerRevisionId' => (string) $line['offer_revision_id'],
        ] + app(LicenseDisclosure::class)->fromSnapshot($line['offer_snapshot']['license']);
        if ($quote->snapshot['schema_version'] === 2) { $disclosure['testOnly'] = true; }

        return $disclosure + ['disclosureHash' => CanonicalJson::hash($disclosure)];
    }
}
