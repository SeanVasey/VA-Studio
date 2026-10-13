<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Billing\BillingAwardFence;
use App\Domain\Memberships\Billing\BillingEvidenceRecord;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Billing\BillingSubscriptionRecord;
use App\Domain\Memberships\Billing\BillingValues;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use PDO;

/** Bounded retained billing audit on one captured primary. No provider I/O and no authority mint. */
final class MembershipBillingEvidence
{
    private const ZERO = '0000000000000000000000000000000000000000000000000000000000000000';

    private const INTEGER_COLUMNS = ['account_id', 'user_id', 'identity_origin_id', 'sequence', 'amount_minor',
        'retrieval_position', 'retrieval_end_position', 'hint_position'];

    /** Caller takes T23 identity locks first. The fixed fence precedes every membership/billing lock. */
    public static function lock(MembershipRows $rows, string $locator, array $configuration): array
    {
        $rows->assertCurrent();
        (new BillingAwardFence)->lock($rows->identity());
        $evidence = self::read($rows->identity(), $rows->driver(), $locator, $configuration, true);
        $rows->assertCurrent();

        return $evidence;
    }

    public static function currentSnapshot(MembershipRows $rows, MembershipInvoiceBinding $expected, array $configuration): array
    {
        MembershipException::require($expected->invoiceId !== null, 'invoice_facts_absent');
        $evidence = self::lock($rows, $expected->invoiceId, $configuration);
        self::same($expected, $evidence['binding']);

        return $evidence['snapshot'];
    }

    public static function committed(IdentityCommittedFrame $frame, MembershipInvoiceBinding $expected, array $configuration, int $originalDeadlineNs): array
    {
        $frame->assertActive();
        MembershipException::require($expected->invoiceId !== null && hrtime(true) < $originalDeadlineNs, 'expired_invoice_proof');
        $reader = $frame->reader();
        $evidence = self::read($reader->identityPrimary(), $reader->identityDriver(), $expected->invoiceId, $configuration, false);
        self::same($expected, $evidence['binding']);
        $frame->assertActive();
        MembershipException::require(hrtime(true) < $originalDeadlineNs, 'expired_invoice_proof');

        return $evidence['snapshot'];
    }

    /** Only native PDO and already-built SQL; no clocks, decryptors or container resolution. */
    public static function assertCurrentSnapshot(MembershipRows $rows, array $snapshot): void
    {
        self::assertSnapshot($rows->identity(), $snapshot);
    }

    /** Only native PDO and already-built SQL; the caller finishes its original committed frame. */
    public static function assertCommittedSnapshot(IdentityCommittedFrame $frame, array $snapshot): void
    {
        self::assertSnapshot($frame->reader()->identityPrimary(), $snapshot);
    }

    public static function same(MembershipInvoiceBinding $expected, MembershipInvoiceBinding $actual): void
    {
        MembershipException::require(CanonicalJson::encode(get_object_vars($expected)) === CanonicalJson::encode(get_object_vars($actual)), 'changed_invoice_facts');
    }

