<?php

namespace App\Domain\Grants\PaidTax;

/** Exact small native attachment POST; decoded duplicates/aliases are refused before CSRF. */
final class PaidTaxForm
{
    public static function body(string $raw): array
    {
        PaidTaxException::require(strlen($raw) <= 4096, 413);
        $body = [];
        foreach (explode('&', $raw) as $field) {
            PaidTaxException::require(substr_count($field, '=') === 1 && ! preg_match('/%(?![0-9a-fA-F]{2})/', $field), 422);
            [$key, $value] = array_map('urldecode', explode('=', $field, 2));
            PaidTaxException::require(in_array($key, ['token', '_token'], true) && ! array_key_exists($key, $body), 422);
            $body[$key] = $value;
        }
        PaidTaxException::require(count($body) === 2 && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $body['token'] ?? '') === 1
            && preg_match('/\A[A-Za-z0-9]{40}\z/D', $body['_token'] ?? '') === 1, 422);

        return $body;
    }
}
