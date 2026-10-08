<?php

namespace App\Domain\Memberships\Billing;

use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * Billing evidence rows. Subscription bindings are read and seal-checked here but never written
 * (the MFA staff approval writer is not part of this lane). Invoice identities are deduplicated by
 * the provider reference hash, and observations are appended in short transactions as a contiguous
 * hash-linked chain. Nothing is updated, deleted, renewed or reversed, and nothing awards a credit.
 */
final class BillingLedger
{
    /** Technical evidence freshness bound for a retrieval, not a billing or entitlement policy. */
    public const FRESHNESS_SECONDS = 600;

    public const MAX_OBSERVATIONS = 1000;

    private const ZERO = '0000000000000000000000000000000000000000000000000000000000000000';

    /** @return array{row: array, payload: array, expectation: BillingExpectation} */
    public function binding(string $bindingId, array $configuration): array
    {
        $row = $this->one(BillingSchema::TABLES[0], 'id = ?', [$bindingId]);
        BillingException::require($row !== null && hash_equals(BillingValues::seal($row), $row['seal']), 'binding_absent');
        $payload = BillingValues::decrypt($row['payload_ciphertext']);
        $keys = array_keys($payload);
        sort($keys);
        BillingException::require($keys === ['account_ref', 'amount_minor', 'currency', 'customer_ref', 'mode', 'plan_version_id', 'price_ref',
            'purpose', 'schema_version', 'subscription_ref'] && $payload['schema_version'] === 1
            && $payload['purpose'] === 'production_membership_billing_subscription', 'binding_payload');
        $expectation = new BillingExpectation($payload['account_ref'], $payload['mode'], $payload['customer_ref'], $payload['subscription_ref'],
            $payload['price_ref'], $payload['currency'], $payload['amount_minor']);
        $account = $expectation->accountRef;
        $mode = $expectation->mode;
        BillingException::require($account === $configuration['account_ref'] && $mode === $configuration['mode']
            && $row['mode'] === $mode && $row['provenance'] === $configuration['provenance'] && $row['plan_version_id'] === $payload['plan_version_id']
            && hash_equals(BillingValues::hash('provider-account', $account, $mode, $account), $row['provider_account_hash'])
            && hash_equals(BillingValues::hash('customer', $account, $mode, $expectation->customerRef), $row['customer_ref_hash'])
            && hash_equals(BillingValues::hash('subscription', $account, $mode, $expectation->subscriptionRef), $row['subscription_ref_hash'])
            && hash_equals(BillingValues::hash('price', $account, $mode, $expectation->priceRef), $row['price_ref_hash']), 'binding_mismatch');

        return ['row' => $row, 'payload' => $payload, 'expectation' => $expectation];
    }

    /** The identity row this binding already owns for the invoice, or null. Never inserts; another binding's row conflicts. */
    public function existingInvoice(array $binding, string $invoiceRef): ?array
    {
        BillingException::require(BillingValues::is('invoice', $invoiceRef), 'invalid_value');
        $expectation = $binding['expectation'];

        return $this->invoiceIdentity(BillingValues::hash('invoice', $expectation->accountRef, $expectation->mode, $invoiceRef), $binding);
    }

    /** The same provider invoice always resolves to the same identity row; another binding conflicts. */
    public function invoice(array $binding, string $invoiceRef): array
    {
        BillingException::require(BillingValues::is('invoice', $invoiceRef), 'invalid_value');
        $expectation = $binding['expectation'];
        $account = $expectation->accountRef;
        $mode = $expectation->mode;
        $refHash = BillingValues::hash('invoice', $account, $mode, $invoiceRef);
        $existing = $this->invoiceIdentity($refHash, $binding);
        if ($existing !== null) {
            return $existing;
        }
        $row = ['id' => BillingValues::id(), 'subscription_binding_id' => $binding['row']['id'], 'invoice_ref_hash' => $refHash,
            'source_invoice_hash' => BillingValues::hash('source-invoice', $account, $mode, $invoiceRef),
            'provider_account_hash' => $binding['row']['provider_account_hash'], 'mode' => $mode,
            'payload_ciphertext' => BillingValues::encrypt(['schema_version' => 1, 'purpose' => 'production_membership_billing_invoice',
                'invoice_ref' => $invoiceRef, 'subscription_binding_id' => $binding['row']['id']]),
            'created_at' => BillingValues::utc(CarbonImmutable::now('UTC')->timestamp)];
        $row['seal'] = BillingValues::seal($row);
        try {
            DB::transaction(fn () => $this->insert(BillingSchema::TABLES[1], $row));
        } catch (PDOException $error) {
            // A concurrent first retrieval inserted the identity: re-read it, never a second row.
            $existing = $this->invoiceIdentity($refHash, $binding);
            BillingException::require($existing !== null, 'invoice_identity');

            return $existing;
        }

        return $this->invoiceIdentity($refHash, $binding) ?? throw new BillingException('invoice_identity');
    }

