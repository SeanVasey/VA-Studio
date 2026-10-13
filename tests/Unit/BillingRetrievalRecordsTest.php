<?php

namespace Tests\Unit;

use App\Domain\Memberships\Billing\BillingAttemptRecord;
use App\Domain\Memberships\Billing\BillingEvidenceRecord;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingRetrievalInterval;
use App\Domain\Memberships\Billing\BillingValues;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingRetrievalRecordsTest extends TestCase
{
    public function test_current_observation_envelope_authenticates_full_row_and_exact_interval(): void
    {
        [$row, $start, $end] = $this->observation();
        $payload = BillingEvidenceRecord::verify($row, BillingEvidenceRecord::OBSERVATION);
        $this->assertSame(3, $payload['schema_version']);
        BillingRetrievalInterval::verifyRows($row, $start, $end);
        $this->assertSame($row['retrieval_token'], $payload['row_binding']['retrieval_token']);
    }

    public static function changedColumns(): array
    {
        return ['retrieval token' => ['retrieval_token', 'd'], 'start allocation' => ['start_allocation_token', 'd'],
            'end allocation' => ['end_allocation_token', 'd'], 'deadline' => ['freshness_deadline', '2026-10-13 00:11:00'],
            'created time' => ['created_at', '2026-10-13 00:00:02'], 'position' => ['retrieval_position', 4]];
    }

    #[DataProvider('changedColumns')]
    public function test_recomputing_outer_checksum_cannot_change_authenticated_clock_or_allocation(string $key, mixed $value): void
    {
        [$row] = $this->observation();
        $row[$key] = $value === 'd' ? str_repeat('d', 64) : $value;
        $row['seal'] = BillingValues::seal($row);
        $this->expectException(BillingException::class);
        BillingEvidenceRecord::verify($row, BillingEvidenceRecord::OBSERVATION);
    }

    public static function legacyVersions(): array
    {
        return [[1], [2]];
    }

    #[DataProvider('legacyVersions')]
    public function test_legacy_observation_bytes_remain_auditable_after_exact_null_extension_but_not_current(int $version): void
    {
        [$row] = $this->observation();
        $payload = BillingValues::decrypt($row['payload_ciphertext']);
        foreach (BillingEvidenceRecord::INTERVAL_COLUMNS as $key) {
            unset($row[$key]);
        }
        unset($row['payload_ciphertext'], $row['seal']);
        $payload['schema_version'] = $version;
        unset($payload['clock_basis']);
        if ($version === 1) {
            unset($payload['row_binding']);
        } else {
            $payload['row_binding'] = $row;
        }
        $row['payload_ciphertext'] = BillingValues::encrypt($payload);
        $row['seal'] = BillingValues::seal($row);
        $original = $row;
        foreach (BillingEvidenceRecord::INTERVAL_COLUMNS as $key) {
            $row[$key] = null;
        }
        $this->assertSame($version, BillingEvidenceRecord::read($row, BillingEvidenceRecord::OBSERVATION)['schema_version']);
        $this->assertSame($original, BillingEvidenceRecord::legacyIntervalProjection($row));
        $this->expectException(BillingException::class);
        BillingEvidenceRecord::verify($row, BillingEvidenceRecord::OBSERVATION);
    }

    public function test_legacy_projection_does_not_drop_partial_nonnull_or_unrelated_columns(): void
    {
        foreach ([['retrieval_token' => null], ['retrieval_token' => str_repeat('a', 64), 'start_allocation_token' => null, 'end_allocation_token' => null]] as $fields) {
            try {
                BillingEvidenceRecord::legacyIntervalProjection(['id' => 'retained', ...$fields]);
                $this->fail('Nonlegacy extension must refuse.');
            } catch (BillingException $error) {
                $this->assertSame('legacy_or_changed_evidence', $error->reason);
            }
        }
        $this->assertSame(['unrelated' => null], BillingEvidenceRecord::legacyIntervalProjection(['unrelated' => null]));
    }

    public function test_authenticated_payload_still_requires_matching_facts_hash(): void
    {
        [$row] = $this->observation();
        $payload = BillingValues::decrypt($row['payload_ciphertext']);
        unset($payload['schema_version'], $payload['row_binding'], $payload['clock_basis']);
        $row['facts_hash'] = str_repeat('0', 64);
        $row['payload_ciphertext'] = BillingEvidenceRecord::encrypt($row, $payload);
        $row['seal'] = BillingValues::seal($row);
        $this->expectException(BillingException::class);
        BillingEvidenceRecord::verify($row, BillingEvidenceRecord::OBSERVATION);
    }

    public static function endpointChanges(): array
    {
        return ['reused start id' => ['start', 'allocation_token', str_repeat('d', 64)],
            'reused end id' => ['end', 'allocation_token', str_repeat('d', 64)],
            'different retrieval' => ['end', 'retrieval_token', str_repeat('d', 64)],
            'wrong phase' => ['end', 'phase', 'start'], 'unknown physical column' => ['end', 'unrecognized', null], 'clock regression' => ['end', 'database_at', '2026-10-12 23:59:59.000000'],
            'wrong retained endpoint timestamp' => ['end', 'created_at', '2026-10-13 00:00:02']];
    }

    #[DataProvider('endpointChanges')]
    public function test_numeric_position_alone_cannot_adopt_reallocated_or_mismatched_endpoint(string $which, string $key, mixed $value): void
    {
        [$row, $start, $end] = $this->observation();
        if ($which === 'start') {
            $start[$key] = $value;
        } else {
            $end[$key] = $value;
        }
        $this->expectException(BillingException::class);
        BillingRetrievalInterval::verifyRows($row, $start, $end);
    }

    public function test_attempt_v2_binds_reason_clock_and_interval_without_relabelling_legacy_diagnostics(): void
    {
        [$observation] = $this->observation();
        $row = ['id' => BillingValues::id(), 'subscription_binding_id' => BillingValues::id(),
            'invoice_ref_hash' => str_repeat('d', 64), 'provider_account_hash' => str_repeat('e', 64),
            'mode' => 'test', 'provenance' => 'synthetic_rehearsal', 'hint_event_id' => null,
            'retrieval_position' => 1, 'retrieval_end_position' => 2, 'retrieval_token' => $observation['retrieval_token'],
            'start_allocation_token' => $observation['start_allocation_token'], 'end_allocation_token' => $observation['end_allocation_token'],
            'outcome' => 'unknown', 'reason' => 'provider_unavailable', 'created_at' => '2026-10-13 00:00:01'];
        $payload = ['invoice_ref' => 'in_SYNTHETIC', 'subscription_binding_id' => $row['subscription_binding_id']];
        $row['payload_ciphertext'] = BillingAttemptRecord::encrypt($row, $payload);
        $row['seal'] = BillingValues::seal($row);
        $this->assertSame(2, BillingAttemptRecord::verify($row)['schema_version']);
        $changed = [...$row, 'reason' => 'provider_inconsistent'];
        $changed['seal'] = BillingValues::seal($changed);
        try {
            BillingAttemptRecord::verify($changed);
            $this->fail('Resealed diagnostic changes must refuse.');
        } catch (BillingException $error) {
            $this->assertSame('legacy_or_changed_evidence', $error->reason);
        }
        foreach (BillingEvidenceRecord::INTERVAL_COLUMNS as $key) {
            unset($row[$key]);
        }
        $row['payload_ciphertext'] = BillingValues::encrypt(['schema_version' => 1, 'purpose' => BillingAttemptRecord::PURPOSE, ...$payload]);
        $row['seal'] = BillingValues::seal($row);
        foreach (BillingEvidenceRecord::INTERVAL_COLUMNS as $key) {
            $row[$key] = null;
        }
        $this->assertSame(1, BillingAttemptRecord::read($row)['schema_version']);
        $this->expectException(BillingException::class);
        BillingAttemptRecord::verify($row);
    }

    public function test_unknown_physical_columns_are_not_admitted_even_with_a_matching_envelope(): void
    {
        [$row] = $this->observation();
        $payload = BillingValues::decrypt($row['payload_ciphertext']);
        $row['unexpected_column'] = null;
        $payload['row_binding']['unexpected_column'] = null;
        $row['payload_ciphertext'] = BillingValues::encrypt($payload);
        $row['seal'] = BillingValues::seal($row);
        $this->expectException(BillingException::class);
        BillingEvidenceRecord::verify($row, BillingEvidenceRecord::OBSERVATION);
    }

    public function test_current_version_requires_its_explicit_database_clock_basis(): void
    {
        [$row] = $this->observation();
        $payload = BillingValues::decrypt($row['payload_ciphertext']);
        $payload['clock_basis'] = 'worker-clock';
        $row['payload_ciphertext'] = BillingValues::encrypt($payload);
        $row['seal'] = BillingValues::seal($row);
        $this->expectException(BillingException::class);
        BillingEvidenceRecord::verify($row, BillingEvidenceRecord::OBSERVATION);
    }

    private function observation(): array
    {
        $start = ['id' => 1, 'kind' => 'retrieval', 'allocation_token' => str_repeat('a', 64), 'retrieval_token' => str_repeat('c', 64),
            'phase' => 'start', 'database_at' => '2026-10-13 00:00:00.123000', 'created_at' => '2026-10-13 00:00:00'];
        $end = [...$start, 'id' => 2, 'allocation_token' => str_repeat('b', 64), 'phase' => 'end',
            'database_at' => '2026-10-13 00:00:01.456000', 'created_at' => '2026-10-13 00:00:01'];
        $facts = ['outcome' => 'unknown', 'reason' => 'provider_unavailable', 'facts' => ['invoice_ref' => 'in_SYNTHETIC']];
        $row = ['id' => BillingValues::id(), 'invoice_id' => BillingValues::id(), 'sequence' => 1,
            'outcome' => 'unknown', 'facts_hash' => CanonicalJson::hash($facts), 'amount_minor' => null, 'currency' => null,
            'line_period_start' => null, 'line_period_end' => null, 'api_version' => '2026-08-26.dahlia',
            'sdk_reference' => str_repeat('a', 40), 'prior_seal' => str_repeat('0', 64), 'retrieval_position' => 1, 'retrieval_end_position' => 2,
            'retrieval_token' => $start['retrieval_token'], 'start_allocation_token' => $start['allocation_token'],
            'end_allocation_token' => $end['allocation_token'], 'retrieval_started_at' => $start['database_at'],
            'retrieved_at' => $end['created_at'], 'freshness_deadline' => '2026-10-13 00:10:01', 'created_at' => $end['created_at']];
        $row['payload_ciphertext'] = BillingEvidenceRecord::encrypt($row,
            ['purpose' => BillingEvidenceRecord::OBSERVATION, ...$facts, 'provenance' => 'synthetic_rehearsal']);
        $row['seal'] = BillingValues::seal($row);

        return [$row, $start, $end];
    }
}
