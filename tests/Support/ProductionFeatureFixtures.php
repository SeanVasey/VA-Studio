<?php

namespace Tests\Support;

use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureAccess;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeaturePolicy;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Synthetic challenge proof passed to the real writer; no mail/SMTP/provider invocation. */
trait ProductionFeatureFixtures
{
    use ProductionIdentityFixture;

    protected function featureSetup(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->identitySetup();
        config(['customer.test_accounts_enabled' => false, 'production-account-features.enabled' => true,
            'production-account-features.provenance' => IdentityPolicy::REHEARSAL,
            'production-account-features.versions' => ProductionAccountFeaturePolicy::VERSIONS]);
    }

    protected function featureIdentity(string $feature = 'listening_library', string $email = 'feature-owner@example.test'): ProductionAccountFeatureIdentity
    {
        $challenge = $this->requestIdentity('enroll', $email);
        $this->completeIdentity($challenge);
        $verified = (new ProductionCustomerSessions)->authenticate($email, 'MailboxPassword123');
        $this->assertNotNull($verified);

        return $this->featureFor($verified, $feature);
    }

    protected function featureFor(array $verified, string $feature): ProductionAccountFeatureIdentity
    {
        Auth::guard('customer')->setUser($verified['user']);
        $request = Request::create('/customer');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('_production_customer_identity', ['binding_digest' => $verified['principal']->sessionBindingDigest()]);
        $request->setUserResolver(fn (?string $guard = null) => Auth::guard($guard ?? 'web')->user());

        return (new ProductionAccountFeatureAccess)->forRequest($request, $feature);
    }

    protected function purposeConfiguration(): array
    {
        $notice = ['purpose' => 'email_marketing', 'version' => 'synthetic-production-purpose-v1',
            'notice' => 'SYNTHETIC choice fixture. No marketing messages are sent.', 'review_reference' => 'SYNTHETIC owner-policy fixture review'];
        config(['production-customer-preferences' => ['grants_enabled' => true, 'email_marketing' => $notice]]);

        return $notice;
    }

    protected function productionGrant(int $version = 0): array
    {
        $notice = $this->purposeConfiguration();

        return ['action' => 'grant-consent', 'version' => $version, 'purpose' => 'email_marketing', 'noticeVersion' => $notice['version'],
            'noticeHash' => hash('sha256', $notice['notice']), 'affirmative' => true];
    }

    protected function productionWithdraw(int $version = 0): array
    {
        return ['action' => 'withdraw-consent', 'version' => $version, 'purpose' => 'email_marketing'];
    }
}