    /**
     * Begins one retrieval: allocates its database-issued start position in its own short committed transaction, before any provider
     * read (Codex P1 on PR #54, review L2-3). The start decides whether a webhook hint is covered (the read began after the hint) and,
     * with the end position (endRetrieval()), orders overlapping retrievals of one invoice; no application clock is compared for
     * either. The row records only that a retrieval began: it names no invoice, binding or provider reference, so it claims no
     * identity (review R-3).
     */
    public function startRetrieval(): int
    {
        return $this->committedRetrievalPosition();
    }

    /**
     * Ends one retrieval's provider reads: allocates its database-issued end position in its own short committed transaction, after
     * the last provider read and before the append (Codex P1 on PR #54, `BillingReconciliation.php:42`). A start position alone does
     * not order the reads: a retrieval can take an early start, stall, and then read newer provider state than one that started
     * later. The [start, end] interval brackets the reads, so "this read began after that read ended" is a comparison of two
     * positions from one database counter. Like the start, the row names nothing and claims no identity.
     */
    public function endRetrieval(int $start): int
    {
        BillingException::require($start > 0, 'invalid_value');
        $end = $this->committedRetrievalPosition();
        BillingException::require($end > $start, 'position');

        return $end;
    }

    /**
     * Allocates the next database-issued position of one kind inside the caller's transaction and returns it. The database assigns
     * the id (the insert guard refuses an explicit one); AUTO_INCREMENT and SQLite AUTOINCREMENT hand ids out in strictly increasing
     * order of allocation and never reuse a committed one, so a larger position was allocated later.
     */
    public function position(string $kind): int
    {
        BillingException::require(in_array($kind, BillingSchema::POSITION_KINDS, true) && DB::transactionLevel() > 0, 'invalid_value');
        $pdo = DB::connection()->getPdo();
        (new BillingSchema)->assertOwned($pdo);
        $table = (new BillingSchema)->table(BillingSchema::TABLES[4]);
        $statement = $pdo->prepare('INSERT INTO '.$table.' (kind, created_at) VALUES (?, ?)');
        $statement->execute([$kind, BillingValues::utc(CarbonImmutable::now('UTC')->timestamp)]);
        BillingException::require($statement->rowCount() === 1, 'ambiguous_effect');
        $id = $pdo->lastInsertId();
        BillingException::require(is_string($id) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) === 1, 'position');
        $check = $pdo->prepare('SELECT kind FROM '.$table.' WHERE id = ?');
        $check->execute([(int) $id]);
        BillingException::require($check->fetchAll(PDO::FETCH_COLUMN) === [$kind], 'position');

