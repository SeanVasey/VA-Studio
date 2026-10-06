<?php

namespace App\Domain\Commerce\RefundResolution;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PromotionCampaign;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\TestPaymentExceptionEvent;
use App\Domain\Commerce\Models\TestPaymentExceptionWork;
use App\Domain\Commerce\Models\TestRefundResolution;
use App\Domain\Commerce\Models\TestRefundResolutionRequest;
use App\Domain\Commerce\Operations\TestPaymentExceptionOperations;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\UnpaidRelease\UnpaidReleasePolicy;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Releases only still-pending test resources. Provider writes and fulfillment are never invoked. */
class ResolveRefundedTestException
{
    public function review(string $publicId, ?User $actor): array
    {
        UnpaidReleasePolicy::outsideTransactions();

        return $this->safe(fn (): array => DB::transaction(function () use ($publicId, $actor): array {
            [$record, $current, $work] = $this->locked($publicId, $actor);
            $resolution = TestRefundResolution::where('order_finalization_id', $record->id)->lockForUpdate()->first();
            AuditEvent::recordAttributed('commerce.refund_resolution.reviewed', $record,
                ['finalization_id' => $record->public_id, 'sequence' => $work->sequence, 'test_only' => true], $current->id);
            // Audit callbacks are inside the transaction and may withdraw authority or alter retained state.
            [$record, , $work] = $this->locked($publicId, $actor);

            return ['testOnly' => true, 'sequence' => $work->sequence, 'resolution' => $resolution ? $this->project($resolution) : null,
                'history' => TestPaymentExceptionEvent::where('order_finalization_id', $record->id)->lockForUpdate()
                    ->orderByDesc('sequence')->limit(20)->get()->map(fn ($event) => [
                        'sequence' => $event->sequence, 'status' => $event->kind, 'outcome' => $event->outcome,
                        'observedAt' => $event->observed_at?->toIso8601ZuluString()])->all()];
        }, 5));
    }

