<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures as F;
use Tests\Support\DeliveryFixtures;
use Tests\Support\ExclusiveSelectionFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\PricingFixtures;
use Tests\Support\PromotionFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class CustomerOrderItemsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        F::configure();
        OrderFixtures::configure();
        $this->travelTo(now()->startOfSecond());
    }

    public static function pricingKinds(): array
    {
        return ['original' => [false, false, 1], 'promotion' => [false, true, 2],
            'exclusive' => [true, false, 3], 'promoted exclusive' => [true, true, 3]];
    }

    #[DataProvider('pricingKinds')]
    public function test_exact_original_item_projection_supports_retained_pricing_versions(bool $exclusive, bool $promoted, int $schema): void
    {
        $customer = F::account();
        $selection = $exclusive ? ExclusiveSelectionFixtures::active() : InventoryFixtures::selection();
        if ($promoted) {
            PromotionFixtures::configure([PromotionFixtures::policy(['eligibility' => ['mode' => 'offer_revisions',
                'offer_revision_ids' => [$selection['revision']->id]]])]);
        }
        $order = $this->prepareFor($customer, $selection['items'], $promoted);
        $original = app(ReadOrder::class)->verify($order);
        $this->assertSame($schema, $original['pricing']['snapshot']['schema_version']);
        $this->login($customer);
        $status = $this->getJson('/orders/'.$order->public_id.'/status')->assertOk()->json();
        $history = $this->getJson('/orders/history')->assertOk()->json();

        $this->assertReadOnly(function () use ($order, $original, $customer, $status, $history): void {
            $response = $this->getJson('/orders/'.$order->public_id.'/items')->assertOk();
            $this->assertPrivate($response);
            $this->assertSame(['items'], array_keys($response->json()));
            $this->assertSame(['orderItemsSchema', 'orderId', 'testOnly', 'currency', 'subtotalMinor', 'discountMinor',
                'taxBasisMinor', 'taxMinor', 'totalMinor', 'lines'], array_keys($response->json('items')));
            $this->assertSame(['position', 'title', 'licenseName', 'licenseVersion', 'quantity', 'baseMinor',
                'discountMinor', 'taxBasisMinor', 'taxMinor', 'totalMinor'], array_keys($response->json('items.lines.0')));
            $pricing = $original['pricing']['snapshot'];
            $line = $original['lines'][0];
            $response->assertExactJson(['items' => [
                'orderItemsSchema' => 1, 'orderId' => $order->public_id, 'testOnly' => true, 'currency' => 'USD',
                'subtotalMinor' => $pricing['subtotal_minor'], 'discountMinor' => $pricing['discount_minor'],
                'taxBasisMinor' => $pricing['tax_basis_minor'], 'taxMinor' => $pricing['tax_minor'], 'totalMinor' => $pricing['total_minor'],
                'lines' => [['position' => 0, 'title' => $line['selection']['offer_snapshot']['product']['title'],
                    'licenseName' => $line['disclosure']['name'], 'licenseVersion' => $line['disclosure']['version'],
                    'quantity' => 1, 'baseMinor' => $line['pricing']['base_minor'], 'discountMinor' => $line['pricing']['discount_minor'],
                    'taxBasisMinor' => $line['pricing']['tax_basis_minor'], 'taxMinor' => $line['pricing']['tax_minor'],
                    'totalMinor' => $line['pricing']['total_minor']]],
            ]]);
            foreach ([$customer['principal']->ownerKey, $order->payload_ciphertext, $order->payload_hash,
                $order->idempotency_key_hash, $original['policy']['seller']['legal_name'],
                ...array_values(OrderFixtures::buyer())] as $private) {
                $response->assertDontSee($private, false);
            }
            $this->getJson('/orders/'.$order->public_id.'/items')->assertOk()->assertExactJson($response->json());
            $this->getJson('/orders/'.$order->public_id.'/status')->assertOk()->assertExactJson($status);
            $this->getJson('/orders/history')->assertOk()->assertExactJson($history);
        });
    }

    public function test_multiple_lines_keep_the_original_promotion_allocation_and_order(): void
    {
        $customer = F::account();
        $first = InventoryFixtures::selection();
        $second = InventoryFixtures::selection();
        PromotionFixtures::configure([PromotionFixtures::policy(['eligibility' => ['mode' => 'offer_revisions',
            'offer_revision_ids' => [$first['revision']->id]]])]);
        $order = $this->prepareFor($customer, [...$second['items'], ...$first['items']], true);
        $original = app(ReadOrder::class)->verify($order);
        $this->login($customer);
        $this->assertReadOnly(function () use ($order, $original): void {
            $items = $this->getJson('/orders/'.$order->public_id.'/items')->assertOk()->assertJsonCount(2, 'items.lines')->json('items');
            $this->assertSame([0, 1], array_column($items['lines'], 'position'));
            foreach ($items['lines'] as $position => $line) {
                $this->assertSame($original['lines'][$position]['selection']['offer_snapshot']['product']['title'], $line['title']);
                $this->assertSame($original['lines'][$position]['pricing']['discount_minor'], $line['discountMinor']);
                $this->assertSame($original['lines'][$position]['pricing']['total_minor'], $line['totalMinor']);
            }
            $this->assertContains(0, array_column($items['lines'], 'discountMinor'));
            $this->assertGreaterThan(0, max(array_column($items['lines'], 'discountMinor')));
            $this->assertSame(array_sum(array_column($items['lines'], 'totalMinor')), $items['totalMinor']);
        });
    }

    public function test_ten_lines_and_maximum_frozen_amounts_preserve_full_unicode_labels(): void
    {
        $customer = F::account();
        $policy = PricingFixtures::policy();
        $policy['tax']['rate_bps'] = 10000;
        PricingFixtures::configure($policy);
        $label = str_repeat('🎧', 255);
        $items = [];
        for ($position = 0; $position < 10; $position++) {
            $selection = QuoteFixtures::selection(2147483647);
            if ($position === 0) {
                $track = app(PublishTrack::class)->unpublish($selection['track'], $selection['actor']);
                app(SaveTrackMetadata::class)->handle($track, ['title' => $label, 'metadata_version' => $track->metadata_version], $selection['actor']);
                $draft = LicenseFixtures::draft($selection['actor']);
                $draft->template()->firstOrFail()->update(['name' => $label]);
                $review = app(ReviewLicense::class);
                $submitted = $review->submit($draft, $selection['actor']);
                $approved = $review->approve($submitted, LicenseFixtures::admin(), [
                    'approval_reference' => 'BOUNDARY-SYNTHETIC-REVIEW', 'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true,
                ]);
                $license = app(PublishLicense::class)->handle($approved, $selection['actor']);
                $offer = app(SaveOfferDraft::class)->handle($selection['offer'], ['license_version_id' => $license->id], $selection['actor']);
                $selection['revision'] = app(PublishOffer::class)->handle($offer, $selection['actor']);
                app(PublishTrack::class)->handle($track->fresh(), $selection['actor']);
                $selection['items'][0]['offerRevisionId'] = $selection['revision']->id;
                $selection['items'][0]['licenseVersionId'] = $license->id;
            }
            $scopes = app(ManageRightsScope::class);
            $scope = $scopes->register('items-boundary-'.Str::uuid(), 'SYNTHETIC-ITEMS-BOUNDARY', $selection['actor']);
            $scopes->link($scope->id, $selection['revision']->id, 'SYNTHETIC-ITEMS-LINK', $selection['actor']);
            array_push($items, ...$selection['items']);
        }
        $order = $this->prepareFor($customer, $items);
        $this->login($customer);
        $this->assertReadOnly(function () use ($order, $label): void {
            $encoding = mb_internal_encoding();
            mb_internal_encoding('ISO-8859-1');
            try {
                $response = $this->getJson('/orders/'.$order->public_id.'/items')->assertOk()->assertJsonCount(10, 'items.lines')
                    ->assertJsonPath('items.lines.0.title', $label)->assertJsonPath('items.lines.0.licenseName', $label)
                    ->assertJsonPath('items.subtotalMinor', 21474836470)->assertJsonPath('items.taxMinor', 21474836470)
                    ->assertJsonPath('items.totalMinor', 42949672940);
            } finally {
                mb_internal_encoding($encoding);
            }
            $this->assertSame(range(0, 9), array_column($response->json('items.lines'), 'position'));
            foreach ($response->json('items.lines') as $line) {
                $this->assertSame(2147483647, $line['baseMinor']);
                $this->assertSame(4294967294, $line['totalMinor']);
            }
            $this->assertLessThan(64 * 1024, strlen($response->getContent()));
        });
    }

    public function test_later_catalog_license_and_price_revisions_cannot_replace_original_items(): void
    {
        $customer = F::account();
        $selection = OrderFixtures::priced();
        $order = $this->prepareFor($customer, $selection['items']);
        $this->login($customer);
        $original = $this->getJson('/orders/'.$order->public_id.'/items')->assertOk()->json();
        $track = app(PublishTrack::class)->unpublish($selection['track'], $selection['actor']);
        app(SaveTrackMetadata::class)->handle($track, ['title' => 'LATER CATALOG TITLE', 'metadata_version' => $track->metadata_version], $selection['actor']);
        // Publish a different template at version 2 through the actual review lifecycle.
        $draft = LicenseFixtures::draft($selection['actor']);
        $template = $draft->template()->firstOrFail();
        $template->update(['name' => 'LATER LICENSE NAME']);
        $successor = app(CreateLicenseDraft::class)->handle($template,
            ['authored_source' => 'LATER SYNTHETIC LICENSE TERMS.', 'structured_terms' => $draft->structured_terms], $selection['actor'], $draft);
        $review = app(ReviewLicense::class);
        $submitted = $review->submit($successor, $selection['actor']);
        $approved = $review->approve($submitted, LicenseFixtures::admin(), [
            'approval_reference' => 'LATER-SYNTHETIC-REVIEW', 'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true,
        ]);
        $license = app(PublishLicense::class)->handle($approved, $selection['actor']);
        $this->assertSame(2, $license->version);
        $offer = app(SaveOfferDraft::class)->handle($selection['offer'], ['price_minor' => 9999, 'license_version_id' => $license->id], $selection['actor']);
        $revision = app(PublishOffer::class)->handle($offer, $selection['actor']);
        $this->assertSame('LATER CATALOG TITLE', $revision->snapshot['product']['title']);
        $this->assertSame('LATER LICENSE NAME', $revision->snapshot['license']['name']);
        $this->travelTo($order->attempt()->sole()->expires_at->addDay());
        config(['commerce.test_order_policy' => null, 'commerce.test_pricing_policy' => null, 'commerce.test_inventory_policy' => null]);

        $this->assertReadOnly(function () use ($order, $original): void {
            $this->getJson('/orders/'.$order->public_id.'/items')->assertOk()->assertExactJson($original)
                ->assertDontSee('LATER CATALOG TITLE', false)->assertDontSee('LATER LICENSE NAME', false)
                ->assertDontSee('LATER SYNTHETIC LICENSE TERMS.', false);
        });
    }

    public function test_foreign_customer_guest_unknown_and_malformed_references_have_the_same_private_failure(): void
    {
        $customer = F::account(['email' => OrderFixtures::buyer()['email']]);
        $other = F::account();
        $foreign = F::prepared($other['user']);
        $selection = OrderFixtures::priced();
        $guest = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), OrderFixtures::request($selection['quote']));
        $this->login($customer);

        $this->assertReadOnly(function () use ($customer, $other, $foreign, $guest): void {
            foreach ([$foreign->public_id, $guest->public_id, (string) Str::uuid(), 'malformed-reference'] as $id) {
                $response = $this->getJson('/orders/'.$id.'/items')->assertNotFound()
                    ->assertExactJson(['code' => 'ORDER_NOT_FOUND', 'message' => 'This order review is unavailable.']);
                $this->assertPrivate($response);
                foreach ([$id, $customer['principal']->ownerKey, $other['principal']->ownerKey,
                    InventoryFixtures::OWNER, ...array_values(OrderFixtures::buyer())] as $private) {
                    $response->assertDontSee($private, false);
                }
            }
        });
    }

    public function test_withdrawn_account_cannot_receive_its_original_items(): void
    {
        $customer = F::account();
        $order = F::prepared($customer['user']);
        $this->login($customer);
        $this->getJson('/orders/'.$order->public_id.'/items')->assertOk();
        F::withdraw($customer);
        $this->assertReadOnly(function () use ($order): void {
            $response = $this->getJson('/orders/'.$order->public_id.'/items')->assertForbidden()
                ->assertJsonPath('code', 'CUSTOMER_SIGN_IN_UNAVAILABLE')->assertDontSee($order->public_id, false);
            $this->assertPrivate($response);
        });
    }

    public function test_withdrawal_during_projection_prevents_the_response_from_escaping(): void
    {
        $customer = F::account();
        $order = F::prepared($customer['user']);
        $this->login($customer);
        $changed = false;
        DB::listen(function (QueryExecuted $query) use ($customer, &$changed): void {
            if (! $changed && preg_match('/\Aselect .*from [`"]orders[`"]/i', $query->sql)) {
                $changed = true;
                F::withdraw($customer);
            }
        });
        $response = $this->getJson('/orders/'.$order->public_id.'/items')->assertForbidden()
            ->assertDontSee($order->public_id, false)->assertDontSee('Synthetic quote recording', false);
        $this->assertPrivate($response);
        $this->assertTrue($changed);
        $this->assertDatabaseCount('test_delivery_authorizations', 0);
    }

    public function test_corrupt_original_fails_closed_without_repair_or_private_exception_output(): void
    {
        $customer = F::account();
        $order = F::prepared($customer['user']);
        $this->login($customer);
        DB::unprepared('DROP TRIGGER orders_immutable_update');
        DB::table('orders')->where('id', $order->id)->update(['payload_ciphertext' => 'PRIVATE-CORRUPT-ORIGINAL']);
        config(['app.debug' => true]);
        Log::spy();
        $this->assertReadOnly(function () use ($order, $customer): void {
            $response = $this->getJson('/orders/'.$order->public_id.'/items')->assertStatus(409)
                ->assertJsonPath('code', 'ORDER_CHANGED')->assertDontSee('PRIVATE-CORRUPT-ORIGINAL', false)
                ->assertDontSee($customer['principal']->ownerKey, false)->assertDontSee('Synthetic quote recording', false);
            $this->assertPrivate($response);
            Log::shouldNotHaveReceived('error');
        });
    }

    public function test_paid_item_reads_preserve_originals_and_never_initiate_checkout_rendering_or_delivery(): void
    {
        $customer = F::account();
        $paid = F::ready($customer['user'], 'ITEMS');
        $this->login($customer);
        $this->assertDatabaseCount('grant_contracts', 1);
        $this->assertDatabaseCount('test_fulfillment_activations', 1);
        $this->assertReadOnly(function () use ($paid): void {
            $root = config('filesystems.disks.local.root');
            config(['filesystems.disks.local.root' => '/unavailable-original-items-test-root']);
            Storage::forgetDisk('local');
            try {
                $response = $this->getJson('/orders/'.$paid['order']->public_id.'/items')->assertOk()
                    ->assertJsonPath('items.orderId', $paid['order']->public_id)
                    ->assertJsonPath('items.lines.0.title', 'Synthetic quote recording');
                $this->assertPrivate($response);
                $this->getJson('/orders/'.$paid['order']->public_id.'/items')->assertOk()->assertExactJson($response->json());
            } finally {
                config(['filesystems.disks.local.root' => $root]);
                Storage::forgetDisk('local');
            }
        });
        $this->assertDatabaseCount('test_delivery_authorizations', 0);
        $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_paid_exception_keeps_prepared_items_without_claiming_entitlement(): void
    {
        FinalizationFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $fixture = PaymentFixtures::started($gateway);
        $before = app(ReadOrder::class)->items($fixture['order']->public_id, InventoryFixtures::OWNER);
        $this->travelTo($fixture['order']->attempt()->sole()->expires_at);
        $fixture = FinalizationFixtures::confirm($fixture);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($fixture['payment']->id));
        $this->assertReadOnly(function () use ($fixture, $before): void {
            $this->assertSame($before, app(ReadOrder::class)->items($fixture['order']->public_id, InventoryFixtures::OWNER));
        });
        $this->assertDatabaseCount('license_grants', 0);
        $this->assertDatabaseCount('pending_entitlements', 0);
    }

    private function prepareFor(array $customer, array $items, bool $promoted = false): Order
    {
        $owner = $customer['principal']->ownerKey;
        $quote = app(CreateQuote::class)->handle($owner, (string) Str::uuid(), $items, $customer['user'], $customer['principal']);
        $pricing = app(PriceQuote::class);
        if ($promoted) {
            $pricing->createWithPromotion($quote->public_id, $owner, 'SYNTHETIC', $customer['user'], $customer['principal']);
        } else {
            $pricing->create($quote->public_id, $owner, $customer['user'], $customer['principal']);
        }

        return app(PrepareOrder::class)->handle($owner, (string) Str::uuid(), OrderFixtures::request($quote, $owner), $customer['user'], $customer['principal']);
    }

    private function login(array $customer): void
    {
        $this->postJson('/account/sign-in', ['email' => $customer['user']->email, 'password' => F::PASSWORD])->assertOk();
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Cookie')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function assertReadOnly(callable $read): void
    {
        $before = $this->retained();
        $files = $this->privateFiles();
        $this->mock(StripeCheckoutGateway::class, fn ($mock) => $mock->shouldNotReceive('account', 'create', 'retrieve'));
        $this->mock(StripePaymentGateway::class, fn ($mock) => $mock->shouldNotReceive('account', 'retrieve', 'paymentIntent'));
        $this->mock(ContractRenderer::class, fn ($mock) => $mock->shouldNotReceive('render'));
        $observing = true;
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$observing, &$writes): void {
            if ($observing && preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        try {
            $read();
        } finally {
            $observing = false;
        }
        $this->assertSame([], $writes, 'Original-item reads must not mutate database state.');
        $this->assertSame($before, $this->retained());
        $this->assertSame($files, $this->privateFiles());
    }

    private function retained(): array
    {
        $rows = DeliveryFixtures::retained();
        foreach (['customer_accounts', 'checkout_intents', 'checkout_sessions', 'checkout_observations', 'test_delivery_controls',
            'test_delivery_authorizations', 'test_delivery_redemptions', 'audit_events'] as $table) {
            $rows[$table] = json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    private function privateFiles(): array
    {
        $disk = Storage::disk('local');
        $files = [];
        foreach ($disk->allFiles() as $path) {
            $files[$path] = hash_file('sha256', $disk->path($path));
            $this->assertIsString($files[$path]);
        }
        ksort($files);

        return $files;
    }
}
