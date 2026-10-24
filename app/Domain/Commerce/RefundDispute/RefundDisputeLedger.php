<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Payments\PaymentFinancialEvidence;
use App\Domain\Commerce\Payments\PaymentVerificationException;
use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObject;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObservation;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeStatus;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeWork;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Staff-requested provider observation and the append-only ledger it feeds.
 *
 * Provider facts come only from the existing authenticated GET path (account/session/PaymentIntent inspection,
 * then the PaymentIntent, Charge, complete refund and dispute lists and a repeated Charge), never from a
 * webhook, a redirect or a client. Provider I/O runs with no application transaction open, under a leased claim
 * fixed to one request UUID. The commit re-locks actor → order → coordination, rechecks authority, policy, claim
 * and lease, appends one ledger row, deduplicates provider objects and statuses, and applies the configured
 * policy's effects. An exact replay of a completed request returns the retained row with no provider call.
 */
final class RefundDisputeLedger
{
    public const LEASE_SECONDS = 120;

    public function observe(string $orderPublicId, ?User $actor, string $requestId, int $expectedSequence): array
    {
        RefundDisputeAuthority::outsideTransactions();
        RefundDisputeAuthority::request($requestId, $expectedSequence);
        $authority = app(RefundDisputeAuthority::class);
        $claim = $this->safe(fn (): array => DB::transaction(function () use ($authority, $orderPublicId, $actor, $requestId, $expectedSequence): array {
            [$current, $order, $finalization, , $work, $policy] = $authority->lock($orderPublicId, $actor);
            $existing = RefundDisputeObservation::where('order_id', $order->id)->where('request_id', $requestId)->lockForUpdate()->first();
            if ($existing !== null) {
                RefundDisputeException::require($existing->actor_id === $current->id, 'conflict');

                return ['result' => $this->project($existing, true)];
            }
            $now = CarbonImmutable::now();
            RefundDisputeException::require($work->sequence === $expectedSequence, 'stale');
            // One provider read per order at a time; an expired claim may be retaken, and its holder then commits nothing.
            if ($work->lease_expires_at?->greaterThan($now)) {
                return ['result' => ['testOnly' => true, 'status' => 'busy', 'sequence' => $work->sequence]];
            }
            $token = (string) Str::uuid();
            $work->fill(['request_id' => $requestId, 'claim_token' => $token,
                'lease_expires_at' => $now->addSeconds(self::LEASE_SECONDS)->utc(), 'updated_at' => $now->utc()->startOfSecond()])->save();
            $payment = $finalization->payment;

            return ['token' => $token, 'policy_hash' => RefundDisputePolicy::hash($policy), 'intent_id' => $payment->checkout_intent_id,
                'payment_id' => $payment->provider_payment_intent_id, 'payment_row' => $payment->id, 'actor_id' => $current->id];
        }, 5));
        if (isset($claim['result'])) {
            return $claim['result'];
        }

        [$outcome, $financial] = $this->retrieve($claim);
        $observedAt = CarbonImmutable::now()->utc()->startOfSecond();

        return $this->safe(fn (): array => DB::transaction(function () use ($authority, $orderPublicId, $actor, $requestId, $claim, $outcome, $financial, $observedAt): array {
            [$current, $order, $finalization, $original, $work, $policy] = $authority->lock($orderPublicId, $actor);
            $now = CarbonImmutable::now();
            if ($work->request_id !== $requestId || $work->claim_token !== $claim['token'] || $current->id !== $claim['actor_id']
                || $work->lease_expires_at === null || ! $work->lease_expires_at->greaterThan($now)
                || RefundDisputePolicy::hash($policy) !== $claim['policy_hash'] || $now->lessThan($observedAt)) {
                return ['testOnly' => true, 'status' => 'stale', 'sequence' => $work->sequence];
            }
            RefundDisputeException::require($finalization->payment->id === $claim['payment_row']
                && $finalization->payment->provider_payment_intent_id === $claim['payment_id'], 'unavailable');
            $case = RefundDisputeCase::load($order, $finalization, $original);
            $sequence = $work->sequence + 1;
            $objects = $financial === null ? null : $this->normalize($financial);
            $evaluation = $objects === null ? null : RefundDisputeState::evaluate($case->paidMinor, $case->currency, $case->snapshot, $objects);
            if ($evaluation !== null && ! $evaluation['valid']) {
                $outcome = 'attention';
            }
            $accepted = $evaluation !== null && $evaluation['valid'] && $outcome === 'observed';
            $publicId = (string) Str::uuid();
            $policyHash = RefundDisputePolicy::hash($policy);
            $payload = ['schema_version' => 1, 'purpose' => 'test_refund_dispute_observation', 'order_id' => $order->public_id,
                'observation_id' => $publicId, 'request_id' => $requestId, 'sequence' => $sequence, 'outcome' => $outcome,
                'policy_hash' => $policyHash, 'policy' => $policy, 'observed_at' => $observedAt->toIso8601ZuluString(),
                'objects' => $accepted ? $objects : [], 'evaluation' => $evaluation, 'financial' => $financial];
            [$ciphertext, $hash] = app(CheckoutEvidence::class)->encrypt($payload);
            $observation = RefundDisputeObservation::create(['public_id' => $publicId, 'order_id' => $order->id, 'actor_id' => $current->id,
                'request_id' => $requestId, 'sequence' => $sequence, 'outcome' => $outcome,
                'refund_state' => $accepted ? $evaluation['refund_state'] : null, 'dispute_state' => $accepted ? $evaluation['dispute_state'] : null,
                'paid_minor' => $case->paidMinor, 'currency' => $case->currency,
                'succeeded_refund_minor' => $accepted ? $evaluation['succeeded_minor'] : null, 'pending_refund_minor' => $accepted ? $evaluation['pending_minor'] : null,
                'policy_hash' => $policyHash, 'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash,
                'canonicalization_version' => CanonicalJson::VERSION, 'observed_at' => $observedAt, 'created_at' => $observedAt]);
            AuditEvent::recordAttributed('commerce.refund_dispute.observed', $order, ['order_public_id' => $order->public_id,
                'observation_public_id' => $publicId, 'sequence' => $sequence, 'outcome' => $outcome, 'test_only' => true], $current->id);
            $effects = app(RefundDisputeEffects::class);
            if ($accepted) {
                foreach ($objects as $object) {
                    $row = $case->objects[$object['kind'].':'.$object['hash']] ?? RefundDisputeObject::create([
                        'public_id' => (string) Str::uuid(), 'order_id' => $order->id, 'kind' => $object['kind'], 'object_hash' => $object['hash'],
                        'amount_minor' => $object['amount'], 'currency' => $object['currency'], 'first_observation_id' => $observation->id,
                        'created_at' => $observedAt]);
                    if (! RefundDisputeStatus::where('refund_dispute_object_id', $row->id)->where('status', $object['status'])->lockForUpdate()->exists()) {
                        RefundDisputeStatus::create(['refund_dispute_object_id' => $row->id, 'status' => $object['status'],
                            'observation_id' => $observation->id, 'created_at' => $observedAt]);
                    }
                }
                $effects->afterObservation($case->reload(), $observation, $policy, $sequence, $observedAt);
            } elseif ($outcome === 'attention') {
                $effects->effect($case, $sequence, 'attention:'.$publicId, 'observation_attention', $policy, $observedAt,
                    ['observation_id' => $observation->id, 'actor_id' => $current->id],
                    ['reason' => $evaluation['reason'] ?? 'provider_evidence', 'access_changed' => false]);
            }
            $work->fill(['sequence' => $sequence, 'request_id' => null, 'claim_token' => null, 'lease_expires_at' => null,
                'updated_at' => $observedAt])->save();

            return $this->project($observation, false);
        }, 5));
    }

