<?php

namespace App\Domain\Commerce\Finalization;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class FinalizationEvidence
{
    public const MAX_BYTES = 16777216;
    public const REASONS = ['late_confirmation', 'inventory_blocked', 'inventory_unavailable', 'asset_unavailable', 'rights_unavailable'];

    public function encrypt(array $payload): array
    {
        $canonical = CanonicalJson::encode($payload);
        if (strlen($canonical) > self::MAX_BYTES) { throw new FinalizationException('changed'); }
        $ciphertext = Crypt::encryptString($canonical);

        return [$ciphertext, hash('sha256', $ciphertext)];
    }

    public function decrypt(string $ciphertext, string $hash, string $version): array
    {
        try {
            if ($version !== CanonicalJson::VERSION || ! hash_equals($hash, hash('sha256', $ciphertext))) {
                throw new FinalizationException('changed');
            }
            $canonical = Crypt::decryptString($ciphertext);
            if (strlen($canonical) > self::MAX_BYTES) { throw new FinalizationException('changed'); }
            $payload = json_decode($canonical, true, 128, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || CanonicalJson::encode($payload) !== $canonical) { throw new FinalizationException('changed'); }

            return $payload;
        } catch (Throwable) { throw new FinalizationException('changed'); }
    }

    public function capture(Order $order, array $original, VerifiedPayment $payment, string $id, array $policy,
        CarbonImmutable $at, ?string $reason, array $checks): array
    {
        FinalizationPolicy::validate($policy);

        return ['schema_version' => 1, 'purpose' => 'test_order_finalization', 'mode' => 'test',
            'finalization_id' => $id, 'order_id' => $order->public_id, 'order_payload_hash' => $order->payload_hash,
            'attempt_id' => $original['attempt_id'], 'verified_payment_id' => $payment->id,
            'payment_evidence_hash' => $payment->evidence_hash, 'account_id' => $payment->account_id,
            'policy' => $policy, 'outcome' => $reason === null ? 'paid' : 'paid_exception', 'reason' => $reason,
            'confirmation_observed_at' => $payment->confirmed_at->toIso8601ZuluString(),
            'eligibility_cutoff' => $original['attempt']['expires_at'], 'finalized_at' => $at->toIso8601ZuluString(),
            'inventory_id' => $original['attempt']['inventory']['id'],
            'inventory_snapshot_hash' => $original['attempt']['inventory']['snapshot_hash'],
            'promotion_use_id' => $original['attempt']['promotion']['id'] ?? null,
            'checks' => $checks];
    }

    /** Minimal acyclic proof permits reconstruction of the original pending-state snapshot.
     * ReadOrder subsequently verifies the complete immutable effect graph.
     */
    public function resourceDisposition(OrderFinalization $finalization, Order $order, $attempt, $reservation, $use): void
    {
        if ($finalization->policy_version === \App\Domain\Commerce\UnpaidRelease\UnpaidReleasePolicy::CONTRACT['version']) {
            app(\App\Domain\Commerce\UnpaidRelease\ReleasedPaymentException::class)->resourceDisposition($finalization, $order, $attempt, $reservation, $use);
            return;
        }
        $proof = $this->decrypt($finalization->evidence_ciphertext, $finalization->evidence_hash, $finalization->canonicalization_version);
        $state = $finalization->outcome === 'paid' ? 'consumed' : 'pending';
        if ($finalization->order_id !== $order->id || $finalization->order_attempt_id !== $attempt->id
            || ! in_array($finalization->outcome, ['paid', 'paid_exception'], true)
            || ($proof['order_id'] ?? null) !== $order->public_id || ($proof['order_payload_hash'] ?? null) !== $order->payload_hash
            || ($proof['finalization_id'] ?? null) !== $finalization->public_id || ($proof['attempt_id'] ?? null) !== $attempt->public_id
            || ($proof['outcome'] ?? null) !== $finalization->outcome
            || ($proof['inventory_id'] ?? null) !== $reservation->id || ($proof['promotion_use_id'] ?? null) !== $use?->id
            || $reservation->state !== $state || ($use !== null && $use->state !== $state)
            || ($state === 'consumed' && ($reservation->consumed_at === null || ! $reservation->consumed_at->equalTo($finalization->finalized_at)
                || ($use !== null && ($use->consumed_at === null || ! $use->consumed_at->equalTo($finalization->finalized_at)))))
            || ($state === 'pending' && ($reservation->consumed_at !== null || $use?->consumed_at !== null))) {
            throw new FinalizationException('changed');
        }
    }
}
