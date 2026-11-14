<?php

namespace App\Support\Http;

/**
 * The reviewed reverse-proxy allowlist (`config/trusted-proxies.php`). Only explicit IPv4/IPv6 addresses or CIDR ranges are
 * admitted: no wildcard (`*`, `**`), no `REMOTE_ADDR` alias, no hostname and no range wider than /8 (IPv4) or /16 (IPv6), so
 * the list can never mean "trust whoever connects". A blank or unset value trusts no proxy. Any malformed entry invalidates the
 * whole list (null): the middleware then trusts none and the production validator refuses the configuration.
 */
final class TrustedProxyList
{
    public const MAXIMUM_ENTRIES = 32;

    /** @return list<string>|null the admitted entries, [] for none, or null when the value is not a valid list */
    public static function parse(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (! is_string($value) || strlen($value) > 4096) {
            return null;
        }
        $entries = array_map(trim(...), explode(',', $value));
        if (count($entries) > self::MAXIMUM_ENTRIES) {
            return null;
        }
        foreach ($entries as $entry) {
            if (! self::entry($entry)) {
                return null;
            }
        }

        return array_values(array_unique($entries));
    }

    private static function entry(string $entry): bool
    {
        if (preg_match('~\A([0-9A-Fa-f:.]{2,45})(?:/([0-9]{1,3}))?\z~D', $entry, $match) !== 1) {
            return false;
        }
        $address = $match[1];
        $v4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $v6 = ! $v4 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (! $v4 && ! $v6) {
            return false;
        }
        if (! isset($match[2])) {
            return true;
        }
        if ($match[2] !== '0' && str_starts_with($match[2], '0')) {
            return false;
        }
        $prefix = (int) $match[2];

        return $v4 ? $prefix >= 8 && $prefix <= 32 : $prefix >= 16 && $prefix <= 128;
    }
}
