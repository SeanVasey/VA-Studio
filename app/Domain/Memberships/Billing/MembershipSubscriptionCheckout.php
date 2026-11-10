<?php

namespace App\Domain\Memberships\Billing;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Models\User;
use App\Support\Environment\TestEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates a Stripe Checkout Session in subscription mode for one signed-in member and one approved plan version (BL-69,
 * test mode only, no HTTP route: `MembershipHttpMount` stays a literal false). Everything is default-off behind the Billing
 * policy, the write switch, the membership policy and the customer identity gate, and the call refuses outside the test-commerce
 * environments. The price id is staff-entered configuration and is re-read from Stripe: it must be an active, licensed, per-unit
 * recurring price whose currency and amount equal the plan version's. Creating a session grants nothing: only a settled
 * `invoice.paid` re-read from Stripe ever awards (BillingSettlement), and binding the resulting subscription to the member is
 * the staff approval (BillingSubscriptionApprovals), which this class neither performs nor shortcuts.
 *
 * The idempotency key derives from the member, plan version, configured price and the caller's request key, so a retry of one
 * action returns the original session instead of a second one, and no random or time value enters the parameters.
 */
final class MembershipSubscriptionCheckout
{
    private readonly BillingProviderWriteGateway $gateway;

    public function __construct(?BillingProviderWriteGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new StripeSdkBillingWriteGateway;
    }

    /** @return array{session_ref: string, url: string, expires_at: int, plan_version_id: string} */
    public function create(ProductionCustomerPrincipal $principal, User $buyer, string $planVersionId, string $requestKey): array
    {
        BillingException::require(TestEnvironment::admitsTestCommerce(), 'environment');
        BillingException::require(preg_match(BillingCheckoutParameters::REQUEST_KEY, $requestKey) === 1
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $planVersionId) === 1, 'invalid_value');
        $billing = new BillingPolicy;
        $configuration = $billing->current();
        $settings = BillingCheckoutConfiguration::checkout($planVersionId);
        try {
            $membership = new MembershipPolicy;
            $membershipConfiguration = $membership->facts();
            BillingException::require($membershipConfiguration['provenance'] === $configuration['provenance'], 'binding_mismatch');
            $row = DB::table(MembershipRecords::TABLE)->where('id', $planVersionId)->first();
            BillingException::require($row !== null, 'policy_absent');
            $document = MembershipPolicyVersions::document((array) $row);
            BillingException::require($document->hash() === $configuration['approved_subscription_policy_hash']
                && $document->hash() === $membershipConfiguration['approved_policy_hash']
                && $document->facts['provenance'] === $configuration['provenance'], 'policy_not_approved');
            // The signed-in member is proven current (identity gate, access version) before any provider I/O.
            (new ProductionCustomerAccess)->current($principal, $buyer);
        } catch (MembershipException $error) {
            // Typed refusals from the membership and identity layers collapse to this layer's one exception, without chaining.
            throw new BillingException('membership_'.$error->reason);
        } catch (IdentityException) {
            throw new BillingException('identity_refused');
        }
        BillingException::require($principal->provenance === $configuration['provenance'], 'binding_mismatch');
        BillingException::require((new MembershipSubscriptionBindings)->forMember($principal->accountId, $principal->userId, $configuration) === [], 'already_subscribed');

        $this->assertPrice($this->gateway->retrievePrice($settings['price_ref']), $settings['price_ref'], $document->facts['billing_currency'], $document->facts['billing_price_minor']);

        $account = $configuration['account_ref'];
        $mode = $configuration['mode'];
        $member = BillingValues::hash('member', $account, $mode, $principal->accountId.':'.$principal->userId);
        $reference = 'va-sub-'.substr(BillingValues::hash('checkout-reference', $account, $mode, $member."\0".$planVersionId."\0".$requestKey), 0, 32);
        $key = 'va-membership-checkout-v1-'.BillingValues::hash('checkout-key', $account, $mode, $member."\0".$planVersionId."\0".$settings['price_ref']."\0".$requestKey);
        $metadata = ['va_member_ref' => $member, 'va_plan_version_id' => $planVersionId, 'va_purpose' => 'production_membership_subscription_checkout'];
        $params = BillingCheckoutParameters::subscription($settings['price_ref'], $settings['success_url'], $settings['cancel_url'], $reference, $metadata);

        $session = $this->gateway->createSubscriptionCheckoutSession($params, $key);
        $verified = $this->verified($session, $reference, $metadata, $document->facts['billing_currency'], $document->facts['billing_price_minor']);
        $billing->proveConfiguration($configuration);
        $membership->proveConfiguration($membershipConfiguration);

        return ['session_ref' => $session['id'], 'url' => $verified['url'], 'expires_at' => $verified['expires_at'], 'plan_version_id' => $planVersionId];
    }

    private function assertPrice(array $price, string $priceRef, string $currency, int $amountMinor): void
    {
        $recurring = $price['recurring'] ?? null;
        BillingException::require(($price['id'] ?? null) === $priceRef && ($price['livemode'] ?? null) === false && ($price['active'] ?? null) === true
            && ($price['type'] ?? null) === 'recurring' && ($price['billing_scheme'] ?? null) === 'per_unit'
            && ($price['tiers_mode'] ?? null) === null && ($price['custom_unit_amount'] ?? null) === null && ($price['transform_quantity'] ?? null) === null
            && is_array($recurring) && ($recurring['usage_type'] ?? null) === 'licensed'
            && is_int($price['unit_amount'] ?? null) && $price['unit_amount'] === $amountMinor
            && is_string($price['currency'] ?? null) && strtoupper($price['currency']) === $currency, 'price_mismatch');
    }

    /** @return array{url: string, expires_at: int} */
    private function verified(array $session, string $reference, array $metadata, string $currency, int $amountMinor): array
    {
        $sessionMetadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        ksort($sessionMetadata);
        $expected = $metadata;
        ksort($expected);
        $now = CarbonImmutable::now('UTC')->timestamp;
        BillingException::require(is_string($session['id'] ?? null) && preg_match('/\Acs_test_[A-Za-z0-9]{1,120}\z/D', $session['id']) === 1
            && ($session['livemode'] ?? null) === false && ($session['mode'] ?? null) === 'subscription' && ($session['status'] ?? null) === 'open'
            && ($session['client_reference_id'] ?? null) === $reference && $sessionMetadata === $expected && ($session['customer'] ?? null) === null
            && is_int($session['amount_total'] ?? null) && $session['amount_total'] === $amountMinor
            && is_string($session['currency'] ?? null) && strtoupper($session['currency']) === $currency
            && is_int($session['expires_at'] ?? null) && $session['expires_at'] > $now && $session['expires_at'] <= $now + 90000, 'provider_inconsistent');
        $url = $session['url'] ?? null;
        BillingException::require(self::stripeUrl($url, 'checkout.stripe.com'), 'provider_inconsistent');

        return ['url' => $url, 'expires_at' => $session['expires_at']];
    }

    /** A hosted Stripe page only: https, the expected host, no credentials or port, bounded length. Stripe's checkout URLs carry a fragment, so it is allowed. */
    public static function stripeUrl(mixed $url, string $host): bool
    {
        if (! is_string($url) || strlen($url) > 2048 || preg_match('/\A[\x21-\x7e]+\z/D', $url) !== 1) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && strtolower($parts['host'] ?? '') === $host
            && array_intersect_key($parts, ['user' => 1, 'pass' => 1, 'port' => 1]) === [];
    }
}
