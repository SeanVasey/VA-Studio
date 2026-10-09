<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use SensitiveParameter;
use Throwable;

/** Bounded encrypted immutable records. Plain hashes authenticate storage, not external facts. */
final class Evidence
{
    public const MAXIMUM_BYTES = 1048576;

    public static function seal(#[SensitiveParameter] array $body): array
    {
        $canonical = CanonicalJson::encode($body);
        CheckoutException::require(strlen($canonical) <= self::MAXIMUM_BYTES);
        $ciphertext = Crypt::encryptString($canonical);
        CheckoutException::require(strlen($ciphertext) <= 2097152);

        return ['payload_ciphertext' => $ciphertext, 'payload_hash' => hash('sha256', $ciphertext),
            'canonicalization_version' => CanonicalJson::VERSION];
    }

    public static function open(#[SensitiveParameter] array $row, string $purpose): array
    {
        try {
            CheckoutException::require(($row['canonicalization_version'] ?? null) === CanonicalJson::VERSION
                && is_string($row['payload_ciphertext'] ?? null) && strlen($row['payload_ciphertext']) <= 2097152
                && self::hash($row['payload_hash'] ?? null)
                && hash_equals($row['payload_hash'], hash('sha256', $row['payload_ciphertext'])));
            $canonical = Crypt::decryptString($row['payload_ciphertext']);
            CheckoutException::require(strlen($canonical) <= self::MAXIMUM_BYTES);
            $body = json_decode($canonical, true, 48, JSON_THROW_ON_ERROR);
            CheckoutException::require(is_array($body) && CanonicalJson::encode($body) === $canonical
                && ($body['schema_version'] ?? null) === 1 && ($body['purpose'] ?? null) === $purpose
                && ($body['public_id'] ?? null) === $row['public_id'] && ($body['created_at'] ?? null) === $row['created_at']);

            return $body;
        } catch (Throwable) {
            throw new CheckoutException('corrupt');
        }
    }

    public static function key(#[SensitiveParameter] string $key): string
    {
        CheckoutException::require(preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $key) === 1, 'invalid', 422);
        $secret = config('app.key');
        CheckoutException::require(is_string($secret) && $secret !== '');

        return hash_hmac('sha256', 'production-checkout-key-v1:'.$key, $secret);
    }

    public static function hash(mixed $hash): bool
    {
        return is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/D', $hash) === 1;
    }

    public static function same(mixed $expected, mixed $actual): void
    {
        CheckoutException::require(CanonicalJson::encode($expected) === CanonicalJson::encode($actual));
    }

    public static function keys(array $value, array $keys): void
    {
        CheckoutException::require(count($value) === count($keys) && array_diff(array_keys($value), $keys) === [], 'invalid', 422);
    }
}
