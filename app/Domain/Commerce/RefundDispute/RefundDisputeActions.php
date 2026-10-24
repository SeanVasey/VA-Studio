<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeEffect;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Staff decisions on a refund/dispute case. Each command needs fresh staff authority and MFA, an admitted
 * policy, the current ledger sequence and an accepted latest observation; it is idempotent by request UUID and
 * appends audited effects in one transaction. No command sends money, revokes a license, rewrites an original or
 * changes catalog availability.
 */
final class RefundDisputeActions
{
    /** Allocates one succeeded partial refund to original lines by position; amounts must sum exactly. */
    public function allocate(string $orderPublicId, ?User $actor, string $requestId, int $expectedSequence, string $refundPublicId, array $lines): array
    {
        RefundDisputeException::require(is_string($refundPublicId) && preg_match('/\A[0-9a-f-]{36}\z/D', $refundPublicId) === 1
            && $lines !== [] && count($lines) <= 10, 'invalid');
        $normalized = [];
        foreach ($lines as $position => $amount) {
            RefundDisputeException::require(is_int($position) && $position >= 0 && is_int($amount) && $amount > 0 && $amount <= 99999999, 'invalid');
            $normalized[$position] = $amount;
        }
        ksort($normalized);

        return $this->command($orderPublicId, $actor, $requestId, $expectedSequence, 'allocate', ['refund' => $refundPublicId, 'lines' => $normalized],
            function (RefundDisputeCase $case, array $policy, User $current, int $sequence, CarbonImmutable $at, array $request) use ($refundPublicId, $normalized): array {
                $object = collect($case->objects)->first(fn ($row) => $row->kind === 'refund' && hash_equals($row->public_id, $refundPublicId));
                RefundDisputeException::require($object !== null, 'not_found');
                RefundDisputeException::require(! isset($case->allocations[$object->id]), 'already_applied');
                $byPosition = collect($case->lines)->keyBy('position');
                $split = [];
                foreach ($normalized as $position => $amount) {
                    RefundDisputeException::require($byPosition->has($position), 'invalid');
                    $split[$byPosition[$position]['id']] = $amount;
                }
                $effects = app(RefundDisputeEffects::class);
                $allocation = $effects->allocate($case, $object, $split, 'staff', $current->id, $sequence, $policy, $at, null,
                    $request['request_id'], $request['request_hash']);

                return [$allocation, ...$effects->lineEffects($case->reload(), $policy, $sequence, $at, null, $request)];
            });
    }

    /** Suspends downloads for one grant after its line is fully refunded or a dispute is lost. */
    public function suspendLine(string $orderPublicId, ?User $actor, string $requestId, int $expectedSequence, string $grantPublicId): array
    {
        return $this->command($orderPublicId, $actor, $requestId, $expectedSequence, 'suspend_line', ['grant' => $grantPublicId],
            function (RefundDisputeCase $case, array $policy, User $current, int $sequence, CarbonImmutable $at, array $request) use ($grantPublicId): array {
                $line = $case->lineForGrant($grantPublicId);
                RefundDisputeException::require($line !== null, 'not_found');
                RefundDisputeException::require($policy['values']['refund_authority'] === 'authoritative_provider_operator_review', 'policy_forbids');
                RefundDisputeException::require($case->lineFullyRefunded($line['id']) || $case->disputeState() === 'lost', 'not_qualified');
                RefundDisputeException::require($case->activeHold($line['grant_id'], ['refund_suspension']) === null, 'already_applied');
                $effects = app(RefundDisputeEffects::class);
                $effect = $effects->effect($case, $sequence, 'suspension:'.$request['request_id'], 'refund_suspension_applied', $policy, $at,
                    ['order_line_id' => $line['id'], 'license_grant_id' => $line['grant_id']] + $request,
                    ['grant' => $line['grant_public_id'], 'cause' => $case->lineFullyRefunded($line['id']) ? 'line_refunded' : 'dispute_lost',
                        'license_changed' => false, 'access' => 'downloads_held']);
                $effects->hold($case, $effect, 'refund_suspension', $line['grant_id'], $at);

                return [$effect];
            });
    }

    /** Under `operator_review`, staff may hold the whole order's downloads while a dispute is open or lost. */
    public function placeDisputeHold(string $orderPublicId, ?User $actor, string $requestId, int $expectedSequence): array
    {
        return $this->command($orderPublicId, $actor, $requestId, $expectedSequence, 'dispute_hold', [],
            function (RefundDisputeCase $case, array $policy, User $current, int $sequence, CarbonImmutable $at, array $request): array {
                RefundDisputeException::require($policy['values']['dispute_access'] === 'operator_review', 'policy_forbids');
                RefundDisputeException::require(in_array($case->disputeState(), ['open', 'lost'], true), 'not_qualified');
                RefundDisputeException::require($case->activeHold(null, ['dispute_staff_hold', 'dispute_reconciling']) === null, 'already_applied');
                $effects = app(RefundDisputeEffects::class);
                $effect = $effects->effect($case, $sequence, 'staff_hold:'.$request['request_id'], 'dispute_staff_hold_applied', $policy, $at,
                    $request, ['scope' => 'order', 'dispute_state' => $case->disputeState(), 'license_changed' => false]);
                $effects->hold($case, $effect, 'dispute_staff_hold', null, $at);

                return [$effect];
            });
    }

