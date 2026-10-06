<?php

namespace App\Domain\Delivery;

use App\Domain\Commerce\Models\Order;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

final class RedeemTestDelivery
{
    /** One committed stream attempt; a later transport failure cannot restore or extend this authorization. */
    public function handle(string $orderPublicId, #[SensitiveParameter] string $ownerKey, string $authorizationPublicId,
        #[SensitiveParameter] string $token, ?User $actor = null, ?CustomerPrincipal $principal = null): PreparedDeliveryStream
    {
        ActivationPolicy::outsideTransactions();
        if ($principal) {
            app(CustomerAccess::class)->current($principal);
        }
        $policies = app(TestAccessPolicy::class);
        $policy = $policies->current();
        $account = $policies->account();
        $read = app(ReadTestDeliveryAuthorization::class)->forOwner($orderPublicId, $ownerKey, $authorizationPublicId, $token);
        $order = $read['order'];
        $source = $read['source'];
        $target = $read['target'];
        $authorization = $read['authorization'];
        $evidence = app(DeliveryAccessEvidence::class);
        $control = $evidence->control($source, TestDeliveryControl::where('order_id', $order->id)->first(), $authorization->control_version);
        $from = now()->toImmutable()->utc()->startOfSecond();
        $this->eligible($authorization, $control, $from, $read['redemption'] !== null);
        if ($principal) {
            app(CustomerAccess::class)->current($principal);
        }
        $prepared = app(PrepareTestDeliveryStream::class)->handle($target['file']);
        try {
            if (! hash_equals($target['file']['sha256'], $prepared->sha256) || $target['file']['size_bytes'] !== $prepared->sizeBytes) {
                throw new DeliveryException('target_unavailable');
            }
            $through = now()->toImmutable()->utc()->startOfSecond();
            if ($through->lessThan($from)) {
                throw new DeliveryException('retry');
            }
            DB::transaction(function () use ($order, $ownerKey, $source, $target, $authorization, $through,
                $policies, $policy, $account, $evidence, $actor, $principal): void {
                app(CustomerAccess::class)->lock($principal, $ownerKey, $actor);
                // Every writer takes the same order -> control -> authorization order. No private bytes are read under these locks.
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();
                if (! $locked || ! hash_equals($locked->owner_key, $ownerKey)) {
                    throw new DeliveryException('not_found');
                }
                $control = TestDeliveryControl::where('order_id', $locked->id)->lockForUpdate()->first();
                $current = TestDeliveryAuthorization::whereKey($authorization->id)->lockForUpdate()->first();
                if (! $current || $current->order_id !== $locked->id || ! hash_equals($current->owner_key, $ownerKey)
                    || ! hash_equals($current->token_hash, $authorization->token_hash)) {
                    throw new DeliveryException('not_found');
                }
                if ($policies->account() !== $account || CanonicalJson::encode($policies->current()) !== CanonicalJson::encode($policy)) {
                    throw new DeliveryException('unavailable');
                }
                $fresh = $evidence->source($locked, $account);
                $freshTarget = $evidence->verify($current, $fresh);
                if ($fresh['snapshot_hash'] !== $source['snapshot_hash'] || $fresh['activation']->evidence_hash !== $source['activation']->evidence_hash
                    || CanonicalJson::encode($freshTarget) !== CanonicalJson::encode($target)) {
                    throw new DeliveryException('changed');
                }
                $control = $evidence->control($fresh, $control, $current->control_version);
                $redemption = TestDeliveryRedemption::where('test_delivery_authorization_id', $current->id)->lockForUpdate()->first();
                if ($redemption) {
                    $evidence->verifyRedemption($redemption, $current, $fresh, $freshTarget);
                }
                $at = now()->toImmutable()->utc()->startOfSecond();
                if ($at->lessThan($through)) {
                    throw new DeliveryException('retry');
                }
                $this->eligible($current, $control, $at, $redemption !== null);
                $id = (string) Str::uuid();
                [$ciphertext, $hash] = $evidence->encrypt($evidence->redemption($current, $fresh, $freshTarget, $id, $at));
                $redemption = TestDeliveryRedemption::create(['public_id' => $id, 'test_delivery_authorization_id' => $current->id,
                    'control_version' => $current->control_version, 'content_hash' => $freshTarget['file']['sha256'],
                    'size_bytes' => $freshTarget['file']['size_bytes'], 'evidence_ciphertext' => $ciphertext,
                    'evidence_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION, 'redeemed_at' => $at]);
                $auditContext = ['order_public_id' => $locked->public_id,
                    'authorization_public_id' => $current->public_id, 'redemption_public_id' => $id, 'test_only' => true];
                if ($principal) {
                    AuditEvent::recordAttributed('commerce.delivery.redeemed', $redemption, $auditContext, $principal->userId);
                } else {
                    AuditEvent::record('commerce.delivery.redeemed', $redemption, $auditContext);
                }
                $evidence->verifyRedemption($redemption, $current, $fresh, $freshTarget);
                // Observers/auditing are part of the transaction too: expiry or withdrawal here must roll back consumption.
                $commitAt = now()->toImmutable()->utc()->startOfSecond();
                if ($commitAt->lessThan($at)) {
                    throw new DeliveryException('retry');
                }
                $this->eligible($current, $control, $commitAt, false);
                if ($policies->account() !== $account || CanonicalJson::encode($policies->current()) !== CanonicalJson::encode($policy)) {
                    throw new DeliveryException('unavailable');
                }
                app(CustomerAccess::class)->lock($principal, $ownerKey, $actor);
            }, 5);

            return $prepared;
        } catch (Throwable $error) {
            $prepared->close();
            throw $error;
        }
    }

    private function eligible(TestDeliveryAuthorization $authorization, TestDeliveryControl $control, CarbonImmutable $at, bool $redeemed): void
    {
        if ($at->lessThan($authorization->issued_at) || $at->lessThan($control->updated_at)) {
            throw new DeliveryException('retry');
        }
        if (! $at->lessThan($authorization->expires_at)) {
            throw new DeliveryException('expired');
        }
        if ($redeemed) {
            throw new DeliveryException('redeemed');
        }
    }
}
