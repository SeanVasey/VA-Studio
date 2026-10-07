<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\Checkout\CheckoutPolicy;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerIdentityPolicy;
use App\Domain\Customers\CustomerPurchaseClaimPolicy;
use App\Domain\Customers\CustomerPurchaseClaims;
use App\Domain\Customers\CustomerSessions;
use App\Domain\Memberships\MembershipPolicy;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use App\Http\Middleware\CustomerPrivacy;
use App\Support\CommerceRequestIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class CustomerSessionController
{
    public function signIn(Request $request): Response
    {
        abort_unless(app(CustomerAccessPolicy::class)->enabled(), 404);

        return Inertia::render('CustomerSignIn', ['testOnly' => true, 'siteContent' => $this->chrome(),
            'purchaseClaimsEnabled' => app(CustomerPurchaseClaimPolicy::class)->enabled(),
            'selfServiceEnabled' => app(CustomerIdentityPolicy::class)->enabled()])->toResponse($request);
    }

    public function store(Request $request): Response
    {
        abort_unless(app(CustomerAccessPolicy::class)->enabled(), 404);
        $body = $this->body($request, ['email', 'password']);
        if (! is_string($body['email']) || strlen($body['email']) > 254 || ! filter_var($body['email'], FILTER_VALIDATE_EMAIL)
            || ! is_string($body['password']) || strlen($body['password']) < 1 || strlen($body['password']) > 1024) {
            return CustomerPrivacy::error(422);
        }
        $guard = Auth::guard('customer');
        $verified = app(CustomerSessions::class)->authenticate($body['email'], $body['password']);
        if ($verified === null) {
            return CustomerPrivacy::error(422);
        }
        $principal = $verified['principal'];
        $guard->login($verified['user'], false);
        try {
            // Never replace this credential-bound principal with a fresh stamp after login events or a reset.
            app(CustomerAccess::class)->current($principal);
        } catch (CustomerAccessException) {
            $guard->logoutCurrentDevice();

            return CustomerPrivacy::error(422);
        }
        $marker = $request->session()->get('_customer_purchase_claim');
        if (is_array($marker)) {
            try {
                $request->session()->put('_customer_purchase_claim', app(CustomerPurchaseClaims::class)->bind($marker, $principal));
            } catch (CustomerAccessException) {
                $request->session()->forget('_customer_purchase_claim');
            }
        }
        $request->session()->forget('_quote_owner');
        $request->session()->put('_customer_access', ['account_id' => $principal->accountId,
            'access_version' => $principal->accessVersion, 'credential_stamp' => $principal->credentialStamp]);
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return response()->json(['authenticated' => true, 'next' => '/account']);
    }

    public function library(Request $request, CommerceRequestIdentity $identity): Response
    {
        abort_unless(app(CustomerAccessPolicy::class)->enabled(), 404);
        try {
            $principal = $identity->principal($request);
            if (! $principal) {
                return redirect('/account/sign-in');
            }
            $user = app(CustomerAccess::class)->current($principal);
            $props = ['testOnly' => true, 'customer' => ['name' => $user->name], 'siteContent' => $this->chrome(),
                'testCheckoutEnabled' => app(CheckoutPolicy::class)->enabled()];
            $marker = $request->session()->get('_customer_purchase_claim');
            if (is_array($marker)) {
                try {
                    $props['guestPurchaseClaim'] = app(CustomerPurchaseClaims::class)->view($marker, $principal);
                } catch (CustomerAccessException) {
                    $request->session()->forget('_customer_purchase_claim');
                }
            }
            app(CustomerAccess::class)->current($principal);
            $props['testMembershipsEnabled'] = app(MembershipPolicy::class)->enabled();
            // Transient UI invalidation only; never customer identity or request authority.
            $props['membershipHistoryScope'] = $props['testMembershipsEnabled'] ? bin2hex(random_bytes(16)) : null;

            Inertia::encryptHistory();

            return Inertia::render('CustomerLibrary', $props)->toResponse($request);
        } catch (CustomerAccessException) {
            return redirect('/account/sign-in');
        }
    }

    public function destroy(Request $request): Response
    {
        $this->body($request, []);
        // Logout is still usable after feature/access withdrawal. It never logs into or out of the staff guard.
        Auth::guard('customer')->logoutCurrentDevice();
        $request->session()->forget(['_customer_access', '_quote_owner', '_customer_purchase_claim']);
        $request->session()->migrate(true);
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return response()->json(['authenticated' => false, 'next' => '/account/sign-in']);
    }

    private function chrome(): array
    {
        return app(EditorialContent::class)->chrome(app(SiteContent::class)->current());
    }

    private function body(Request $request, array $fields): array
    {
        $raw = $request->attributes->get('_customer_body');
        $object = is_string($raw) ? json_decode($raw, false, 3) : null;
        abort_unless($object instanceof \stdClass, 422);
        $body = get_object_vars($object);
        abort_if(count($body) !== count($fields) || array_diff(array_keys($body), $fields), 422);
        // A valid flat string-only object has exactly one key token and one value token per field.
        // Count complete JSON string tokens, including escaped quotes; duplicate keys cannot disappear in json_decode.
        abort_if(count(array_filter($body, 'is_string')) !== count($fields)
            || preg_match_all('/"(?:[^"\\\\]|\\\\.)*"/s', $raw) !== count($fields) * 2, 422);

        return $body;
    }
}
