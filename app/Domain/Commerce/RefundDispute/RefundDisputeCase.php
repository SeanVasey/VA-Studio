<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Models\ExclusiveSale;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\OrderLine;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeHold;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObject;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObservation;
use App\Domain\Commerce\RefundDispute\Models\RefundLineAllocation;
use App\Support\CanonicalJson;
use Throwable;

/**
 * The current refund/dispute case for one paid test order, read with current locking reads after the caller
 * holds the Order row. Line amounts come only from the verified original order payload; their sum must equal
 * the verified payment in the same currency, or the case is unavailable.
 */
final class RefundDisputeCase
{
    /**
     * @param  array<int, array{id: int, position: int, total_minor: int, grant_id: int, grant_public_id: string, rights_scope_id: int, exclusive: bool}>  $lines  keyed by order line id
     * @param  array<string, RefundDisputeObject>  $objects  keyed by kind:hash
     * @param  array<int, array{kind: string, hash: string, amount: int, currency: string, status: string}>  $snapshot  latest accepted provider snapshot
     * @param  array<int, array<int, int>>  $allocations  object id => [order line id => minor]
     * @param  list<RefundDisputeHold>  $holds  active holds
     */
    private function __construct(
        public readonly Order $order,
        public readonly OrderFinalization $finalization,
        public readonly VerifiedPayment $payment,
        public readonly int $paidMinor,
        public readonly string $currency,
        public readonly array $lines,
        public readonly array $objects,
        public readonly array $snapshot,
        public readonly ?RefundDisputeObservation $latest,
        public readonly ?RefundDisputeObservation $latestObserved,
        public readonly array $allocations,
        public readonly array $holds,
        public readonly array $original,
    ) {}

    /**
     * @param  array  $original  the verified original order payload (ReadOrder::verify)
     * @param  bool  $forUpdate  writers keep the default and read under row locks after taking the Order row. Read-only
     *                           reporting passes false: plain consistent reads, so it never waits on or blocks a writer.
     */
    public static function load(Order $order, OrderFinalization $finalization, array $original, bool $forUpdate = true): self
    {
        $lock = static fn ($query) => $forUpdate ? $query->lockForUpdate() : $query;
        $payment = VerifiedPayment::findOrFail($finalization->verified_payment_id);
        $pricing = $original['pricing']['snapshot'] ?? null;
        RefundDisputeException::require($payment->order_id === $order->id && $payment->currency === 'USD' && is_array($pricing)
            && ($pricing['currency'] ?? null) === 'USD' && $payment->amount_minor > 0
            && ($pricing['total_minor'] ?? null) === $payment->amount_minor, 'unavailable');
        $grants = LicenseGrant::where('order_finalization_id', $finalization->id)->get()->keyBy('order_line_id');
        $exclusive = ExclusiveSale::where('order_finalization_id', $finalization->id)->pluck('order_line_id')->all();
        $lines = [];
        $sum = 0;
        foreach (OrderLine::where('order_id', $order->id)->orderBy('position')->get() as $line) {
            $total = $original['lines'][$line->position]['pricing']['total_minor'] ?? null;
            $grant = $grants->get($line->id);
            RefundDisputeException::require(is_int($total) && $total >= 0 && $grant !== null, 'unavailable');
            $sum += $total;
            $lines[$line->id] = ['id' => $line->id, 'position' => $line->position, 'total_minor' => $total, 'grant_id' => $grant->id,
                'grant_public_id' => $grant->public_id, 'rights_scope_id' => $grant->rights_scope_id,
                'exclusive' => in_array($line->id, $exclusive, true)];
        }
        RefundDisputeException::require($lines !== [] && count($lines) === count($original['lines'] ?? []) && $sum === $payment->amount_minor
            && $grants->count() === count($lines), 'unavailable');

        $objects = [];
        foreach ($lock(RefundDisputeObject::where('order_id', $order->id)->orderBy('id'))->get() as $object) {
            $objects[$object->kind.':'.$object->object_hash] = $object;
        }
        $latest = $lock(RefundDisputeObservation::where('order_id', $order->id)->orderByDesc('sequence'))->first();
        $observed = $lock(RefundDisputeObservation::where('order_id', $order->id)->where('outcome', 'observed')
            ->orderByDesc('sequence'))->first();
        $snapshot = $observed === null ? [] : self::snapshot($observed, $order, $objects);
        $allocations = [];
        foreach ($lock(RefundLineAllocation::where('order_id', $order->id)->orderBy('id'))->get() as $allocation) {
            RefundDisputeException::require(isset($lines[$allocation->order_line_id]) && $allocation->currency === 'USD', 'unavailable');
            $allocations[$allocation->refund_dispute_object_id][$allocation->order_line_id] = $allocation->amount_minor;
        }
        $holds = $lock(RefundDisputeHold::where('order_id', $order->id)->whereNull('released_at')->orderBy('id'))->get()->all();

        return new self($order, $finalization, $payment, $payment->amount_minor, 'USD', $lines, $objects, $snapshot,
            $latest, $observed, $allocations, $holds, $original);
    }

