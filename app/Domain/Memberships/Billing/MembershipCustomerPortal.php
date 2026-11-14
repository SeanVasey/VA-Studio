<?php

namespace App\Domain\Memberships\Billing;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Models\User;
use App\Support\Environment\TestEnvironment;

/**
 * Opens a Stripe Customer Portal session for the signed-in member's own approved subscription customer (BL-69, test mode only,
 * no HTTP route). The customer comes only from the member's sealed v2 approval, never from a caller, and the session URL is
 * returned once and never stored or logged: Stripe issues it short-lived. The idempotency key derives from the member, customer
 * and request key, so a request key names ONE user action; reusing it after the URL lapsed returns the same expired session, and a
 * new action takes a new key. The portal's own settings (what a member may change) live in the Stripe portal configuration; a
 * subscription change made there reaches us only as a hint event and never awards (BillingWebhookIntake).
 */
final class MembershipCustomerPortal
{
    private readonly BillingProviderWriteGateway $gateway;

    public function __construct(?BillingProviderWriteGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new StripeSdkBillingWriteGateway;
    }

    /** @return array{session_ref: string, url: string} */
    public function open(ProductionCustomerPrincipal $principal, User $member, string $requestKey): array
    {
        BillingException::require(TestEnvironment::admitsTestCommerce(), 'environment');
        BillingException::require(preg_match(BillingCheckoutParameters::REQUEST_KEY, $requestKey) === 1, 'invalid_value');
        $billing = new BillingPolicy;
        $configuration = $billing->current();
        $settings = BillingCheckoutConfiguration::portal();
        try {
            (new ProductionCustomerAccess)->current($principal, $member);
        } catch (IdentityException) {
            throw new BillingException('identity_refused');
        }
        BillingException::require($principal->provenance === $configuration['provenance'], 'binding_mismatch');
        $bindings = (new MembershipSubscriptionBindings)->forMember($principal->accountId, $principal->userId, $configuration);
        BillingException::require($bindings !== [], 'no_subscription');
        $customers = array_values(array_unique(array_map(fn (array $binding): string => $binding['expectation']->customerRef, $bindings)));
        BillingException::require(count($customers) === 1, 'ambiguous_source');
        $customer = $customers[0];

        $account = $configuration['account_ref'];
        $mode = $configuration['mode'];
        $key = 'va-membership-portal-v1-'.BillingValues::hash('portal-key', $account, $mode, $principal->accountId.':'.$principal->userId."\0".$customer."\0".$requestKey);
        $session = $this->gateway->createPortalSession(BillingCheckoutParameters::portal($customer, $settings['return_url'], $settings['configuration_ref']), $key);
        BillingException::require(is_string($session['id'] ?? null) && preg_match('/\Abps_[A-Za-z0-9]{1,120}\z/D', $session['id']) === 1
            && ($session['livemode'] ?? null) === false && ($session['customer'] ?? null) === $customer
            && ($session['return_url'] ?? null) === $settings['return_url']
            && ($settings['configuration_ref'] === null || ($session['configuration'] ?? null) === $settings['configuration_ref'])
            && MembershipSubscriptionCheckout::stripeUrl($session['url'] ?? null, 'billing.stripe.com'), 'provider_inconsistent');
        $billing->proveConfiguration($configuration);

        return ['session_ref' => $session['id'], 'url' => $session['url']];
    }
}