    private static function read(PDO $pdo, string $driver, string $locator, array $configuration, bool $locking): array
    {
        MembershipValues::id($locator);
        BillingProviderPin::assertInstalled();
        $schema = new BillingSchema;
        $schema->assertOwned($pdo);
        $snapshot = ['pdo' => $pdo, 'queries' => []];
        $invoiceTable = $schema->table(BillingSchema::TABLES[1]);
        $subscriptionTable = $schema->table(BillingSchema::TABLES[0]);
        // Immutable routing only. All authority rows are subsequently read in the fixed lock order.
        $route = self::query($pdo, 'SELECT * FROM '.$invoiceTable.' WHERE id = ? LIMIT 2', [$locator]);
        MembershipException::require(count($route) === 1, 'invoice_facts_absent');
        $subscriptionRoute = self::query($pdo, 'SELECT * FROM '.$subscriptionTable.' WHERE id = ? LIMIT 2', [$route[0]['subscription_binding_id']]);
        MembershipException::require(count($subscriptionRoute) === 1, 'invoice_facts_absent');
        $suffix = $locking && $driver === 'mysql' ? ' FOR UPDATE' : '';
        $fence = self::capture($pdo, $snapshot, 'SELECT * FROM '.(new BillingAwardFence)->table().' WHERE id = 1'.$suffix, []);
        MembershipException::require(count($fence) === 1, 'invoice_facts_absent');
        $plans = self::capture($pdo, $snapshot, 'SELECT * FROM '.(new MembershipSchema)->table(MembershipRecords::TABLE).' WHERE id = ? LIMIT 2'.$suffix, [$subscriptionRoute[0]['plan_version_id']]);
        MembershipException::require(count($plans) === 1, 'policy_facts_absent');
        $document = MembershipPolicyVersions::document($plans[0]);
        $subscriptions = self::capture($pdo, $snapshot, 'SELECT * FROM '.$subscriptionTable.' WHERE id = ? LIMIT 2'.$suffix, [$subscriptionRoute[0]['id']]);
        MembershipException::require(count($subscriptions) === 1, 'invoice_facts_absent');
        $approval = BillingSubscriptionRecord::verify($subscriptions[0], $configuration);
        $approved = $approval['payload'];
        MembershipException::require($approved['plan_version_id'] === $plans[0]['id']
            && $approved['plan_policy_hash'] === $document->hash() && $approved['original_terms_hash'] === $document->termsHash()
            && $document->hash() === $configuration['approved_subscription_policy_hash']
            && $document->facts['provenance'] === $configuration['provenance']
            && $document->facts['billing_price_minor'] === $approved['amount_minor']
            && $document->facts['billing_currency'] === $approved['currency'], 'policy_not_approved');
        $invoices = self::capture($pdo, $snapshot, 'SELECT * FROM '.$invoiceTable.' WHERE id = ? LIMIT 2'.$suffix, [$locator]);
        MembershipException::require(count($invoices) === 1, 'invoice_facts_absent');
        $invoice = $invoices[0];
        $invoicePayload = self::invoice($invoice, $approval);
        $chain = self::capture($pdo, $snapshot, 'SELECT * FROM '.$schema->table(BillingSchema::TABLES[2]).' WHERE invoice_id = ? ORDER BY sequence LIMIT '.(BillingLedger::MAX_OBSERVATIONS + 1).$suffix, [$locator]);
        MembershipException::require($chain !== [] && count($chain) <= BillingLedger::MAX_OBSERVATIONS, 'technical_bound');
        $prior = self::ZERO;
        $priorEnd = 0;
        foreach ($chain as $index => $observation) {
            MembershipException::require($observation['invoice_id'] === $locator && $observation['sequence'] === $index + 1
                && $observation['prior_seal'] === $prior && hash_equals(BillingValues::seal($observation), $observation['seal'])
                && $observation['retrieval_position'] > $priorEnd && $observation['retrieval_end_position'] > $observation['retrieval_position'], 'tampered_ledger');
            $payload = BillingValues::decrypt($observation['payload_ciphertext']);
            MembershipException::require(in_array($payload['schema_version'] ?? null, [1, 2], true)
                && ($payload['purpose'] ?? null) === 'production_membership_billing_observation'
                && ($payload['provenance'] ?? null) === $configuration['provenance']
                && ($payload['outcome'] ?? null) === $observation['outcome']
                && CanonicalJson::hash(['outcome' => $payload['outcome'], 'reason' => $payload['reason'] ?? null, 'facts' => $payload['facts'] ?? null]) === $observation['facts_hash'], 'tampered_ledger');
            if ($payload['schema_version'] === 2) {
                BillingEvidenceRecord::verify($observation, 'production_membership_billing_observation');
            }
            $prior = $observation['seal'];
            $priorEnd = $observation['retrieval_end_position'];
        }
        $tail = $chain[array_key_last($chain)];
        $payload = BillingEvidenceRecord::verify($tail, 'production_membership_billing_observation');
        MembershipException::require($tail['outcome'] === 'settled' && $payload['reason'] === null
            && $tail['api_version'] === BillingProviderPin::API_VERSION && $tail['sdk_reference'] === BillingProviderPin::SDK_REFERENCE, 'invoice_not_settled');
        $facts = $payload['facts'];
        MembershipException::require(is_array($facts), 'invoice_facts_absent');
        MembershipPolicyDocument::keys($facts, ['invoice_ref', 'subscription_ref', 'customer_ref', 'price_ref', 'provenance',
            'invoice_payment_ref', 'payment_intent_ref', 'charge_ref', 'balance_transaction_ref', 'line_period_start', 'line_period_end',
            'amount_minor', 'currency', 'paid_facts_version', 'paid_at']);
        MembershipException::require($facts['paid_facts_version'] === 'membership-paid-invoice-v2' && $facts['invoice_ref'] === $invoicePayload['invoice_ref']
            && $facts['subscription_ref'] === $approved['subscription_ref'] && $facts['customer_ref'] === $approved['customer_ref']
            && $facts['price_ref'] === $approved['price_ref'] && $facts['amount_minor'] === $approved['amount_minor']
            && $facts['currency'] === $approved['currency'] && $facts['provenance'] === $configuration['provenance'], 'invoice_binding_mismatch');
        foreach (['invoice_payment', 'payment_intent', 'charge', 'balance_transaction'] as $kind) {
            MembershipException::require(BillingValues::is($kind, $facts[$kind.'_ref']), 'invoice_facts_absent');
        }
        foreach (['line_period_start', 'line_period_end', 'amount_minor', 'currency'] as $key) {
            MembershipException::require($tail[$key] === $facts[$key], 'tampered_ledger');
        }
        foreach (['retrieved_at', 'freshness_deadline'] as $key) {
            MembershipValues::utc($tail[$key]);
        }
        MembershipValues::utc($facts['paid_at']);
        $retrieved = new \DateTimeImmutable($tail['retrieved_at'], new \DateTimeZone('UTC'));
        MembershipException::require($facts['paid_at'] <= $tail['retrieved_at']
            && $retrieved->modify('+'.BillingLedger::FRESHNESS_SECONDS.' seconds')->format('Y-m-d H:i:s') === $tail['freshness_deadline'], 'invalid_freshness');
        $hints = self::capture($pdo, $snapshot, 'SELECT * FROM '.$schema->table(BillingSchema::TABLES[3]).' WHERE invoice_ref_hash = ? ORDER BY id LIMIT '.(MembershipPolicy::MAX_EVENTS + 1).$suffix, [$invoice['invoice_ref_hash']]);
        MembershipException::require(count($hints) <= MembershipPolicy::MAX_EVENTS, 'technical_bound');
        foreach ($hints as $hint) {
            $hintPayload = BillingEvidenceRecord::verify($hint, 'production_membership_billing_event_hint');
            MembershipException::require($hint['disposition'] === 'retrieval_hint' && $hint['mode'] === $configuration['mode']
                && $hint['provider_account_hash'] === $invoice['provider_account_hash'] && $hintPayload['invoice_ref'] === $facts['invoice_ref']
                && BillingValues::is('event', $hintPayload['event_ref'] ?? null)
                && $hint['provider_event_ref_hash'] === BillingValues::hash('event', $configuration['account_ref'], $configuration['mode'], $hintPayload['event_ref'])
                && $hint['type'] === $hintPayload['type'] && $hintPayload['api_version'] === BillingProviderPin::API_VERSION, 'tampered_ledger');
            MembershipException::require($hint['hint_position'] < $tail['retrieval_position'], 'uncovered_invoice_hint');
        }
        $graph = CanonicalJson::hash(['purpose' => 'membership-paid-invoice-graph-v1', 'facts' => $facts,
            'approval_binding_hash' => $subscriptions[0]['approval_binding_hash'], 'plan_policy_hash' => $document->hash(),
            'original_terms_hash' => $document->termsHash(), 'original_buyer_binding' => $approved['original_buyer_binding']]);
        $binding = new MembershipInvoiceBinding($invoice['source_invoice_hash'], $graph, $invoice['provider_account_hash'], $facts['amount_minor'],
            $facts['currency'], $facts['line_period_start'], $facts['line_period_end'], $document->hash(), $document->termsHash(),
            $approved['original_buyer_binding'], $facts['provenance'], $facts['paid_at'], $approved['plan_version_id'], $locator, $tail['freshness_deadline'], $tail['id'], $tail['seal']);
        // Clock hooks precede the caller's terminal raw snapshot/configuration/frame comparisons.
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        MembershipException::require($tail['retrieved_at'] <= $now && $now < $tail['freshness_deadline'], 'expired_invoice_proof');
        self::assertSnapshot($pdo, $snapshot);

        return ['binding' => $binding, 'snapshot' => $snapshot];
    }

