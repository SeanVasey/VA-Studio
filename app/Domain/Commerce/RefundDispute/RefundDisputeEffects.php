<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Domain\Commerce\RefundDispute\Models\RefundDisputeEffect;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeHold;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObject;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObservation;
use App\Domain\Commerce\RefundDispute\Models\RefundLineAllocation;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Policy-governed effects of an accepted observation, applied in the caller's transaction after the Order row
 * lock. Effect keys are unique per order, so a replayed or duplicated observation adds nothing. Effects are
 * review records, exact allocations and technical download holds only: no license, grant, contract, payment,
 * exclusive sale, reservation or promotion row is changed.
 */
final class RefundDisputeEffects
{
    /** Automatic effects for one newly accepted observation. Returns the effects written. */
    public function afterObservation(RefundDisputeCase $case, RefundDisputeObservation $observation, array $policy, int $sequence, CarbonImmutable $at): array
    {
        $written = [];
        $values = $policy['values'];
        $statuses = $case->statuses();
        $objects = collect($case->objects)->keyBy('id');

        foreach ($case->allocations as $objectId => $lines) {
            if (($statuses[$objectId] ?? null) !== 'succeeded') {
                $object = $objects->get($objectId);
                $written[] = $this->effect($case, $sequence, 'reversed:'.$object->public_id, 'refund_reversed_review_required', $policy, $at,
                    ['refund_dispute_object_id' => $objectId, 'observation_id' => $observation->id],
                    ['refund' => $object->public_id, 'status' => $statuses[$objectId] ?? 'absent', 'allocations_retained' => true]);
            }
        }

        $unallocated = [];
        foreach ($case->snapshot as $item) {
            $object = $case->objects[$item['kind'].':'.$item['hash']];
            if ($item['kind'] === 'refund' && $item['status'] === 'succeeded' && ! isset($case->allocations[$object->id])) {
                $unallocated[] = $object;
            }
        }
        if ($unallocated !== []) {
            if ($observation->refund_state === 'full' || count($case->lines) === 1) {
                $source = $observation->refund_state === 'full' ? 'automatic_full_refund' : 'automatic_single_line';
                $capacity = $this->capacity($case);
                foreach ($unallocated as $object) {
                    $split = $this->greedy($capacity, $object->amount_minor);
                    $written[] = $this->allocate($case, $object, $split, $source, null, $sequence, $policy, $at, $observation->id);
                }
            } else {
                foreach ($unallocated as $object) {
                    $written[] = $this->effect($case, $sequence, 'allocation_required:'.$object->public_id, 'allocation_required', $policy, $at,
                        ['refund_dispute_object_id' => $object->id, 'observation_id' => $observation->id],
                        ['refund' => $object->public_id, 'amount_minor' => $object->amount_minor, 'currency' => 'USD', 'partial_refunds' => $values['partial_refunds']]);
                }
            }
            $case = $case->reload();
        }
        $written = [...$written, ...$this->lineEffects($case, $policy, $sequence, $at, $observation->id)];

        $disputeState = $observation->dispute_state;
        $reconciling = $case->activeHold(null, ['dispute_reconciling']);
        if ($disputeState === 'open') {
            if ($values['dispute_access'] === 'temporarily_block_while_reconciling') {
                if ($reconciling === null) {
                    $effect = $this->effect($case, $sequence, 'dispute_hold:'.$observation->public_id, 'dispute_hold_applied', $policy, $at,
                        ['observation_id' => $observation->id], ['scope' => 'order', 'dispute_access' => $values['dispute_access']]);
                    $this->hold($case, $effect, 'dispute_reconciling', null, $at);
                    $written[] = $effect;
                }
            } else {
                foreach ($case->snapshot as $item) {
                    if ($item['kind'] === 'dispute' && in_array($item['status'], RefundDisputeState::OPEN_DISPUTES, true)) {
                        $object = $case->objects[$item['kind'].':'.$item['hash']];
                        $written[] = $this->effect($case, $sequence, 'dispute_review:'.$object->public_id, 'dispute_review_required', $policy, $at,
                            ['refund_dispute_object_id' => $object->id, 'observation_id' => $observation->id],
                            ['dispute' => $object->public_id, 'status' => $item['status'], 'dispute_access' => $values['dispute_access']]);
                    }
                }
            }
        } elseif ($reconciling !== null && $values['dispute_access'] === 'temporarily_block_while_reconciling'
            && in_array($disputeState, ['won', 'closed'], true)) {
            $effect = $this->effect($case, $sequence, 'dispute_release:'.$observation->public_id, 'dispute_hold_released', $policy, $at,
                ['observation_id' => $observation->id], ['hold' => $reconciling->public_id, 'dispute_state' => $disputeState]);
            $this->release($reconciling, $effect, $at);
            $written[] = $effect;
        }
        foreach ($case->snapshot as $item) {
            if ($item['kind'] === 'dispute' && $item['status'] === 'lost') {
                $object = $case->objects[$item['kind'].':'.$item['hash']];
                $written[] = $this->effect($case, $sequence, 'dispute_lost:'.$object->public_id, 'dispute_lost_review_required', $policy, $at,
                    ['refund_dispute_object_id' => $object->id, 'observation_id' => $observation->id],
                    ['dispute' => $object->public_id, 'hold_retained' => $reconciling !== null, 'dispute_access' => $values['dispute_access']]);
                foreach ($case->lines as $line) {
                    if ($line['exclusive']) {
                        $written[] = $this->exclusiveEffect($case, $line, $policy, $sequence, $at, $observation->id, 'dispute_lost');
                    }
                }
            }
        }

        return array_values(array_filter($written));
    }

