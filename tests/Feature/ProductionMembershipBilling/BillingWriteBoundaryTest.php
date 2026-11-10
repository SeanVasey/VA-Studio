<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingCheckoutConfiguration;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingProviderGateway;
use App\Domain\Memberships\Billing\BillingProviderWriteGateway;
use App\Domain\Memberships\Billing\StripeSdkBillingGateway;
use App\Domain\Memberships\Billing\StripeSdkBillingWriteGateway;
use App\Http\Membership\MembershipHttpMount;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\Support\BillingStripeFixtures as F;
use Tests\TestCase;

/**
 * BL-69 stays unmounted and default-off: MembershipHttpMount is a literal false, no route reaches the new services, the read-only
 * gateway gained no write, the write gateway has exactly its three operations, the shipped write configuration is off, and a
 * configured URL cannot leave this application's host.
 */
class BillingWriteBoundaryTest extends TestCase
{
    private const PLAN = '11111111-1111-4111-8111-111111111111';

    public function test_membership_http_mount_is_still_a_literal_false_and_no_route_reaches_the_write_services(): void
    {
        $this->assertFalse((require base_path('config/production-memberships.php'))['http_mounted']);
        $this->assertStringContainsString("'http_mounted' => false,", (string) file_get_contents(base_path('config/production-memberships.php')));
        $this->assertFalse(MembershipHttpMount::admitted());
        $this->assertFalse(config('production-memberships.http_mounted'));
        foreach (Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('/membership-billing|subscription|portal/i', $route->uri().' '.($route->getActionName()), $route->uri());
        }
        $source = '';
        foreach (['routes', 'app/Http'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS)) as $file) {
                $source .= $file->isFile() && $file->getExtension() === 'php' ? (string) file_get_contents($file->getPathname()) : '';
            }
        }
        foreach (['MembershipSubscriptionCheckout', 'MembershipCustomerPortal', 'StripeSdkBillingWriteGateway', 'BillingProviderWriteGateway'] as $class) {
            $this->assertStringNotContainsString($class, $source, $class.' must not be referenced by any route or HTTP class');
        }
    }

    public function test_gateways_are_separated_and_the_write_gateway_has_exactly_its_operations(): void
    {
        $read = array_map(fn (\ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(BillingProviderGateway::class))->getMethods());
        $this->assertSame(['provenance', 'account', 'retrieveInvoice', 'listInvoicePayments', 'retrievePaymentIntent', 'retrieveCharge', 'retrieveBalanceTransaction', 'retrieveSubscription'], $read);
        $write = array_map(fn (\ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(BillingProviderWriteGateway::class))->getMethods());
        $this->assertSame(['provenance', 'retrievePrice', 'createSubscriptionCheckoutSession', 'createPortalSession'], $write);
        $this->assertNotInstanceOf(BillingProviderWriteGateway::class, new StripeSdkBillingGateway);
        $this->assertNotInstanceOf(BillingProviderGateway::class, new StripeSdkBillingWriteGateway);
        $source = (string) file_get_contents(base_path('app/Domain/Memberships/Billing/StripeSdkBillingWriteGateway.php'));
        preg_match_all('/\$client->([a-zA-Z]+)->(?:([a-zA-Z]+)->)?([a-zA-Z]+)\(/', $source, $calls, PREG_SET_ORDER);
        $this->assertEqualsCanonicalizing(['accounts.retrieve', 'prices.retrieve', 'checkout.sessions.create', 'billingPortal.sessions.create'],
            array_map(fn (array $call): string => implode('.', array_filter([$call[1], $call[2], $call[3]])), $calls));
    }

    public function test_shipped_write_configuration_is_literal_default_off(): void
    {
        $this->assertSame(['enabled' => false, 'price_refs' => [], 'success_url' => null, 'cancel_url' => null, 'portal_return_url' => null, 'portal_configuration_ref' => null],
            require base_path('config/production-membership-billing-checkout.php'));
        $this->assertSame(require base_path('config/production-membership-billing-checkout.php'), config('production-membership-billing-checkout'));
        $this->assertRefused(fn () => BillingCheckoutConfiguration::checkout(self::PLAN), 'checkout_disabled');
        $this->assertRefused(fn () => BillingCheckoutConfiguration::portal(), 'checkout_disabled');
        foreach ([null, 'true', 1, [true]] as $enabled) {
            config(['production-membership-billing-checkout.enabled' => $enabled]);
            $this->assertRefused(fn () => BillingCheckoutConfiguration::portal(), 'checkout_disabled');
        }
    }

    public function test_price_must_be_staff_entered_and_shaped_like_a_price_id(): void
    {
        $this->enable();
        $this->assertSame(F::PRICE, BillingCheckoutConfiguration::checkout(self::PLAN)['price_ref']);
        foreach ([[], ['22222222-2222-4222-8222-222222222222' => F::PRICE], [self::PLAN => 'prod_NOTAPRICE'], [self::PLAN => null], [self::PLAN => 'price_a b']] as $refs) {
            config(['production-membership-billing-checkout.price_refs' => $refs]);
            $this->assertRefused(fn () => BillingCheckoutConfiguration::checkout(self::PLAN), 'price_not_configured');
        }
    }

    public function test_return_urls_must_stay_on_this_applications_host_over_https_or_local_http(): void
    {
        $this->enable();
        config(['app.url' => 'https://store.example.test']);
        $bad = ['https://evil.example.test/membership', 'https://store.example.test.evil.test/x', '//store.example.test/x', 'javascript:alert(1)',
            'https://user:pass@store.example.test/x', 'https://store.example.test/x#frag', 'https://store.example.test:8443/x', 'http://store.example.test/x',
            'https://store.example.test/x y', 'https://store.example.test/x'."\n", '/relative/path', '', 'ftp://store.example.test/x'];
        foreach ($bad as $url) {
            config(['production-membership-billing-checkout.portal_return_url' => $url, 'production-membership-billing-checkout.success_url' => $url,
                'production-membership-billing-checkout.cancel_url' => $url]);
            $this->assertRefused(fn () => BillingCheckoutConfiguration::portal(), 'checkout_configuration', $url);
            $this->assertRefused(fn () => BillingCheckoutConfiguration::checkout(self::PLAN), 'checkout_configuration', $url);
        }
        config(['production-membership-billing-checkout.success_url' => 'https://store.example.test/done?s={CHECKOUT_SESSION_ID}&t={CHECKOUT_SESSION_ID}']);
        $this->assertRefused(fn () => BillingCheckoutConfiguration::checkout(self::PLAN), 'checkout_configuration');
        config(['production-membership-billing-checkout.success_url' => 'https://store.example.test/done?s={CHECKOUT_SESSION_ID}',
            'production-membership-billing-checkout.cancel_url' => 'https://store.example.test/cancelled', 'production-membership-billing-checkout.portal_return_url' => 'https://store.example.test/account',
            'production-membership-billing-checkout.portal_configuration_ref' => 'bpc_SYNTHETIC']);
        $this->assertSame('https://store.example.test/done?s={CHECKOUT_SESSION_ID}', BillingCheckoutConfiguration::checkout(self::PLAN)['success_url']);
        $this->assertSame(['https://store.example.test/account', 'bpc_SYNTHETIC'], array_values(BillingCheckoutConfiguration::portal()));
        config(['production-membership-billing-checkout.portal_configuration_ref' => 'bpc_bad value']);
        $this->assertRefused(fn () => BillingCheckoutConfiguration::portal(), 'checkout_configuration');
        // Plain http is only for localhost in local/testing, and never in staging or production.
        config(['app.url' => 'http://localhost', 'production-membership-billing-checkout.portal_configuration_ref' => null, 'production-membership-billing-checkout.portal_return_url' => 'http://localhost/account']);
        $this->assertSame('http://localhost/account', BillingCheckoutConfiguration::portal()['return_url']);
        $this->app['env'] = 'staging';
        $this->assertRefused(fn () => BillingCheckoutConfiguration::portal(), 'checkout_configuration');
    }

    private function enable(): void
    {
        config(['production-membership-billing-checkout' => ['enabled' => true, 'price_refs' => [self::PLAN => F::PRICE],
            'success_url' => 'http://localhost/membership/done?s={CHECKOUT_SESSION_ID}', 'cancel_url' => 'http://localhost/membership',
            'portal_return_url' => 'http://localhost/account', 'portal_configuration_ref' => null], 'app.url' => 'http://localhost']);
    }

    private function assertRefused(callable $call, string $reason, string $context = ''): void
    {
        try {
            $call();
        } catch (BillingException $error) {
            $this->assertSame($reason, $error->reason, $context);

            return;
        }
        $this->fail('Expected a refusal ('.$reason.') '.$context);
    }
}
