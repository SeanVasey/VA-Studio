<?php

namespace App\Domain\Memberships\Billing;

use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use WeakMap;

/**
 * Billing evidence rows. Subscription bindings are read here; the separate MFA staff approval writer
 * retains v2 approval records. Invoice identities are deduplicated by
 * the provider reference hash, and observations are appended in short transactions as a contiguous
 * hash-linked chain. Nothing is updated, deleted, renewed or reversed, and nothing awards a credit.
 */
final class BillingLedger
{
    /** Technical evidence freshness bound for a retrieval, not a billing or entitlement policy. */
    public const FRESHNESS_SECONDS = 600;

    public const MAX_OBSERVATIONS = 1000;

    private const ZERO = '0000000000000000000000000000000000000000000000000000000000000000';

    private WeakMap $starts;

    private static ?WeakMap $intervals = null;

    public function __construct()
    {
        $this->starts = new WeakMap;
    }

    public function __clone(): void
    {
        throw new BillingException('foreign_retrieval');
    }

    /** @return array{row: array, payload: array, expectation: BillingExpectation} */
    public function binding(string $bindingId, array $configuration): array
    {
        $row = $this->one(BillingSchema::TABLES[0], 'id = ?', [$bindingId]);
        BillingException::require($row !== null, 'binding_absent');

        return BillingSubscriptionRecord::read($row, $configuration);
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
        return $this->retainInvoice($binding, $invoiceRef, null);
    }

    /** The operative first claim keeps both original endpoints locked across identity creation. */
    public function invoiceForRetrieval(array $binding, string $invoiceRef, BillingRetrievalInterval $interval): array
    {
        self::outsideTransactions();
        BillingException::require(self::$intervals !== null && isset(self::$intervals[$interval]) && self::$intervals[$interval]['issuer'] === $this, 'foreign_retrieval');

        return DB::transaction(function () use ($binding, $invoiceRef, $interval): array {
            self::assertIssuedInterval($interval, DB::connection()->getPdo());

            return $this->retainInvoice($binding, $invoiceRef, $interval);
        });
    }

