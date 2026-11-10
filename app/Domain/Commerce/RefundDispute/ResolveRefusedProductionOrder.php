<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Commerce\RefundDispute\Models\RefusedOrderResolution;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * FP-068 (Sean, 2026-11-10): staff resolution of a paid production tax order whose finalization is refused because the provider
 * shows it fully refunded or its dispute lost ({@see ProductionTaxFinalizeGuard}). Such an order has no `paid_tax_order_origins`
 * batch, so the refund/dispute ledger and its hold/release path cannot see it; this records the decision instead.
 *
 * It proceeds only when the existing guard's provider read returns HOLD for committed full-refund coverage or a lost dispute, and
 * refuses a finalized order (those use the ledger's hold/release path), a clear provider state, a failed read, a partial refund,
 * an open dispute (the hold stays until the outcome), refunds that are only in flight, and an order that is not paid. The refund
 * must have SUCCEEDED for the full gross: the resolution is final, so a refund that could still fail never resolves an order.
 *
 * Writes exactly one append-only {@see RefusedOrderResolution} row and one audit event. It creates no grant, license, contract or
 * download authorization and never changes, deletes or rewrites the order, payment evidence, refund or dispute records. The
 * finalize frame refuses a resolved order, so no grant can follow. The track and offers are untouched: this chain reserves nothing
 * and sells no exclusive (DD-14; C4), so the instrumental is purchasable exactly as before the order and there is nothing to release.
 *
 * Lock order: provider I/O first, outside every transaction (like the finalize guard); then the staff user row (fresh authority and
 * MFA under lock, as RefundDisputeAuthority), then the checkout order row (the row finalization also locks), then the batch check
 * and the resolution row, all re-verified under lock. Idempotent: a second resolve returns the existing resolution.
 */
final class ResolveRefusedProductionOrder
{
    public const PURPOSE = 'production_tax_refused_order_resolution';

    public function resolve(string $orderPublicId, ?User $actor): array
    {
        RefundDisputeAuthority::outsideTransactions();
        RefundDisputeException::require(OrderRequest::uuid($orderPublicId), 'not_found');
        $authority = app(RefundDisputeAuthority::class);
        // Staff authority and MFA before any other read, so a caller who is not staff causes no provider call.
        $authority->staff($actor);
        app(RefundDisputePolicy::class)->productionAccount();
        try {
            $existing = RefusedOrderResolution::where('order_public_id', $orderPublicId)->first();
            if ($existing !== null) {
                return $this->project($existing, true);
            }
            RefundDisputeException::require(DB::table(TaxCheckoutSchema::TABLES['order'])->where('public_id', $orderPublicId)->exists(), 'not_found');
            RefundDisputeException::require(! $this->finalized($orderPublicId), 'finalized');
            $guard = app(ProductionTaxFinalizeGuard::class);
            try {
                $facts = $guard->reviewedFacts($orderPublicId);
            } catch (PaidTaxException) {
                throw new RefundDisputeException('unavailable');
            }
            RefundDisputeException::require($facts !== null, 'not_paid');
            // Provider I/O: outside every transaction.
            $observedAt = CarbonImmutable::now()->utc()->startOfSecond();
            $assessment = $guard->assess($facts);
            $reason = $this->reason($assessment, $facts);

            return DB::transaction(fn (): array => $this->record($orderPublicId, $actor, $facts, $assessment, $reason, $observedAt), 5);
        } catch (AuthorizationException|RefundDisputeException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RefundDisputeException('unavailable');
        }
    }

    /** @return string `full_refund` or `dispute_lost`; every other state is a bounded refusal */
    private function reason(array $assessment, array $facts): string
    {
        RefundDisputeException::require($assessment['verdict'] !== 'attention', 'not_observed');
        RefundDisputeException::require($assessment['verdict'] === 'hold', 'not_refused');
        $evaluation = $assessment['evaluation'];
        // An open dispute keeps the hold until its outcome, even when the payment is also fully refunded.
        RefundDisputeException::require($evaluation['dispute_state'] !== 'open', 'reconciling');
        if ($evaluation['succeeded_minor'] >= $facts['gross']) {
            return 'full_refund';
        }
        RefundDisputeException::require($evaluation['dispute_state'] === 'lost', 'in_flight');

        return 'dispute_lost';
    }

    private function finalized(string $orderPublicId, bool $locking = false): bool
    {
        $query = DB::table('paid_tax_order_origins')->where('order_public_id', $orderPublicId);

        return ($locking ? $query->lockForUpdate() : $query)->exists();
    }

    private function record(string $orderPublicId, ?User $actor, array $facts, array $assessment, string $reason, CarbonImmutable $observedAt): array
    {
        $current = app(RefundDisputeAuthority::class)->lockStaff($actor);
        $account = app(RefundDisputePolicy::class)->productionAccount();
        // The row finalization also locks: a concurrent finalize and resolve run one after the other.
        $order = DB::table(TaxCheckoutSchema::TABLES['order'])->where('public_id', $orderPublicId)->lockForUpdate()->first();
        RefundDisputeException::require($order !== null, 'not_found');
        $existing = RefusedOrderResolution::where('order_public_id', $orderPublicId)->lockForUpdate()->first();
        if ($existing !== null) {
            return $this->project($existing, true);
        }
        RefundDisputeException::require(! $this->finalized($orderPublicId, locking: true), 'finalized');
        try {
            Evidence::open((array) $order, 'production_tax_checkout_order');
            $reviewed = DB::table(TaxCheckoutSchema::TABLES['reviewed'])->where('order_id', $order->id)->lockForUpdate()->limit(2)->get();
            RefundDisputeException::require($reviewed->count() === 1, 'not_paid');
            $row = $reviewed->first();
            $body = Evidence::open((array) $row, 'production_tax_checkout_reviewed_session');
            // The facts the provider read was bound to are still the order's own paid facts, proven from the retained sealed rows.
            RefundDisputeException::require($row->funds_mode === 'test' && hash_equals($account, (string) $row->account_id)
                && (string) $row->account_id === $facts['account'] && (string) $row->provider_payment_id === $facts['payment']
                && (int) $row->amount_total_minor === $facts['gross'] && $row->currency === 'USD'
                && ($body['order_public_id'] ?? null) === $orderPublicId && ($body['order_hash'] ?? null) === $order->payload_hash, 'unavailable');
        } catch (CheckoutException) {
            throw new RefundDisputeException('unavailable');
        }
        $resolvedAt = CarbonImmutable::now()->utc()->startOfSecond();
        $publicId = (string) Str::uuid();
        $payload = ['schema_version' => 1, 'purpose' => self::PURPOSE, 'resolution_id' => $publicId, 'order_id' => $orderPublicId,
            'order_hash' => $order->payload_hash, 'provider_account' => $facts['account'], 'provider_payment_id' => $facts['payment'],
            'gross_minor' => $facts['gross'], 'currency' => 'USD', 'reason' => $reason, 'actor_id' => $current->id,
            'observed_at' => $observedAt->toIso8601ZuluString(), 'resolved_at' => $resolvedAt->toIso8601ZuluString(),
            'refunded_minor' => $assessment['refunded_minor'], 'evaluation' => $assessment['evaluation'], 'financial' => $assessment['financial']];
        [$ciphertext, $hash] = app(CheckoutEvidence::class)->encrypt($payload);
        $resolution = RefusedOrderResolution::create(['public_id' => $publicId, 'order_public_id' => $orderPublicId, 'order_hash' => $order->payload_hash,
            'provider_account' => $facts['account'], 'provider_payment_id' => $facts['payment'], 'reason' => $reason, 'gross_minor' => $facts['gross'],
            'currency' => 'USD', 'actor_id' => $current->id, 'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash,
            'canonicalization_version' => CanonicalJson::VERSION, 'observed_at' => $observedAt, 'resolved_at' => $resolvedAt]);
        AuditEvent::recordAttributed('commerce.refused_order.resolved', $resolution, ['order_public_id' => $orderPublicId, 'reason' => $reason,
            'gross_minor' => $facts['gross'], 'evidence_hash' => $hash, 'test_only' => true, 'grants_created' => 0], $current->id);

        return $this->project($resolution, false);
    }

    private function project(RefusedOrderResolution $row, bool $replayed): array
    {
        return ['testOnly' => true, 'status' => 'recorded', 'replayed' => $replayed, 'resolution' => $row->public_id, 'order' => $row->order_public_id,
            'reason' => $row->reason, 'grossMinor' => $row->gross_minor, 'resolvedAt' => $row->resolved_at->toIso8601ZuluString()];
    }
}
