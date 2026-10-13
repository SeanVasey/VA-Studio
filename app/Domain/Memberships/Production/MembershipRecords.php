<?php

namespace App\Domain\Memberships\Production;

use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Purpose-bound encrypted policy records, with keyed seals over every retained column. */
final class MembershipRecords
{
    public const TABLE = 'production_membership_plan_versions';

    public static function encrypt(array $payload): string
    {
        $plain = CanonicalJson::encode($payload);
        MembershipException::require(strlen($plain) <= 131072, 'invalid_policy_value');

        return Crypt::encryptString($plain);
    }

    public static function seal(array $row, ?string $key = null): string
    {
        unset($row['seal']);

        return hash_hmac('sha256', CanonicalJson::encode(['purpose' => 'membership-policy-record-v1', 'table' => self::TABLE,
            'row' => self::strings($row)]), $key ?? self::keys()[0]);
    }

    public static function verify(array $row): array
    {
        MembershipPolicyDocument::keys($row, ['id', 'policy_hash', 'original_terms_hash', 'provenance', 'payload_ciphertext', 'seal', 'created_at']);
        MembershipException::require(is_string($row['seal']) && is_string($row['payload_ciphertext']) && strlen($row['payload_ciphertext']) <= 524288, 'tampered');
        $matched = false;
        foreach (self::keys() as $key) {
            $matched = hash_equals(self::seal($row, $key), $row['seal']) || $matched;
        }
        MembershipException::require($matched, 'tampered');
        try {
            $plain = Crypt::decryptString($row['payload_ciphertext']);
            $payload = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new MembershipException('tampered');
        }
        MembershipException::require(is_array($payload) && strlen($plain) <= 131072 && CanonicalJson::encode($payload) === $plain, 'tampered');

        return $payload;
    }

    public static function strings(array $row): array
    {
        ksort($row, SORT_STRING);
        foreach ($row as $value) {
            MembershipException::require($value === null || is_scalar($value), 'tampered');
        }

        return array_map(fn ($value): ?string => $value === null ? null : (string) $value, $row);
    }

    private static function keys(): array
    {
        $current = config('app.key');
        $previous = config('app.previous_keys', []);
        MembershipException::require(is_string($current) && strlen($current) >= 32 && is_array($previous), 'key_absent');
        foreach ($previous as $key) {
            MembershipException::require(is_string($key) && strlen($key) >= 32, 'key_absent');
        }

        return array_values(array_unique([$current, ...$previous]));
    }
}
