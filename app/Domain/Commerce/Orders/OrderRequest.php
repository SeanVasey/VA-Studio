<?php

namespace App\Domain\Commerce\Orders;

use App\Domain\Commerce\QuoteException;
use SensitiveParameter;

final class OrderRequest
{
    public static function uuid(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $id) === 1;
    }

    public static function normalize(#[SensitiveParameter] array $request): array
    {
        if (! OrderPolicy::keys($request, ['quoteId', 'reviewHash', 'buyer', 'accepted']) ||
            ! self::uuid($request['quoteId']) || $request['accepted'] !== true ||
            ! is_string($request['reviewHash']) || preg_match('/\A[a-f0-9]{64}\z/D', $request['reviewHash']) !== 1 ||
            ! is_array($request['buyer']) || ! OrderPolicy::keys($request['buyer'], ['legalName', 'email'])) {
            throw new QuoteException('INVALID_ORDER_REQUEST', 422);
        }
        $name = $request['buyer']['legalName']; $email = $request['buyer']['email'];
        if (! is_string($name) || ! mb_check_encoding($name, 'UTF-8') ||
            preg_match('/[\p{C}]/u', $name) !== 0 || mb_strlen(trim($name)) < 1 || mb_strlen(trim($name)) > 160 ||
            ! is_string($email) || strlen($email) > 254 || preg_match('/[\x00-\x1f\x7f]/', $email) !== 0 ||
            filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            throw new QuoteException('INVALID_ORDER_REQUEST', 422);
        }

        // Keep case and meaningful internal spacing. Supplied contact information establishes no account or consent.
        return ['quoteId' => $request['quoteId'], 'reviewHash' => $request['reviewHash'],
            'buyer' => ['legalName' => trim($name), 'email' => trim($email)], 'accepted' => true];
    }
}
