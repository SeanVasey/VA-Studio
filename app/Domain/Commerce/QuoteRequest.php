<?php

namespace App\Domain\Commerce;

final class QuoteRequest
{
    public static function owner(string $ownerKey): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $ownerKey)) {
            throw new QuoteException('INVALID_QUOTE_REQUEST', 422);
        }
    }

    public static function key(string $key): void
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $key)) {
            throw new QuoteException('INVALID_QUOTE_REQUEST', 422);
        }
    }

    public static function items(array $items): array
    {
        if (! array_is_list($items) || count($items) < 1 || count($items) > 10) {
            throw new QuoteException('INVALID_QUOTE_REQUEST', 422);
        }
        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item) || count($item) !== 4 || array_diff(array_keys($item), ['trackId', 'offerId', 'licenseVersionId', 'offerRevisionId']) !== []) {
                throw new QuoteException('INVALID_QUOTE_REQUEST', 422);
            }
            foreach ($item as $field => $id) {
                // Bound by the largest exact JavaScript integer; reject coercion, leading zeros and exponent notation.
                if ((! is_int($id) && ! is_string($id)) || ! preg_match('/\A[1-9][0-9]{0,15}\z/D', (string) $id) || $id > 9007199254740991) {
                    throw new QuoteException('INVALID_QUOTE_REQUEST', 422);
                }
                $item[$field] = (int) $id;
            }
            if (isset($normalized[$item['trackId']])) {
                throw new QuoteException('INVALID_QUOTE_REQUEST', 422);
            }
            $normalized[$item['trackId']] = $item;
        }
        ksort($normalized, SORT_NUMERIC);

        return array_values($normalized);
    }
}