    /** Decrypts and cross-checks the latest accepted snapshot against the deduplicated object registry. */
    public static function snapshot(RefundDisputeObservation $observation, Order $order, array $objects): array
    {
        try {
            $payload = app(CheckoutEvidence::class)->decrypt($observation->evidence_ciphertext, $observation->evidence_hash,
                $observation->canonicalization_version);
        } catch (Throwable) {
            throw new RefundDisputeException('unavailable');
        }
        RefundDisputeException::require(($payload['schema_version'] ?? null) === 1 && ($payload['purpose'] ?? null) === 'test_refund_dispute_observation'
            && ($payload['order_id'] ?? null) === $order->public_id && ($payload['observation_id'] ?? null) === $observation->public_id
            && ($payload['request_id'] ?? null) === $observation->request_id && ($payload['sequence'] ?? null) === $observation->sequence
            && ($payload['outcome'] ?? null) === $observation->outcome && ($payload['policy_hash'] ?? null) === $observation->policy_hash
            && is_array($payload['objects'] ?? null), 'unavailable');
        $snapshot = [];
        foreach ($payload['objects'] as $object) {
            $row = is_array($object) ? ($objects[($object['kind'] ?? '').':'.($object['hash'] ?? '')] ?? null) : null;
            RefundDisputeException::require($row !== null && $row->amount_minor === ($object['amount'] ?? null)
                && $row->currency === ($object['currency'] ?? null) && is_string($object['status'] ?? null), 'unavailable');
            $snapshot[] = ['kind' => $object['kind'], 'hash' => $object['hash'], 'amount' => $object['amount'],
                'currency' => $object['currency'], 'status' => $object['status']];
        }

        return $snapshot;
    }

    /** Current status of each object in the latest accepted snapshot, keyed by object id. */
    /** A fresh current read of the same case, for use after this transaction wrote allocations or holds. */
    public function reload(): self
    {
        return self::load($this->order, $this->finalization, $this->original);
    }

    public function statuses(): array
    {
        $statuses = [];
        foreach ($this->snapshot as $object) {
            $statuses[$this->objects[$object['kind'].':'.$object['hash']]->id] = $object['status'];
        }

        return $statuses;
    }

    /** Minor units refunded per line by allocations of refunds whose latest status is succeeded. */
    public function refundedByLine(): array
    {
        $statuses = $this->statuses();
        $refunded = array_fill_keys(array_keys($this->lines), 0);
        foreach ($this->allocations as $objectId => $lines) {
            if (($statuses[$objectId] ?? null) !== 'succeeded') {
                continue;
            }
            foreach ($lines as $lineId => $amount) {
                $refunded[$lineId] += $amount;
            }
        }

        return $refunded;
    }

    public function lineFullyRefunded(int $lineId): bool
    {
        $line = $this->lines[$lineId] ?? null;

        return $line !== null && $line['total_minor'] > 0 && $this->refundedByLine()[$lineId] === $line['total_minor'];
    }

    public function disputeState(): ?string
    {
        return $this->latestObserved?->dispute_state;
    }

    public function lineForGrant(string $grantPublicId): ?array
    {
        foreach ($this->lines as $line) {
            if (hash_equals($line['grant_public_id'], $grantPublicId)) {
                return $line;
            }
        }

        return null;
    }

    public function activeHold(?int $grantId, array $reasons): ?RefundDisputeHold
    {
        foreach ($this->holds as $hold) {
            if (in_array($hold->reason, $reasons, true) && $hold->license_grant_id === $grantId) {
                return $hold;
            }
        }

        return null;
    }

    /** True when an active hold covers the grant: an order-wide hold or the grant's own hold. */
    public function grantHeld(int $grantId): bool
    {
        foreach ($this->holds as $hold) {
            if ($hold->license_grant_id === null || $hold->license_grant_id === $grantId) {
                return true;
            }
        }

        return false;
    }

    public static function objectHash(string $kind, string $providerId): string
    {
        return hash('sha256', CanonicalJson::encode(['purpose' => 'refund_dispute_object_v1', 'kind' => $kind, 'id' => $providerId]));
    }
}
