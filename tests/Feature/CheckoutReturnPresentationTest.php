<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\CanonicalJson;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/** Real synthetic owner/order and CMS records; only the external Stripe transport is a fixture. */
class CheckoutReturnPresentationTest extends TestCase
{
    // Pointer-damage cases remove guards in a disposable database rebuilt after each test.
    use FinalizationDatabaseMigrations;

    private const TITLE = 'Checkout status — VASEY.AUDIO';

    private const DESCRIPTION = 'View the saved test order status for this session. A browser return does not verify payment.';

    private const RETAINED_TABLES = [
        'quotes', 'quote_lines', 'quote_pricings', 'orders', 'order_lines', 'order_attempts',
        'rights_scopes', 'rights_scope_offers', 'inventory_reservations', 'inventory_claims',
        'promotion_campaigns', 'promotion_uses', 'promotion_availabilities', 'promotion_availability_revisions',
        'exclusive_activations', 'exclusive_sales', 'checkout_intents', 'checkout_sessions', 'checkout_observations',
        'stripe_webhook_receipts', 'stripe_receipt_work', 'payment_observations', 'verified_payments',
        'order_finalizations', 'license_grants', 'pending_entitlements', 'fulfillment_outbox',
        'contract_render_requests', 'contract_render_work', 'grant_contracts', 'test_fulfillment_activations',
        'test_delivery_controls', 'test_delivery_authorizations', 'test_delivery_redemptions',
        'audit_events', 'site_releases', 'site_publications', 'site_publication_revisions',
        'site_publication_schedules', 'site_images', 'site_image_variants', 'site_release_images',
        'customer_inquiries', 'jobs', 'job_batches', 'failed_jobs',
    ];

