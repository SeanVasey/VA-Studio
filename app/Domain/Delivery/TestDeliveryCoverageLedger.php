<?php

namespace App\Domain\Delivery;

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
 * Bounds: admission refuses a further response once MAX_RESPONSES rows exist, and at most PrepareTestDeliveryStream::SLOTS
 * verified streams exist at once, so rows (and therefore intervals, since each row adds at most one) stay far below the
 * schema's hard sequence cap of HARD_MAX_SEQUENCE.
 */
final class TestDeliveryCoverageLedger
{
    public const MAX_RESPONSES = 64;

    public const HARD_MAX_SEQUENCE = 128;

    /**
     * Verified current state of an authorization's coverage. Pass $lock inside a transaction that already holds the
     * authorization row lock, so the read sees the latest committed row rather than an older snapshot.
     *
     * @return array{responses: int, intervals: list<array{0: int, 1: int}>, covered: int, completed: bool}
     */
    public function state(TestDeliveryAuthorization $authorization, int $sizeBytes, bool $lock = false): array
    {
        $rows = TestDeliveryCoverage::where('test_delivery_authorization_id', $authorization->id);
        $latest = (clone $rows)->orderByDesc('sequence');
        $latest = ($lock ? $latest->lockForUpdate() : $latest)->first();
        $responses = (clone $rows)->count();
        $completions = TestDeliveryCoverage::where('completed_authorization_id', $authorization->id)->get();
        if (! $latest) {
            if ($responses !== 0 || $completions->isNotEmpty()) {
                throw new DeliveryException('changed');
            }

            return ['responses' => 0, 'intervals' => [], 'covered' => 0, 'completed' => false];
        }
        $intervals = self::decode($latest->intervals, $sizeBytes);
        $covered = self::covered($intervals);
        $completed = $covered === $sizeBytes;
        if ($latest->sequence !== $responses || $responses > self::HARD_MAX_SEQUENCE || $latest->covered_bytes !== $covered
            || $latest->first_byte < 0 || $latest->last_byte < $latest->first_byte || $latest->last_byte >= $sizeBytes
            || $completions->count() !== ($completed ? 1 : 0)
            || ($completed && ($completions[0]->test_delivery_authorization_id !== $authorization->id || $completions[0]->covered_bytes !== $sizeBytes))) {
            throw new DeliveryException('changed');
        }

        return ['responses' => $responses, 'intervals' => $intervals, 'covered' => $covered, 'completed' => $completed];
    }

    /**
     * Record bytes first..last (inclusive) that one response handed to the transport. Called after streaming, outside the
     * admission transaction; a failure here loses only this response's coverage (it can never count a download that was not
     * served). Returns true only for the single call that completed the file.
     */
    public function record(int $authorizationId, int $redemptionId, int $first, int $last, ?int $actorId = null): bool
    {
        ActivationPolicy::outsideTransactions();

        return DB::transaction(function () use ($authorizationId, $redemptionId, $first, $last, $actorId): bool {
            // Every coverage writer takes this row lock first; admission takes it after order and control.
            $authorization = TestDeliveryAuthorization::whereKey($authorizationId)->lockForUpdate()->first();
            $redemption = TestDeliveryRedemption::where('test_delivery_authorization_id', $authorizationId)->first();
            if (! $authorization || $authorization->policy_version !== TestAccessPolicy::V2 || ! $redemption || $redemption->id !== $redemptionId
                || $first < 0 || $last < $first || $last >= $redemption->size_bytes) {
                throw new DeliveryException('changed');
            }
            $state = $this->state($authorization, $redemption->size_bytes, true);
            $intervals = self::merge($state['intervals'], $first, $last);
            $covered = self::covered($intervals);
            $completes = ! $state['completed'] && $covered === $redemption->size_bytes;
            $row = TestDeliveryCoverage::create(['test_delivery_authorization_id' => $authorizationId,
                'test_delivery_redemption_id' => $redemptionId, 'sequence' => $state['responses'] + 1,
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
    public function recordServed(int $authorizationId, int $redemptionId, int $first, int $last, ?int $actorId = null): void
    {
        try {
            $this->record($authorizationId, $redemptionId, $first, $last, $actorId);
        } catch (Throwable $error) {
            try {
                Log::warning('Test delivery coverage was not recorded.', ['exception_class' => $error::class]);
            } catch (Throwable) { /* A failed logger must not reach the private stream. */
            }
        }
    }

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