        return (int) $id;
    }

    /**
     * Append one observation after the current tail. A concurrent append retries on the new tail.
     *
     * `$position` and `$endPosition` are the retrieval's database-issued start and end positions (startRetrieval(), committed before
     * its first provider read; endRetrieval(), committed after its last), and `$startedAt` the worker's clock at the start, kept only
     * as information: it never orders anything. Overlapping retrievals of one invoice append in commit order, not in provider-read
     * order, so an older snapshot could land after a newer one and become the tail (review R-6). Under a row lock on the invoice
     * identity (MySQL; the lock covers only this short append, never provider I/O) the append admits a retrieval only when its read
     * began after the tail's read ended: its start is above the tail's end (Codex P1 on PR #54, `BillingReconciliation.php:42`).
     * Otherwise it refuses, writing nothing:
     *  - `superseded_retrieval` when the read wholly preceded the tail's (its end is below the tail's start): the tail already records
     *    a strictly later read, so this is a normal end with nothing to retry;
     *  - `concurrent_retrieval` when the two reads overlapped: the database cannot tell which read saw the later provider state, so
     *    the job retries with a fresh interval, which converges on a read that began after the tail's ended.
     * It is refused rather than appended as a "superseded" row so that the outcome vocabulary, the CHECKs and every reader of the tail
     * stay unchanged: no consumer has to learn to skip a row type, and a stale snapshot has no row to be mistaken for current evidence.
     * Positions are unique, so no two retrievals tie, and the order is the database's own, whatever the workers' clocks read.
     */
    public function append(array $invoice, BillingVerdict $verdict, int $retrievedAt, string $provenance, int $position, int $endPosition, CarbonImmutable $startedAt): array
    {
        BillingException::require(in_array($provenance, ['synthetic_rehearsal', 'verified_production'], true) && $position > 0 && $endPosition > $position, 'invalid_value');
        foreach (DB::getConnections() as $connection) {
            BillingException::require($connection->transactionLevel() === 0, 'transaction_open');
        }
        $started = BillingValues::utcMicro($startedAt);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($invoice, $verdict, $retrievedAt, $provenance, $position, $endPosition, $started): array {
                    if (DB::getDriverName() === 'mysql') {
                        $this->lockIdentity($invoice['id']);
                    }
                    $chain = $this->observations($invoice['id']);
                    $last = $chain === [] ? null : $chain[array_key_last($chain)];
                    BillingException::require(count($chain) < self::MAX_OBSERVATIONS, 'technical_bound');
                    if ($last !== null && $last['retrieval_end_position'] >= $position) {
                        throw new BillingException($endPosition < $last['retrieval_position'] ? 'superseded_retrieval' : 'concurrent_retrieval');
                    }
                    $now = CarbonImmutable::now('UTC')->timestamp;
                    $createdAt = BillingValues::utc($now);
                    BillingException::require($last === null || $last['created_at'] <= $createdAt, 'clock');
                    $facts = ['outcome' => $verdict->outcome, 'reason' => $verdict->reason, 'facts' => $verdict->facts];
                    $row = ['id' => BillingValues::id(), 'invoice_id' => $invoice['id'], 'sequence' => ($last['sequence'] ?? 0) + 1,
                        'outcome' => $verdict->outcome, 'facts_hash' => CanonicalJson::hash($facts),
                        'line_period_start' => $verdict->facts['line_period_start'] ?? null, 'line_period_end' => $verdict->facts['line_period_end'] ?? null,
                        'amount_minor' => $verdict->outcome === 'settled' ? $verdict->facts['amount_minor'] : null,
                        'currency' => $verdict->outcome === 'settled' ? $verdict->facts['currency'] : null,
                        'retrieved_at' => BillingValues::utc($retrievedAt), 'retrieval_started_at' => $started, 'retrieval_position' => $position, 'retrieval_end_position' => $endPosition, 'freshness_deadline' => BillingValues::utc($retrievedAt + self::FRESHNESS_SECONDS),
                        'api_version' => BillingProviderPin::API_VERSION, 'sdk_reference' => BillingProviderPin::SDK_REFERENCE,
                        'prior_seal' => $last['seal'] ?? self::ZERO,
                        'payload_ciphertext' => BillingValues::encrypt(['schema_version' => 1, 'purpose' => 'production_membership_billing_observation',
                            ...$facts, 'provenance' => $provenance]),
                        'created_at' => $createdAt];
                    $row['seal'] = BillingValues::seal($row);
                    $this->insert(BillingSchema::TABLES[2], $row);
                    $stored = $this->observations($invoice['id']);

                    return $stored[array_key_last($stored)];
                });
            } catch (PDOException) {
                continue;
            }
        }

        throw new BillingException('observation_contention');
    }

    /** Read-only chain audit: contiguous sequences, recomputed seals and prior links, decryptable payloads. */
    public function observations(string $invoiceId): array
    {
        $rows = $this->rows(BillingSchema::TABLES[2], 'invoice_id = ?', [$invoiceId], 'sequence', self::MAX_OBSERVATIONS + 1);
        BillingException::require(count($rows) <= self::MAX_OBSERVATIONS, 'technical_bound');
        $prior = self::ZERO;
        foreach ($rows as $index => $row) {
            BillingException::require($row['sequence'] === $index + 1 && hash_equals($prior, $row['prior_seal'])
                && hash_equals(BillingValues::seal($row), $row['seal']), 'tampered_ledger');
            $payload = BillingValues::decrypt($row['payload_ciphertext']);
            BillingException::require(($payload['outcome'] ?? null) === $row['outcome']
                && hash_equals(CanonicalJson::hash(['outcome' => $payload['outcome'], 'reason' => $payload['reason'] ?? null, 'facts' => $payload['facts'] ?? null]), $row['facts_hash']), 'tampered_ledger');
            $prior = $row['seal'];
        }

        return $rows;
    }

    /**
     * The latest observation only if it is a settled one still inside its original freshness deadline.
     * A newer non-settled observation therefore hides an older settled one, and no deadline is renewed.
     */
    public function currentSettled(string $invoiceId, int $now): ?array
    {
        $rows = $this->observations($invoiceId);
        $latest = $rows === [] ? null : $rows[array_key_last($rows)];

        return $latest !== null && $latest['outcome'] === 'settled' && BillingValues::utc($now) < $latest['freshness_deadline'] ? $latest : null;
    }

    /** One `retrieval` position committed in its own short transaction; no transaction may be open (no provider I/O inside one). */
    private function committedRetrievalPosition(): int
    {
        foreach (DB::getConnections() as $connection) {
            BillingException::require($connection->transactionLevel() === 0, 'transaction_open');
        }

        return DB::transaction(fn (): int => $this->position('retrieval'));
    }

    /** Serializes appends to one invoice's chain. Held only for the append transaction. */
    private function lockIdentity(string $invoiceId): void
    {
        $statement = DB::connection()->getPdo()->prepare('SELECT id FROM '.(new BillingSchema)->table(BillingSchema::TABLES[1]).' WHERE id = ? FOR UPDATE');
        $statement->execute([$invoiceId]);
        BillingException::require(count($statement->fetchAll(PDO::FETCH_COLUMN)) === 1, 'invoice_identity');
    }

    private function invoiceIdentity(string $refHash, array $binding): ?array
    {
        $row = $this->one(BillingSchema::TABLES[1], 'invoice_ref_hash = ?', [$refHash]);
        if ($row === null) {
            return null;
        }
        BillingException::require(hash_equals(BillingValues::seal($row), $row['seal']), 'tampered_ledger');
        BillingException::require($row['subscription_binding_id'] === $binding['row']['id'], 'conflicting_invoice');

        return $row;
    }

    private function one(string $table, string $where, array $bindings): ?array
    {
        $rows = $this->rows($table, $where, $bindings, 'id', 2);
        BillingException::require(count($rows) <= 1, 'ambiguous_source');

        return $rows[0] ?? null;
    }

    private function rows(string $table, string $where, array $bindings, string $order, int $limit): array
    {
        $pdo = DB::connection()->getPdo();
        (new BillingSchema)->assertOwned($pdo);
        $statement = $pdo->prepare('SELECT * FROM '.(new BillingSchema)->table($table).' WHERE '.$where.' ORDER BY '.$order.' LIMIT '.$limit);
        $statement->execute($bindings);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['sequence', 'amount_minor', 'account_id', 'user_id', 'identity_origin_id', 'retrieval_position', 'retrieval_end_position'] as $integer) {
                if (isset($row[$integer]) && is_string($row[$integer])) {
                    $row[$integer] = (int) $row[$integer];
                }
            }
        }

        return $rows;
    }

    private function insert(string $table, array $row): void
    {
        $statement = DB::connection()->getPdo()->prepare('INSERT INTO '.(new BillingSchema)->table($table).' ('.implode(', ', array_keys($row))
            .') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
        BillingException::require($statement->rowCount() === 1, 'ambiguous_effect');
    }
}
