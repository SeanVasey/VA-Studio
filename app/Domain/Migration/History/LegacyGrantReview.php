<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The separately reviewed path from an `archive_only` line to a `verified_grant` classification.
 *
 * Every condition is re-derived against the newest archived export of the line's source system, which must be
 * fully archived: the line, its order, its buyer and its original contract must still be in that export with the
 * archived hashes (a later export that drops or changes any of them withdraws the evidence). The order must be paid
 * with nothing refunded and carry a payment reference, the buyer must be unambiguous in that export, the line must
 * have exactly one stored original whose bytes still match their hash, and no other unrefunded sale of the same
 * exclusive may remain. The reviewer must hold current staff authority, satisfy the panel's staff MFA rule, present
 * a fresh attestation bound to this line and reference, and must not have inserted (or created the batch of) any
 * evidence row the review relies on. Only then is one immutable review row recorded, bound to that exact line
 * version and the evidence hashes.
 *
 * `classification()` repeats the whole derivation: a review counts only while the current evidence still hashes to
 * what was reviewed. `verified_grant` records that the historical evidence supports a grant the buyer once received.
 * It is not a download right and creates no entitlement, grant, account or delivery: activating historical
 * entitlements is a separate import that needs Sean's authorization (AGENTS.md release boundary).
 */
final class LegacyGrantReview
{
    public const CLASSIFICATION = 'verified_grant';

    public const REVIEW_ACTION = 'legacy_history.verified_grant_review';

    /** The console attestation one recorded review consumes (`StaffWriteAuthority`). */
    public const ATTESTATION_ACTION = 'legacy-history.review-grant';

    public static function attestationScope(string $lineRecordKey): string
    {
        return 'legacy-line:'.$lineRecordKey;
    }

    /**
     * Evaluate (and with `$write`, record) the review of one line. Read-only unless `$write` is true; a write needs
     * the reviewer's attestation.
     *
     * @return array{line_record_key: string, line_id: ?int, eligible: bool, blockers: list<string>, evidence_sha256: ?string, outcome: string}
     */
    public function review(string $lineRecordKey, User $reviewer, string $reference, ?StaffWriteAuthority $attestation, bool $write): array
    {
        $this->key($lineRecordKey);
        (new LegacyArchivePolicy)->admit();
        if (! $write) {
            if (! Gate::forUser($reviewer)->allows('administer-catalog')) {
                throw new LegacyArchiveRefusal('reviewer_refused');
            }

            return $this->evaluate($lineRecordKey, $reviewer) + ['outcome' => 'not_written'];
        }
        if ($attestation === null) {
            throw new LegacyArchiveRefusal('attestation_required');
        }

        try {
            return $this->record($lineRecordKey, $reviewer, $reference, $attestation);
        } catch (QueryException $error) {
            // Two reviews of one line version raced and the unique line_id refused the later one; nothing of it was written.
            if (in_array((string) $error->getCode(), ['23000', '40001'], true)) {
                throw new LegacyArchiveRefusal('review_changed_concurrently');
            }
            throw $error;
        }
    }

    private function record(string $lineRecordKey, User $reviewer, string $reference, StaffWriteAuthority $attestation): array
    {
        return DB::transaction(function () use ($lineRecordKey, $reviewer, $reference, $attestation): array {
            $current = LegacyHistoryArchive::authorize($reviewer, $attestation, self::ATTESTATION_ACTION, self::attestationScope($lineRecordKey), $reference);
            $result = $this->evaluate($lineRecordKey, $current);
            if (! $result['eligible']) {
                throw new LegacyArchiveRefusal('not_eligible');
            }
            $existing = DB::table('legacy_history_grant_reviews')->where('line_id', $result['line_id'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->evidence_sha256 === $result['evidence_sha256'] && (int) $existing->reviewer_id === $current->id) {
                    return $result + ['outcome' => 'unchanged'];
                }
                throw new LegacyArchiveRefusal('already_reviewed');
            }
            $id = DB::table('legacy_history_grant_reviews')->insertGetId(['line_id' => $result['line_id'], 'line_record_key' => $lineRecordKey,
                'classification' => self::CLASSIFICATION, 'evidence_sha256' => $result['evidence_sha256'],
                'reference_hmac' => hash_hmac('sha256', 'vasey-legacy-grant-review-v1|'.$reference, (string) config('app.key')),
                'reviewer_id' => $current->id, 'attestation_audit_id' => $attestation->auditEventId, 'created_at' => now()]);
            AuditEvent::create(['actor_id' => $current->id, 'action' => self::REVIEW_ACTION, 'subject_type' => 'legacy_history_grant_review',
                'subject_id' => $id, 'context' => ['line_record_key' => $lineRecordKey, 'evidence_sha256' => $result['evidence_sha256'],
                    'attestation_audit_id' => $attestation->auditEventId, 'entitlement_created' => false]]);

            return $result + ['outcome' => 'recorded'];
        });
    }

