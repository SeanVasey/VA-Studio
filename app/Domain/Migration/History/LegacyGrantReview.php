<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The separately reviewed path from an `archive_only` line to a `verified_grant` classification.
 *
 * A reviewer who did not import the evidence re-derives every condition from the stored archive at review time:
 * the latest version of the line, its order paid with nothing refunded and a payment reference, an unambiguous
 * buyer, exactly one stored original contract for the line whose bytes still match their hash, and no competing
 * unrefunded sale of the same exclusive. Only then is one immutable review row recorded, bound to that exact line
 * version and the evidence hashes.
 *
 * `verified_grant` records that the historical evidence supports a grant the buyer once received. It is not a
 * download right and creates no entitlement, grant, account or delivery: activating historical entitlements is a
 * separate import that needs Sean's authorization (AGENTS.md release boundary).
 */
final class LegacyGrantReview
{
    public const CLASSIFICATION = 'verified_grant';

    public const REVIEW_ACTION = 'legacy_history.verified_grant_review';

    /**
     * Evaluate (and with `$write`, record) the review of one line. Read-only unless `$write` is true.
     *
     * @return array{line_record_key: string, eligible: bool, blockers: list<string>, evidence_sha256: ?string, outcome: string}
     */
    public function review(string $lineRecordKey, User $reviewer, string $reference, ?int $attestationAuditId, bool $write): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $lineRecordKey) !== 1) {
            throw new LegacyArchiveRefusal('line_invalid');
        }
        $evaluate = function (bool $lock) use ($lineRecordKey, $reviewer): array {
            if (! Gate::forUser($reviewer)->allows('administer-catalog', $lock)) {
                throw new LegacyArchiveRefusal('reviewer_refused');
            }

            return $this->evaluate($lineRecordKey, $reviewer);
        };
        if (! $write) {
            return $evaluate(false) + ['outcome' => 'not_written'];
        }

        return DB::transaction(function () use ($evaluate, $lineRecordKey, $reviewer, $reference, $attestationAuditId): array {
            $result = $evaluate(true);
            if (! $result['eligible']) {
                throw new LegacyArchiveRefusal('not_eligible');
            }
            $existing = DB::table('legacy_history_grant_reviews')->where('line_id', $result['line_id'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->evidence_sha256 === $result['evidence_sha256'] && (int) $existing->reviewer_id === $reviewer->id) {
                    return $result + ['outcome' => 'unchanged'];
                }
                throw new LegacyArchiveRefusal('already_reviewed');
            }
            $id = DB::table('legacy_history_grant_reviews')->insertGetId(['line_id' => $result['line_id'], 'line_record_key' => $lineRecordKey,
                'classification' => self::CLASSIFICATION, 'evidence_sha256' => $result['evidence_sha256'],
                'reference_hmac' => hash_hmac('sha256', 'vasey-legacy-grant-review-v1|'.$reference, (string) config('app.key')),
                'reviewer_id' => $reviewer->id, 'attestation_audit_id' => $attestationAuditId, 'created_at' => now()]);
            AuditEvent::create(['actor_id' => $reviewer->id, 'action' => self::REVIEW_ACTION, 'subject_type' => 'legacy_history_grant_review',
                'subject_id' => $id, 'context' => ['line_record_key' => $lineRecordKey, 'evidence_sha256' => $result['evidence_sha256'],
                    'attestation_audit_id' => $attestationAuditId, 'entitlement_created' => false]]);

            return $result + ['outcome' => 'recorded'];
        });
    }

    /** Read-only eligibility without a reviewer identity; the write re-evaluates with authority and separation of duties. */
    public function inspect(string $lineRecordKey): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $lineRecordKey) !== 1) {
            throw new LegacyArchiveRefusal('line_invalid');
        }

        return $this->evaluate($lineRecordKey, null);
    }

    /** The current classification of a line: `verified_grant` only when its latest version carries a review. */
    public function classification(string $lineRecordKey): ?string
    {
        $line = DB::table('legacy_history_lines')->where('record_key', $lineRecordKey)->orderByDesc('id')->first();
        if ($line === null) {
            return null;
        }

        return DB::table('legacy_history_grant_reviews')->where('line_id', $line->id)->exists() ? self::CLASSIFICATION : HistoryNormalizer::CLASSIFICATION;
    }

    private function evaluate(string $lineRecordKey, ?User $reviewer): array
    {
        $blockers = [];
        $line = $this->latest('legacy_history_lines', 'record_key', $lineRecordKey);
        if ($line === null) {
            throw new LegacyArchiveRefusal('line_not_archived');
        }
        $lineRecord = LegacyHistoryArchive::decrypt($line);
        $order = $this->latest('legacy_history_orders', 'record_key', $line->order_record_key);
        $orderRecord = $order === null ? null : LegacyHistoryArchive::decrypt($order);
        if ($lineRecord === null || $orderRecord === null) {
            throw new LegacyArchiveRefusal('archive_integrity');
        }
        if ($orderRecord['status'] !== 'paid') {
            $blockers[] = 'order_not_paid';
        }
        if ($lineRecord['refunded_minor'] !== 0 || $orderRecord['refunded_minor'] !== 0) {
            $blockers[] = 'line_refunded';
        }
        if ($orderRecord['payment_reference'] === null) {
            $blockers[] = 'payment_reference_missing';
        }
        $customer = $this->latest('legacy_history_customers', 'record_key', $orderRecord['customer_record_key']);
        $customerRecord = $customer === null ? null : LegacyHistoryArchive::decrypt($customer);
        if ($customerRecord === null) {
            $blockers[] = 'customer_missing';
        } else {
            $sharing = DB::table('legacy_history_customers')->where('email_hmac', $customer->email_hmac)
                ->where('record_key', '<>', $customer->record_key)->exists();
            if ($sharing) {
                $blockers[] = 'ambiguous_customer_identity';
            }
        }
        $contracts = [];
        foreach (DB::table('legacy_history_contracts')->where('line_record_key', $lineRecordKey)->orderBy('id')->get() as $row) {
            $contracts[$row->record_key] = $row;
        }
        $contract = null;
        if ($contracts === []) {
            $blockers[] = 'contract_absent';
        } elseif (count($contracts) > 1) {
            $blockers[] = 'multiple_contracts';
        } else {
            $contract = array_values($contracts)[0];
            if (LegacyHistoryArchive::decrypt($contract) === null
                || LegacyHistoryArchive::artifactState($contract->storage_path, $contract->original_sha256, (int) $contract->original_bytes) !== 'verified') {
                $blockers[] = 'contract_original_unverified';
            }
        }
        if ((bool) $line->exclusive) {
            foreach (DB::table('legacy_history_lines')->where('track_source_id', $line->track_source_id)->where('exclusive', true)
                ->where('record_key', '<>', $lineRecordKey)->orderBy('id')->get() as $other) {
                $otherOrder = $this->latest('legacy_history_orders', 'record_key', $other->order_record_key);
                $latestOther = $this->latest('legacy_history_lines', 'record_key', $other->record_key);
                if ($latestOther !== null && (int) $latestOther->id === (int) $other->id && $otherOrder !== null
                    && in_array($otherOrder->status, ['paid', 'partially_refunded', 'unknown'], true) && (int) $other->refunded_minor < (int) $other->amount_minor) {
                    $blockers[] = 'duplicate_exclusive_sale';
                    break;
                }
            }
        }
        // Separation of duties: whoever archived any of this evidence cannot also review it.
        $importers = DB::table('legacy_history_batches')->whereIn('id', array_filter([$line->batch_id, $order->batch_id, $customer?->batch_id, $contract?->batch_id]))
            ->pluck('actor_id')->map(static fn ($id): int => (int) $id)->all();
        if ($reviewer !== null && in_array($reviewer->id, $importers, true)) {
            $blockers[] = 'reviewer_imported_evidence';
        }
        sort($blockers, SORT_STRING);
        $evidence = $blockers === [] ? CanonicalJson::hash(['line_record_sha256' => $line->record_sha256, 'order_record_sha256' => $order->record_sha256,
            'customer_record_sha256' => $customer->record_sha256, 'contract_record_sha256' => $contract->record_sha256,
            'original_sha256' => $contract->original_sha256]) : null;

        return ['line_record_key' => $lineRecordKey, 'line_id' => (int) $line->id, 'eligible' => $blockers === [], 'blockers' => $blockers,
            'evidence_sha256' => $evidence];
    }

    private function latest(string $table, string $column, string $value): ?object
    {
        return DB::table($table)->where($column, $value)->orderByDesc('id')->first();
    }
}
