<?php

namespace App\Domain\Commerce;

use App\Domain\Rights\LicenseDisclosure;
use App\Support\CanonicalJson;

final class ReadQuoteDisclosure
{
    public function handle(string $quoteId, string $ownerKey, string $revisionId): array
    {
        // Ownership, expiry, current evidence and immutable hashes are checked
        // before looking up the requested line or exposing its retained terms.
        $quote = app(ReadQuote::class)->handle($quoteId, $ownerKey);
        $line = collect($quote->snapshot['lines'])->first(fn (array $line) => (string) $line['offer_revision_id'] === $revisionId);
        if ($line === null) {
            throw new QuoteException('QUOTE_NOT_FOUND', 404);
        }
        $disclosure = [
            'disclosureSchema' => 1,
            'quoteId' => $quote->public_id,
            'expiresAt' => $quote->expires_at->utc()->toISOString(),
            'offerId' => (string) $line['offer_id'],
            'offerRevisionId' => (string) $line['offer_revision_id'],
        ] + app(LicenseDisclosure::class)->fromSnapshot($line['offer_snapshot']['license']);

        // This fingerprints the safe disclosure only, never the private quote
        // snapshot. It is not an assent receipt or an executed buyer contract.
        return $disclosure + ['disclosureHash' => CanonicalJson::hash($disclosure)];
    }
}
