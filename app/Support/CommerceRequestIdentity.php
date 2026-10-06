<?php

namespace App\Support;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerPrincipal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** The browser supplies no owner key, account ID, audit actor or access version. */
final class CommerceRequestIdentity
{
    public function forRequest(Request $request): string
    {
        return $this->resolve($request)['owner'];
    }

    public function actor(Request $request): ?User
    {
        return $this->resolve($request)['actor'];
    }

    public function principal(Request $request): ?CustomerPrincipal
    {
        return $this->resolve($request)['principal'];
    }

    private function resolve(Request $request): array
    {
        if ($request->attributes->has('_commerce_identity')) {
            return $request->attributes->get('_commerce_identity');
        }
        $marker = $request->session()->get('_customer_access');
        $customer = Auth::guard('customer')->user();
        if ($marker !== null || $customer !== null) {
            if (! $customer instanceof User || ! is_array($marker)
                || array_keys($marker) !== ['account_id', 'access_version', 'credential_stamp']) {
                throw new CustomerAccessException;
            }
            $access = app(CustomerAccess::class);
            $principal = $access->principal($customer);
            if ($marker['account_id'] !== $principal->accountId || $marker['access_version'] !== $principal->accessVersion
                || ! is_string($marker['credential_stamp']) || ! hash_equals($principal->credentialStamp, $marker['credential_stamp'])) {
                throw new CustomerAccessException;
            }
            $identity = ['owner' => $principal->ownerKey, 'actor' => $access->current($principal), 'principal' => $principal];
        } else {
            $identity = ['owner' => app(QuoteOwner::class)->forRequest($request), 'actor' => $request->user(), 'principal' => null];
        }
        $request->attributes->set('_commerce_identity', $identity);

        return $identity;
    }
}
