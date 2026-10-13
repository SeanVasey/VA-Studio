<?php

namespace App\Domain\Memberships\Billing;

use App\Support\CanonicalJson;

/** Versioned retained columns. Legacy bytes remain auditable but cannot supply database-clock freshness. */
final class BillingEvidenceRecord
{
    public const INTERVAL_COLUMNS = ['retrieval_token', 'start_allocation_token', 'end_allocation_token'];

    public const OBSERVATION = 'production_membership_billing_observation';

    public const HINT = 'production_membership_billing_event_hint';

    public const CLOCK_BASIS = 'database-retrieval-interval-v1';

    private const OBSERVATION_COLUMNS = ['id', 'invoice_id', 'sequence', 'outcome', 'facts_hash', 'line_period_start',
        'line_period_end', 'amount_minor', 'currency', 'retrieved_at', 'retrieval_started_at', 'retrieval_position',
        'retrieval_end_position', 'freshness_deadline', 'api_version', 'sdk_reference', 'prior_seal',
        'payload_ciphertext', 'seal', 'created_at'];

    private const HINT_COLUMNS = ['id', 'provider_event_ref_hash', 'type', 'mode', 'provider_account_hash', 'invoice_ref_hash',
        'received_at', 'hint_position', 'payload_hash', 'disposition', 'payload_ciphertext', 'seal', 'created_at'];

    public static function encrypt(array $row, array $payload): string
    {
        $purpose = $payload['purpose'] ?? null;
        BillingException::require(in_array($purpose, [self::OBSERVATION, self::HINT], true)
            && ! array_key_exists('schema_version', $payload) && ! array_key_exists('row_binding', $payload)
            && ! array_key_exists('clock_basis', $payload), 'invalid_evidence');
        if ($purpose === self::OBSERVATION) {
            self::requireIntervalColumns($row);
        }
        unset($row['payload_ciphertext'], $row['seal']);
        self::physicalKeys($row, $purpose, $purpose === self::OBSERVATION, false);

        return BillingValues::encrypt(['schema_version' => $purpose === self::OBSERVATION ? 3 : 2, ...$payload,
            ...($purpose === self::OBSERVATION ? ['clock_basis' => self::CLOCK_BASIS] : []), 'row_binding' => $row]);
    }

    /** Authoritative envelope admission; legacy observations and hints are diagnostic only. */
    public static function verify(array $row, string $purpose): array
    {
        $payload = self::read($row, $purpose);
        BillingException::require($payload['schema_version'] === ($purpose === self::OBSERVATION ? 3 : 2), 'legacy_or_changed_evidence');

        return $payload;
    }

    /** Audit original seals and authenticated envelopes without relabelling legacy time provenance. */
    public static function read(array $row, string $purpose): array
    {
        BillingException::require(in_array($purpose, [self::OBSERVATION, self::HINT], true)
            && is_string($row['seal'] ?? null) && is_string($row['payload_ciphertext'] ?? null), 'tampered_ledger');
        $payload = BillingValues::decrypt($row['payload_ciphertext']);
        $version = $payload['schema_version'] ?? null;
        BillingException::require(in_array($version, $purpose === self::OBSERVATION ? [1, 2, 3] : [1, 2], true)
            && ($payload['purpose'] ?? null) === $purpose, 'legacy_or_changed_evidence');
        $keys = $purpose === self::OBSERVATION ? ['schema_version', 'purpose', 'outcome', 'reason', 'facts', 'provenance']
            : ['schema_version', 'purpose', 'event_ref', 'type', 'api_version', 'created', 'invoice_ref', 'subscription_ref'];
        if ($version >= 2) {
            $keys[] = 'row_binding';
        }
        if ($purpose === self::OBSERVATION && $version === 3) {
            $keys[] = 'clock_basis';
            BillingException::require(($payload['clock_basis'] ?? null) === self::CLOCK_BASIS, 'legacy_or_changed_evidence');
        }
        self::keys($payload, $keys);
        if ($purpose === self::OBSERVATION) {
            if ($version < 3) {
                $row = self::legacyIntervalProjection($row);
            } else {
                self::requireIntervalColumns($row);
            }
        }
        self::physicalKeys($row, $purpose, $purpose === self::OBSERVATION && $version === 3, true);
        BillingException::require(hash_equals(BillingValues::seal($row), $row['seal']), 'tampered_ledger');
        if ($version >= 2) {
            $bound = $row;
            unset($bound['payload_ciphertext'], $bound['seal']);
            BillingException::require(is_array($payload['row_binding'])
                && CanonicalJson::encode($payload['row_binding']) === CanonicalJson::encode($bound), 'legacy_or_changed_evidence');
        }
        if ($purpose === self::OBSERVATION) {
            BillingException::require(($payload['outcome'] ?? null) === ($row['outcome'] ?? null)
                && is_string($row['facts_hash'] ?? null)
                && hash_equals(CanonicalJson::hash(['outcome' => $payload['outcome'], 'reason' => $payload['reason'], 'facts' => $payload['facts']]), $row['facts_hash']), 'tampered_ledger');
        }

        return $payload;
    }

    /** Only a decoder that has already recognized a legacy version may use this exact NULL projection. */
    public static function legacyIntervalProjection(array $row): array
    {
        $present = array_intersect(self::INTERVAL_COLUMNS, array_keys($row));
        BillingException::require($present === [] || count($present) === count(self::INTERVAL_COLUMNS), 'legacy_or_changed_evidence');
        foreach ($present as $key) {
            BillingException::require($row[$key] === null, 'legacy_or_changed_evidence');
            unset($row[$key]);
        }

        return $row;
    }

    public static function requireIntervalColumns(array $row): void
    {
        foreach (self::INTERVAL_COLUMNS as $key) {
            BillingException::require(is_string($row[$key] ?? null) && preg_match('/\A[0-9a-f]{64}\z/D', $row[$key]) === 1, 'retrieval_interval');
        }
        BillingException::require($row['start_allocation_token'] !== $row['end_allocation_token'], 'retrieval_interval');
    }

    private static function physicalKeys(array $row, string $purpose, bool $currentInterval, bool $retained): void
    {
        $keys = $purpose === self::OBSERVATION ? self::OBSERVATION_COLUMNS : self::HINT_COLUMNS;
        if ($currentInterval) {
            $keys = [...$keys, ...self::INTERVAL_COLUMNS];
        }
        if (! $retained) {
            $keys = array_values(array_diff($keys, ['payload_ciphertext', 'seal']));
        }
        self::keys($row, $keys);
    }

    public static function keys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        BillingException::require($actual === $keys, 'legacy_or_changed_evidence');
    }
}
