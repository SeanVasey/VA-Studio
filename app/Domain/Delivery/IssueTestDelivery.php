<?php

namespace App\Domain\Delivery;

use App\Domain\Commerce\Models\Order;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

final class IssueTestDelivery
{
    public function handle(string $orderPublicId, #[SensitiveParameter] string $ownerKey, string $grantPublicId,
        string $kind, string $idempotencyKey, ?User $actor = null, ?CustomerPrincipal $principal = null): IssuedTestDelivery
    {
        ActivationPolicy::outsideTransactions();
        if ($principal) {
            app(CustomerAccess::class)->current($principal);
        }
        $policies = app(TestAccessPolicy::class);
        $policy = $policies->current();
        $account = $policies->account();
        $evidence = app(DeliveryAccessEvidence::class);
        $order = $evidence->owned($orderPublicId, $ownerKey);
        $source = $evidence->source($order, $account);
        $target = $evidence->target($source, $grantPublicId, $kind);
        $control = $evidence->control($source, TestDeliveryControl::where('order_id', $order->id)->first());
        $keyHash = $evidence->idempotencyHash($order, $ownerKey, $idempotencyKey);
        $requestHash = $evidence->requestHash($order, $ownerKey, $grantPublicId, $kind);
        $this->replay($order, $keyHash, $requestHash, $source);
        $from = now()->toImmutable()->utc()->startOfSecond();
        $this->budget($order, $source, $control, $from);
        if ($principal) {
            app(CustomerAccess::class)->current($principal);
        }
        $prepared = app(PrepareTestDeliveryStream::class)->handle($target['file']);
        try {
            if (! hash_equals($target['file']['sha256'], $prepared->sha256) || $target['file']['size_bytes'] !== $prepared->sizeBytes) {
                throw new DeliveryException('target_unavailable');
            }
        } finally {
            $prepared->close();
        }
        $through = now()->toImmutable()->utc()->startOfSecond();
        if ($through->lessThan($from)) {
            throw new DeliveryException('retry');
        }

        return DB::transaction(function () use ($order, $ownerKey, $source, $target, $control, $keyHash, $requestHash, $through,
            $policies, $policy, $account, $evidence, $actor, $principal): IssuedTestDelivery {
            app(CustomerAccess::class)->lock($principal, $ownerKey, $actor);
            // The order mutex serializes every target and every idempotency key, including the last rolling-budget slot.
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || ! hash_equals($locked->owner_key, $ownerKey)) {
                throw new DeliveryException('not_found');
            }
            $currentControl = TestDeliveryControl::where('order_id', $locked->id)->lockForUpdate()->first();
            if ($policies->account() !== $account || CanonicalJson::encode($policies->current()) !== CanonicalJson::encode($policy)) {
                throw new DeliveryException('unavailable');
            }
            $fresh = $evidence->source($locked, $account);
            $freshTarget = $evidence->target($fresh, $target['grant_public_id'], $target['kind']);
            if ($fresh['snapshot_hash'] !== $source['snapshot_hash'] || $fresh['activation']->evidence_hash !== $source['activation']->evidence_hash
                || CanonicalJson::encode($freshTarget) !== CanonicalJson::encode($target)) {
                throw new DeliveryException('changed');
            }
            $currentControl = $evidence->control($fresh, $currentControl, $control->control_version);
            $this->replay($locked, $keyHash, $requestHash, $fresh);
            $at = now()->toImmutable()->utc()->startOfSecond();
            if ($at->lessThan($through)) {
                throw new DeliveryException('retry');
            }
            $this->budget($locked, $fresh, $currentControl, $at);
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $columns = ['public_id' => (string) Str::uuid(), 'order_id' => $locked->id,
                'test_fulfillment_activation_id' => $fresh['activation']->id, 'test_delivery_control_id' => $currentControl->id,
                'control_version' => $currentControl->control_version, 'owner_key' => $ownerKey, 'token_hash' => hash('sha256', $token),
                'idempotency_key_hash' => $keyHash, 'request_hash' => $requestHash, 'kind' => $target['kind'],
                'license_grant_id' => $target['license_grant_id'], 'grant_contract_id' => $target['grant_contract_id'],
                'pending_entitlement_id' => $target['pending_entitlement_id'], 'policy_version' => $policy['version'],
                'issued_at' => $at, 'expires_at' => $at->addSeconds(60)];
            [$ciphertext, $hash] = $evidence->encrypt($evidence->capture($fresh, $target, $currentControl, $columns));
            $authorization = TestDeliveryAuthorization::create($columns + ['evidence_ciphertext' => $ciphertext,
                'evidence_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION]);
            $auditContext = ['order_public_id' => $locked->public_id,
                'authorization_public_id' => $authorization->public_id, 'kind' => $authorization->kind, 'test_only' => true];
            if ($principal) {
                AuditEvent::recordAttributed('commerce.delivery.authorized', $authorization, $auditContext, $principal->userId);
            } else {
                AuditEvent::record('commerce.delivery.authorized', $authorization, $auditContext);
            }
            $evidence->verify($authorization, $fresh);
            $commitAt = now()->toImmutable()->utc()->startOfSecond();
            if ($commitAt->lessThan($at)) {
                throw new DeliveryException('retry');
            }
            if (! $commitAt->lessThan($authorization->expires_at)) {
                throw new DeliveryException('expired');
            }
            if ($policies->account() !== $account || CanonicalJson::encode($policies->current()) !== CanonicalJson::encode($policy)) {
                throw new DeliveryException('unavailable');
            }
            app(CustomerAccess::class)->lock($principal, $ownerKey, $actor);

            return new IssuedTestDelivery($authorization->public_id, $token, $authorization->expires_at, $target['filename'], $target['mime_type']);
        }, 5);
    }

    private function replay(Order $order, string $keyHash, string $requestHash, array $source): void
    {
        $existing = TestDeliveryAuthorization::where('order_id', $order->id)->where('idempotency_key_hash', $keyHash)->first();
        if (! $existing) {
            return;
        }
        app(DeliveryAccessEvidence::class)->verify($existing, $source);
        throw new DeliveryException(hash_equals($existing->request_hash, $requestHash) ? 'already_issued' : 'conflict');
    }

    private function budget(Order $order, array $source, TestDeliveryControl $control, CarbonImmutable $at): void
    {
        $query = TestDeliveryAuthorization::where('order_id', $order->id);
        if ($at->lessThan($source['activation']->activated_at) || $at->lessThan($control->updated_at)
            || (clone $query)->where('issued_at', '>', $at)->exists()) {
            throw new DeliveryException('retry');
        }
        if ($query->where('issued_at', '>', $at->subSeconds(60))->count() >= 3) {
            throw new DeliveryException('budget_exhausted');
        }
    }
}
