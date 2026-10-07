<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutTransport;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Http\Middleware\ProductionTaxCheckoutPrivacy;
use App\Providers\ProductionTaxCheckoutServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\Support\RecordingTaxCheckoutTransport;
use Tests\TestCase;

/** Files exist but are not mounted; tests mount them explicitly, as root composition will. */
class ProductionTaxCheckoutHttpBoundaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutFixtures;

    private const SANITIZED = ['code' => 'PRODUCTION_TAX_CHECKOUT_UNAVAILABLE',
        'message' => 'Checkout could not be confirmed. Retry the same request or check the saved order.'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('h', 32))]);
    }

    private function mount(): void
    {
        $this->app->register(ProductionTaxCheckoutServiceProvider::class);
        $this->app[Kernel::class]->prependMiddleware(ProductionTaxCheckoutPrivacy::class);
        Route::middleware('web')->group(base_path('routes/production-tax-checkout.php'));
    }

    public function test_nothing_is_registered_or_mounted_by_default(): void
    {
        $this->assertFalse(Route::has('production.tax-checkout.previews'));
        $this->assertFalse($this->app->bound(TaxCheckoutTransport::class));
        $this->assertNotContains(ProductionTaxCheckoutServiceProvider::class, require base_path('bootstrap/providers.php'));
        $this->postJson('/production/tax-checkout/previews', ['candidateId' => 1])->assertNotFound();
    }

    public function test_default_off_never_queries_private_data_and_never_resolves_identity(): void
    {
        $this->mount();
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $this->postJson('/production/tax-checkout/previews', ['candidateId' => 1])->assertStatus(503)->assertExactJson(self::SANITIZED)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame([], $queries);
        $this->assertFalse($this->app->resolved(ProductionCustomerAccess::class));
        $this->assertFalse($this->app->resolved(ProductionCustomerSessions::class));
    }

    public static function transportCases(): array
    {
        return [['query', 422], ['range', 422], ['cross_site', 403], ['origin', 403], ['form', 415], ['compression', 415],
            ['oversize', 413], ['method', 405], ['override', 405], ['malformed', 422], ['array', 422], ['duplicate', 422], ['nested_duplicate', 422], ['guest', 403]];
    }

    #[DataProvider('transportCases')]
    public function test_private_transport_refusals_are_bounded_and_sanitized(string $case, int $status): void
    {
        $this->mount();
        self::configureTax();
        $path = '/production/tax-checkout/previews';
        $method = 'POST';
        $body = '{}';
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        match ($case) {
            'query' => $path .= '?amount_total=1',
            'range' => $headers['Range'] = 'bytes=0-1',
            'cross_site' => $headers['Sec-Fetch-Site'] = 'cross-site',
            'origin' => $headers['Origin'] = 'https://foreign.invalid',
            'form' => $headers['Content-Type'] = 'application/x-www-form-urlencoded',
            'compression' => $headers['Content-Encoding'] = 'gzip',
            'oversize' => $body = str_repeat('x', 16385),
            'method' => $method = 'PUT',
            'override' => $headers['X-HTTP-Method-Override'] = 'DELETE',
            'malformed' => $body = '{"private_marker":"DO_NOT_LOG",',
            'array' => $body = '[]',
            'duplicate' => $body = '{"candidateId":1,"candidateId":2}',
            'nested_duplicate' => $body = '{"items":[{"trackId":1,"trackId":2}]}',
            default => null,
        };
        $response = $this->call($method, $path, [], [], [], $this->transformHeadersToServerVars($headers), $body);
        $response->assertStatus($status)->assertJsonPath('code', 'PRODUCTION_TAX_CHECKOUT_UNAVAILABLE')
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('DO_NOT_LOG', false)->assertDontSee('amount_total', false);
    }

    public function test_session_floor_principal_is_required_and_no_request_field_can_carry_an_amount(): void
    {
        $this->fakePrivateMediaStorage();
        config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
        $catalog = $this->taxCatalog();
        $buyer = $this->enrollThroughLocalSmtp();
        $transport = new RecordingTaxCheckoutTransport;
        $this->withoutVite();
        $this->mount();
        $this->app->instance(TaxCheckoutTransport::class, $transport);
        Route::middleware('web')->group(base_path('routes/production-customer-identity.php'));
        $preview = ['candidateId' => $catalog['candidate']->id, 'items' => $catalog['items']];

        // A cached guard actor without the T23 session marker has no checkout authority.
        $this->actingAs($buyer['user'], 'customer');
        $this->postJson('/production/tax-checkout/previews', $preview)->assertForbidden()->assertExactJson(self::SANITIZED);
        $this->postJson('/customer/sign-in', ['email' => 'buyer@example.test', 'password' => 'MailboxPassword123'])->assertOk();
        $hash = $this->postJson('/production/tax-checkout/previews', $preview)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('checkout.amounts.tax', 'calculated_by_provider_at_hosted_checkout')->json('checkout.previewHash');
        $order = ['candidateId' => $catalog['candidate']->id, 'items' => $catalog['items'], 'previewHash' => $hash, 'accepted' => true,
            'buyer' => ['legalName' => 'Declared synthetic buyer'], 'requestKey' => 'synthetic-http-tax-order'];
        foreach (['totalMinor' => 1, 'taxMinor' => 0, 'amounts' => ['total_minor' => 1]] as $key => $value) {
            $this->postJson('/production/tax-checkout/orders', [...$order, $key => $value])->assertStatus(422);
        }
        $this->postJson('/production/tax-checkout/orders', [...$order, 'accepted' => 'true'])->assertStatus(422);
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['order'], 0);
        $created = $this->postJson('/production/tax-checkout/orders', $order)->assertOk()->json('checkout');
        $this->assertSame(4999, $created['amounts']['subtotal_minor']);

        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        // Provider still unbound: the request is retained and the transport is never used.
        $this->call('POST', '/production/tax-checkout/orders/'.$created['orderId'].'/hosted', [], [], [], $headers, '{}')->assertStatus(503)
            ->assertExactJson(self::SANITIZED);
        $this->assertSame([], $transport->calls);
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['request'], 1);
        config(['production-tax-checkout.provider' => self::SYNTHETIC_PROVIDER]);
        $this->call('POST', '/production/tax-checkout/orders/'.$created['orderId'].'/hosted', [], [], [], $headers, '{}')->assertOk()
            ->assertJsonPath('checkout.checkoutStatus', 'open');
        $transport->paid = true;
        $this->call('POST', '/production/tax-checkout/orders/'.$created['orderId'].'/reconcile', [], [], [], $headers, '{"sessionLocator":"cs_test_SYNTHETICTAX"}')
            ->assertStatus(422);
        $this->call('POST', '/production/tax-checkout/orders/'.$created['orderId'].'/reconcile', [], [], [], $headers, '{}')->assertOk()
            ->assertJsonPath('checkout.paymentStatus', 'verified')->assertJsonPath('checkout.reviewedAmounts.tax_minor', 437);
        $this->get('/production/tax-checkout/orders/'.$created['orderId'].'/return?payment_status=paid')->assertStatus(422);
        $this->get('/production/tax-checkout/orders/'.$created['orderId'].'/return')->assertOk()->assertJsonPath('checkout.reviewedAmounts.total_minor', 5436);
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['reviewed'], 1);
        $this->assertDatabaseCount('license_grants', 0);
    }
}