    /** Review effects for lines that allocations of succeeded refunds now cover in full. */
    public function lineEffects(RefundDisputeCase $case, array $policy, int $sequence, CarbonImmutable $at, ?int $observationId, array $request = []): array
    {
        $written = [];
        foreach ($case->lines as $line) {
            if (! $case->lineFullyRefunded($line['id'])) {
                continue;
            }
            $written[] = $this->effect($case, $sequence, 'line_refunded:'.$line['id'], 'line_refunded_review_required', $policy, $at,
                ['order_line_id' => $line['id'], 'license_grant_id' => $line['grant_id'], 'observation_id' => $observationId] + $request,
                ['position' => $line['position'], 'grant' => $line['grant_public_id'], 'refunded_minor' => $line['total_minor'],
                    'currency' => 'USD', 'refund_authority' => $policy['values']['refund_authority'], 'access_changed' => false]);
            if ($line['exclusive']) {
                $written[] = $this->exclusiveEffect($case, $line, $policy, $sequence, $at, $observationId, 'line_refunded', $request);
            }
        }

        return array_values(array_filter($written));
    }

    /**
     * Records an exact allocation of one succeeded refund. $split maps order line id to minor units and must
     * sum exactly to the refund without exceeding any line's remaining refundable amount.
     */
    public function allocate(RefundDisputeCase $case, RefundDisputeObject $object, array $split, string $source, ?int $actorId,
        int $sequence, array $policy, CarbonImmutable $at, ?int $observationId, ?string $requestId = null, ?string $requestHash = null): RefundDisputeEffect
    {
        $capacity = $this->capacity($case);
        $sum = 0;
        foreach ($split as $lineId => $amount) {
            RefundDisputeException::require(is_int($lineId) && isset($case->lines[$lineId]) && is_int($amount) && $amount > 0
                && $amount <= $capacity[$lineId], 'invalid');
            $sum += $amount;
        }
        RefundDisputeException::require($split !== [] && $sum === $object->amount_minor && $object->kind === 'refund'
            && ! isset($case->allocations[$object->id]) && ($case->statuses()[$object->id] ?? null) === 'succeeded', 'invalid');
        ksort($split);
        foreach ($split as $lineId => $amount) {
            RefundLineAllocation::create(['public_id' => (string) Str::uuid(), 'order_id' => $case->order->id,
                'refund_dispute_object_id' => $object->id, 'order_line_id' => $lineId, 'amount_minor' => $amount, 'currency' => 'USD',
                'source' => $source, 'actor_id' => $actorId, 'sequence' => $sequence, 'created_at' => $at]);
        }
        $positions = [];
        foreach ($split as $lineId => $amount) {
            $positions[] = ['position' => $case->lines[$lineId]['position'], 'amount_minor' => $amount];
        }

        return $this->effect($case, $sequence, 'allocation:'.$object->public_id, 'allocation_recorded', $policy, $at,
            ['refund_dispute_object_id' => $object->id, 'observation_id' => $observationId, 'actor_id' => $actorId,
                'request_id' => $requestId, 'request_hash' => $requestHash],
            ['refund' => $object->public_id, 'source' => $source, 'currency' => 'USD', 'lines' => $positions,
                'partial_refunds' => $policy['values']['partial_refunds']]);
    }

