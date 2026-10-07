<?php

namespace App\Domain\Memberships\Billing;

use App\Jobs\RetrieveMembershipInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use SensitiveParameter;
use Stripe\Webhook;
use Throwable;

/**
 * Verifies the signature, deduplicates by provider event id and records a retrieval hint. A valid
 * signature is not proof of payment: the event body is never evaluated for settlement, never
 * appends an observation and never awards. Order does not matter because retrieval always reads
 * current provider state. No route is registered here; root mounts it.
 */
final class BillingWebhookIntake
{
    public const MAX_PAYLOAD_BYTES = 262144;

    public const TOLERANCE_SECONDS = 300;

    private const INVOICE_TYPES = ['invoice.created', 'invoice.finalized', 'invoice.paid', 'invoice.payment_succeeded', 'invoice.payment_failed',
        'invoice.payment_action_required', 'invoice.updated', 'invoice.voided', 'invoice.marked_uncollectible', 'invoice_payment.paid'];

    /** These name no invoice in this API version; they are retained as hints for operator reconciliation. */
    private const CHARGE_TYPES = ['charge.refunded', 'charge.dispute.created', 'charge.dispute.closed', 'charge.dispute.funds_withdrawn'];

    /** @return array{event: array, duplicate: bool, scheduled: ?array{binding_id: string, invoice_ref: string}} */
    public function receive(#[SensitiveParameter] string $payload, #[SensitiveParameter] string $signature): array
    {
        $policy = new BillingPolicy;
        $configuration = $policy->current();
        BillingException::require(strlen($payload) <= self::MAX_PAYLOAD_BYTES && strlen($signature) <= 4096, 'payload_bound');
        try {
            $event = Webhook::constructEvent($payload, $signature, $policy->webhookSecret(), self::TOLERANCE_SECONDS)->toArray();
        } catch (BillingException $error) {
            throw $error;
        } catch (Throwable) {
            throw new BillingException('signature');
        }
        $account = $configuration['account_ref'];
        $mode = $configuration['mode'];
        BillingException::require(($event['object'] ?? null) === 'event' && BillingValues::is('event', $event['id'] ?? null)
            && is_string($event['type'] ?? null) && preg_match('/\A[a-z_.]{1,64}\z/D', $event['type']) === 1
            && ($event['livemode'] ?? null) === ($mode === 'live') && ($event['account'] ?? null) === null, 'event_scope');
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        $type = $event['type'];
        $invoiceRef = null;
        $subscriptionRef = null;
        if (in_array($type, self::INVOICE_TYPES, true)) {
            $invoiceRef = $type === 'invoice_payment.paid' ? BillingValues::ref($object['invoice'] ?? null) : BillingValues::ref($object['id'] ?? null);
            $subscriptionRef = BillingValues::ref($object['parent']['subscription_details']['subscription'] ?? null);
            $disposition = BillingValues::is('invoice', $invoiceRef) ? 'retrieval_hint' : 'no_invoice_hint';
        } else {
            $disposition = in_array($type, self::CHARGE_TYPES, true) ? 'no_invoice_hint' : 'ignored_type';
        }
        $invoiceRef = $disposition === 'retrieval_hint' ? $invoiceRef : null;
        $eventHash = BillingValues::hash('event', $account, $mode, $event['id']);
        $row = ['id' => BillingValues::id(), 'provider_event_ref_hash' => $eventHash, 'type' => $type, 'mode' => $mode,
            'provider_account_hash' => BillingValues::hash('provider-account', $account, $mode, $account),
            'invoice_ref_hash' => $invoiceRef === null ? null : BillingValues::hash('invoice', $account, $mode, $invoiceRef),
            'received_at' => BillingValues::utc(CarbonImmutable::now('UTC')->timestamp), 'payload_hash' => hash('sha256', $payload),
            'disposition' => $disposition,
            'payload_ciphertext' => BillingValues::encrypt(['schema_version' => 1, 'purpose' => 'production_membership_billing_event_hint',
                'event_ref' => $event['id'], 'type' => $type, 'api_version' => is_string($event['api_version'] ?? null) ? $event['api_version'] : null,
                'created' => is_int($event['created'] ?? null) ? $event['created'] : null, 'invoice_ref' => $invoiceRef,
                'subscription_ref' => BillingValues::is('subscription', $subscriptionRef) ? $subscriptionRef : null])];
        $row['created_at'] = $row['received_at'];
        $row['seal'] = BillingValues::seal($row);
        $existing = $this->event($eventHash);
        if ($existing !== null) {
            return ['event' => $existing, 'duplicate' => true, 'scheduled' => $this->recoverLostDispatch($existing, $configuration, $policy)];
        }
        try {
            DB::transaction(function () use ($row) {
                $statement = DB::connection()->getPdo()->prepare('INSERT INTO '.(new BillingSchema)->table(BillingSchema::TABLES[3])
                    .' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
                $statement->execute(array_values($row));
            });
        } catch (PDOException) {
            // A concurrent delivery of the same event id won; this one is the replay.
            return ['event' => $this->event($eventHash) ?? throw new BillingException('event_identity'), 'duplicate' => true, 'scheduled' => null];
        }
        $policy->proveConfiguration($configuration);
        $scheduled = null;
        if ($invoiceRef !== null && BillingValues::is('subscription', $subscriptionRef)) {
            // The hinted subscription only selects which approved binding to retrieve against; retrieval proves it.
            $binding = $this->bindingFor(BillingValues::hash('subscription', $account, $mode, $subscriptionRef));
            if ($binding !== null) {
                $scheduled = ['binding_id' => $binding, 'invoice_ref' => $invoiceRef];
                RetrieveMembershipInvoice::dispatch($binding, $invoiceRef);
            }
        }

        return ['event' => $row, 'duplicate' => false, 'scheduled' => $scheduled];
    }

    /**
     * A hint is committed before its retrieval is dispatched, so a failed dispatch leaves a hint the provider will redeliver.
     * A duplicate delivery therefore dispatches that retrieval again unless the hint is already covered. "Covered" means the
     * invoice's identity row has an observation row (any outcome, including unknown or refused) created strictly after the
     * hint's received_at; an older observation, or an identity row with no observation, does not cover it. Only the observation
     * table is consulted because an observation is the one row appended after a retrieval completes. The dispatch is read-only
     * against the provider and each retrieval appends one chained observation, so a redundant dispatch is harmless and the
     * stored event row is never touched. A hint that names no bound subscription, and every non-hint event, stays unscheduled.
     *
     * @return array{binding_id: string, invoice_ref: string}|null
     */
    private function recoverLostDispatch(array $event, array $configuration, BillingPolicy $policy): ?array
    {
        if ($event['disposition'] !== 'retrieval_hint') {
            return null;
        }
        $hint = BillingValues::decrypt($event['payload_ciphertext']);
        $invoiceRef = $hint['invoice_ref'] ?? null;
        $subscriptionRef = $hint['subscription_ref'] ?? null;
        if (! BillingValues::is('invoice', $invoiceRef) || ! BillingValues::is('subscription', $subscriptionRef)
            || ! hash_equals(BillingValues::hash('invoice', $configuration['account_ref'], $configuration['mode'], $invoiceRef), (string) $event['invoice_ref_hash'])) {
            return null;
        }
        $binding = $this->bindingFor(BillingValues::hash('subscription', $configuration['account_ref'], $configuration['mode'], $subscriptionRef));
        if ($binding === null || $this->observedAfter($event['invoice_ref_hash'], $event['received_at'])) {
            return null;
        }
        $policy->proveConfiguration($configuration);
        RetrieveMembershipInvoice::dispatch($binding, $invoiceRef);

        return ['binding_id' => $binding, 'invoice_ref' => $invoiceRef];
    }

    private function observedAfter(string $invoiceRefHash, string $receivedAt): bool
    {
        $schema = new BillingSchema;
        $statement = DB::connection()->getPdo()->prepare('SELECT 1 FROM '.$schema->table(BillingSchema::TABLES[2]).' o JOIN '.$schema->table(BillingSchema::TABLES[1])
            .' i ON i.id = o.invoice_id WHERE i.invoice_ref_hash = ? AND o.created_at > ? LIMIT 1');
        $statement->execute([$invoiceRefHash, $receivedAt]);

        return $statement->fetchColumn() !== false;
    }

    private function event(string $eventHash): ?array
    {
        $pdo = DB::connection()->getPdo();
        (new BillingSchema)->assertOwned($pdo);
        $statement = $pdo->prepare('SELECT * FROM '.(new BillingSchema)->table(BillingSchema::TABLES[3]).' WHERE provider_event_ref_hash = ? LIMIT 2');
        $statement->execute([$eventHash]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        BillingException::require(count($rows) <= 1 && ($rows === [] || hash_equals(BillingValues::seal($rows[0]), $rows[0]['seal'])), 'tampered_ledger');

        return $rows[0] ?? null;
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
