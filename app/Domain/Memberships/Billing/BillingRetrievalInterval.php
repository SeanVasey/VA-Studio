<?php

namespace App\Domain\Memberships\Billing;

/** Value only; a ledger-issued object must also pass its private registry and current-row checks. */
final readonly class BillingRetrievalInterval implements \JsonSerializable
{
    public function __construct(public BillingRetrievalStart $start, public int $endPosition, public string $endAllocationToken, public string $databaseAt) {}

    public function columns(): array
    {
        return ['retrieval_position' => $this->start->position, 'retrieval_end_position' => $this->endPosition,
            'retrieval_token' => $this->start->retrievalToken, 'start_allocation_token' => $this->start->allocationToken,
            'end_allocation_token' => $this->endAllocationToken];
    }

    /** Pure validation. Callers capture these exact endpoint rows using their owned current-read frame. */
    public static function verifyRows(array $record, array $start, array $end): void
    {
        foreach ([$start, $end] as $endpoint) {
            BillingEvidenceRecord::keys($endpoint, ['id', 'kind', 'created_at', 'allocation_token', 'retrieval_token', 'phase', 'database_at']);
        }
        $start = BillingValues::integers($start, ['id']);
        $end = BillingValues::integers($end, ['id']);
        foreach (['retrieval_token', 'start_allocation_token', 'end_allocation_token'] as $key) {
            BillingException::require(is_string($record[$key] ?? null) && preg_match('/\A[0-9a-f]{64}\z/D', $record[$key]) === 1, 'retrieval_interval');
        }
        BillingException::require(is_int($record['retrieval_position'] ?? null) && $record['retrieval_position'] > 0
            && is_int($record['retrieval_end_position'] ?? null) && $record['retrieval_end_position'] > $record['retrieval_position']
            && ($start['id'] ?? null) === $record['retrieval_position'] && ($end['id'] ?? null) === $record['retrieval_end_position']
            && ($start['kind'] ?? null) === 'retrieval' && ($end['kind'] ?? null) === 'retrieval'
            && ($start['phase'] ?? null) === 'start' && ($end['phase'] ?? null) === 'end'
            && ($start['retrieval_token'] ?? null) === $record['retrieval_token'] && ($end['retrieval_token'] ?? null) === $record['retrieval_token']
            && ($start['allocation_token'] ?? null) === $record['start_allocation_token']
            && ($end['allocation_token'] ?? null) === $record['end_allocation_token']
            && $record['start_allocation_token'] !== $record['end_allocation_token'], 'retrieval_interval');
        BillingException::require(is_string($start['database_at'] ?? null) && is_string($end['database_at'] ?? null), 'retrieval_interval');
        $first = BillingDatabaseInstant::fromNativeValue($start['database_at'], 0);
        $last = BillingDatabaseInstant::fromNativeValue($end['database_at'], 0);
        BillingException::require($first->utcMicros <= $last->utcMicros
            && ($start['created_at'] ?? null) === $first->stamp19() && ($end['created_at'] ?? null) === $last->stamp19(), 'retrieval_interval');
        if (array_key_exists('created_at', $record)) {
            BillingException::require(is_string($record['created_at']), 'retrieval_interval');
            $created = BillingDatabaseInstant::fromNativeValue($record['created_at'].'.000000', 0);
            BillingException::require($created->seconds() >= $last->seconds(), 'retrieval_interval');
        }
        if (array_key_exists('retrieved_at', $record)) {
            BillingException::require(($record['retrieval_started_at'] ?? null) === $first->stamp26
                && $record['retrieved_at'] === $last->stamp19()
                && ($record['freshness_deadline'] ?? null) === BillingValues::utc($last->seconds() + BillingLedger::FRESHNESS_SECONDS), 'retrieval_interval');
        }
    }

    public function __serialize(): never
    {
        throw new BillingException('foreign_retrieval');
    }

    public function jsonSerialize(): never
    {
        throw new BillingException('foreign_retrieval');
    }
}
