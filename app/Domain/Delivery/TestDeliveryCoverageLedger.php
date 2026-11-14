<?php

namespace App\Domain\Delivery;

use App\Domain\Delivery\Models\TestDeliveryAdmission;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryCoverage;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Distinct-byte coverage of one v2 transfer (FP-077). Each served response appends one row holding the merged intervals of
 * every byte served so far, so re-served bytes never add coverage. The authorization counts as one download exactly once,
 * on the row whose coverage first reaches the whole file. Writers serialize on the authorization row lock.
 *
 * Bounds: every response is admitted (a `test_delivery_admissions` row, written under the authorization lock before its
 * first byte) and admission stops at MAX_RESPONSES, so admissions, coverage rows (one per admission at most) and intervals
 * (each row adds at most one) stay below the schema's hard sequence cap of HARD_MAX_SEQUENCE, even when a worker dies
 * mid-stream and records no coverage.
 */
class TestDeliveryCoverageLedger
{
    public const MAX_RESPONSES = 64;

    public const HARD_MAX_SEQUENCE = 128;

    /**
     * Verified current state of an authorization's admissions and coverage. With $lock (inside a transaction holding the
     * authorization row lock) every value comes from locking reads, which read the latest committed rows even when an
     * earlier plain read in the same REPEATABLE READ transaction fixed an older snapshot.
     *
     * @return array{admitted: int, responses: int, intervals: list<array{0: int, 1: int}>, covered: int, completed: bool}
     */
    public function state(TestDeliveryAuthorization $authorization, int $sizeBytes, bool $lock = false): array
    {
        $locking = fn ($query) => $lock ? $query->lockForUpdate() : $query;
        $admissions = $locking(TestDeliveryAdmission::where('test_delivery_authorization_id', $authorization->id)->orderBy('sequence'))
            ->get(['id', 'sequence', 'first_byte', 'last_byte']);
        $rows = $locking(TestDeliveryCoverage::where('test_delivery_authorization_id', $authorization->id)->orderBy('sequence'))->get();
        $foreign = $locking(TestDeliveryCoverage::where('completed_authorization_id', $authorization->id)
            ->where('test_delivery_authorization_id', '!=', $authorization->id))->exists();
        $admitted = $admissions->count();
        $responses = $rows->count();
        $contiguous = fn ($sequences, int $count): bool => $sequences->all() === ($count === 0 ? [] : range(1, $count));
        if ($foreign || $admitted > self::HARD_MAX_SEQUENCE || $responses > $admitted
            || ! $contiguous($admissions->pluck('sequence'), $admitted) || ! $contiguous($rows->pluck('sequence'), $responses)) {
            throw new DeliveryException('changed');
        }
        if ($responses === 0) {
            return ['admitted' => $admitted, 'responses' => 0, 'intervals' => [], 'covered' => 0, 'completed' => false];
        }
        $latest = $rows->last();
        $intervals = self::decode($latest->intervals, $sizeBytes);
        $covered = self::covered($intervals);
        $completed = $covered === $sizeBytes;
        $completions = $rows->whereNotNull('completed_authorization_id');
        if ($latest->covered_bytes !== $covered || $latest->first_byte < 0 || $latest->last_byte < $latest->first_byte
            || $latest->last_byte >= $sizeBytes || $completions->count() !== ($completed ? 1 : 0)
            || ($completed && ($completions->first()->completed_authorization_id !== $authorization->id
                || $completions->first()->covered_bytes !== $sizeBytes))) {
            throw new DeliveryException('changed');
        }

        return ['admitted' => $admitted, 'responses' => $responses, 'intervals' => $intervals, 'covered' => $covered, 'completed' => $completed];
    }

