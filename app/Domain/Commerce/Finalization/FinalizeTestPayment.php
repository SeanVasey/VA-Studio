<?php

namespace App\Domain\Commerce\Finalization;

use App\Domain\Catalog\OfferSnapshot;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\ExclusiveSale;
use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Models\PromotionCampaign;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\PaymentProcessingPolicy;
use App\Domain\Commerce\Payments\PaymentVerificationException;
use App\Domain\Commerce\QuoteException;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class FinalizeTestPayment
{
    /** Trusted ID-only worker/console entry point. No provider call or public mutation endpoint. */
    public function handle(int $paymentId): string
    {
        try {
            $policyService = app(FinalizationPolicy::class);
            $policy = $policyService->current(); $account = $policyService->account();
            app(PaymentProcessingPolicy::class)->outsideTransactions();
            $payment = VerifiedPayment::whereKey($paymentId)->where('mode', 'test')->where('account_id', $account)->first();
            if (! $payment) { return 'unverified'; }
            $order = Order::findOrFail($payment->order_id);
            $original = app(ReadOrder::class)->verify($order);
            app(ReadPaymentState::class)->verify($payment, $order, $original);
            $existing = OrderFinalization::where('order_id', $order->id)->first();
            if ($existing) { app(ReadFinalization::class)->verify($existing, $original); return $existing->outcome; }
            // A proved late confirmation cannot become eligible when storage recovers.
            // Timely file hashing stays outside every transaction and lock; null records no inspection.
            $assetsAvailable = $payment->confirmed_at->lessThan($original['attempt']['expires_at'])
                ? app(FinalizationAssets::class)->inspect($original) : null;

            return DB::transaction(function () use ($paymentId, $order, $original, $assetsAvailable, $policy, $account): string {
                // Order -> intent -> campaign/use -> sorted scopes -> reservation/claims -> retained effects.
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $payment = VerifiedPayment::whereKey($paymentId)->firstOrFail();
                CheckoutIntent::whereKey($payment->checkout_intent_id)->lockForUpdate()->firstOrFail();
                $existing = OrderFinalization::where('order_id', $locked->id)->lockForUpdate()->first();
                $fresh = app(ReadOrder::class)->verify($locked);
                if (CanonicalJson::encode($fresh) !== CanonicalJson::encode($original)
                    || $payment->account_id !== $account || $payment->order_id !== $locked->id) {
                    throw new FinalizationException('changed');
                }
                app(ReadPaymentState::class)->verify($payment, $locked, $fresh);
                if ($existing) { app(ReadFinalization::class)->verify($existing, $fresh); return $existing->outcome; }
                $attempt = $locked->attempt()->sole(); $use = null;
                if ($attempt->promotion_use_id !== null) {
                    $campaignId = PromotionUse::whereKey($attempt->promotion_use_id)->value('promotion_campaign_id');
                    PromotionCampaign::whereKey($campaignId)->lockForUpdate()->firstOrFail();
                    $use = PromotionUse::whereKey($attempt->promotion_use_id)->lockForUpdate()->firstOrFail();
                }
                $bindings = $fresh['attempt']['inventory']['snapshot']['bindings'];
                $scopeIds = array_column($bindings, 'scope_id');
                $scopes = RightsScope::whereIn('id', $scopeIds)->orderBy('id')->lockForUpdate()->get();
                $reservation = InventoryReservation::whereKey($attempt->inventory_reservation_id)->lockForUpdate()->firstOrFail();
                if ($scopes->count() !== count($scopeIds) || $reservation->state !== 'pending'
                    || $reservation->attempt_id !== $attempt->public_id || ($use && ($use->state !== 'pending' || $use->attempt_id !== $attempt->public_id))) {
                    throw new FinalizationException('changed');
                }
                // Current reads after the shared mutex, never an older REPEATABLE READ snapshot.
                $sold = ExclusiveSale::whereIn('rights_scope_id', $scopeIds)->orderBy('rights_scope_id')->lockForUpdate()->get();
                $others = InventoryReservation::query()->select('inventory_reservations.*')
                    ->join('inventory_claims', 'inventory_claims.inventory_reservation_id', '=', 'inventory_reservations.id')
                    ->whereIn('inventory_claims.rights_scope_id', $scopeIds)->where('inventory_reservations.id', '<>', $reservation->id)
                    ->whereIn('inventory_reservations.state', ['held', 'pending'])
                    ->orderBy('inventory_reservations.id')->lockForUpdate()->get();
                $at = now()->toImmutable()->utc()->startOfSecond();
                if ($at->lessThan($payment->confirmed_at)) { throw new FinalizationException('retry'); }
                $inventoryAvailable = $sold->isEmpty() && ! $others->contains(fn ($other) =>
                    $other->state === 'pending' || $other->expires_at->greaterThan($at));
                $scopeControls = [];
                foreach ($bindings as $binding) {
                    $scope = $scopes->firstWhere('id', $binding['scope_id']);
                    if ($scope->public_id !== $binding['scope_public_id']) { throw new FinalizationException('changed'); }
                    $scopeControls[] = ['scope_id' => $scope->id, 'scope_public_id' => $scope->public_id,
                        'control_version' => $scope->control_version, 'blocked' => $scope->blocked];
                }
                $rightsControls = [];
                foreach ($fresh['lines'] as $line) {
                    $snapshot = $line['selection']['offer_snapshot'];
                    $rights = RightsDeclaration::where('track_id', $snapshot['product']['id'])->orderByDesc('id')->lockForUpdate()->first();
                    $available = $rights && $rights->id === $snapshot['rights']['id'] && $rights->status === 'verified'
                        && $rights->verified_at && $rights->verified_by
                        && hash_equals($snapshot['rights']['identity_hash'], app(OfferSnapshot::class)->rightsHash($rights));
                    $rightsControls[] = ['track_id' => $snapshot['product']['id'], 'retained_declaration_id' => $snapshot['rights']['id'],
                        'observed_declaration_id' => $rights?->id, 'available' => (bool) $available];
                }
                // Recheck immutable asset descriptors while locked, without touching storage.
                $assetIds = collect($fresh['lines'])->flatMap(fn ($line) => array_column($line['selection']['offer_snapshot']['assets'], 'id'))->unique()->sort()->values()->all();
                $assets = MediaAsset::whereIn('id', $assetIds)->orderBy('id')->lockForUpdate()->get();
                foreach ($fresh['lines'] as $line) {
                    foreach ($line['selection']['offer_snapshot']['assets'] as $entry) {
                        $asset = $assets->firstWhere('id', $entry['id']);
                        if (! $asset || CanonicalJson::encode(app(OfferSnapshot::class)->asset($asset)) !== CanonicalJson::encode($entry)) {
                            throw new FinalizationException('changed');
                        }
                        if ($assetsAvailable !== null && $asset->status !== 'ready') { $assetsAvailable = false; }
                    }
                }
                $checks = ['scope_controls' => $scopeControls, 'inventory_available' => $inventoryAvailable,
                    'assets_available' => $assetsAvailable, 'rights_controls' => $rightsControls];
                $reason = app(ReadFinalization::class)->reason($payment, $attempt->expires_at, $checks);
                $id = (string) Str::uuid(); $evidence = app(FinalizationEvidence::class);
                $payload = $evidence->capture($locked, $fresh, $payment, $id, $policy, $at, $reason, $checks);
                [$ciphertext, $hash] = $evidence->encrypt($payload);
                $finalization = OrderFinalization::create(['public_id' => $id, 'order_id' => $locked->id,
                    'verified_payment_id' => $payment->id, 'order_attempt_id' => $attempt->id, 'mode' => 'test',
                    'outcome' => $payload['outcome'], 'reason' => $reason, 'policy_version' => $policy['version'],
                    'confirmed_at' => $payment->confirmed_at, 'eligibility_cutoff' => $attempt->expires_at, 'finalized_at' => $at,
                    'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION]);
                if ($reason !== null) {
                    $this->outbox($finalization, null, 'exception', 'order_paid_exception_v1', $hash);
                } else {
                    $references = $locked->lines()->orderBy('position')->get();
                    foreach ($fresh['lines'] as $position => $line) {
                        $reference = $references[$position]; $grantId = (string) Str::uuid();
                        $input = app(GrantRenderInput::class)->capture($locked, $fresh, $line, $reference, $finalization, $grantId, $policy);
                        [$inputCipher, $inputHash] = $evidence->encrypt($input);
                        $grant = LicenseGrant::create(['public_id' => $grantId, 'order_line_id' => $reference->id,
                            'order_finalization_id' => $finalization->id, 'offer_revision_id' => $reference->offer_revision_id,
                            'license_version_id' => $input['selection']['offer_snapshot']['license']['id'],
                            'rights_scope_id' => $input['inventory_binding']['scope_id'], 'created_at' => $at,
                            'render_input_ciphertext' => $inputCipher, 'render_input_hash' => $inputHash,
                            'canonicalization_version' => CanonicalJson::VERSION]);
                        foreach ($input['selection']['offer_snapshot']['assets'] as $asset) {
                            PendingEntitlement::create(['license_grant_id' => $grant->id, 'media_asset_id' => $asset['id'],
                                'role' => $asset['role'], 'asset_hash' => $asset['sha256'], 'size_bytes' => $asset['size_bytes'],
                                'state' => 'pending', 'created_at' => $at]);
                        }
                        $this->outbox($finalization, $grant, 'grant:'.$position, 'render_test_contract_v1', $inputHash);
                        if ($input['selection']['offer_snapshot']['commercial']['type'] === 'exclusive') {
                            ExclusiveSale::create(['rights_scope_id' => $grant->rights_scope_id, 'license_grant_id' => $grant->id,
                                'order_line_id' => $reference->id, 'order_finalization_id' => $finalization->id, 'created_at' => $at]);
                        }
                    }
                    if (DB::table('inventory_reservations')->where('id', $reservation->id)->where('state', 'pending')
                        ->update(['state' => 'consumed', 'consumed_at' => $at]) !== 1) { throw new FinalizationException('changed'); }
                    if ($use && DB::table('promotion_uses')->where('id', $use->id)->where('state', 'pending')
                        ->update(['state' => 'consumed', 'consumed_at' => $at]) !== 1) { throw new FinalizationException('changed'); }
                }
                AuditEvent::record('commerce.order.test_finalized', $finalization, ['order_public_id' => $locked->public_id,
                    'outcome' => $finalization->outcome, 'reason' => $reason, 'test_only' => true]);
                app(ReadFinalization::class)->verify($finalization, $fresh);

                return $finalization->outcome;
            }, 5);
        } catch (FinalizationException $error) { return $error->reason; }
        catch (PaymentVerificationException $error) { return $error->reason === 'unavailable' ? 'unavailable' : 'changed'; }
        catch (QuoteException) { return 'changed'; }
        catch (Throwable) { return 'retry'; }
    }

    private function outbox(OrderFinalization $finalization, ?LicenseGrant $grant, string $key, string $kind, string $hash): void
    {
        FulfillmentOutbox::create(['public_id' => (string) Str::uuid(), 'order_finalization_id' => $finalization->id,
            'license_grant_id' => $grant?->id, 'effect_key' => $key, 'kind' => $kind,
            'payload' => ['schema_version' => 1, 'finalization_id' => $finalization->public_id,
                'grant_id' => $grant?->public_id, 'evidence_hash' => $hash], 'state' => 'pending', 'created_at' => $finalization->finalized_at]);
    }
}
