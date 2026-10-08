<?php

namespace App\Domain\Grants\ProductionFree;

use App\Support\CanonicalJson;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;

/** Encrypted canonical payloads and keyed row seals. A seal binds every column, including the ciphertext. */
final class ProductionFreeGrantRecords
{
    private const MAX_PLAIN = 1048576;

    public static function encrypt(array $payload): string
    {
        $plain = CanonicalJson::encode($payload);
        ProductionFreeGrantException::require(strlen($plain) <= self::MAX_PLAIN, 'payload_too_large');

        return Crypt::encryptString($plain);
    }

    public static function decrypt(array $row): array
    {
        ProductionFreeGrantException::require(is_string($row['payload_ciphertext'] ?? null) && strlen($row['payload_ciphertext']) <= 4 * self::MAX_PLAIN, 'tampered');
        try {
            $plain = Crypt::decryptString($row['payload_ciphertext']);
            $payload = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new ProductionFreeGrantException('tampered');
        }
        ProductionFreeGrantException::require(is_array($payload) && ! array_is_list($payload) && CanonicalJson::encode($payload) === $plain, 'tampered');

        return $payload;
    }

    public static function seal(string $logical, array $row): string
    {
        ProductionFreeGrantException::require(in_array($logical, ProductionFreeGrantSchema::TABLES, true), 'schema');
        unset($row['seal']);
        $key = (string) Config::get('app.key');
        ProductionFreeGrantException::require(strlen($key) >= 32, 'key_absent');

        return hash_hmac('sha256', CanonicalJson::encode(['schema' => 'production-free-seal-v1', 'table' => $logical, 'row' => self::strings($row)]), $key);
    }

    /** Seal and payload are verified together; a forged or relabelled row is refused before any use. */
    public static function verify(string $logical, array $row): array
    {
        ProductionFreeGrantException::require(is_string($row['seal'] ?? null) && hash_equals(self::seal($logical, $row), $row['seal']), 'tampered');

        return self::decrypt($row);
    }

    public static function strings(array $row): array
    {
        ksort($row, SORT_STRING);

        return array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, $row);
    }
}