    private function retainInvoice(array $binding, string $invoiceRef, ?BillingRetrievalInterval $interval): array
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
            'created_at' => BillingDatabaseClock::sample(DB::connection()->getPdo(), DB::getDriverName())->stamp19()];
        $row['seal'] = BillingValues::seal($row);
        try {
            DB::transaction(function () use ($row, $interval): void {
                if ($interval !== null) {
                    self::assertIssuedInterval($interval, DB::connection()->getPdo());
                }
                $this->insert(BillingSchema::TABLES[1], $row);
            });
        } catch (PDOException $error) {
            // A concurrent first retrieval inserted the identity: re-read it, never a second row.
            $existing = $this->invoiceIdentity($refHash, $binding, $interval !== null);
            BillingException::require($existing !== null, 'invoice_identity');

            return $existing;
        }

        return $this->invoiceIdentity($refHash, $binding) ?? throw new BillingException('invoice_identity');
    }

    /** Commit an anonymous, uniquely identified start before the first provider read. */
    public function startRetrieval(): BillingRetrievalStart
    {
        self::outsideTransactions();
        $issued = DB::transaction(function (): array {
            $source = self::source();
            $row = self::allocate($source['pdo'], $source['driver'], 'retrieval', 'start', bin2hex(random_bytes(32)));

            return [...$source, 'row' => $row];
        });
        self::assertSource($issued, $issued['pdo']);
        $row = $issued['row'];
        $start = new BillingRetrievalStart($row['id'], $row['allocation_token'], $row['retrieval_token'], $row['database_at']);
        $this->starts[$start] = $issued;

        return $start;
    }

    /** Commit an end only for this issuer's unchanged original start, after all provider reads. */
    public function endRetrieval(BillingRetrievalStart $start): BillingRetrievalInterval
    {
        self::outsideTransactions();
        BillingException::require(isset($this->starts[$start]), 'foreign_retrieval');
        $issued = $this->starts[$start];
        $end = DB::transaction(function () use ($start, $issued): array {
            $pdo = DB::connection()->getPdo();
            self::assertSource($issued, $pdo);
            $current = self::readPosition($pdo, $issued['driver'], $start->position, true);
            BillingException::require($current === $issued['row'], 'changed_retrieval');
            $end = self::allocate($pdo, $issued['driver'], 'retrieval', 'end', $start->retrievalToken);
            $interval = new BillingRetrievalInterval($start, $end['id'], $end['allocation_token'], $end['database_at']);
            BillingRetrievalInterval::verifyRows($interval->columns(), $current, $end);
            self::assertSource($issued, $pdo);

            return $end;
        });
        self::assertSource($issued, $issued['pdo']);
        unset($this->starts[$start]);
        $interval = new BillingRetrievalInterval($start, $end['id'], $end['allocation_token'], $end['database_at']);
        self::$intervals ??= new WeakMap;
        self::$intervals[$interval] = [...$issued, 'issuer' => $this, 'end' => $end];

        return $interval;
    }

    /** Hint allocation remains inside its caller's transaction; retrieval allocation requires the typed issuer path. */
    public function position(string $kind): int
    {
        BillingException::require($kind === 'hint', 'invalid_value');

        return $this->hintPosition()['id'];
    }

    /** Exact native allocation receipt; caller retains its hint in the same transaction. */
    public function hintPosition(): array
    {
        BillingException::require(DB::transactionLevel() > 0, 'invalid_value');
        $source = self::source();

        return self::allocate($source['pdo'], $source['driver'], 'hint', 'hint', null);
    }

    /** Retained current observation validation for hint recovery; legacy rows never cover a new hint. */
    public function assertRetainedObservationInterval(array $row): void
    {
        BillingEvidenceRecord::verify($row, BillingEvidenceRecord::OBSERVATION);
        $source = self::source();
        $pdo = $source['pdo'];
        BillingRetrievalInterval::verifyRows($row,
            self::readPosition($pdo, $source['driver'], $row['retrieval_position'], $pdo->inTransaction()),
            self::readPosition($pdo, $source['driver'], $row['retrieval_end_position'], $pdo->inTransaction()));
    }

    /** Original in-process issuance plus exact current endpoint identity, before append or diagnostic retention. */
    public static function assertIssuedInterval(BillingRetrievalInterval $interval, PDO $pdo): array
    {
        BillingException::require(self::$intervals !== null && isset(self::$intervals[$interval]) && $pdo->inTransaction(), 'foreign_retrieval');
        $issued = self::$intervals[$interval];
        self::assertSource($issued, $pdo);
        $start = self::readPosition($pdo, $issued['driver'], $interval->start->position, true);
        $end = self::readPosition($pdo, $issued['driver'], $interval->endPosition, true);
        BillingException::require($start === $issued['row'] && $end === $issued['end'], 'changed_retrieval');
        BillingRetrievalInterval::verifyRows($interval->columns(), $start, $end);

        return [$start, $end];
    }

    /**
     * Append only after the tail's complete read interval. Overlap is concurrent_retrieval;
     * an interval wholly before the tail is superseded_retrieval. Positions order reads,
     * native database times bound freshness, and provider paid_at stays in the verdict.
     */
    public function append(array $invoice, BillingVerdict $verdict, string $provenance, BillingRetrievalInterval $interval): array
    {
        BillingException::require(in_array($provenance, ['synthetic_rehearsal', 'verified_production'], true), 'invalid_value');
        self::outsideTransactions();
        BillingException::require(self::$intervals !== null && isset(self::$intervals[$interval]) && self::$intervals[$interval]['issuer'] === $this, 'foreign_retrieval');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($invoice, $verdict, $provenance, $interval): array {
                    $pdo = DB::connection()->getPdo();
                    [$start, $end] = self::assertIssuedInterval($interval, $pdo);
                    $position = $start['id'];
                    $endPosition = $end['id'];
                    if (DB::getDriverName() === 'mysql') {
                        $this->lockIdentity($invoice['id']);
                    }
                    $chain = $this->observations($invoice['id']);
                    $last = $chain === [] ? null : $chain[array_key_last($chain)];
                    BillingException::require(count($chain) < self::MAX_OBSERVATIONS, 'technical_bound');
                    if ($last !== null && $last['retrieval_end_position'] >= $position) {
                        throw new BillingException($endPosition < $last['retrieval_position'] ? 'superseded_retrieval' : 'concurrent_retrieval');
                    }
                    $readEnd = BillingDatabaseInstant::fromNativeValue($end['database_at'], 0);
                    $now = BillingDatabaseClock::sample($pdo, DB::getDriverName());
                    $createdAt = $now->stamp19();
                    BillingException::require($now->utcMicros >= $readEnd->utcMicros, 'clock');
                    $legacyTail = $last !== null && ($last['retrieval_token'] ?? null) === null
                        && ($last['start_allocation_token'] ?? null) === null && ($last['end_allocation_token'] ?? null) === null;
                    BillingException::require($last === null || $legacyTail || $last['created_at'] <= $createdAt, 'clock');
                    $facts = ['outcome' => $verdict->outcome, 'reason' => $verdict->reason, 'facts' => $verdict->facts];
                    $row = ['id' => BillingValues::id(), 'invoice_id' => $invoice['id'], 'sequence' => ($last['sequence'] ?? 0) + 1,
                        'outcome' => $verdict->outcome, 'facts_hash' => CanonicalJson::hash($facts),
                        'line_period_start' => $verdict->facts['line_period_start'] ?? null, 'line_period_end' => $verdict->facts['line_period_end'] ?? null,
                        'amount_minor' => $verdict->outcome === 'settled' ? $verdict->facts['amount_minor'] : null,
                        'currency' => $verdict->outcome === 'settled' ? $verdict->facts['currency'] : null,
                        'retrieved_at' => $readEnd->stamp19(), 'retrieval_started_at' => $start['database_at'],
                        ...$interval->columns(), 'freshness_deadline' => BillingValues::utc($readEnd->seconds() + self::FRESHNESS_SECONDS),
                        'api_version' => BillingProviderPin::API_VERSION, 'sdk_reference' => BillingProviderPin::SDK_REFERENCE,
                        'prior_seal' => $last['seal'] ?? self::ZERO,
                        'created_at' => $createdAt];
                    $row['payload_ciphertext'] = BillingEvidenceRecord::encrypt($row, ['purpose' => 'production_membership_billing_observation',
                        ...$facts, 'provenance' => $provenance]);
                    $row['seal'] = BillingValues::seal($row);
                    BillingRetrievalInterval::verifyRows($row, ...self::assertIssuedInterval($interval, $pdo));
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
        $previous = null;
        $priorVersion = null;
        foreach ($rows as $index => $row) {
            BillingException::require($row['sequence'] === $index + 1 && hash_equals($prior, $row['prior_seal']), 'tampered_ledger');
            $payload = BillingEvidenceRecord::read($row, BillingEvidenceRecord::OBSERVATION);
            BillingException::require($row['retrieval_position'] > ($previous['retrieval_end_position'] ?? 0)
                && $row['retrieval_end_position'] > $row['retrieval_position'], 'tampered_ledger');
            if ($previous !== null) {
                $transition = $priorVersion < 3 && $payload['schema_version'] === 3;
                BillingException::require(($priorVersion !== 3 || $payload['schema_version'] === 3)
                    && ($transition || $previous['created_at'] <= $row['created_at']), 'tampered_ledger');
            }
            $prior = $row['seal'];
            $previous = $row;
            $priorVersion = $payload['schema_version'];
        }

        return $rows;
    }

    /**
     * The latest observation only if it is a settled one still inside its original freshness deadline.
     * A newer non-settled observation therefore hides an older settled one, and no deadline is renewed.
     */
    public function currentSettled(string $invoiceId): ?array
    {
        $rows = $this->observations($invoiceId);
        $latest = $rows === [] ? null : $rows[array_key_last($rows)];
        if ($latest === null || $latest['outcome'] !== 'settled') {
            return null;
        }
        $payload = BillingEvidenceRecord::read($latest, BillingEvidenceRecord::OBSERVATION);
        if ($payload['schema_version'] !== 3) {
            return null;
        }
        $source = self::source();
        $pdo = $source['pdo'];
        $start = self::readPosition($pdo, $source['driver'], $latest['retrieval_position'], $pdo->inTransaction());
        $end = self::readPosition($pdo, $source['driver'], $latest['retrieval_end_position'], $pdo->inTransaction());
        BillingRetrievalInterval::verifyRows($latest, $start, $end);
        $now = BillingDatabaseClock::sample($pdo, $source['driver']);
        $readEnd = BillingDatabaseInstant::fromNativeValue($end['database_at'], 0);

        return $now->utcMicros >= $readEnd->utcMicros && $now->stamp19() < $latest['freshness_deadline'] ? $latest : null;
    }

    private static function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            BillingException::require($connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction(), 'transaction_open');
        }
    }

    private static function source(): array
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        BillingDatabaseClock::assertNative($pdo, $driver);
        (new BillingRetrievalSchema)->assertOwned($pdo);
        $schema = $driver === 'mysql' ? $pdo->query('SELECT DATABASE()')->fetchColumn() : 'main';
        BillingException::require(is_string($schema) && preg_match('/\A[a-zA-Z0-9_]+\z/D', $schema) === 1, 'changed_primary');

        return ['pdo' => $pdo, 'driver' => $driver, 'schema' => $schema];
    }

    private static function assertSource(array $issued, PDO $pdo): void
    {
        $connection = DB::connection();
        BillingException::require($connection->getPdo() === $pdo && $pdo === $issued['pdo']
            && $connection->getDriverName() === $issued['driver'], 'changed_primary');
        BillingDatabaseClock::assertNative($pdo, $issued['driver']);
        $schema = $issued['driver'] === 'mysql' ? $pdo->query('SELECT DATABASE()')->fetchColumn() : 'main';
        BillingException::require($schema === $issued['schema'], 'changed_primary');
        (new BillingRetrievalSchema)->assertOwned($pdo);
    }

    private static function allocate(PDO $pdo, string $driver, string $kind, string $phase, ?string $retrievalToken): array
    {
        BillingException::require($pdo->inTransaction(), 'transaction_open');
        BillingDatabaseClock::assertNative($pdo, $driver);
        $table = (new BillingSchema)->table(BillingSchema::TABLES[4]);
        $token = bin2hex(random_bytes(32));
        $statement = $pdo->prepare('INSERT INTO '.$table.' (kind, created_at, allocation_token, retrieval_token, phase, database_at) '
            .'SELECT ?, SUBSTR(issued_at, 1, 19), ?, ?, ?, issued_at FROM (SELECT '.BillingDatabaseClock::expression($driver).' AS issued_at) AS billing_clock');
        $statement->execute([$kind, $token, $retrievalToken, $phase]);
        BillingException::require($statement->rowCount() === 1, 'ambiguous_effect');
        $id = $pdo->lastInsertId();
        BillingException::require(is_string($id) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) === 1 && (string) (int) $id === $id, 'position');
        $row = self::readPosition($pdo, $driver, (int) $id, true);
        BillingException::require($row['kind'] === $kind && $row['allocation_token'] === $token
            && $row['retrieval_token'] === $retrievalToken && $row['phase'] === $phase, 'position');
        $at = BillingDatabaseInstant::fromNativeValue($row['database_at'], 0);
        BillingException::require($row['created_at'] === $at->stamp19(), 'database_clock');

        return $row;
    }

    private static function readPosition(PDO $pdo, string $driver, int $id, bool $locking): array
    {
        $statement = $pdo->prepare('SELECT * FROM '.(new BillingSchema)->table(BillingSchema::TABLES[4]).' WHERE id = ?'
            .($driver === 'mysql' && $locking ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        BillingException::require(count($rows) === 1, 'changed_retrieval');

        return BillingValues::integers($rows[0], ['id']);
    }

    /** Serializes appends to one invoice's chain. Held only for the append transaction. */
    private function lockIdentity(string $invoiceId): void
    {
        $statement = DB::connection()->getPdo()->prepare('SELECT id FROM '.(new BillingSchema)->table(BillingSchema::TABLES[1]).' WHERE id = ? FOR UPDATE');
        $statement->execute([$invoiceId]);
        BillingException::require(count($statement->fetchAll(PDO::FETCH_COLUMN)) === 1, 'invoice_identity');
    }

    private function invoiceIdentity(string $refHash, array $binding, bool $current = false): ?array
    {
        $row = $this->one(BillingSchema::TABLES[1], 'invoice_ref_hash = ?', [$refHash], $current);
        if ($row === null) {
            return null;
        }
        BillingException::require(hash_equals(BillingValues::seal($row), $row['seal']), 'tampered_ledger');
        BillingException::require($row['subscription_binding_id'] === $binding['row']['id'], 'conflicting_invoice');

        return $row;
    }

    private function one(string $table, string $where, array $bindings, bool $current = false): ?array
    {
        $rows = $this->rows($table, $where, $bindings, 'id', 2, $current);
        BillingException::require(count($rows) <= 1, 'ambiguous_source');

        return $rows[0] ?? null;
    }

    private function rows(string $table, string $where, array $bindings, string $order, int $limit, bool $current = false): array
    {
        $pdo = DB::connection()->getPdo();
        (new BillingRetrievalSchema)->assertOwned($pdo);
        $statement = $pdo->prepare('SELECT * FROM '.(new BillingSchema)->table($table).' WHERE '.$where.' ORDER BY '.$order.' LIMIT '.$limit
            .($current && DB::getDriverName() === 'mysql' && $pdo->inTransaction() ? ' FOR UPDATE' : ''));
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
