<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Support\CanonicalJson;

/** Fixed immutable commitments; no decrypted payload, framework query or application callback. */
final class IdentityEvidence
{
    public const EMPTY_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private const FIELDS = [
        'challenge' => ['public_id', 'address_id', 'purpose', 'provenance', 'identity_policy_version', 'identity_policy_hash',
            'request_hash', 'recipient_hmac', 'payload_hash', 'proof_hash', 'bound_user_id', 'bound_account_id',
            'bound_origin_id', 'bound_access_version', 'bound_credential_binding', 'availability', 'created_at', 'expires_at'],
        'origin' => ['public_id', 'account_id', 'user_id', 'address_id', 'initial_challenge_id', 'provenance',
            'identity_policy_version', 'identity_policy_hash', 'owner_digest', 'recipient_hmac', 'created_at'],
        'verification' => ['public_id', 'origin_id', 'account_id', 'user_id', 'challenge_id', 'sequence', 'purpose',
            'provenance', 'identity_policy_version', 'identity_policy_hash', 'recipient_hmac', 'proof_hash',
            'credential_binding', 'completion_hash', 'challenge_hash', 'prior_observation_hash', 'created_at'],
        'notice' => ['public_id', 'challenge_id', 'challenge_hash', 'template_version', 'provenance',
            'identity_policy_version', 'identity_policy_hash', 'created_at'],
    ];

    private const INTEGERS = ['address_id', 'bound_user_id', 'bound_account_id', 'bound_origin_id', 'bound_access_version',
        'account_id', 'user_id', 'initial_challenge_id', 'origin_id', 'challenge_id', 'sequence'];

    /** New evidence is committed with the current key only. */
    public static function hash(string $kind, array $row): string
    {
        return IdentityPolicy::digest('evidence-'.$kind, self::value($kind, $row));
    }

    /** Retained evidence verifies under any configured key, so a routine key rotation does not orphan it. */
    public static function verify(string $kind, array $row, string $column): void
    {
        if (! is_string($row[$column] ?? null) || ! IdentityPolicy::matches('evidence-'.$kind, self::value($kind, $row), $row[$column])) {
            throw new IdentityException;
        }
    }

    private static function value(string $kind, array $row): string
    {
        $fields = self::FIELDS[$kind] ?? throw new IdentityException;
        $value = ['schema_version' => 1, 'kind' => $kind, 'canonicalization_version' => CanonicalJson::VERSION];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $row) || ! is_scalar($row[$field])) {
                throw new IdentityException;
            }
            $value[$field] = in_array($field, self::INTEGERS, true) ? (int) $row[$field] : (string) $row[$field];
        }

        return CanonicalJson::encode($value);
    }
}
