<?php

namespace App\Domain\Memberships\Billing;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Which retained `retrieval_hint` events still need a retrieval, and for which binding. Shared by the webhook intake (a duplicate
 * delivery re-dispatches a lost hint) and the operator sweep command, so both judge coverage identically. Read-only: it writes no
 * row, reads no provider and dispatches nothing.
 */
final class BillingHintRecovery
{
    /**
     * The retrieval an event's hint still needs, or null when the event is not a hint, names no bound subscription or invoice
     * identity, or is already covered. "Covered" means the invoice's identity row has a definitive observation (see observedAfter())
     * from a retrieval that began after the hint was received. An older retrieval, an `unknown` or `provider_incomplete`
     * observation, and an identity row with no observation do not cover it.
     *
     * @return array{binding_id: string, invoice_ref: string}|null
     */
    public function pending(array $event, array $configuration): ?array
    {
        if ($event['disposition'] !== 'retrieval_hint') {
            return null;
        }
        $hint = BillingValues::decrypt($event['payload_ciphertext']);
        $invoiceRef = $hint['invoice_ref'] ?? null;
        $subscriptionRef = $hint['subscription_ref'] ?? null;
        if (! BillingValues::is('invoice', $invoiceRef)
            || ! hash_equals(BillingValues::hash('invoice', $configuration['account_ref'], $configuration['mode'], $invoiceRef), (string) $event['invoice_ref_hash'])) {
            return null;
        }
        $binding = $this->bindingForHint($event['type'], $invoiceRef, $subscriptionRef, $configuration['account_ref'], $configuration['mode']);
        if ($binding === null || $this->observedAfter($event['invoice_ref_hash'], $event['hint_position'])) {
            return null;
        }

        return ['binding_id' => $binding, 'invoice_ref' => $invoiceRef];
    }

    /**
     * Which approved binding a hint selects (retrieval proves it). An invoice event names its subscription. An InvoicePayment
     * (`invoice_payment.*`) has no invoice parent, so it takes the binding of the identity row the invoice already has; with
     * no identity row the hint is only retained and nothing is dispatched.
     */
    public function bindingForHint(string $type, string $invoiceRef, mixed $subscriptionRef, string $account, string $mode): ?string
    {
        if (BillingValues::is('subscription', $subscriptionRef)) {
            return $this->bindingFor(BillingValues::hash('subscription', $account, $mode, $subscriptionRef));
        }
        if (! str_starts_with($type, 'invoice_payment.')) {
            return null;
        }
        $schema = new BillingSchema;
        $statement = DB::connection()->getPdo()->prepare('SELECT subscription_binding_id FROM '.$schema->table(BillingSchema::TABLES[1]).' WHERE invoice_ref_hash = ? LIMIT 2');
        $statement->execute([BillingValues::hash('invoice', $account, $mode, $invoiceRef)]);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        BillingException::require(count($ids) <= 1, 'ambiguous_source');

        return isset($ids[0]) && is_string($ids[0]) ? $ids[0] : null;
    }

    /**
     * Whether a definitive observation from a retrieval that began after the hint was received was appended. The ledger's outcomes are settled,
     * not_settled, refused and reversed (each a retrieved provider state, so each covers a hint) and unknown (a timeout or
     * ambiguous response). `unknown` never covers, and neither does a `refused` observation whose reason is `provider_incomplete`:
     * the provider's unbounded list meant no state was read. Otherwise a transient provider outage would hide the hint, leaving a
     * paid invoice unreconciled until some different event arrived. This reads the reason from the encrypted payload, so only
     * `refused` rows are decrypted.
     *
     * Order (Addendum 1, A1-3; Codex P1 on PR #54, review L2-3): the observation's retrieval position against the hint's position,
     * both issued by the database from one counter, never the append time and never an application clock. A retrieval whose provider
     * reads began before the hint may have read state older than the hint even if it was appended after it, and a worker's or intake
     * host's skewed clock cannot make it look later. The retrieval position is committed before its first provider read and the
     * hint's is allocated after the hint arrived, so a larger retrieval position means the provider reads followed the hint's
     * receipt. Positions are unique, so the whole-second margin of A1-6 is no longer needed. Coverage uses the START position only:
     * the end position (allocated after the reads) orders appends and never decides coverage, since a retrieval whose reads ended
     * after the hint but began before it may have read state older than the hint (Codex P1 on PR #54, `BillingReconciliation.php:42`).
     */
    private function observedAfter(string $invoiceRefHash, mixed $hintPosition): bool
    {
        $hintPosition = is_string($hintPosition) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $hintPosition) === 1 ? (int) $hintPosition : $hintPosition;
        BillingException::require(is_int($hintPosition) && $hintPosition > 0, 'tampered_ledger');
        $schema = new BillingSchema;
        $statement = DB::connection()->getPdo()->prepare('SELECT o.outcome, o.payload_ciphertext FROM '.$schema->table(BillingSchema::TABLES[2]).' o JOIN '
            .$schema->table(BillingSchema::TABLES[1]).' i ON i.id = o.invoice_id WHERE i.invoice_ref_hash = ? AND o.retrieval_position > ? AND o.outcome <> ? '
            .'ORDER BY o.sequence LIMIT '.BillingLedger::MAX_OBSERVATIONS);
        $statement->execute([$invoiceRefHash, $hintPosition, 'unknown']);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['outcome'] !== 'refused') {
                return true;
            }
            try {
                if ((BillingValues::decrypt($row['payload_ciphertext'])['reason'] ?? null) !== 'provider_incomplete') {
                    return true;
                }
            } catch (BillingException) {
                // An unreadable payload proves nothing; retrieving again is safe and the job audits the chain.
            }
        }

        return false;
    }

    private function bindingFor(string $subscriptionHash): ?string
    {
        $statement = DB::connection()->getPdo()->prepare('SELECT id FROM '.(new BillingSchema)->table(BillingSchema::TABLES[0]).' WHERE subscription_ref_hash = ? LIMIT 2');
        $statement->execute([$subscriptionHash]);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        BillingException::require(count($ids) <= 1, 'ambiguous_source');

        return isset($ids[0]) && is_string($ids[0]) ? $ids[0] : null;
    }
}
