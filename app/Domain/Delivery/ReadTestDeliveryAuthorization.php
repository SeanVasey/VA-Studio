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
            || strlen((string) base64_decode(strtr($token, '-_', '+/').'=', true)) !== 32) { throw new DeliveryException('not_found'); }
        $authorization = TestDeliveryAuthorization::where('order_id', $order->id)->where('public_id', $authorizationPublicId)->first();
        if (! $authorization || ! hash_equals($authorization->owner_key, $ownerKey)
            || ! hash_equals($authorization->token_hash, hash('sha256', $token))) { throw new DeliveryException('not_found'); }
        $source = $evidence->source($order, app(TestAccessPolicy::class)->environmentAccount());
        $target = $evidence->verify($authorization, $source);
        $redemption = TestDeliveryRedemption::where('test_delivery_authorization_id', $authorization->id)->first();
        if ($redemption) { $evidence->verifyRedemption($redemption, $authorization, $source, $target); }
        return compact('order', 'source', 'target', 'authorization', 'redemption');
    }
}