    /** Remaining refundable minor units per line after allocations of currently succeeded refunds. */
    public function capacity(RefundDisputeCase $case): array
    {
        $refunded = $case->refundedByLine();
        $capacity = [];
        foreach ($case->lines as $lineId => $line) {
            $capacity[$lineId] = $line['total_minor'] - $refunded[$lineId];
            if ($capacity[$lineId] < 0) {
                throw new RefundDisputeException('unavailable');
            }
        }

        return $capacity;
    }

    /** Appends an effect unless its key already exists for the order; returns null for an existing key. */
    public function effect(RefundDisputeCase $case, int $sequence, string $key, string $kind, array $policy, CarbonImmutable $at,
        array $columns, array $context): ?RefundDisputeEffect
    {
        if (RefundDisputeEffect::where('order_id', $case->order->id)->where('effect_key', $key)->lockForUpdate()->exists()) {
            return null;
        }
        $effect = RefundDisputeEffect::create(array_merge(['order_line_id' => null, 'license_grant_id' => null,
            'refund_dispute_object_id' => null, 'observation_id' => null, 'actor_id' => null, 'request_id' => null, 'request_hash' => null],
            $columns, ['public_id' => (string) Str::uuid(), 'order_id' => $case->order->id, 'sequence' => $sequence,
                'effect_key' => $key, 'kind' => $kind, 'policy_hash' => RefundDisputePolicy::hash($policy),
                'context' => $context + ['test_only' => true], 'created_at' => $at]));
        AuditEvent::recordAttributed('commerce.refund_dispute.'.$kind, $case->order, ['order_public_id' => $case->order->public_id,
            'effect_public_id' => $effect->public_id, 'sequence' => $sequence, 'test_only' => true], $effect->actor_id);

        return $effect;
    }

    public function hold(RefundDisputeCase $case, RefundDisputeEffect $effect, string $reason, ?int $grantId, CarbonImmutable $at): RefundDisputeHold
    {
        return RefundDisputeHold::create(['public_id' => (string) Str::uuid(), 'order_id' => $case->order->id,
            'license_grant_id' => $grantId, 'reason' => $reason, 'applied_effect_id' => $effect->id, 'created_at' => $at]);
    }

    public function release(RefundDisputeHold $hold, RefundDisputeEffect $effect, CarbonImmutable $at): void
    {
        if (DB::table('refund_dispute_holds')->where('id', $hold->id)->whereNull('released_at')
            ->update(['released_at' => $at, 'released_effect_id' => $effect->id]) !== 1) {
            throw new RefundDisputeException('conflict');
        }
    }

    private function exclusiveEffect(RefundDisputeCase $case, array $line, array $policy, int $sequence, CarbonImmutable $at, ?int $observationId,
        string $cause, array $request = []): ?RefundDisputeEffect
    {
        $reopening = $policy['values']['exclusive_reopening'];

        return $this->effect($case, $sequence, 'exclusive:'.$line['id'],
            $reopening === 'remain_sold' ? 'exclusive_remains_sold' : 'exclusive_reopening_review_required', $policy, $at,
            ['order_line_id' => $line['id'], 'license_grant_id' => $line['grant_id'], 'observation_id' => $observationId] + $request,
            ['grant' => $line['grant_public_id'], 'cause' => $cause, 'exclusive_reopening' => $reopening,
                'exclusive_sale' => 'retained', 'catalog_availability_changed' => false]);
    }

    /** Fills lines in position order. Only used where the exact allocation is unique (full refund or one line). */
    private function greedy(array &$capacity, int $amount): array
    {
        $split = [];
        foreach ($capacity as $lineId => $available) {
            if ($amount === 0) {
                break;
            }
            $take = min($available, $amount);
            if ($take > 0) {
                $split[$lineId] = $take;
                $capacity[$lineId] -= $take;
                $amount -= $take;
            }
        }
        if ($amount !== 0) {
            throw new LogicException('An automatic refund allocation was not exact.');
        }

        return $split;
    }
}