    /** Current sequence and history for staff review. Reads only; no locks, provider call or write. */
    public function review(string $orderPublicId, ?User $actor): array
    {
        app(RefundDisputeAuthority::class)->staff($actor);
        $policy = app(RefundDisputePolicy::class);
        [$order] = app(RefundDisputeAuthority::class)->paidOrder($orderPublicId, $policy->account());
        $work = RefundDisputeWork::where('order_id', $order->id)->first();

        return ['testOnly' => true, 'sequence' => $work?->sequence ?? 0,
            'history' => RefundDisputeObservation::where('order_id', $order->id)->orderByDesc('sequence')->limit(20)->get()
                ->map(fn (RefundDisputeObservation $row): array => $this->project($row, false))->all()];
    }

    /** @return array{0: string, 1: ?array} outcome and the validated financial observation when one was captured */
    private function retrieve(array $claim): array
    {
        try {
            $intent = CheckoutIntent::findOrFail($claim['intent_id']);
            $inspection = app(VerifyTestPayment::class)->inspect($intent->session()->sole()->provider_session_id, $intent);
            if ($inspection['intent_id'] !== $claim['intent_id'] || ($inspection['evidence']['payment']['id'] ?? null) !== $claim['payment_id']
                || ($inspection['evidence']['outcome'] ?? null) !== 'confirmed') {
                return ['attention', null];
            }
            $evidence = $inspection['evidence'];
            try {
                $financial = app(PaymentFinancialEvidence::class)->capture(
                    app(StripeFinancialInspectionGateway::class)->financialState($claim['payment_id']),
                    ['account_id' => $evidence['account_id'], 'payment' => $evidence['payment'], 'amount_minor' => $evidence['amount_minor']]);
            } catch (PaymentVerificationException $error) {
                return [$error->reason === 'financial_incomplete' ? 'incomplete' : 'attention', null];
            }

            return ['observed', $financial];
        } catch (PaymentVerificationException $error) {
            return [in_array($error->reason, ['retry', 'unavailable'], true) ? 'unavailable' : 'attention', null];
        } catch (Throwable) {
            return ['unavailable', null];
        }
    }

    /** Provider identifiers become hashes; only kind, amount, currency and status take part in the state machine. */
    private function normalize(array $financial): array
    {
        $objects = [];
        foreach (['refund' => 'refunds', 'dispute' => 'disputes'] as $kind => $list) {
            foreach ($financial['source'][$list]['data'] as $item) {
                $objects[] = ['kind' => $kind, 'hash' => RefundDisputeCase::objectHash($kind, $item['id']), 'amount' => $item['amount'],
                    'currency' => strtoupper($item['currency']), 'status' => $item['status']];
            }
        }
        usort($objects, fn (array $a, array $b): int => [$a['kind'], $a['hash']] <=> [$b['kind'], $b['hash']]);

        return $objects;
    }

    public function project(RefundDisputeObservation $row, bool $replayed): array
    {
        return ['testOnly' => true, 'status' => 'recorded', 'replayed' => $replayed, 'observation' => $row->public_id,
            'sequence' => $row->sequence, 'outcome' => $row->outcome, 'refundState' => $row->refund_state,
            'disputeState' => $row->dispute_state, 'paidMinor' => $row->paid_minor, 'currency' => $row->currency,
            'succeededRefundMinor' => $row->succeeded_refund_minor, 'pendingRefundMinor' => $row->pending_refund_minor,
            'observedAt' => $row->observed_at->toIso8601ZuluString()];
    }

    private function safe(callable $operation): array
    {
        try {
            return $operation();
        } catch (AuthorizationException|RefundDisputeException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RefundDisputeException('unavailable');
        }
    }
}
