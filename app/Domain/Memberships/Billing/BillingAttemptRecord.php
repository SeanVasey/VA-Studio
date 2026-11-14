<?php

namespace App\Domain\Memberships\Billing;

use App\Support\CanonicalJson;

/** Diagnostic attempt envelopes. No version claims an invoice or covers a hint. */
final class BillingAttemptRecord
{
    public const PURPOSE = 'production_membership_billing_attempt';

    private const COLUMNS = ['id', 'subscription_binding_id', 'invoice_ref_hash', 'provider_account_hash', 'mode', 'provenance',
        'hint_event_id', 'retrieval_position', 'retrieval_end_position', 'outcome', 'reason', 'payload_ciphertext', 'seal', 'created_at'];

    public static function encrypt(array $row, array $payload): string
    {
        BillingEvidenceRecord::requireIntervalColumns($row);
        BillingEvidenceRecord::keys($payload, ['invoice_ref', 'subscription_binding_id']);
        unset($row['payload_ciphertext'], $row['seal']);
        BillingEvidenceRecord::keys($row, [...array_diff(self::COLUMNS, ['payload_ciphertext', 'seal']), ...BillingEvidenceRecord::INTERVAL_COLUMNS]);

        return BillingValues::encrypt(['schema_version' => 2, 'purpose' => self::PURPOSE, ...$payload,
            'clock_basis' => BillingEvidenceRecord::CLOCK_BASIS, 'row_binding' => $row]);
    }

    public static function verify(array $row): array
    {
        $payload = self::read($row);
        BillingException::require($payload['schema_version'] === 2, 'legacy_or_changed_evidence');

        return $payload;
    }

    public static function read(array $row): array
    {
        BillingException::require(is_string($row['seal'] ?? null) && is_string($row['payload_ciphertext'] ?? null), 'tampered_ledger');
        $payload = BillingValues::decrypt($row['payload_ciphertext']);
        $version = $payload['schema_version'] ?? null;
        BillingException::require(in_array($version, [1, 2], true) && ($payload['purpose'] ?? null) === self::PURPOSE, 'legacy_or_changed_evidence');
        BillingEvidenceRecord::keys($payload, $version === 1 ? ['schema_version', 'purpose', 'invoice_ref', 'subscription_binding_id']
            : ['schema_version', 'purpose', 'invoice_ref', 'subscription_binding_id', 'clock_basis', 'row_binding']);
        if ($version === 1) {
            $row = BillingEvidenceRecord::legacyIntervalProjection($row);
        } else {
            BillingEvidenceRecord::requireIntervalColumns($row);
            BillingException::require($payload['clock_basis'] === BillingEvidenceRecord::CLOCK_BASIS, 'legacy_or_changed_evidence');
        }
        BillingEvidenceRecord::keys($row, $version === 1 ? self::COLUMNS : [...self::COLUMNS, ...BillingEvidenceRecord::INTERVAL_COLUMNS]);
        BillingException::require(hash_equals(BillingValues::seal($row), $row['seal'])
            && BillingValues::is('invoice', $payload['invoice_ref'])
            && $payload['subscription_binding_id'] === ($row['subscription_binding_id'] ?? null), 'tampered_ledger');
        if ($version === 2) {
            unset($row['payload_ciphertext'], $row['seal']);
            BillingException::require(is_array($payload['row_binding'])
                && CanonicalJson::encode($payload['row_binding']) === CanonicalJson::encode($row), 'legacy_or_changed_evidence');
        }

        return $payload;
    }
}
