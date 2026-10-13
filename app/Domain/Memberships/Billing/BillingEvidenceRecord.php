<?php

namespace App\Domain\Memberships\Billing;

use App\Support\CanonicalJson;

/** Authenticated retained columns for new observations and hints; old records remain diagnostic. */
final class BillingEvidenceRecord
{
    public static function encrypt(array $row, array $payload): string
    {
        unset($row['payload_ciphertext'], $row['seal']);

        return BillingValues::encrypt(['schema_version' => 2, ...$payload, 'row_binding' => $row]);
    }

    public static function verify(array $row, string $purpose): array
    {
        BillingException::require(isset($row['seal'], $row['payload_ciphertext'])
            && hash_equals(BillingValues::seal($row), $row['seal']), 'tampered_ledger');
        $payload = BillingValues::decrypt($row['payload_ciphertext']);
        unset($row['payload_ciphertext'], $row['seal']);
        BillingException::require(($payload['schema_version'] ?? null) === 2 && ($payload['purpose'] ?? null) === $purpose
            && isset($payload['row_binding']) && is_array($payload['row_binding'])
            && CanonicalJson::encode($payload['row_binding']) === CanonicalJson::encode($row), 'legacy_or_changed_evidence');

        return $payload;
    }
}
