<?php

namespace App\Domain\Memberships\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Append-only diagnostics for a first provider retrieval that could not establish invoice ownership.
 * These rows never cover hints, qualify as observations or confer paid-invoice authority. A later
 * retrieval, including one under another correct binding, remains free to claim the invoice.
 */
final class BillingRetrievalAttempts
{
    private const REASONS = ['provider_unavailable', 'provider_inconsistent', 'provider_incomplete',
        'binding_refused_account', 'binding_refused_invoice_identity', 'binding_refused_mode',
        'binding_refused_customer', 'binding_refused_subscription'];

    public function record(array $binding, string $invoiceRef, string $reason, int $start, int $end, ?string $hintEventId, array $configuration): array
    {
        BillingException::require(BillingValues::is('invoice', $invoiceRef) && in_array($reason, self::REASONS, true)
            && $start > 0 && $end > $start && ($hintEventId === null
                || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $hintEventId) === 1), 'invalid_attempt');
        foreach (DB::getConnections() as $connection) {
            BillingException::require($connection->transactionLevel() === 0, 'transaction_open');
        }

        return DB::transaction(function () use ($binding, $invoiceRef, $reason, $start, $end, $hintEventId, $configuration): array {
            $policy = new BillingPolicy;
            $policy->proveConfiguration($configuration);
            $current = (new BillingLedger)->binding($binding['row']['id'], $configuration);
            BillingException::require(hash_equals($current['row']['seal'], $binding['row']['seal']), 'binding_mismatch');
            $account = $current['expectation']->accountRef;
            $mode = $current['expectation']->mode;
            $invoiceHash = BillingValues::hash('invoice', $account, $mode, $invoiceRef);
            $schema = new BillingSchema;
            $pdo = DB::connection()->getPdo();
            $schema->assertOwned($pdo);
            if ($hintEventId !== null) {
                $statement = $pdo->prepare('SELECT * FROM '.$schema->table(BillingSchema::TABLES[3]).' WHERE id = ?');
                $statement->execute([$hintEventId]);
                $hint = $statement->fetch(PDO::FETCH_ASSOC);
                BillingException::require(is_array($hint), 'attempt_hint');
                $hint = BillingValues::integers($hint, ['hint_position']);
                BillingException::require(hash_equals(BillingValues::seal($hint), $hint['seal'])
                    && $hint['disposition'] === 'retrieval_hint' && $hint['mode'] === $mode
                    && $hint['provider_account_hash'] === $current['row']['provider_account_hash']
                    && $hint['invoice_ref_hash'] === $invoiceHash && $hint['hint_position'] < $start, 'attempt_hint');
            }
            $row = ['id' => BillingValues::id(), 'subscription_binding_id' => $current['row']['id'],
                'invoice_ref_hash' => $invoiceHash, 'provider_account_hash' => $current['row']['provider_account_hash'],
                'mode' => $mode, 'provenance' => $current['row']['provenance'], 'hint_event_id' => $hintEventId,
                'retrieval_position' => $start, 'retrieval_end_position' => $end,
                'outcome' => in_array($reason, ['provider_unavailable', 'provider_inconsistent'], true) ? 'unknown' : 'refused',
                'reason' => $reason,
                'payload_ciphertext' => BillingValues::encrypt(['schema_version' => 1, 'purpose' => 'production_membership_billing_attempt',
                    'invoice_ref' => $invoiceRef, 'subscription_binding_id' => $current['row']['id']]),
                'created_at' => BillingValues::utc(CarbonImmutable::now('UTC')->timestamp)];
            $row['seal'] = BillingValues::seal($row);
            $statement = $pdo->prepare('INSERT INTO '.$schema->table(BillingSchema::TABLES[5]).' ('.implode(', ', array_keys($row))
                .') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
            $statement->execute(array_values($row));
            BillingException::require($statement->rowCount() === 1, 'ambiguous_effect');
            $policy->proveConfiguration($configuration);

            return $row;
        }, 3);
    }
}
