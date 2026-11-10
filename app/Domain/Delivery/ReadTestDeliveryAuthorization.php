<?php

namespace App\Domain\Delivery;

use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use SensitiveParameter;

/** Internal owner-bound context, never an HTTP response. No current access policy, filesystem work, or consumption. */
final class ReadTestDeliveryAuthorization
{
    public function forOwner(string $orderPublicId, #[SensitiveParameter] string $ownerKey, string $authorizationPublicId,
        #[SensitiveParameter] string $token): array
    {
        $evidence = app(DeliveryAccessEvidence::class);
        $order = $evidence->owned($orderPublicId, $ownerKey);
        if (! OrderRequest::uuid($authorizationPublicId) || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) !== 1
            || strlen((string) base64_decode(strtr($token, '-_', '+/').'=', true)) !== 32) {
            throw new DeliveryException('not_found');
        }
        $authorization = TestDeliveryAuthorization::where('order_id', $order->id)->where('public_id', $authorizationPublicId)->first();
        if (! $authorization || ! hash_equals($authorization->owner_key, $ownerKey)
            || ! hash_equals($authorization->token_hash, hash('sha256', $token))) {
            throw new DeliveryException('not_found');
        }
        $source = $evidence->source($order, app(TestAccessPolicy::class)->environmentAccount());
        $target = $evidence->verify($authorization, $source);
        $redemption = TestDeliveryRedemption::where('test_delivery_authorization_id', $authorization->id)->first();
        if ($redemption) {
            $evidence->verifyRedemption($redemption, $authorization, $source, $target);
        }

        return compact('order', 'source', 'target', 'authorization', 'redemption');
    }

    /**
     * v2 GET context: no token. The request must carry the server-side session secret this authorization was bound to at
     * issuance (the URL holds only the opaque ID). A foreign session, a logged-out session or another owner is not_found.
     */
    public function forSession(string $orderPublicId, #[SensitiveParameter] string $ownerKey, string $authorizationPublicId,
        #[SensitiveParameter] ?string $sessionSecret): array
    {
        $evidence = app(DeliveryAccessEvidence::class);
        $order = $evidence->owned($orderPublicId, $ownerKey);
        if (! OrderRequest::uuid($authorizationPublicId) || ! is_string($sessionSecret)) {
            throw new DeliveryException('not_found');
        }
        $authorization = TestDeliveryAuthorization::where('order_id', $order->id)->where('public_id', $authorizationPublicId)->first();
        if (! $authorization || ! hash_equals($authorization->owner_key, $ownerKey) || $authorization->policy_version !== TestAccessPolicy::V2) {
            throw new DeliveryException('not_found');
        }
        $source = $evidence->source($order, app(TestAccessPolicy::class)->environmentAccount());
        $target = $evidence->verify($authorization, $source);
        $transfer = $evidence->transfer($authorization);
        if (! hash_equals($transfer['session_binding_hash'], DeliveryAccessEvidence::sessionBinding($authorization->public_id, $sessionSecret))) {
            throw new DeliveryException('not_found');
        }
        $redemption = TestDeliveryRedemption::where('test_delivery_authorization_id', $authorization->id)->first();
        if ($redemption) {
            $evidence->verifyRedemption($redemption, $authorization, $source, $target);
        }
        $coverage = app(TestDeliveryCoverageLedger::class)->state($authorization, $target['file']['size_bytes']);
        if ($coverage['admitted'] > 0 && ! $redemption) {
            throw new DeliveryException('changed');
        }

        return compact('order', 'source', 'target', 'authorization', 'redemption', 'transfer', 'coverage');
    }
}
