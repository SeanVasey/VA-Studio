<?php

namespace Tests\ReviewProbes;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidLineAdapterV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderLocatorV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutPolicy;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutTransport;
use App\Domain\Commerce\ProductionTaxCheckout\UnboundTaxCheckoutTransport;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Http\Controllers\ProductionTaxCheckoutController;
use App\Http\Middleware\ProductionTaxCheckoutPrivacy;
use App\Providers\ProductionTaxCheckoutServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\TestCase;

/** Independent reviewer probe (question 2). Not part of the suite. Documents actual behaviour at 9ec94d8c. */
final class TaxReviewDefaultOffProbeTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('d', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
    }

    private function calls(array $f, ProductionTaxCheckout $checkout): array
    {
        $p = $f['buyer']['principal'];
        $u = $f['buyer']['user'];
        $c = $f['catalog']['candidate']->id;
        $i = $f['catalog']['items'];

        return [
            'preview' => fn () => $checkout->preview($p, $u, $c, $i),
            'order' => fn () => $checkout->order($p, $u, $c, $i, $f['preview']['previewHash'], true, ['legalName' => 'Declared synthetic buyer'], 'probe-other-key'),
            'initiate' => fn () => $checkout->initiate($p, $u, $f['order']['orderId']),
            'reconcile' => fn () => $checkout->reconcile($p, $u, $f['order']['orderId']),
            'status' => fn () => $checkout->status($p, $u, $f['order']['orderId']),
        ];
    }

    private function refusals(array $f, ProductionTaxCheckout $checkout): array
    {
        $out = [];
        foreach ($this->calls($f, $checkout) as $name => $call) {
            try {
                $call();
                $out[$name] = 'ACCEPTED';
            } catch (CheckoutException $error) {
                $out[$name] = $error->reason.'/'.$error->status;
            }
        }

        return $out;
    }

    public function test_q2_every_service_entry_point_refuses_when_off_outside_local_testing_or_on_pin_drift(): void
    {
        $f = $this->taxOrder();
        $checkout = new ProductionTaxCheckout($f['access'], $f['transport']);
        $counts = fn (): array => array_map(fn (string $t): int => DB::table($t)->count(), TaxCheckoutSchema::TABLES);
        $before = $counts();
        $all = fn (string $value): array => array_fill_keys(['preview', 'order', 'initiate', 'reconcile', 'status'], $value);

        config(['production-tax-checkout.enabled' => false]);
        $this->assertSame($all('disabled/503'), $this->refusals($f, $checkout));
        config(['production-tax-checkout.enabled' => 1]);
        $this->assertSame($all('disabled/503'), $this->refusals($f, $checkout));
        config(['production-tax-checkout.enabled' => 'true']);
        $this->assertSame($all('disabled/503'), $this->refusals($f, $checkout));

        config(['production-tax-checkout.enabled' => true]);
        $this->app['env'] = 'production';
        $this->assertSame($all('disabled/503'), $this->refusals($f, $checkout));
        $this->app['env'] = 'staging';
        $this->assertSame($all('disabled/503'), $this->refusals($f, $checkout));
        $this->app['env'] = 'testing';

        config(['production-tax-checkout.stripe_sdk_version' => '21.3.3']);
        $this->assertSame($all('unsupported/503'), $this->refusals($f, $checkout));
        config(['production-tax-checkout.stripe_sdk_version' => '21.3.2', 'production-tax-checkout.stripe_api_version' => '2026-09-30.dahlia']);
        $this->assertSame($all('unsupported/503'), $this->refusals($f, $checkout));
        config(['production-tax-checkout.stripe_api_version' => '2026-08-26.dahlia']);

        $this->assertSame($before, $counts());
        $this->assertSame([], $f['transport']->calls);
    }

    public function test_q2_transport_admission_matrix(): void
    {
        $f = $this->taxOrder();
        $cases = [];
        foreach ([[null, 'recording'], ['', 'recording'], ['unbound', 'unbound'], ['unbound', 'recording'], ['synthetic-tax-transport-v1', 'unbound'],
            ['synthetic-tax-transport-v1', 'none'], ['SYNTHETIC-TAX-TRANSPORT-V1', 'recording'], [['synthetic-tax-transport-v1'], 'recording']] as [$provider, $kind]) {
            config(['production-tax-checkout.provider' => $provider]);
            $transport = match ($kind) {
                'recording' => $f['transport'], 'unbound' => new UnboundTaxCheckoutTransport, 'none' => null,
            };
            try {
                TaxCheckoutPolicy::transport($transport);
                $cases[] = 'ADMITTED';
            } catch (CheckoutException $error) {
                $cases[] = $error->reason;
            }
        }
        $this->assertSame(array_fill(0, 8, 'provider_unbound'), $cases);
        $this->assertSame([], $f['transport']->calls);
    }

    public function test_q2_registered_provider_binds_only_the_refusing_transport(): void
    {
        $this->assertFalse($this->app->bound(TaxCheckoutTransport::class));
        $this->app->register(ProductionTaxCheckoutServiceProvider::class);
        $this->assertInstanceOf(UnboundTaxCheckoutTransport::class, $this->app->make(TaxCheckoutTransport::class));
        $f = $this->taxOrder();
        config(['production-tax-checkout.provider' => 'unbound']);
        try {
            $this->app->make(ProductionTaxCheckout::class)->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('container-resolved service admitted the unbound transport');
        } catch (CheckoutException $error) {
            $this->assertSame('provider_unbound', $error->reason);
        }
    }

    public function test_q2_controller_and_middleware_refuse_when_off_without_touching_the_database(): void
    {
        config(['production-tax-checkout.enabled' => false]);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $request = Request::create('/production/tax-checkout/previews', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $middleware = (new ProductionTaxCheckoutPrivacy)->handle($request, fn () => $this->fail('next() reached while off'));
        $this->assertSame(503, $middleware->getStatusCode());
        $controller = new ProductionTaxCheckoutController;
        foreach (['preview' => [], 'order' => [], 'initiate' => ['x'], 'reconcile' => ['x'], 'status' => ['x'], 'returned' => ['x']] as $method => $args) {
            $this->assertSame(503, $controller->{$method}($request, ...$args)->getStatusCode(), $method);
        }
        $this->app['env'] = 'production';
        config(['production-tax-checkout.enabled' => true]);
        $this->assertSame(503, (new ProductionTaxCheckoutPrivacy)->handle($request, fn () => $this->fail('next() reached in production'))->getStatusCode());
        $this->app['env'] = 'testing';
        $this->assertSame(0, $queries);
    }

    public function test_q2_source_v2_reader_ignores_the_enabled_flag_but_refuses_outside_local_testing(): void
    {
        // Observation: retained-evidence readers carry no `enabled` gate (a disabled flag still reads in testing),
        // but the retained execution context only re-admits test funds in local/testing, so production refuses.
        $f = $this->taxOrder();
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->paid = true;
        $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        config(['production-tax-checkout.enabled' => false, 'production-tax-checkout.provider' => null]);
        $read = function () use ($f): array {
            $locator = ProductionTaxPaidOrderLocatorV2::locate($f['order']['orderId']);

            return CommandTransaction::run(function (Records $rows) use ($locator, $f): array {
                $historical = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);

                return ProductionTaxPaidOrderSourceV2::lockedRead($locator, $rows->current, $historical)->line(1);
            });
        };
        $line = $read();
        $this->assertSame($line, ProductionTaxPaidLineAdapterV2::accept($line, 'synthetic_rehearsal'));
        $this->app['env'] = 'production';
        try {
            $read();
            $this->fail('SourceV2 read retained test-funds evidence in production');
        } catch (CheckoutException $error) {
            $this->assertSame(['unsupported', 409], [$error->reason, $error->status]);
        } finally {
            $this->app['env'] = 'testing';
        }
        // The pure adapter has no environment or policy gate at all.
        $this->app['env'] = 'production';
        $this->assertSame($line, ProductionTaxPaidLineAdapterV2::accept($line, 'synthetic_rehearsal'));
        $this->app['env'] = 'testing';
    }
}
