<?php

namespace Tests\Support;

use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureAccess;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** BL-44: production customers, their sessions and their feature data for the data-rights tests. Needs ProductionIdentityFixture. */
trait ProductionDataRightsFixtures
{
    /** @return array{user: User, principal: ProductionCustomerPrincipal, binding: array} */
    protected function customer(string $email): array
    {
        return $this->enrollThroughLocalSmtp($email);
    }

    protected function signIn(array $customer): void
    {
        $this->actingAs($customer['user'], 'customer')->withSession(['_production_customer_identity' => ['binding_digest' => $customer['principal']->sessionBindingDigest()]]);
    }

    protected function staff(bool $enrolled = true): User
    {
        $admin = LicenseFixtures::admin();
        if ($enrolled) {
            $admin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        }

        return $admin->fresh();
    }

    protected function accountOf(array $customer): CustomerAccount
    {
        return CustomerAccount::findOrFail($customer['principal']->accountId);
    }

    /** The production feature identity the real library service needs, from the same verified session. */
    protected function feature(array $customer, string $feature): ProductionAccountFeatureIdentity
    {
        Auth::guard('customer')->setUser($customer['user']);
        $request = Request::create('/customer');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('_production_customer_identity', ['binding_digest' => $customer['principal']->sessionBindingDigest()]);
        $request->setUserResolver(fn (?string $guard = null) => Auth::guard($guard ?? 'web')->user());

        return (new ProductionAccountFeatureAccess)->forRequest($request, $feature);
    }

    /** A library with one playlist, and (optionally) a granted marketing consent, for the customer. */
    protected function populate(array $customer, string $playlist, bool $consent = false): void
    {
        $library = new ProductionListeningLibrary;
        $identity = $this->feature($customer, 'listening_library');
        $library->initialize($identity);
        $library->change($identity, ['action' => 'create-playlist', 'version' => 0, 'name' => $playlist]);
        if ($consent) {
            $notice = ['purpose' => 'email_marketing', 'version' => 'synthetic-production-purpose-v1',
                'notice' => 'SYNTHETIC choice fixture. No marketing messages are sent.', 'review_reference' => 'SYNTHETIC owner-policy fixture review'];
            config(['production-customer-preferences' => ['grants_enabled' => true, 'email_marketing' => $notice]]);
            $preferences = new ProductionConsentPreferences;
            $identity = $this->feature($customer, 'consent_preferences');
            $preferences->initialize($identity);
            $preferences->change($identity, ['action' => 'grant-consent', 'version' => 0, 'purpose' => 'email_marketing', 'noticeVersion' => $notice['version'],
                'noticeHash' => hash('sha256', $notice['notice']), 'affirmative' => true]);
        }
    }
}
