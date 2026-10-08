<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use DateTimeImmutable;
use DateTimeZone;

/** Value validation only. These values never confer invoice, policy, license or grant authority. */
final class MembershipValues
{
    public static function hash(string $value): void
    {
        MembershipException::require(preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1, 'invalid_value');
    }

    public static function id(string $value): void
    {
        MembershipException::require(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $value) === 1, 'invalid_value');
    }

    public static function utc(string $value): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        MembershipException::require($parsed !== false && $parsed->format('Y-m-d H:i:s') === $value, 'invalid_value');
    }

    public static function provenance(string $value): void
    {
        MembershipException::require(in_array($value, [IdentityPolicy::REHEARSAL, IdentityPolicy::PRODUCTION], true), 'invalid_value');
    }

    public static function buyer(array $binding): void
    {
        $keys = array_keys($binding);
        sort($keys);
        MembershipException::require($keys === ['account_id', 'account_public_id', 'identity_policy_hash', 'identity_policy_version',
            'origin_id', 'provenance', 'schema_version', 'user_id', 'verification_observation_hash', 'verification_observation_id']
            && $binding['schema_version'] === 1 && $binding['identity_policy_version'] === IdentityPolicy::VERSION
            && is_int($binding['account_id']) && $binding['account_id'] > 0 && is_int($binding['user_id']) && $binding['user_id'] > 0, 'invalid_buyer_binding');
        self::provenance($binding['provenance']);
        foreach (['origin_id', 'account_public_id', 'verification_observation_id'] as $key) {
            self::id($binding[$key]);
        }
        self::hash($binding['verification_observation_hash']);
        self::hash($binding['identity_policy_hash']);
    }
}