    private static function invoice(array $row, array $approval): array
    {
        MembershipException::require(hash_equals(BillingValues::seal($row), $row['seal']), 'tampered_ledger');
        $payload = BillingValues::decrypt($row['payload_ciphertext']);
        MembershipPolicyDocument::keys($payload, ['schema_version', 'purpose', 'invoice_ref', 'subscription_binding_id']);
        $expected = $approval['expectation'];
        MembershipException::require($payload['schema_version'] === 1 && $payload['purpose'] === 'production_membership_billing_invoice'
            && BillingValues::is('invoice', $payload['invoice_ref']) && $row['subscription_binding_id'] === $approval['row']['id']
            && $payload['subscription_binding_id'] === $row['subscription_binding_id'] && $row['mode'] === $expected->mode
            && $row['provider_account_hash'] === $approval['row']['provider_account_hash']
            && $row['invoice_ref_hash'] === BillingValues::hash('invoice', $expected->accountRef, $expected->mode, $payload['invoice_ref'])
            && $row['source_invoice_hash'] === BillingValues::hash('source-invoice', $expected->accountRef, $expected->mode, $payload['invoice_ref']), 'invoice_binding_mismatch');

        return $payload;
    }

    private static function capture(PDO $pdo, array &$snapshot, string $sql, array $parameters): array
    {
        $rows = self::query($pdo, $sql, $parameters);
        $snapshot['queries'][] = ['sql' => $sql, 'parameters' => $parameters, 'rows' => $rows];

        return $rows;
    }

    private static function query(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return array_map(fn (array $row): array => BillingValues::integers($row, self::INTEGER_COLUMNS), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function assertSnapshot(PDO $pdo, array $snapshot): void
    {
        MembershipException::require($snapshot['pdo'] === $pdo, 'changed_primary');
        foreach ($snapshot['queries'] as $query) {
            MembershipException::require(self::query($pdo, $query['sql'], $query['parameters']) === $query['rows'], 'changed_invoice_facts');
        }
    }
}
