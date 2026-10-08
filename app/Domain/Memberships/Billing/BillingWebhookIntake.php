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
        // The hint parser reads the pinned object shape (for example parent.subscription_details), so an event emitted under another
        // API version would be stored yet never scheduled. A mis-versioned endpoint is a configuration error and must be loud.
        BillingException::require(($event['api_version'] ?? null) === BillingProviderPin::API_VERSION, 'api_version');
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
        $existing = $this->event($eventHash);
        if ($existing !== null) {
            return ['event' => $existing, 'duplicate' => true, 'scheduled' => $this->recoverLostDispatch($existing, $configuration, $policy)];
        }
        try {
            $row = DB::transaction(function () use ($row): array {
                // The hint's database-issued position, allocated after the hint arrived and in the same transaction as its row, so a
                // retrieval covers the hint only if its own position (committed before its provider reads) is larger. `received_at`
                // is the intake host's clock and is information only (Codex P1 on PR #54, review L2-3).
                $row['hint_position'] = (new BillingLedger)->position('hint');
                $row['seal'] = BillingValues::seal($row);
                $statement = DB::connection()->getPdo()->prepare('INSERT INTO '.(new BillingSchema)->table(BillingSchema::TABLES[3])
                    .' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
                $statement->execute(array_values($row));

                return $row;
            });
        } catch (PDOException) {
            // A concurrent delivery of the same event id won; this one is the replay.
            // The winner's post-commit dispatch may still fail, and this success would stop the provider's retries: recover it too.
            $winner = $this->event($eventHash) ?? throw new BillingException('event_identity');

            return ['event' => $winner, 'duplicate' => true, 'scheduled' => $this->recoverLostDispatch($winner, $configuration, $policy)];
        }
        $policy->proveConfiguration($configuration);
        $scheduled = null;
        if ($invoiceRef !== null) {
            $binding = (new BillingHintRecovery)->bindingForHint($type, $invoiceRef, $subscriptionRef, $account, $mode);
            if ($binding !== null) {
                $scheduled = ['binding_id' => $binding, 'invoice_ref' => $invoiceRef];
                RetrieveMembershipInvoice::dispatch($binding, $invoiceRef);
            }
        }

        return ['event' => $row, 'duplicate' => false, 'scheduled' => $scheduled];
    }

    /**
     * A hint is committed before its retrieval is dispatched, so a failed dispatch leaves a hint the provider will redeliver.
     * A duplicate delivery therefore dispatches that retrieval again unless the hint is already covered (BillingHintRecovery::pending():
     * a definitive observation from a retrieval that began after the hint). The dispatch is read-only against the provider and each
     * retrieval appends one chained observation, so a redundant dispatch is harmless and the stored event row is never touched. A hint
     * that names no bound subscription, and every non-hint event, stays unscheduled.
     *
     * @return array{binding_id: string, invoice_ref: string}|null
     */
    private function recoverLostDispatch(array $event, array $configuration, BillingPolicy $policy): ?array
    {
        $pending = (new BillingHintRecovery)->pending($event, $configuration);
        if ($pending === null) {
            return null;
        }
        $policy->proveConfiguration($configuration);
        RetrieveMembershipInvoice::dispatch($pending['binding_id'], $pending['invoice_ref']);

        return $pending;
    }

    private function event(string $eventHash): ?array
    {
        $pdo = DB::connection()->getPdo();
        (new BillingSchema)->assertOwned($pdo);
        $statement = $pdo->prepare('SELECT * FROM '.(new BillingSchema)->table(BillingSchema::TABLES[3]).' WHERE provider_event_ref_hash = ? LIMIT 2');
        $statement->execute([$eventHash]);
        $rows = array_map(fn (array $row): array => BillingValues::integers($row, ['hint_position']), $statement->fetchAll(PDO::FETCH_ASSOC));
        BillingException::require(count($rows) <= 1 && ($rows === [] || hash_equals(BillingValues::seal($rows[0]), $rows[0]['seal'])), 'tampered_ledger');

        return $rows[0] ?? null;
    }
}
