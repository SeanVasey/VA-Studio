<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Memberships\Billing\BillingCheckoutParameters;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingSubscriptionApprovals;
use App\Domain\Memberships\Billing\MembershipCustomerPortal;
use App\Domain\Memberships\Billing\MembershipSubscriptionCheckout;
use App\Domain\Memberships\Production\MembershipRows;
use App\Jobs\RetrieveMembershipInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FakeBillingWriteGateway;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipPolicyFixtures;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

/**
 * Subscription Checkout and Customer Portal creation (BL-69) through the real identity, policy and approval writers and a fake
 * write gateway: exact parameters, idempotent retry, every default-off / environment / policy / price / member refusal making no
 * provider write, and provider answers that disagree never being handed out. Synthetic terms and ids only; no HTTP route exists.
 */
class MembershipCheckoutPortalTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipPolicyFixtures;
    use ProductionIdentityFixture;

    private FakeBillingWriteGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policySetup();
        config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
        F::configure();
        $this->gateway = new FakeBillingWriteGateway;
    }

    public function test_checkout_creates_one_exact_test_mode_session_and_a_retry_returns_the_original(): void
    {
        [$plan, $buyer] = $this->fixture();
        $result = $this->checkout($plan, $buyer, 'request-key-0000001');
        $this->assertSame(['cs_test_SYNTHETIC1', $plan['id']], [$result['session_ref'], $result['plan_version_id']]);
        $this->assertStringStartsWith('https://checkout.stripe.com/', $result['url']);
        [$read, $create] = [$this->gateway->of('price'), $this->gateway->of('checkout')];
        $this->assertSame([F::PRICE], array_column(array_column($read, 'params'), 'id'));
        $params = $create[0]['params'];
        $this->assertSame(['subscription', ['type' => 'flexible'], [['price' => F::PRICE, 'quantity' => 1]]],
            [$params['mode'], $params['subscription_data']['billing_mode'], $params['line_items']]);
        $this->assertSame('http://localhost/membership/done?s={CHECKOUT_SESSION_ID}', $params['success_url']);
        $this->assertSame(['va_member_ref', 'va_plan_version_id', 'va_purpose'], array_keys($params['metadata']));
        $this->assertSame($plan['id'], $params['metadata']['va_plan_version_id']);
        $this->assertArrayNotHasKey('customer', $params);
        $this->assertStringNotContainsString((string) $buyer['user']->email, json_encode($params));
        $this->assertMatchesRegularExpression(BillingCheckoutParameters::CHECKOUT_KEY, $create[0]['key']);

        // A retry of the same action (same request key) is the same provider call and the same session.
        $retry = $this->checkout($plan, $buyer, 'request-key-0000001');
        $this->assertSame($result['session_ref'], $retry['session_ref']);
        $this->assertSame(1, $this->gateway->created);
        $this->assertSame($create[0]['key'], $this->gateway->of('checkout')[1]['key']);
        // A new action is a new key and a new session; another member never shares a key.
        $this->assertNotSame($result['session_ref'], $this->checkout($plan, $buyer, 'request-key-0000002')['session_ref']);
        $this->assertSame(2, $this->gateway->created);
        $this->assertNothingAwarded();
    }

    public function test_every_default_off_environment_and_policy_refusal_makes_no_provider_call(): void
    {
        [$plan, $buyer] = $this->fixture();
        $cases = [
            'write switch off' => [fn () => config(['production-membership-billing-checkout.enabled' => false]), 'checkout_disabled'],
            'billing policy off' => [fn () => config(['production-membership-billing.enabled' => false]), 'disabled'],
            'live mode' => [fn () => config(['production-membership-billing.mode' => 'live']), 'live_not_authorized'],
            'membership policy off' => [fn () => config(['production-memberships.enabled' => false]), 'membership_disabled'],
            'plan not approved by billing' => [fn () => config(['production-membership-billing.approved_subscription_policy_hash' => str_repeat('a', 64)]), 'policy_not_approved'],
            'plan not approved by membership' => [fn () => config(['production-memberships.approved_policy_hash' => str_repeat('a', 64)]), 'policy_not_approved'],
            'identity gate off' => [fn () => config(['production-customer-identity.enabled' => false]), 'identity_refused'],
            'price not configured' => [fn () => config(['production-membership-billing-checkout.price_refs' => []]), 'price_not_configured'],
            'production environment' => [fn () => $this->app['env'] = 'production', 'environment'],
            'staging environment' => [fn () => $this->app['env'] = 'staging', 'provenance'],
        ];
        foreach ($cases as $name => [$break, $reason]) {
            $this->fixtureConfig($plan);
            $break();
            $this->refused(fn () => $this->checkout($plan, $buyer, 'request-key-0000001'), $reason, $name);
            $this->app['env'] = 'testing';
            $this->assertSame([], $this->gateway->calls, $name);
        }
        $this->fixtureConfig($plan);
        foreach (['short', 'has spaces in it 123456', str_repeat('a', 81)] as $key) {
            $this->refused(fn () => $this->checkout($plan, $buyer, $key), 'invalid_value');
        }
        $this->refused(fn () => $this->checkout(['id' => 'not-a-uuid'] + $plan, $buyer, 'request-key-0000001'), 'invalid_value');
        $this->refused(fn () => $this->checkout(['id' => '33333333-3333-4333-8333-333333333333'] + $plan, $buyer, 'request-key-0000001'), 'price_not_configured');
        $this->assertSame([], $this->gateway->calls);
        // With everything restored the same call succeeds, so each refusal above was caused by exactly its own break.
        $this->assertSame('cs_test_SYNTHETIC1', $this->checkout($plan, $buyer, 'request-key-0000001')['session_ref']);
    }

    public function test_a_stripe_price_that_differs_from_the_plan_is_refused_before_any_session_is_created(): void
    {
        [$plan, $buyer] = $this->fixture();
        $cases = ['amount' => ['unit_amount' => 1701], 'currency' => ['currency' => 'eur'], 'live price' => ['livemode' => true], 'inactive' => ['active' => false],
            'one time' => ['type' => 'one_time', 'recurring' => null], 'tiered' => ['billing_scheme' => 'tiered', 'tiers_mode' => 'graduated'],
            'pay what you want' => ['custom_unit_amount' => ['enabled' => true]], 'metered' => ['recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'metered']],
            'other id' => ['id' => 'price_SOMETHINGELSE']];
        foreach ($cases as $name => $override) {
            $this->gateway = new FakeBillingWriteGateway;
            $this->gateway->price = $override;
            $this->refused(fn () => $this->checkout($plan, $buyer, 'request-key-0000001'), 'price_mismatch', $name);
            $this->assertSame(0, $this->gateway->created, $name);
        }
    }

    public function test_a_provider_answer_that_disagrees_is_never_handed_out(): void
    {
        [$plan, $buyer] = $this->fixture();
        $cases = ['live session' => ['livemode' => true], 'payment mode' => ['mode' => 'payment'], 'not open' => ['status' => 'expired'],
            'other reference' => ['client_reference_id' => 'va-sub-'.str_repeat('0', 32)], 'other metadata' => ['metadata' => ['va_purpose' => 'x']],
            'customer attached' => ['customer' => F::CUSTOMER], 'other amount' => ['amount_total' => 1], 'no amount' => ['amount_total' => null],
            'other currency' => ['currency' => 'eur'], 'foreign url' => ['url' => 'https://evil.example.test/c/pay'], 'http url' => ['url' => 'http://checkout.stripe.com/c/pay/x'],
            'credentialed url' => ['url' => 'https://checkout.stripe.com@evil.example.test/c'], 'lookalike host' => ['url' => 'https://checkout.stripe.com.evil.test/c'],
            'no url' => ['url' => null], 'already expired' => ['expires_at' => time() - 5], 'live id' => ['id' => 'cs_live_SYNTHETIC']];
        foreach ($cases as $name => $override) {
            $this->gateway = new FakeBillingWriteGateway;
            $this->gateway->session = $override;
            $this->refused(fn () => $this->checkout($plan, $buyer, 'request-key-0000001'), 'provider_inconsistent', $name);
        }
        // Stripe's own checkout URLs carry a fragment and must pass.
        $this->gateway = new FakeBillingWriteGateway;
        $this->gateway->session = ['url' => 'https://checkout.stripe.com/c/pay/cs_test_SYNTHETIC#fidSYNTHETICFRAGMENT'];
        $this->assertStringContainsString('#fid', $this->checkout($plan, $buyer, 'request-key-0000001')['url']);
    }

    public function test_an_already_subscribed_member_and_another_members_principal_are_refused(): void
    {
        [$plan, $buyer, $staff] = $this->fixture();
        $other = $this->enrollThroughLocalSmtp('other@example.test');
        // One member's principal never opens a session for another member's user, in either direction.
        $this->refused(fn () => (new MembershipSubscriptionCheckout($this->gateway))->create($other['principal'], $buyer['user'], $plan['id'], 'request-key-0000001'), 'identity_refused');
        $this->refused(fn () => (new MembershipSubscriptionCheckout($this->gateway))->create($buyer['principal'], $other['user'], $plan['id'], 'request-key-0000001'), 'identity_refused');
        $this->assertSame([], $this->gateway->calls);
        $this->approve($plan, $buyer, $staff);
        $this->refused(fn () => $this->checkout($plan, $buyer, 'request-key-0000003'), 'already_subscribed');
        $this->assertSame([], $this->gateway->calls);
        // The other member is unaffected by the first member's approved subscription.
        $this->assertSame('cs_test_SYNTHETIC1', $this->checkout($plan, $other, 'request-key-0000004')['session_ref']);
    }

    public function test_portal_session_is_for_the_members_own_approved_customer_only(): void
    {
        [$plan, $buyer, $staff] = $this->fixture();
        $other = $this->enrollThroughLocalSmtp('other@example.test');
        $this->refused(fn () => $this->portal($buyer, 'request-key-0000001'), 'no_subscription');
        $this->approve($plan, $buyer, $staff);
        $this->assertSame([], $this->gateway->calls);
        $result = $this->portal($buyer, 'request-key-0000001');
        $this->assertSame('bps_SYNTHETIC1', $result['session_ref']);
        $this->assertStringStartsWith('https://billing.stripe.com/', $result['url']);
        $call = $this->gateway->of('portal')[0];
        $this->assertSame(['customer' => F::CUSTOMER, 'return_url' => 'http://localhost/account', 'configuration' => 'bpc_SYNTHETIC'], $call['params']);
        $this->assertMatchesRegularExpression(BillingCheckoutParameters::PORTAL_KEY, $call['key']);
        // The same action retried is the same session; the other member (no subscription) and mixed-up principals are refused.
        $this->assertSame('bps_SYNTHETIC1', $this->portal($buyer, 'request-key-0000001')['session_ref']);
        $this->assertSame(1, $this->gateway->created);
        $this->refused(fn () => $this->portal($other, 'request-key-0000001'), 'no_subscription');
        $this->refused(fn () => (new MembershipCustomerPortal($this->gateway))->open($other['principal'], $buyer['user'], 'request-key-0000001'), 'identity_refused');
        $this->assertSame(1, $this->gateway->created);

        $disagreements = ['live session' => ['livemode' => true], 'another customer' => ['customer' => 'cus_SOMEBODYELSE'], 'foreign url' => ['url' => 'https://billing.stripe.com.evil.test/p'],
            'checkout host' => ['url' => 'https://checkout.stripe.com/p'], 'other return' => ['return_url' => 'https://evil.example.test/'], 'other configuration' => ['configuration' => 'bpc_OTHER'],
            'live id' => ['id' => 'cs_test_NOTAPORTAL']];
        foreach ($disagreements as $name => $override) {
            $this->gateway = new FakeBillingWriteGateway;
            $this->gateway->portal = $override;
            $this->refused(fn () => $this->portal($buyer, 'request-key-0000009'), 'provider_inconsistent', $name);
        }
        $this->gateway = new FakeBillingWriteGateway;
        foreach ([fn () => config(['production-membership-billing-checkout.enabled' => false]), fn () => config(['production-membership-billing.enabled' => false]),
            fn () => config(['production-customer-identity.enabled' => false]), fn () => $this->app['env'] = 'production'] as $break) {
            $this->fixtureConfig($plan);
            $break();
            $this->refused(fn () => $this->portal($buyer, 'request-key-0000001'));
            $this->app['env'] = 'testing';
        }
        $this->assertSame([], $this->gateway->calls);
        $this->assertNothingAwarded();
    }

    public function test_console_commands_refuse_by_default_print_no_url_unasked_and_never_name_provider_detail(): void
    {
        [$plan, $buyer, $staff] = $this->fixture();
        $id = (string) $buyer['user']->id;
        $this->artisan('membership-billing:test-checkout', ['user' => '999999', 'plan_version' => $plan['id']])->expectsOutput('Unknown user.')->assertExitCode(2);
        // The shipped Billing provider I/O switch is off: the real SDK gateway refuses before any request.
        $this->artisan('membership-billing:test-checkout', ['user' => $id, 'plan_version' => $plan['id']])->expectsOutput('Refused (provider_io_disabled).')->assertFailed();
        config(['production-membership-billing-checkout.enabled' => false]);
        $this->artisan('membership-billing:test-checkout', ['user' => $id, 'plan_version' => $plan['id']])->expectsOutput('Refused (checkout_disabled).')->assertFailed();
        $this->artisan('membership-billing:test-portal', ['user' => $id])->expectsOutput('Refused (checkout_disabled).')->assertFailed();
        $this->fixtureConfig($plan);
        $this->app->instance(MembershipSubscriptionCheckout::class, new MembershipSubscriptionCheckout($this->gateway));
        $this->app->instance(MembershipCustomerPortal::class, new MembershipCustomerPortal($this->gateway));
        $this->artisan('membership-billing:test-checkout', ['user' => $id, 'plan_version' => $plan['id'], '--request-key' => 'request-key-0000001'])
            ->expectsOutputToContain('Created test checkout session cs_test_SYNTHETIC1')->doesntExpectOutputToContain('checkout.stripe.com')->assertSuccessful();
        $this->artisan('membership-billing:test-checkout', ['user' => $id, 'plan_version' => $plan['id'], '--request-key' => 'request-key-0000001', '--show-url' => true])
            ->expectsOutputToContain('https://checkout.stripe.com/')->assertSuccessful();
        $this->assertSame(1, $this->gateway->created);
        $this->artisan('membership-billing:test-portal', ['user' => $id])->expectsOutput('Refused (no_subscription).')->assertFailed();
        $this->approve($plan, $buyer, $staff);
        $this->artisan('membership-billing:test-portal', ['user' => $id, '--request-key' => 'request-key-0000002'])
            ->expectsOutputToContain('Opened test portal session bps_SYNTHETIC2')->doesntExpectOutputToContain('billing.stripe.com')->assertSuccessful();
        $this->artisan('membership-billing:test-portal', ['user' => $id, '--request-key' => 'request-key-0000002', '--show-url' => true])
            ->expectsOutputToContain('https://billing.stripe.com/')->assertSuccessful();
    }

    private function fixture(): array
    {
        $staff = $this->policyStaff();
        $plan = $this->createPolicy($staff);
        $this->fixtureConfig($plan);

        return [$plan, $this->enrollThroughLocalSmtp(), $staff];
    }

    private function fixtureConfig(array $plan): void
    {
        F::configure(['approved_subscription_policy_hash' => $plan['policy_hash']]);
        config(['production-customer-identity.enabled' => true]);
        config(['production-memberships.enabled' => true, 'production-memberships.authoring_enabled' => true, 'production-memberships.provenance' => IdentityPolicy::REHEARSAL,
            'production-memberships.approved_policy_hash' => $plan['policy_hash'], 'app.url' => 'http://localhost',
            'production-membership-billing-checkout' => ['enabled' => true, 'price_refs' => [$plan['id'] => F::PRICE],
                'success_url' => 'http://localhost/membership/done?s={CHECKOUT_SESSION_ID}', 'cancel_url' => 'http://localhost/membership',
                'portal_return_url' => 'http://localhost/account', 'portal_configuration_ref' => 'bpc_SYNTHETIC']]);
    }

    private function checkout(array $plan, array $member, string $key): array
    {
        return (new MembershipSubscriptionCheckout($this->gateway))->create($member['principal'], $member['user'], $plan['id'], $key);
    }

    private function portal(array $member, string $key): array
    {
        return (new MembershipCustomerPortal($this->gateway))->open($member['principal'], $member['user'], $key);
    }

    private function approve(array $plan, array $buyer, User $staff): array
    {
        return DB::transaction(fn () => (new BillingSubscriptionApprovals)->approve($plan['id'], F::CUSTOMER, F::SUBSCRIPTION, F::PRICE,
            $buyer['principal'], $buyer['user'], $staff, new MembershipRows));
    }

    private function refused(callable $call, ?string $reason = null, string $context = ''): void
    {
        try {
            $call();
        } catch (BillingException $error) {
            if ($reason !== null) {
                $this->assertSame($reason, $error->reason, $context);
            }

            return;
        }
        $this->fail('Expected a refusal ('.($reason ?? 'any').') '.$context);
    }

    private function assertNothingAwarded(): void
    {
        foreach (['production_membership_billing_observations', 'production_membership_billing_invoices', 'production_membership_paid_periods',
            'production_membership_credit_events', 'production_membership_billing_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        // Neither service ever queues a settling retrieval; only the identity fixture's own notices are queued.
        Queue::assertNotPushed(RetrieveMembershipInvoice::class);
    }
}
