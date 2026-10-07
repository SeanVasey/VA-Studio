<?php

namespace App\Domain\Customers\Preferences;

use App\Support\CanonicalJson;

final class ConsentPolicy
{
    public const PURPOSE = 'email_marketing';

    public const MAX_REVISION = 2147483646;

    public function configured(): ?array
    {
        $policy = config('customer-preferences.email_marketing');
        if (! is_array($policy) || ! self::keys($policy, ['purpose', 'version', 'notice', 'review_reference'])
            || $policy['purpose'] !== self::PURPOSE || ! self::version($policy['version'])
            || ! self::text($policy['notice'], 2000, 8000)
            || ! self::text($policy['review_reference'], 200, 800)) {
            return null;
        }

        return ['purpose' => self::PURPOSE, 'version' => $policy['version'], 'notice' => $policy['notice'],
            'notice_hash' => hash('sha256', $policy['notice']), 'review_reference' => $policy['review_reference'],
            'policy_hash' => CanonicalJson::hash($policy)];
    }

    public static function keys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    public static function version(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,79}\z/D', $value) === 1;
    }

    public static function text(mixed $value, int $characters, int $bytes): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && strlen($value) <= $bytes
            && mb_strlen($value, 'UTF-8') <= $characters && preg_match('/[^\s\p{Z}]/u', $value) === 1
            && preg_match('/[\p{Cf}\p{Cc}]/u', str_replace(["\n", "\t"], '', $value)) === 0;
    }
}