    /** Read-only eligibility without a reviewer identity; the write re-evaluates with authority and separation of duties. */
    public function inspect(string $lineRecordKey): array
    {
        $this->key($lineRecordKey);

        return $this->evaluate($lineRecordKey, null);
    }

    /**
     * The current classification of a line: `verified_grant` only while the line's current version carries a review
     * and the evidence, re-derived now against the newest archived export, is still eligible and hashes to what the
     * reviewer approved. Otherwise `archive_only`; null when the line was never archived.
     */
    public function classification(string $lineRecordKey): ?string
    {
        $this->key($lineRecordKey);
        if (! DB::table('legacy_history_lines')->where('record_key', $lineRecordKey)->exists()) {
            return null;
        }
        $current = $this->evaluate($lineRecordKey, null);
        $review = $current['line_id'] === null ? null : DB::table('legacy_history_grant_reviews')->where('line_id', $current['line_id'])->first();

        return $review !== null && $current['eligible'] && hash_equals($review->evidence_sha256, (string) $current['evidence_sha256'])
            ? self::CLASSIFICATION : HistoryNormalizer::CLASSIFICATION;
    }

    private function evaluate(string $lineRecordKey, ?User $reviewer): array
    {
        $anyVersion = DB::table('legacy_history_lines')->where('record_key', $lineRecordKey)->orderByDesc('id')->first();
        if ($anyVersion === null) {
            throw new LegacyArchiveRefusal('line_not_archived');
        }
        $sourceSystem = (string) DB::table('legacy_history_batches')->where('id', $anyVersion->batch_id)->value('source_system');
        $batch = LegacyHistoryArchive::currentBatch($sourceSystem);
        $members = $batch === null ? null : LegacyHistoryArchive::completeMembers($batch);
        $result = ['line_record_key' => $lineRecordKey, 'line_id' => null, 'eligible' => false, 'evidence_sha256' => null];
        if ($members === null) {
            return $result + ['blockers' => ['latest_export_not_archived']];
        }
        $line = $this->member('legacy_history_lines', $members['lines'], $lineRecordKey);
        if ($line === null) {
            return $result + ['blockers' => ['evidence_removed']];
        }
        $result['line_id'] = (int) $line->id;
        $lineRecord = LegacyHistoryArchive::decrypt($line);
        $order = $this->member('legacy_history_orders', $members['orders'], $line->order_record_key);
        $orderRecord = $order === null ? null : LegacyHistoryArchive::decrypt($order);
        if ($lineRecord === null || ($order !== null && $orderRecord === null)) {
            throw new LegacyArchiveRefusal('archive_integrity');
        }
        if ($orderRecord === null) {
            return $result + ['blockers' => ['evidence_removed']];
        }
        $blockers = [];
        if ($orderRecord['status'] !== 'paid') {
            $blockers[] = 'order_not_paid';
        }
        if ($lineRecord['refunded_minor'] !== 0 || $orderRecord['refunded_minor'] !== 0) {
            $blockers[] = 'line_refunded';
        }
        if ($orderRecord['payment_reference'] === null) {
            $blockers[] = 'payment_reference_missing';
        }
        $customer = $this->member('legacy_history_customers', $members['customers'], $orderRecord['customer_record_key']);
        if ($customer === null || LegacyHistoryArchive::decrypt($customer) === null) {
            $customer = null;
            $blockers[] = 'evidence_removed';
        } else {
            foreach (DB::table('legacy_history_customers')->where('email_hmac', $customer->email_hmac)->where('record_key', '<>', $customer->record_key)->get() as $other) {
                if (($members['customers'][$other->record_key] ?? null) === $other->record_sha256) {
                    $blockers[] = 'ambiguous_customer_identity';
                    break;
                }
            }
        }
        $contracts = [];
        foreach (DB::table('legacy_history_contracts')->where('line_record_key', $lineRecordKey)->orderBy('id')->get() as $row) {
            if (($members['contracts'][$row->record_key] ?? null) === $row->record_sha256) {
                $contracts[] = $row;
            }
        }
        $contract = null;
        if ($contracts === []) {
            $blockers[] = 'contract_absent';
        } elseif (count($contracts) > 1) {
            $blockers[] = 'multiple_contracts';
        } else {
            $contract = $contracts[0];
            if (LegacyHistoryArchive::decrypt($contract) === null
                || LegacyHistoryArchive::artifactState($contract->storage_path, $contract->original_sha256, (int) $contract->original_bytes) !== 'verified') {
                $blockers[] = 'contract_original_unverified';
            }
        }
        if ((bool) $line->exclusive) {
            foreach (DB::table('legacy_history_lines')->where('track_source_id', $line->track_source_id)->where('exclusive', true)
                ->where('record_key', '<>', $lineRecordKey)->orderBy('id')->get() as $other) {
                if (($members['lines'][$other->record_key] ?? null) !== $other->record_sha256) {
                    continue;
                }
                $otherOrder = $this->member('legacy_history_orders', $members['orders'], $other->order_record_key);
                if ($otherOrder !== null && in_array($otherOrder->status, ['paid', 'partially_refunded', 'unknown'], true)
                    && (int) $other->refunded_minor < (int) $other->amount_minor) {
                    $blockers[] = 'duplicate_exclusive_sale';
                    break;
                }
            }
        }
        // Separation of duties: whoever inserted any evidence row this review relies on, or created its batch, cannot review it.
        if ($reviewer !== null) {
            $rows = array_values(array_filter([$line, $order, $customer, $contract]));
            $importers = array_map(static fn (object $row): int => (int) $row->actor_id, $rows);
            $batchActors = DB::table('legacy_history_batches')->whereIn('id', array_map(static fn (object $row): int => (int) $row->batch_id, $rows))
                ->pluck('actor_id')->map(static fn ($id): int => (int) $id)->all();
            if (in_array((int) $reviewer->id, [...$importers, ...$batchActors], true)) {
                $blockers[] = 'reviewer_imported_evidence';
            }
        }
        $evidence = $customer !== null && $contract !== null ? CanonicalJson::hash(['line_record_sha256' => $line->record_sha256,
            'order_record_sha256' => $order->record_sha256, 'customer_record_sha256' => $customer->record_sha256,
            'contract_record_sha256' => $contract->record_sha256, 'original_sha256' => $contract->original_sha256]) : null;
        // A review of this very line version whose evidence has since changed (for example the buyer) no longer stands.
        $review = DB::table('legacy_history_grant_reviews')->where('line_id', $line->id)->first();
        if ($review !== null && ($evidence === null || ! hash_equals($review->evidence_sha256, $evidence))) {
            $blockers[] = 'reviewed_evidence_changed';
        }
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        return ['line_record_key' => $lineRecordKey, 'line_id' => (int) $line->id, 'eligible' => $blockers === [], 'blockers' => $blockers,
            'evidence_sha256' => $blockers === [] ? $evidence : null];
    }

    /** The stored row of one record as the given export contains it, or null when that export no longer has it. */
    private function member(string $table, array $members, string $recordKey): ?object
    {
        $sha = $members[$recordKey] ?? null;

        return $sha === null ? null : DB::table($table)->where('record_key', $recordKey)->where('record_sha256', $sha)->first();
    }

    private function key(string $lineRecordKey): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $lineRecordKey) !== 1) {
            throw new LegacyArchiveRefusal('line_invalid');
        }
    }
}