    private StripeCheckoutGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        config(['app.debug' => false, 'app.url' => 'https://audio.example.test']);
        CheckoutFixtures::configure();
        $this->gateway = CheckoutFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        Queue::fake();
    }

    private function ownedHttpOrder(): array
    {
        $fixture = InventoryFixtures::selection();
        $quoteId = $this->postJson('/quotes', ['items' => $fixture['items']], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->json('quote.id');
        $quote = Quote::where('public_id', $quoteId)->sole();
        app(PriceQuote::class)->create($quoteId, $quote->owner_key);
        $request = OrderFixtures::request($quote, $quote->owner_key);
        $orderId = $this->postJson('/orders', $request, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->json('order.id');

        return ['id' => $orderId, 'owner' => $quote->owner_key];
    }

    private function release(string $marker, User $actor): SiteRelease
    {
        $content = SiteContentSchema::forEditing(SiteContentSchema::defaults());
        $content['hero']['title'] = $marker.' HERO';
        $content['footer']['description'] = $marker.' FOOTER';
        $content['seo'] = ['title' => $marker.' TITLE', 'description' => $marker.' DESCRIPTION'];
        $content['about'] = ['title' => $marker.' ABOUT', 'description' => $marker.' ABOUT DESCRIPTION',
            'paragraphs' => [$marker.' EDITORIAL BODY']];
        $content['navigation'] = [
            ['label' => $marker.' CATALOG', 'href' => '/#catalog'],
            ['label' => $marker.' ABOUT LINK', 'href' => '/about'],
        ];

        return app(SiteContent::class)->create($content, $marker.' INTERNAL RELEASE LABEL', $actor);
    }

    private function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }

    private function retained(): array
    {
        // Query-builder rows preserve every stored column, including ciphertext and raw JSON strings.
        $rows = ['quote_owners' => DB::table('quote_owners')->orderBy('owner_key')->get()
            ->map(fn (object $row): array => get_object_vars($row))->all()];
        foreach (self::RETAINED_TABLES as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()
                ->map(fn (object $row): array => get_object_vars($row))->all();
        }

        return $rows;
    }

    private function assertReadOnly(array $before): void
    {
        $this->assertSame($before, $this->retained());
        $this->assertSame([], $this->gateway->calls);
        Queue::assertNothingPushed();
    }

    private function assertPrivate(TestResponse $response, bool $returned = false): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('Location');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
        if ($returned) {
            $this->assertContains('X-Inertia', $response->baseResponse->getVary());
        }
    }

    private function returnPage(string $url, array $headers = [], int $expectedPointerReads = 1): TestResponse
    {
        $reads = 0;
        $observing = true;
        DB::listen(function (QueryExecuted $query) use (&$reads, &$observing): void {
            if ($observing && str_contains(strtolower($query->sql), 'site_publications')) {
                $reads++;
            }
        });
        try {
            $response = $this->get($url, $headers);
        } finally {
            $observing = false;
        }
        $this->assertSame($expectedPointerReads, $reads, 'A return reads one current CMS pointer only after ownership passes.');
        $this->assertPrivate($response, true);
        if ($response->getStatusCode() === 200) {
            if (($headers['X-Inertia'] ?? null) === 'true') {
                $response->assertHeader('X-Inertia', 'true')->assertHeader('Content-Type', 'application/json');
            } else {
                $response->assertHeaderMissing('X-Inertia')->assertHeader('Content-Type', 'text/html; charset=UTF-8');
            }
        }

        return $response;
    }

    private function privateHeadDocument(TestResponse $response): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($dom);
    }

    private function assertPrivateHead(TestResponse $response, array $sensitive = []): void
    {
        $head = $this->privateHeadDocument($response);
        $this->assertCount(1, $head->query('//head/title'));
        $this->assertSame('title', $head->query('//head/title')->item(0)->getAttribute('data-inertia'));
        $this->assertSame(self::TITLE, $head->evaluate('string(//head/title)'));
        foreach (['description' => self::DESCRIPTION, 'robots' => 'noindex, nofollow', 'referrer' => 'no-referrer'] as $key => $value) {
            $nodes = $head->query('//head/meta[@name="'.$key.'"]');
            $this->assertCount(1, $nodes);
            $this->assertSame($key, $nodes->item(0)->getAttribute('data-inertia'));
            $this->assertSame($value, $nodes->item(0)->getAttribute('content'));
        }
        $this->assertCount(4, $head->query('//head/*[@data-inertia]'));
        $this->assertCount(0, $head->query('//head/link[@rel="canonical"] | //head/meta[starts-with(@property, "og:") or starts-with(@name, "twitter:")]'));
        $this->assertCount(1, $head->query('//head/meta[@name="csrf-token"]'));
        $this->assertCount(1, $head->query('//head/meta[@name="theme-color"]'));
        $markup = $head->document->saveHTML($head->query('//head')->item(0));
        foreach ($sensitive as $value) {
            $this->assertStringNotContainsString((string) $value, $markup);
        }
    }

    private function assertUnverifiedStatus(string $orderId, string $query = ''): TestResponse
    {
        $response = $this->getJson('/orders/'.$orderId.'/checkout'.$query)->assertOk()
            ->assertJsonPath('checkout.orderId', $orderId)->assertJsonPath('checkout.status', 'not_started')
            ->assertJsonPath('checkout.paymentStatus', 'not_verified')->assertJsonPath('checkout.finalizationStatus', 'not_started')
            ->assertJsonPath('checkout.contractStatus', 'not_started')->assertJsonPath('checkout.fulfillmentStatus', 'not_started')
            ->assertJsonPath('checkout.testOnly', true)->assertJsonPath('checkout.id', null)->assertJsonPath('checkout.url', null);
        $this->assertPrivate($response);

        return $response;
    }

    public function test_owned_html_and_inertia_use_one_current_public_chrome_without_private_body_or_payment_writes(): void
    {
        $order = $this->ownedHttpOrder();
        $actor = LicenseFixtures::admin();
        $active = $this->release('PUBLIC SYNTHETIC', $actor);
        app(SiteContent::class)->publish($active->id, 0, $actor);
        $draft = $this->release('PRIVATE SYNTHETIC', $actor);
        Queue::fake();
        $before = $this->retained();
        $query = '?success=true&payment_status=paid&session_id=cs_live_REDIRECT_MARKER&preview='.$draft->id.'&site_release_id='.$draft->id;
        $url = '/orders/'.$order['id'].'/checkout/return'.$query;
        $status = $this->assertUnverifiedStatus($order['id'], $query);
        $saved = $this->getJson('/orders/'.$order['id'].'/status')->assertOk()
            ->assertJsonPath('order.id', $order['id'])->assertJsonPath('order.status', 'prepared')
            ->assertJsonPath('order.paymentStatus', 'not_started');
        $saved->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertContains('Cookie', $saved->baseResponse->getVary());
        $html = $this->returnPage($url)->assertOk()->assertSee('PUBLIC SYNTHETIC FOOTER', false)
            ->assertSee('PUBLIC SYNTHETIC ABOUT LINK', false);
        $page = $this->returnPage($url, $this->inertiaHeaders())->assertOk()->assertJsonPath('component', 'CheckoutReturn')
            ->assertJsonPath('props.orderId', $order['id']);
        $chrome = $page->json('props.siteContent');
        $keys = array_keys($chrome);
        sort($keys);
        $this->assertSame(['footer', 'hero', 'navigation', 'schema_version', 'seo', 'studio'], $keys);
        // Compare projected content without depending on object-key order; scalar types stay strict.
        $expectedChrome = array_intersect_key($active->fresh()->content, array_flip($keys));
        $this->assertSame(CanonicalJson::encode($expectedChrome), CanonicalJson::encode($chrome));
        $this->assertNotSame(SiteContentSchema::defaults(), $chrome);
        foreach (['metadata', 'privateMetadata', 'sitePreview', 'sitePreviewBase', 'releaseId', 'checkout'] as $key) {
            $this->assertArrayNotHasKey($key, $page->json('props'));
        }
        foreach ([$draft->label, $draft->content_hash, $active->label, $active->content_hash,
            'PRIVATE SYNTHETIC', 'PUBLIC SYNTHETIC EDITORIAL BODY', ...array_values(OrderFixtures::buyer()), $order['owner']] as $private) {
            $html->assertDontSee($private, false);
            $page->assertDontSee($private, false);
        }
        $this->assertPrivateHead($html, [$order['id'], $order['owner'], CheckoutFixtures::ACCOUNT,
            ...array_values(OrderFixtures::buyer()), $status->json('checkout.totalMinor'), 'cs_live_REDIRECT_MARKER',
            'PUBLIC SYNTHETIC', 'PRIVATE SYNTHETIC']);
        $this->assertUnverifiedStatus($order['id'], $query);
        $this->assertReadOnly($before);
    }

    public function test_never_published_site_uses_approved_static_defaults_and_ignores_private_draft_queries(): void
    {
        $order = $this->ownedHttpOrder();
        $draft = $this->release('UNPUBLISHED SYNTHETIC', LicenseFixtures::admin());
        Queue::fake();
        $before = $this->retained();
        $url = '/orders/'.$order['id'].'/checkout/return?success=true&release='.$draft->id.'&preview='.$draft->id;
        $html = $this->returnPage($url)->assertOk();
        $page = $this->returnPage($url, $this->inertiaHeaders())->assertOk();
        $this->assertSame(SiteContentSchema::defaults(), $page->json('props.siteContent'));
        foreach ([$draft->label, $draft->content_hash, 'UNPUBLISHED SYNTHETIC'] as $private) {
            $html->assertDontSee($private, false);
            $page->assertDontSee($private, false);
        }
        $this->assertPrivateHead($html, [$order['id']]);
        $this->assertUnverifiedStatus($order['id'], '?success=true&payment_status=paid');
        $this->assertReadOnly($before);
    }

    public function test_a_real_corrupt_active_hash_uses_static_private_chrome_without_repairing_publication(): void
    {
        $order = $this->ownedHttpOrder();
        $actor = LicenseFixtures::admin();
        $bad = SiteContentSchema::defaults();
        $bad['hero']['title'] = 'CORRUPTED SYNTHETIC CMS';
        $hash = CanonicalJson::hash(SiteContentSchema::defaults());
        $releaseId = DB::table('site_releases')->insertGetId([
            'label' => 'Synthetic corrupt restoration', 'schema_version' => 1, 'content' => json_encode($bad, JSON_THROW_ON_ERROR),
            'content_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION,
            'created_by' => $actor->id, 'created_at' => now(),
        ]);
        DB::table('site_publication_revisions')->insert(['revision' => 1, 'release_id' => $releaseId, 'previous_release_id' => null,
            'operation' => 'publish', 'content_hash' => $hash, 'actor_id' => $actor->id, 'created_at' => now()]);
        DB::table('site_publications')->where('id', 1)->update(['revision' => 1, 'active_release_id' => $releaseId, 'updated_at' => now()]);
        Queue::fake();
        $before = $this->retained();
        $this->assertUnavailableCmsReturn($order['id'], ['CORRUPTED SYNTHETIC CMS', 'Synthetic corrupt restoration', $hash]);
        $this->assertReadOnly($before);
    }

    #[DataProvider('pointerDamageCases')]
    public function test_a_real_missing_or_damaged_pointer_preserves_private_status_without_cms_writes(string $damage): void
    {
        $order = $this->ownedHttpOrder();
        $actor = LicenseFixtures::admin();
        $active = $this->release('RETAINED SYNTHETIC', $actor);
        app(SiteContent::class)->publish($active->id, 0, $actor);
        if ($damage === 'missing') {
            DB::unprepared('DROP TRIGGER site_publications_retain');
            DB::table('site_publications')->where('id', 1)->delete();
        } else {
            DB::unprepared('DROP TRIGGER site_publications_transition');
            DB::table('site_publications')->where('id', 1)->update(['revision' => 2]);
        }
        Queue::fake();
        $before = $this->retained();
        $this->assertUnavailableCmsReturn($order['id'], ['RETAINED SYNTHETIC', $active->content_hash, $active->label]);
        $this->assertReadOnly($before);
    }

    public static function pointerDamageCases(): array
    {
        return ['missing singleton' => ['missing'], 'revision without retained history' => ['revision_without_history']];
    }

    private function assertUnavailableCmsReturn(string $orderId, array $private): void
    {
        $url = '/orders/'.$orderId.'/checkout/return?success=true&payment_status=paid&session_id=cs_test_UNTRUSTED';
        $html = $this->returnPage($url)->assertOk();
        $page = $this->returnPage($url, $this->inertiaHeaders())->assertOk()->assertJsonPath('props.orderId', $orderId);
        $this->assertSame(SiteContentSchema::defaults(), $page->json('props.siteContent'));
        foreach ($private as $marker) {
            $html->assertDontSee($marker, false);
            $page->assertDontSee($marker, false);
        }
        $this->assertPrivateHead($html, [$orderId, ...$private, 'cs_test_UNTRUSTED']);
        $this->assertUnverifiedStatus($orderId, '?success=true&payment_status=paid');
        // Private availability is not publication repair: the public CMS still fails closed.
        $public = $this->get('/')->assertStatus(503)->assertHeaderMissing('Location');
        $public->assertDontSee(SiteContentSchema::defaults()['hero']['title'], false);
    }

    public function test_foreign_and_unknown_orders_are_identically_denied_before_reading_public_chrome(): void
    {
        $order = $this->ownedHttpOrder();
        $active = $this->release('PUBLIC SYNTHETIC', LicenseFixtures::admin());
        app(SiteContent::class)->publish($active->id, 0, User::findOrFail($active->created_by));
        Queue::fake();
        $before = $this->retained();
        $this->flushSession();
        $unknown = (string) Str::uuid();
        foreach ([[], $this->inertiaHeaders()] as $headers) {
            $foreign = $this->returnPage('/orders/'.$order['id'].'/checkout/return?success=true', $headers, 0)
                ->assertNotFound()->assertExactJson(['code' => 'ORDER_NOT_FOUND']);
            $missing = $this->returnPage('/orders/'.$unknown.'/checkout/return?success=true', $headers, 0)
                ->assertNotFound()->assertExactJson(['code' => 'ORDER_NOT_FOUND']);
            $this->assertSame($foreign->json(), $missing->json());
            foreach ([$order['id'], $order['owner'], 'PUBLIC SYNTHETIC', $active->label, $active->content_hash, ...array_values(OrderFixtures::buyer())] as $private) {
                $foreign->assertDontSee($private, false);
                $missing->assertDontSee($private, false);
            }
        }
        $foreignStatus = $this->getJson('/orders/'.$order['id'].'/checkout')->assertNotFound();
        $unknownStatus = $this->getJson('/orders/'.$unknown.'/checkout')->assertNotFound();
        $this->assertPrivate($foreignStatus);
        $this->assertPrivate($unknownStatus);
        $this->assertSame($foreignStatus->json(), $unknownStatus->json());
        $foreignOrder = $this->getJson('/orders/'.$order['id'].'/status')->assertNotFound();
        $unknownOrder = $this->getJson('/orders/'.$unknown.'/status')->assertNotFound();
        $this->assertSame($foreignOrder->json(), $unknownOrder->json());
        $this->assertReadOnly($before);
    }
}