    /**
     * Record bytes first..last (inclusive) that one admitted response handed to the transport. Called after streaming,
     * outside the admission transaction; a failure here loses only this response's coverage (it can never count a download
     * that was not served, and its admission still counts). Returns true only for the single call that completed the file.
     */
    public function record(int $authorizationId, int $admissionId, int $first, int $last, ?int $actorId = null): bool
    {
        ActivationPolicy::outsideTransactions();

        return DB::transaction(function () use ($authorizationId, $admissionId, $first, $last, $actorId): bool {
            // Every coverage writer takes this row lock first; admission takes it after order and control. All later reads
            // are locking reads, so they see the latest commits however this transaction's snapshot was fixed.
            $authorization = TestDeliveryAuthorization::whereKey($authorizationId)->lockForUpdate()->first();
            $this->locked();
            $redemption = TestDeliveryRedemption::where('test_delivery_authorization_id', $authorizationId)->lockForUpdate()->first();
            $admission = TestDeliveryAdmission::whereKey($admissionId)->lockForUpdate()->first();
            if (! $authorization || $authorization->policy_version !== TestAccessPolicy::V2 || ! $redemption || ! $admission
                || $admission->test_delivery_authorization_id !== $authorizationId || $admission->test_delivery_redemption_id !== $redemption->id
                || $first < $admission->first_byte || $last < $first || $last > $admission->last_byte || $last >= $redemption->size_bytes
                || TestDeliveryCoverage::where('test_delivery_admission_id', $admissionId)->lockForUpdate()->exists()) {
                throw new DeliveryException('changed');
            }
            $state = $this->state($authorization, $redemption->size_bytes, true);
            $intervals = self::merge($state['intervals'], $first, $last);
            $covered = self::covered($intervals);
            $completes = ! $state['completed'] && $covered === $redemption->size_bytes;
            $row = TestDeliveryCoverage::create(['test_delivery_admission_id' => $admissionId, 'test_delivery_authorization_id' => $authorizationId,
                'test_delivery_redemption_id' => $redemption->id, 'sequence' => $state['responses'] + 1,
                'first_byte' => $first, 'last_byte' => $last, 'intervals' => self::encode($intervals), 'covered_bytes' => $covered,
                'completed_authorization_id' => $completes ? $authorizationId : null,
                'recorded_at' => now()->toImmutable()->utc()->startOfSecond()]);
            if ($completes) {
                AuditEvent::recordAttributed('commerce.delivery.completed', $row, ['authorization_public_id' => $authorization->public_id,
                    'redemption_public_id' => $redemption->public_id, 'responses' => $row->sequence, 'test_only' => true], $actorId);
            }

            return $completes;
        }, 5);
    }

    /** Never throws: the bytes have already left, so a bookkeeping failure is logged and the response finishes. */
    public function recordServed(int $authorizationId, int $admissionId, int $first, int $last, ?int $actorId = null): void
    {
        try {
            $this->record($authorizationId, $admissionId, $first, $last, $actorId);
        } catch (Throwable $error) {
            try {
                // Internal row IDs only: enough to reconcile the admission with no coverage, never buyer data or secrets.
                Log::warning('Test delivery coverage was not recorded.', ['exception_class' => $error::class,
                    'authorization_id' => $authorizationId, 'admission_id' => $admissionId, 'first_byte' => $first, 'last_byte' => $last]);
            } catch (Throwable) { /* A failed logger must not reach the private stream. */
            }
        }
    }

    /** Test seam: runs while record() holds the authorization row lock. */
    protected function locked(): void {}

    /** @return list<array{0: int, 1: int}> sorted, disjoint, non-adjacent */
    public static function merge(array $intervals, int $first, int $last): array
    {
        if ($first < 0 || $last < $first) {
            throw new DeliveryException('changed');
        }
        $result = [];
        foreach ($intervals as [$start, $end]) {
            if ($end + 1 < $first) {
                $result[] = [$start, $end];

                continue;
            }
            if ($start > $last + 1) {
                $result[] = [$first, $last];
                $first = $start;
                $last = $end;

                continue;
            }
            $first = min($first, $start);
            $last = max($last, $end);
        }
        $result[] = [$first, $last];

        return $result;
    }

    public static function covered(array $intervals): int
    {
        return array_sum(array_map(fn (array $interval): int => $interval[1] - $interval[0] + 1, $intervals));
    }

    public static function encode(array $intervals): string
    {
        return json_encode($intervals, JSON_THROW_ON_ERROR);
    }

    /** @return list<array{0: int, 1: int}> */
    public static function decode(mixed $encoded, int $sizeBytes): array
    {
        try {
            if (! is_string($encoded) || strlen($encoded) > 8192) {
                throw new DeliveryException('changed');
            }
            $intervals = json_decode($encoded, true, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DeliveryException('changed');
        }
        if (! is_array($intervals) || ! array_is_list($intervals) || $intervals === [] || count($intervals) > self::HARD_MAX_SEQUENCE
            || self::encode($intervals) !== $encoded) {
            throw new DeliveryException('changed');
        }
        $previous = -2;
        foreach ($intervals as $interval) {
            if (! is_array($interval) || ! array_is_list($interval) || count($interval) !== 2 || ! is_int($interval[0]) || ! is_int($interval[1])
                || $interval[0] <= $previous + 1 || $interval[1] < $interval[0] || $interval[1] >= $sizeBytes) {
                throw new DeliveryException('changed');
            }
            $previous = $interval[1];
        }

        return $intervals;
    }
}
