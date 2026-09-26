<?php

namespace App\Domain\Delivery;

use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Contracts\ContractFiles;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use App\Domain\Media\Models\MediaAsset;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ActivateTestFulfillment
{
    /** Record a complete test order's verified storage proof. This never authorizes a download. */
    public function handle(int $orderId): string
    {
        try {
            $policies = app(ActivationPolicy::class); $policy = $policies->current(); $account = $policies->account();
            ActivationPolicy::outsideTransactions();
            $order = Order::find($orderId);
            if (! $order) { return 'unavailable'; }
            $evidence = app(ActivationEvidence::class);
            $existing = TestFulfillmentActivation::where('order_id', $order->id)->first();
            try { $source = $evidence->source($order, $account); }
            catch (DeliveryException $error) {
                // A retained activation cannot legitimately regress to an unfinished parent graph.
                if ($existing && in_array($error->reason, ['pending_contracts', 'ineligible'], true)) { throw new DeliveryException('changed'); }
                throw $error;
            }
            if ($existing) { $evidence->verify($existing, $source); return 'activated'; }

            $from = now()->toImmutable()->utc()->startOfSecond();
            foreach ($source['documents'] as $document) { app(ContractFiles::class)->verify($document); }
            app(DeliveryAssets::class)->verify($source['assets']);
            $through = now()->toImmutable()->utc()->startOfSecond();
            if (! $evidence->window($source, $from, $through, $through)) { return 'retry'; }

            return DB::transaction(function () use ($orderId, $source, $policy, $account, $from, $through, $evidence, $policies): string {
                // Shared publication order: order -> sorted grants -> request/work -> sorted asset revisions.
                $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
                LicenseGrant::where('order_finalization_id', $source['finalization']->id)->orderBy('id')->lockForUpdate()->get();
                $requests = ContractRenderRequest::whereIn('license_grant_id', $source['grants']->pluck('id')->all())
                    ->orderBy('id')->lockForUpdate()->get();
                ContractRenderWork::whereIn('contract_render_request_id', $requests->pluck('id')->all())->orderBy('id')->lockForUpdate()->get();
                $assetIds = collect($source['original']['lines'])->flatMap(fn ($line) => array_column($line['selection']['offer_snapshot']['assets'], 'id'))
                    ->unique()->sort()->values()->all();
                MediaAsset::whereIn('id', $assetIds)->orderBy('id')->lockForUpdate()->get();
                if (CanonicalJson::encode($policies->current()) !== CanonicalJson::encode($policy) || $policies->account() !== $account) { throw new DeliveryException('unavailable'); }
                $fresh = $evidence->source($order, $account);
                if (CanonicalJson::encode($fresh['snapshot']) !== CanonicalJson::encode($source['snapshot'])) { throw new DeliveryException('changed'); }
                $existing = TestFulfillmentActivation::where('order_id', $order->id)->lockForUpdate()->first();
                if ($existing) { $evidence->verify($existing, $fresh); return 'activated'; }
                $at = now()->toImmutable()->utc()->startOfSecond();
                if (! $evidence->window($fresh, $from, $through, $at)) { return 'retry'; }
                $id = (string) Str::uuid();
                [$ciphertext, $hash] = $evidence->encrypt($evidence->capture($fresh, $id, $policy, $from, $through, $at));
                $activation = TestFulfillmentActivation::create(['public_id' => $id, 'order_id' => $order->id,
                    'order_finalization_id' => $fresh['finalization']->id, 'policy_version' => $policy['version'],
                    'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION,
                    'verified_from' => $from, 'verified_through' => $through, 'activated_at' => $at]);
                AuditEvent::record('commerce.fulfillment.test_activated', $activation,
                    ['order_public_id' => $order->public_id, 'activation_public_id' => $id, 'test_only' => true]);
                $evidence->verify($activation, $fresh);
                return 'activated';
            }, 5);
        } catch (DeliveryException $error) {
            return in_array($error->reason, ['pending_contracts', 'ineligible', 'unavailable', 'changed', 'asset_unavailable', 'retry'], true)
                ? $error->reason : 'changed';
        } catch (ContractIssuanceException $error) {
            return $error->reason === 'original_unavailable' ? 'original_unavailable' : 'changed';
        } catch (Throwable) { return 'retry'; }
    }
}
