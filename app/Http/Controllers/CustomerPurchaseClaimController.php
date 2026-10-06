<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerPurchaseClaimPolicy;
use App\Domain\Customers\CustomerPurchaseClaims;
use App\Http\Middleware\CustomerPrivacy;
use App\Support\CommerceRequestIdentity;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CustomerPurchaseClaimController
{
    public function stage(Request $request, CommerceRequestIdentity $identity, CustomerPurchaseClaims $claims): Response
    {
        return $this->run(function () use ($request, $identity, $claims): array {
            $id = $this->orderId($request);
            if ($identity->principal($request) !== null || $request->user() !== null) {
                throw new CustomerAccessException;
            }
            $marker = $claims->stage($id, $identity->forRequest($request));
            $request->session()->put('_customer_purchase_claim', $marker);

            return ['orderId' => $id, 'staged' => true];
        });
    }

    public function complete(Request $request, CommerceRequestIdentity $identity, CustomerPurchaseClaims $claims): Response
    {
        return $this->run(function () use ($request, $identity, $claims): array {
            $id = $this->orderId($request);
            $principal = $identity->principal($request);
            $marker = $request->session()->get('_customer_purchase_claim');
            if (! $principal || ! is_array($marker)) {
                throw new CustomerAccessException;
            }
            $claims->complete($marker, $id, $principal, $identity->actor($request));

            return ['orderId' => $id, 'saved' => true];
        });
    }

    private function orderId(Request $request): string
    {
        app(CustomerPurchaseClaimPolicy::class)->requireEnabled();
        $raw = $request->attributes->get('_customer_body');
        if (! is_string($raw) || strlen($raw) > 128 || ! preg_match('/\A\s*\{\s*"orderId"\s*:\s*"([a-f0-9-]{36})"\s*\}\s*\z/D', $raw, $matches)
            || ! OrderRequest::uuid($matches[1])) {
            throw new CustomerAccessException;
        }

        return $matches[1];
    }

    private function run(callable $operation): Response
    {
        try {
            return CustomerPrivacy::protect(response()->json($operation()));
        } catch (\Throwable) {
            return CustomerPrivacy::protect(response()->json(['code' => 'PURCHASE_CLAIM_UNAVAILABLE',
                'message' => 'This purchase could not be saved. Use its original browser session and a current test account.'], 403));
        }
    }
}
