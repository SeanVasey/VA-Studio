<?php

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerIdentityChallenges;
use App\Domain\Customers\CustomerIdentityPolicy;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use App\Http\Middleware\CustomerPrivacy;
use App\Http\Requests\CustomerIdentityRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class CustomerIdentityController
{
    public function page(Request $request): Response
    {
        abort_unless(app(CustomerIdentityPolicy::class)->enabled(), 404);
        $props = ['testOnly' => true, 'siteContent' => app(EditorialContent::class)->chrome(app(SiteContent::class)->current())];
        Inertia::encryptHistory();
        if ($request->is('account/access')) {
            return Inertia::render('CustomerAccessFinish', $props)->toResponse($request);
        }
        $props['purpose'] = $request->is('account/create') ? 'enroll' : 'recover';

        return Inertia::render('CustomerAccessRequest', $props)->toResponse($request);
    }

    public function request(Request $request, CustomerIdentityChallenges $challenges): Response
    {
        abort_unless(app(CustomerIdentityPolicy::class)->enabled(), 404);
        $body = CustomerIdentityRequest::body($request, ['purpose', 'email', 'requestKey']);
        $owner = $request->session()->get('_customer_identity_request_owner');
        if (! is_string($owner) || ! preg_match('/\A[a-f0-9]{64}\z/D', $owner)) {
            $owner = bin2hex(random_bytes(32));
            $request->session()->put('_customer_identity_request_owner', $owner);
        }
        try {
            $challenges->request($body['purpose'], $body['email'], $body['requestKey'], $owner);
        } catch (CustomerAccessException) {
            return CustomerPrivacy::error(422);
        }

        return response()->json(['accepted' => true], 202);
    }

    public function complete(Request $request, CustomerIdentityChallenges $challenges): Response
    {
        abort_unless(app(CustomerIdentityPolicy::class)->enabled(), 404);
        $body = CustomerIdentityRequest::body($request, ['purpose', 'id', 'proof', 'name', 'password', 'requestKey']);
        try {
            $challenges->complete(...array_map(fn ($field) => $body[$field], ['purpose', 'id', 'proof', 'name', 'password', 'requestKey']));
        } catch (CustomerAccessException) {
            return CustomerPrivacy::error(422);
        }
        // This command never authenticates either guard, logs staff out, or changes ownership/session state.
        Inertia::clearHistory();

        return response()->json(['completed' => true, 'next' => '/account/sign-in']);
    }
}
