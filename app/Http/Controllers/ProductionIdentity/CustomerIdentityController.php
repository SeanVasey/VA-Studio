<?php

namespace App\Http\Controllers\ProductionIdentity;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\CompleteIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\IdentityRequests;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Http\Middleware\ProductionIdentity\IdentityPrivacy;
use App\Http\Requests\ProductionIdentity\IdentityBody;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class CustomerIdentityController
{
    public function page(Request $request): Response
    {
        abort_unless((new IdentityPolicy)->enabled(), 404);
        Inertia::encryptHistory();

        return Inertia::render('ProductionCustomerIdentity', ['mode' => match (true) {
            $request->is('customer/create') => 'enroll', $request->is('customer/recover') => 'recover',
            $request->is('customer/access') => 'complete', default => 'sign-in',
        }, 'rehearsal' => (new IdentityPolicy)->provenance() === IdentityPolicy::REHEARSAL])->toResponse($request);
    }

    public function request(Request $request, IdentityRequests $requests): Response
    {
        abort_unless((new IdentityPolicy)->enabled(), 404);
        $body = IdentityBody::body($request, ['purpose', 'email', 'requestKey']);
        try {
            $requests->request($body['purpose'], $body['email'], $body['requestKey']);
        } catch (IdentityException) {
            return IdentityPrivacy::error(422);
        }

        return IdentityPrivacy::protect(response()->json(['accepted' => true], 202));
    }

    public function complete(Request $request, CompleteIdentity $completion): Response
    {
        abort_unless((new IdentityPolicy)->enabled(), 404);
        $body = IdentityBody::body($request, ['id', 'proof', 'password', 'name', 'requestKey']);
        try {
            $completion->complete(...array_map(fn ($field) => $body[$field], ['id', 'proof', 'password', 'name', 'requestKey']));
        } catch (IdentityException) {
            return IdentityPrivacy::error(422);
        }
        Inertia::clearHistory();

        return IdentityPrivacy::protect(response()->json(['completed' => true, 'next' => '/customer/sign-in']));
    }

    public function signIn(Request $request, ProductionCustomerSessions $sessions): Response
    {
        abort_unless((new IdentityPolicy)->enabled(), 404);
        $body = IdentityBody::body($request, ['email', 'password']);
        try {
            $verified = $sessions->authenticate($body['email'], $body['password']);
        } catch (IdentityException) {
            $verified = null;
        }
        if ($verified === null) {
            return IdentityPrivacy::error(422);
        }
        $guard = Auth::guard('customer');
        $principal = $verified['principal'];
        $guard->login($verified['user'], false);
        try {
            (new ProductionCustomerAccess)->current($principal, $verified['user']);
        } catch (IdentityException) {
            $guard->logoutCurrentDevice();

            return IdentityPrivacy::error(422);
        }
        $request->session()->forget(['_customer_access', '_customer_purchase_claim', '_quote_owner']);
        $request->session()->put('_production_customer_identity', ['binding_digest' => $principal->sessionBindingDigest()]);
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return IdentityPrivacy::protect(response()->json(['authenticated' => true, 'next' => '/customer']));
    }

    public function home(Request $request, ProductionCustomerSessions $sessions): Response
    {
        try {
            $principal = $sessions->principal($request);
        } catch (IdentityException) {
            return IdentityPrivacy::protect(redirect('/customer/sign-in'));
        }
        Inertia::encryptHistory();

        return IdentityPrivacy::protect(Inertia::render('ProductionCustomerIdentity', ['mode' => 'account',
            'rehearsal' => $principal->provenance === IdentityPolicy::REHEARSAL])->toResponse($request));
    }

    public function signOut(Request $request): Response
    {
        IdentityBody::body($request, []);
        Auth::guard('customer')->logoutCurrentDevice();
        $request->session()->forget('_production_customer_identity');
        $request->session()->migrate(true);
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return IdentityPrivacy::protect(response()->json(['authenticated' => false, 'next' => '/customer/sign-in']));
    }
}