    /** Releases one active hold after review. A reconciling dispute hold stays while the dispute is open. */
    public function releaseHold(string $orderPublicId, ?User $actor, string $requestId, int $expectedSequence, string $holdPublicId): array
    {
        return $this->command($orderPublicId, $actor, $requestId, $expectedSequence, 'release_hold', ['hold' => $holdPublicId],
            function (RefundDisputeCase $case, array $policy, User $current, int $sequence, CarbonImmutable $at, array $request) use ($holdPublicId): array {
                $hold = collect($case->holds)->first(fn ($row) => hash_equals($row->public_id, $holdPublicId));
                RefundDisputeException::require($hold !== null, 'not_found');
                RefundDisputeException::require(! ($hold->reason === 'dispute_reconciling' && $case->disputeState() === 'open'
                    && $policy['values']['dispute_access'] === 'temporarily_block_while_reconciling'), 'reconciling');
                $effects = app(RefundDisputeEffects::class);
                $effect = $effects->effect($case, $sequence, 'release:'.$hold->public_id, 'hold_released_by_staff', $policy, $at,
                    ['license_grant_id' => $hold->license_grant_id] + $request,
                    ['hold' => $hold->public_id, 'reason' => $hold->reason, 'dispute_state' => $case->disputeState()]);
                RefundDisputeException::require($effect !== null, 'already_applied');
                $effects->release($hold, $effect, $at);

                return [$effect];
            });
    }

    /**
     * Under `qualified_operator_review`, records a qualified reopening decision for an exclusive line. The exclusive
     * sale and every other grant on the same rights scope are retained; catalog availability is not changed here.
     */
    public function qualifyExclusiveReopening(string $orderPublicId, ?User $actor, string $requestId, int $expectedSequence, string $grantPublicId): array
    {
        return $this->command($orderPublicId, $actor, $requestId, $expectedSequence, 'qualify_exclusive', ['grant' => $grantPublicId],
            function (RefundDisputeCase $case, array $policy, User $current, int $sequence, CarbonImmutable $at, array $request) use ($grantPublicId): array {
                RefundDisputeException::require($policy['values']['exclusive_reopening'] === 'qualified_operator_review', 'policy_forbids');
                $line = $case->lineForGrant($grantPublicId);
                RefundDisputeException::require($line !== null, 'not_found');
                $dispute = $case->disputeState();
                RefundDisputeException::require($line['exclusive'] && $dispute !== 'open'
                    && ($case->lineFullyRefunded($line['id']) || $dispute === 'lost') && $case->grantHeld($line['grant_id']), 'not_qualified');
                $retained = LicenseGrant::where('rights_scope_id', $line['rights_scope_id'])->where('id', '<>', $line['grant_id'])
                    ->orderBy('id')->lockForUpdate()->pluck('public_id')->all();
                $effect = app(RefundDisputeEffects::class)->effect($case, $sequence, 'exclusive_qualified:'.$line['id'], 'exclusive_reopening_qualified',
                    $policy, $at, ['order_line_id' => $line['id'], 'license_grant_id' => $line['grant_id']] + $request,
                    ['grant' => $line['grant_public_id'], 'cause' => $case->lineFullyRefunded($line['id']) ? 'line_refunded' : 'dispute_lost',
                        'retained_grants' => $retained, 'exclusive_sale' => 'retained', 'catalog_availability_changed' => false,
                        'availability_decision' => 'u08_owner_decision_required']);
                RefundDisputeException::require($effect !== null, 'already_applied');

                return [$effect];
            });
    }

    private function command(string $orderPublicId, ?User $actor, string $requestId, int $expectedSequence, string $command,
        array $arguments, callable $apply): array
    {
        RefundDisputeAuthority::outsideTransactions();
        RefundDisputeAuthority::request($requestId, $expectedSequence);
        $requestHash = CanonicalJson::hash(['purpose' => 'refund_dispute_command_v1', 'command' => $command, 'order' => $orderPublicId,
            'arguments' => $arguments]);
        try {
            return DB::transaction(function () use ($orderPublicId, $actor, $requestId, $expectedSequence, $requestHash, $apply): array {
                [$current, $order, $finalization, $original, $work, $policy] = app(RefundDisputeAuthority::class)->lock($orderPublicId, $actor);
                $existing = RefundDisputeEffect::where('order_id', $order->id)->where('request_id', $requestId)->orderBy('id')->lockForUpdate()->get();
                if ($existing->isNotEmpty()) {
                    foreach ($existing as $effect) {
                        RefundDisputeException::require($effect->actor_id === $current->id && hash_equals($effect->request_hash, $requestHash), 'conflict');
                    }

                    return $this->project($existing->all(), $existing->max('sequence'), true);
                }
                RefundDisputeException::require($work->sequence === $expectedSequence, 'stale');
                $now = CarbonImmutable::now();
                RefundDisputeException::require(! $work->lease_expires_at?->greaterThan($now), 'busy');
                $case = RefundDisputeCase::load($order, $finalization, $original);
                RefundDisputeException::require($case->latest !== null && $case->latest->outcome === 'observed'
                    && $case->latestObserved?->id === $case->latest->id, 'not_observed');
                $sequence = $work->sequence + 1;
                $at = $now->utc()->startOfSecond();
                $effects = array_values(array_filter($apply($case, $policy, $current, $sequence, $at,
                    ['actor_id' => $current->id, 'request_id' => $requestId, 'request_hash' => $requestHash])));
                RefundDisputeException::require($effects !== [], 'already_applied');
                $work->fill(['sequence' => $sequence, 'updated_at' => $at])->save();

                return $this->project($effects, $sequence, false);
            }, 5);
        } catch (AuthorizationException|RefundDisputeException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RefundDisputeException('unavailable');
        }
    }

    private function project(array $effects, int $sequence, bool $replayed): array
    {
        return ['testOnly' => true, 'status' => 'recorded', 'replayed' => $replayed, 'sequence' => $sequence,
            'effects' => array_map(fn (RefundDisputeEffect $effect): array => ['effect' => $effect->public_id, 'kind' => $effect->kind], $effects)];
    }
}