    public function resolve(string $publicId, ?User $actor, string $requestId, int $expectedSequence): array
    {
        UnpaidReleasePolicy::outsideTransactions();
        if (! OrderRequest::uuid($requestId) || $expectedSequence < 0 || $expectedSequence >= 4294967294) {
            throw new RuntimeException('Invalid test refund resolution request.');
        }
        app(RefundResolutionPolicy::class)->current();
        $request = $this->safe(fn () => DB::transaction(function () use ($publicId, $actor, $requestId, $expectedSequence) {
            [$record, $current, $work] = $this->locked($publicId, $actor);
            $policy = app(RefundResolutionPolicy::class)->current();
            $request = TestRefundResolutionRequest::where('order_finalization_id', $record->id)
                ->where('request_id', $requestId)->lockForUpdate()->first();
            if ($request) {
                if ($request->actor_id !== $current->id || $request->expected_sequence !== $expectedSequence
                    || $request->policy_version !== $policy['version']) {
                    throw new RuntimeException('Test refund resolution request changed.');
                }

                return $request;
            }
            if ($work->sequence !== $expectedSequence || $work->lease_expires_at?->greaterThan(now())
                || TestPaymentExceptionEvent::where('order_finalization_id', $record->id)->where('request_id', $requestId)->lockForUpdate()->exists()
                || TestRefundResolution::where('order_finalization_id', $record->id)->lockForUpdate()->exists()
                || $record->reason === 'released_attempt') {
                throw new RuntimeException('Test refund resolution review changed.');
            }

            return TestRefundResolutionRequest::create(['public_id' => (string) Str::uuid(), 'order_finalization_id' => $record->id,
                'actor_id' => $current->id, 'request_id' => $requestId, 'expected_sequence' => $expectedSequence,
                'policy_version' => $policy['version'], 'created_at' => now()->utc()->startOfSecond()]);
        }, 5));

        // A retained successful request can be confirmed without observing the provider again.
        $existing = $this->safe(fn () => DB::transaction(function () use ($publicId, $actor, $request) {
            [$record, , $work] = $this->locked($publicId, $actor);
            app(RefundResolutionPolicy::class)->current();
            $resolution = TestRefundResolution::where('request_record_id', $request->id)->lockForUpdate()->first();
            if ($resolution) {
                return $this->result('released', $work->sequence, $resolution);
            }
            if (now()->lessThan($request->created_at) || now()->greaterThanOrEqualTo($request->created_at->addSeconds(120))) {
                return $this->result('stale', $work->sequence);
            }

            return null;
        }, 5));
        if ($existing) {
            return $existing;
        }

        // Reuse the existing fenced request, exact original payment verification and GET-only adapter.
        $observed = app(TestPaymentExceptionOperations::class)->reconcile($publicId, $actor, $requestId, $expectedSequence);
        if (in_array($observed['status'], ['busy', 'stale'], true)) {
            return $this->result($observed['status'], $observed['sequence']);
        }

        return $this->safe(fn (): array => DB::transaction(function () use ($publicId, $actor, $request, $observed): array {
            [$record, $current, $work, $order, $original] = $this->locked($publicId, $actor);
            $policy = app(RefundResolutionPolicy::class)->current();
            $existing = TestRefundResolution::where('order_finalization_id', $record->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->request_record_id !== $request->id) {
                    return $this->result('stale', $work->sequence);
                }

                return $this->result('released', $work->sequence, $existing);
            }
            $event = TestPaymentExceptionEvent::where('order_finalization_id', $record->id)
                ->where('request_id', $request->request_id)->where('kind', 'reconciliation_observed')->lockForUpdate()->sole();
            if ($work->sequence !== $event->sequence || $event->sequence !== $observed['sequence']
                || $event->sequence !== $request->expected_sequence + 2 || $work->claim_token !== null) {
                return $this->result('stale', $work->sequence);
            }
            $state = $observed['refundDisputeState'] ?? 'not_inspected';
            if ($event->outcome !== 'confirmed' || $state !== 'observed') {
                return $this->result($event->outcome === 'unavailable' || $state === 'unavailable' ? 'unavailable' : 'attention', $work->sequence);
            }
            $evidence = app(RefundResolutionEvidence::class);
            if (! $evidence->financial($event)) {
                return $this->result('not_refunded', $work->sequence);
            }
            $attempt = $order->attempt()->sole();
            $use = null;
            if ($attempt->promotion_use_id !== null) {
                $campaign = PromotionUse::whereKey($attempt->promotion_use_id)->value('promotion_campaign_id');
                PromotionCampaign::whereKey($campaign)->lockForUpdate()->firstOrFail();
                $use = PromotionUse::whereKey($attempt->promotion_use_id)->lockForUpdate()->firstOrFail();
            }
            $scopeIds = array_column($original['attempt']['inventory']['snapshot']['bindings'], 'scope_id');
            $scopes = RightsScope::whereIn('id', $scopeIds)->orderBy('id')->lockForUpdate()->get();
            $reservation = InventoryReservation::whereKey($attempt->inventory_reservation_id)->lockForUpdate()->firstOrFail();
            $claims = DB::table('inventory_claims')->where('inventory_reservation_id', $reservation->id)
                ->orderBy('rights_scope_id')->lockForUpdate()->pluck('rights_scope_id')->map(fn ($id) => (int) $id)->all();
            if ($scopes->count() !== count($scopeIds) || $claims !== $scopeIds || $reservation->state !== 'pending'
                || $reservation->attempt_id !== $attempt->public_id || $reservation->consumed_at !== null
                || ($use && ($use->state !== 'pending' || $use->attempt_id !== $attempt->public_id || $use->consumed_at !== null))) {
                throw new RuntimeException('Test refund resolution resources changed.');
            }
            // Includes all lock waits. An old sidecar cannot become a fresh release proof.
            $at = now()->toImmutable()->utc()->startOfSecond();
            if ($event->observed_at === null || $at->lessThan($event->observed_at)
                || $at->greaterThanOrEqualTo($event->observed_at->addSeconds(60))
                || $at->greaterThanOrEqualTo($request->created_at->addSeconds(120))) {
                return $this->result('stale', $work->sequence);
            }
            $id = (string) Str::uuid();
            $payload = $evidence->capture($id, $request, $record, $order, $event, $at);
            [$ciphertext, $hash] = app(CheckoutEvidence::class)->encrypt($payload);
            $resolution = TestRefundResolution::create(['public_id' => $id, 'request_record_id' => $request->id,
                'order_id' => $order->id, 'order_finalization_id' => $record->id, 'order_attempt_id' => $attempt->id,
                'observed_event_id' => $event->id, 'inventory_reservation_id' => $reservation->id,
                'promotion_use_id' => $use?->id, 'actor_id' => $current->id, 'policy_version' => $policy['version'],
                'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash,
                'canonicalization_version' => CanonicalJson::VERSION, 'released_at' => $at]);
            if (DB::table('inventory_reservations')->where('id', $reservation->id)->where('state', 'pending')->update(['state' => 'released']) !== 1
                || ($use && DB::table('promotion_uses')->where('id', $use->id)->where('state', 'pending')->update(['state' => 'released']) !== 1)) {
                throw new RuntimeException('Test refund resolution resources changed.');
            }
            app(ReadOrder::class)->verify($order);
            AuditEvent::recordAttributed('commerce.refund_resolution.released', $resolution,
                ['order_public_id' => $order->public_id, 'resolution_id' => $id, 'test_only' => true], $current->id);
            $this->locked($publicId, $actor);
            app(RefundResolutionPolicy::class)->current();
            // Audit work can wait or invoke callbacks. A late new release must still roll back.
            $finishedAt = now()->toImmutable()->utc()->startOfSecond();
            if ($finishedAt->lessThan($at) || $finishedAt->greaterThanOrEqualTo($event->observed_at->addSeconds(60))
                || $finishedAt->greaterThanOrEqualTo($request->created_at->addSeconds(120))) {
                throw new RuntimeException('Test refund resolution proof expired before commit.');
            }

            return $this->result('released', $work->sequence, $resolution);
        }, 5));
    }

    private function locked(string $publicId, ?User $actor): array
    {
        $current = $actor?->exists ? User::whereKey($actor->id)->lockForUpdate()->first() : null;
        if (! $current || ! Gate::forUser($current)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException;
        }
        $account = app(RefundResolutionPolicy::class)->account();
        if (! OrderRequest::uuid($publicId)) {
            throw (new ModelNotFoundException)->setModel(OrderFinalization::class);
        }
        $record = OrderFinalization::where('public_id', $publicId)->where('mode', 'test')->where('outcome', 'paid_exception')
            ->whereHas('payment', fn ($query) => $query->where('mode', 'test')->where('account_id', $account))->first();
        if (! $record || ! hash_equals($publicId, $record->public_id) || ! hash_equals($account, $record->payment->account_id)) {
            throw (new ModelNotFoundException)->setModel(OrderFinalization::class);
        }
        $order = Order::whereKey($record->order_id)->lockForUpdate()->firstOrFail();
        $original = app(ReadOrder::class)->verify($order);
        $work = TestPaymentExceptionWork::where('order_finalization_id', $record->id)->lockForUpdate()->first();
        if (! $work) {
            $work = TestPaymentExceptionWork::create(['order_finalization_id' => $record->id, 'sequence' => 0]);
        }

        return [$record, $current, $work, $order, $original];
    }

    private function project(TestRefundResolution $resolution): array
    {
        return ['publicId' => $resolution->public_id, 'releasedAt' => $resolution->released_at->toIso8601ZuluString()];
    }

    private function result(string $status, int $sequence, ?TestRefundResolution $resolution = null): array
    {
        return ['testOnly' => true, 'status' => $status, 'sequence' => $sequence]
            + ($resolution ? ['resolution' => $resolution->public_id] : []);
    }

    private function safe(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (AuthorizationException|ModelNotFoundException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RuntimeException('Test refund resolution unavailable; inspect retained history before a new review.');
        }
    }
}
