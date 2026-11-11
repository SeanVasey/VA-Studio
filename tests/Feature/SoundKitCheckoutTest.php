<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Domain\Commerce\Payments\ProcessStripeReceipt;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Customers\DataRights\RetentionSchedule;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\Products\SoundKitProduct;
use App\Domain\SoundKits\Products\SoundKitProducts;
use App\Domain\SoundKits\Purchases\Models\SoundKitContract;
use App\Domain\SoundKits\Purchases\Models\SoundKitDeliveryAuthorization;
use App\Domain\SoundKits\Purchases\Models\SoundKitGrant;
use App\Domain\SoundKits\Purchases\Models\SoundKitOrder;
use App\Domain\SoundKits\Purchases\Models\SoundKitPayment;
use App\Domain\SoundKits\Purchases\SoundKitCheckout;
use App\Domain\SoundKits\Purchases\SoundKitContracts;
use App\Domain\SoundKits\Purchases\SoundKitDelivery;
use App\Domain\SoundKits\Purchases\SoundKitOrders;
use App\Domain\SoundKits\Purchases\SoundKitPayments;
use App\Domain\SoundKits\Purchases\SoundKitPurchaseException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\SoundKitProductFixtures;
use Tests\TestCase;

/**
 * BL-13 (FP-007): Stripe TEST purchase, grant, kit-license contract and delivery of a published sound kit. Every kit, license text,
 * licensee, licensor, price and signature below is synthetic; no real Stripe account or network is used (fake gateway).
 */
class SoundKitCheckoutTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use SoundKitProductFixtures;

    private const LICENSOR = 'Synthetic Licensor Line';

    private const TEXT = "[SYNTHETIC] TEST KIT LICENSE\n\nNONBINDING TERMS\n\nSynthetic test terms only; no real rights are granted.\n\n"
        ."PURCHASE DETAILS\n\nKit: {KIT_NAME}\nLicensee: {LICENSEE_NAME}\nLicensor: {LICENSOR_NAME}\nPurchase date: {PURCHASE_DATE}\n"
        ."License fee: {LICENSE_FEE}\nOrder reference: {ORDER_REFERENCE}";

    private User $actor;

    private object $gateway;

    private string $originalEnv = 'testing';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = $this->app['env'];
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        PaymentFixtures::configure();
        $this->gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->actor = LicenseFixtures::admin();
        $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $png = $this->signaturePng();
        Storage::disk('local')->put('contracts/kit-signature.png', $png);
        config(['sound-kits.test_checkout_enabled' => true, 'contracts.vasey_kit_license.licensor_name' => self::LICENSOR,
            'contracts.vasey_license_v1.signature_path' => 'contracts/kit-signature.png', 'contracts.vasey_license_v1.signature_sha256' => hash('sha256', $png)]);
    }

    protected function tearDown(): void
    {
        $this->app->instance('env', $this->originalEnv);
        parent::tearDown();
    }

    /** A published synthetic `sound-kit` license whose text declares exactly the six purchase-details placeholders. */
    private function kitLicense(string $text = self::TEXT): LicenseVersion
    {
        $author = LicenseFixtures::admin();
        $template = LicenseTemplate::create(['name' => 'NONBINDING SYNTHETIC KIT', 'slug' => 'synthetic-kit-'.Str::uuid(), 'type' => 'sound-kit']);
        $draft = app(CreateLicenseDraft::class)->handle($template, ['authored_source' => $text,
            'structured_terms' => ['schema_version' => 1, 'features' => ['Synthetic grant'], 'required_asset_roles' => ['master_wav']]], $author);
        $review = app(ReviewLicense::class);
        $submitted = $review->submit($draft, $author);
        $approved = $review->approve($submitted, LicenseFixtures::admin(), ['approval_reference' => 'SYNTHETIC-TEST-ONLY',
            'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]);

        return app(PublishLicense::class)->handle($approved, $author);
    }

    private function listed(): SoundKitProduct
    {
        return $this->product('published', [], $this->kitLicense());
    }

    private function order(SoundKitProduct $product, string $name = 'Ada Synthetic', ?string $key = null): SoundKitOrder
    {
        return app(SoundKitOrders::class)->create($product->slug, InventoryFixtures::OWNER, $key ?? (string) Str::uuid(), $name);
    }

    /** Starts checkout, then makes the fake provider report the session paid with a succeeded PaymentIntent. */
    private function paidSession(SoundKitOrder $order): void
    {
        app(SoundKitCheckout::class)->start($order->public_id, InventoryFixtures::OWNER);
        $this->gateway->session['status'] = 'complete';
        $this->gateway->session['payment_status'] = 'paid';
        $this->gateway->session['url'] = null;
        $this->gateway->session['payment_intent'] = PaymentFixtures::PAYMENT;
        $this->gateway->payment = PaymentFixtures::payment($this->gateway->session);
    }

    private function receipt(string $id = 'evt_SYNTHETICKITPAYMENT'): StripeWebhookReceipt
    {
        return PaymentFixtures::receipt($this->gateway->session, $id);
    }

    private function paidAndGranted(?SoundKitProduct $product = null): array
    {
        $order = $this->order($product ?? $this->listed());
        $this->paidSession($order);
        $this->assertSame('granted', app(ProcessStripeReceipt::class)->handle($this->receipt()->id));

        return [$order, SoundKitGrant::query()->where('sound_kit_order_id', $order->id)->sole()];
    }

    private function deliverable(): array
    {
        [$order, $grant] = $this->paidAndGranted();
        app(SoundKitContracts::class)->issue($grant);
        app(SoundKitDelivery::class)->control($order->public_id, 'enable', 0, 'SYNTHETIC-ENABLE');

        return [$order, $grant];
    }

    private function refusal(callable $operation): string
    {
        try {
            $operation();
        } catch (SoundKitPurchaseException $error) {
            return $error->errorCode;
        }
        $this->fail('The operation was accepted.');
    }

    private function counts(): array
    {
        return array_map(fn (string $table) => DB::table($table)->count(), ['sound_kit_orders', 'sound_kit_payments', 'sound_kit_grants', 'sound_kit_contracts']);
    }

    private function streamed(array $prepared): string
    {
        $bytes = stream_get_contents($prepared['stream']);
        fclose($prepared['stream']);

        return $bytes;
    }

    public function test_flag_off_or_production_closes_every_kit_purchase_route_and_the_page_offers_no_purchase(): void
    {
        $product = $this->listed();
        $order = $this->order($product);
        $id = $order->public_id;
        $routes = [['post', '/sound-kits/'.$product->slug.'/orders'], ['get', '/sound-kit-orders/'.$id], ['get', '/sound-kit-orders/'.$id.'/status'],
            ['post', '/sound-kit-orders/'.$id.'/checkout'], ['post', '/sound-kit-orders/'.$id.'/checkout/reconcile'],
            ['post', '/sound-kit-orders/'.$id.'/delivery/authorizations'], ['post', '/sound-kit-orders/'.$id.'/delivery/download']];
        foreach ([fn () => config(['sound-kits.test_checkout_enabled' => false]), fn () => config(['sound-kits.test_checkout_enabled' => 'true']),
            fn () => $this->app->instance('env', 'production')] as $close) {
            $close();
            foreach ($routes as [$method, $uri]) {
                // Production also enforces CSRF before routing reaches the controller (419); either way nothing is served.
                $status = $this->{$method.'Json'}($uri, ['licenseeName' => 'Ada', 'idempotencyKey' => 'k-1'])->status();
                $this->assertContains($status, $method === 'post' && app()->environment('production') ? [404, 419] : [404], $uri);
            }
            $this->get('/sound-kits/'.$product->slug)->assertNotFound();
            $this->assertSame('NOT_FOUND', $this->refusal(fn () => $this->order($product)));
            $this->assertSame('unavailable', app(SoundKitPayments::class)->reconcile($order));
            $this->assertSame(1, Artisan::call('vasey:issue-test-kit-contracts'));
            config(['sound-kits.test_checkout_enabled' => true]);
            $this->app->instance('env', $this->originalEnv);
        }
        $this->assertSame([1, 0, 0, 0], $this->counts());
    }

    public function test_the_buyer_journey_over_http_ignores_client_price_revision_and_license_and_names_the_kit_at_stripe(): void
    {
        $product = $this->listed();
        $this->get('/sound-kits/'.$product->slug, ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))])
            ->assertOk()->assertJsonPath('props.kit.purchasable', true);
        $response = $this->postJson('/sound-kits/'.$product->slug.'/orders', ['licenseeName' => 'Ada Synthetic', 'idempotencyKey' => 'kit-key-1',
            'priceMinor' => 1, 'currency' => 'EUR', 'revisionId' => (string) Str::uuid(), 'licenseVersionId' => 1, 'productId' => 999])->assertCreated();
        $order = SoundKitOrder::query()->where('public_id', $response->json('orderId'))->sole();
        $this->assertSame([2500, 'USD'], [$order->price_minor, $order->currency]);
        $revision = $product->archiveRevision;
        $this->assertSame([$revision->public_id, $revision->archive_sha256, $revision->archive_size_bytes, $product->license_version_id, 2500],
            [$order->snapshot['kit_revision']['id'], $order->snapshot['archive']['sha256'], $order->snapshot['archive']['size_bytes'],
                $order->snapshot['license']['version_id'], $order->snapshot['price_minor']]);
        $this->assertNotSame('Ada Synthetic', $order->licensee_ciphertext);
        $this->assertSame('Ada Synthetic', app(SoundKitOrders::class)->licensee($order));
        // The same key replays the same order; a different request under it conflicts.
        $this->postJson('/sound-kits/'.$product->slug.'/orders', ['licenseeName' => 'Ada Synthetic', 'idempotencyKey' => 'kit-key-1'])->assertCreated()->assertJsonPath('orderId', $order->public_id);
        $this->postJson('/sound-kits/'.$product->slug.'/orders', ['licenseeName' => 'Someone Else', 'idempotencyKey' => 'kit-key-1'])->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');

        $checkout = $this->postJson('/sound-kit-orders/'.$order->public_id.'/checkout')->assertOk();
        $this->assertSame('https://checkout.stripe.com/c/pay/'.CheckoutFixtures::SESSION, $checkout->json('checkoutUrl'));
        $create = collect($this->gateway->calls)->where('operation', 'create')->sole();
        $this->assertSame('vasey-kit-checkout-v1-'.$order->public_id, $create['key']);
        $this->assertSame([['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => 2500,
            'product_data' => ['name' => 'VASEY.AUDIO sound kit (test): '.$product->title]]]], $create['params']['line_items']);
        $this->assertSame(['kit_order_id' => $order->public_id, 'schema_version' => 'kit-1'], $create['params']['metadata']);
        $this->assertSame(0, $create['transaction_level']);
        // A second start recovers the bound session instead of creating another.
        $this->postJson('/sound-kit-orders/'.$order->public_id.'/checkout')->assertOk();
        $this->assertCount(1, collect($this->gateway->calls)->where('operation', 'create'));

        // Another session owner cannot see or act on the order.
        $this->flushSession();
        $this->getJson('/sound-kit-orders/'.$order->public_id.'/status')->assertNotFound();
        $this->get('/sound-kit-orders/'.$order->public_id)->assertNotFound();
    }

    public function test_a_paid_webhook_grants_once_and_replays_duplicates_and_reconcile_never_grant_twice(): void
    {
        $order = $this->order($this->listed());
        $this->paidSession($order);
        $receipt = $this->receipt();
        $this->assertSame('granted', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $payment = SoundKitPayment::query()->sole();
        $this->assertSame([$order->id, 2500, 'USD', 'receipt', $receipt->id], [$payment->sound_kit_order_id, $payment->amount_minor, $payment->currency, $payment->source, $payment->stripe_webhook_receipt_id]);
        $grant = SoundKitGrant::query()->sole();
        $this->assertSame([$order->snapshot_hash, $payment->id], [$grant->snapshot_hash, $grant->sound_kit_payment_id]);
        $this->assertSame($order->snapshot['kit_revision']['id'], SoundKitRevision::query()->find($grant->sound_kit_revision_id)->public_id);

        // Replay of the same receipt, a duplicate event under another id, an async_payment_succeeded and a reconcile.
        $this->assertSame('processed', DB::table('stripe_receipt_work')->where('stripe_webhook_receipt_id', $receipt->id)->value('state'));
        app(ProcessStripeReceipt::class)->handle($receipt->id, true);
        app(ProcessStripeReceipt::class)->handle($this->receipt('evt_SYNTHETICKITDUPLICATE')->id);
        app(ProcessStripeReceipt::class)->handle(PaymentFixtures::receipt($this->gateway->session, 'evt_SYNTHETICKITASYNC', 'checkout.session.async_payment_succeeded')->id);
        $this->assertSame('granted', app(SoundKitPayments::class)->reconcile($order));
        $this->assertSame([1, 1, 1], [SoundKitPayment::query()->count(), SoundKitGrant::query()->count(), SoundKitOrder::query()->count()]);
        $this->assertSame($grant->public_id, app(SoundKitPayments::class)->finalize($order)->public_id);
        // The track chain wrote nothing.
        $this->assertSame([0, 0, 0], [DB::table('verified_payments')->count(), DB::table('license_grants')->count(), DB::table('checkout_intents')->count()]);
    }

    public function test_reconcile_recovers_a_missing_webhook_and_unpaid_or_tampered_provider_evidence_never_grants(): void
    {
        $order = $this->order($this->listed());
        app(SoundKitCheckout::class)->start($order->public_id, InventoryFixtures::OWNER);
        // Open and unpaid: nothing is granted.
        $this->assertSame('pending', app(SoundKitPayments::class)->reconcile($order));
        $this->paidSession($order);
        // The PaymentIntent received less than the order price: refused.
        $this->gateway->payment['amount_received'] = 100;
        $this->assertSame('payment_changed', app(SoundKitPayments::class)->reconcile($order));
        // The session total differs from the frozen request: refused.
        $this->gateway->payment = PaymentFixtures::payment($this->gateway->session);
        $session = $this->gateway->session;
        $this->gateway->session['amount_total'] = 1;
        $this->assertSame('payment_changed', app(SoundKitPayments::class)->reconcile($order));
        // Metadata pointing at another order: refused.
        $this->gateway->session = $session;
        $this->gateway->session['metadata']['kit_order_id'] = (string) Str::uuid();
        $this->assertSame('unmatched', app(SoundKitPayments::class)->reconcile($order));
        $this->assertSame([0, 0], [SoundKitPayment::query()->count(), SoundKitGrant::query()->count()]);
        // Honest provider evidence: one payment and one grant, from reconciliation.
        $this->gateway->session = $session;
        $this->assertSame('granted', app(SoundKitPayments::class)->reconcile($order));
        $this->assertSame('reconciliation', SoundKitPayment::query()->sole()->source);
        $this->assertSame(1, SoundKitGrant::query()->count());
    }

    public function test_unpublished_unapproved_blocked_and_changed_kits_are_refused_before_any_money_moves(): void
    {
        $service = app(SoundKitProducts::class);
        foreach (['saved', 'cleared', 'approved'] as $stage) {
            $this->assertSame('NOT_FOUND', $this->refusal(fn () => $this->order($this->product($stage, [], $this->kitLicense()))), $stage);
        }
        // A kit whose license text lacks the purchase-details placeholders cannot be sold (its contract could not be issued).
        $this->assertSame('KIT_UNAVAILABLE', $this->refusal(fn () => $this->order($this->product('published', [], $this->kitLicense("[SYNTHETIC] NO PLACEHOLDERS\n\nText.")))));
        // Without a configured licensor name the lane is unavailable.
        $product = $this->listed();
        config(['contracts.vasey_kit_license.licensor_name' => '']);
        $this->assertSame('CHECKOUT_UNAVAILABLE', $this->refusal(fn () => $this->order($product)));
        config(['contracts.vasey_kit_license.licensor_name' => self::LICENSOR]);
        // A licensee name the license cannot print is refused.
        foreach (['', ' Ada', 'Ada {LICENSOR_NAME}', 'Ада Синтетик', str_repeat('a', 256)] as $bad) {
            $this->assertSame('LICENSEE_NAME_INVALID', $this->refusal(fn () => $this->order($product, $bad)), $bad);
        }
        // Rights revoked (blocked) after an order: checkout refuses to ask for money.
        $order = $this->order($product);
        $down = $service->unpublish($product, $product->revision, $this->actor);
        $this->assertSame('KIT_UNAVAILABLE', $this->refusal(fn () => app(SoundKitCheckout::class)->start($order->public_id, InventoryFixtures::OWNER)));
        $this->assertSame('NOT_FOUND', $this->refusal(fn () => $this->order($down)));
        // A replaced private archive is unavailable at order time.
        $other = $this->listed();
        $path = app(PrivateMediaFiles::class)->resolve($other->archiveRevision->archive_path);
        @chmod($path, 0600);
        file_put_contents($path, 'tampered', FILE_APPEND);
        $this->assertSame('KIT_UNAVAILABLE', $this->refusal(fn () => $this->order($other)));
        // An order must start checkout within its window.
        $late = $this->order($this->listed());
        $this->travel(16)->minutes();
        $this->assertSame('CHECKOUT_EXPIRED', $this->refusal(fn () => app(SoundKitCheckout::class)->start($late->public_id, InventoryFixtures::OWNER)));
        $this->assertSame(0, collect($this->gateway->calls)->where('operation', 'create')->count());
    }

    public function test_the_contract_fills_only_the_six_purchase_details_from_the_snapshot_and_the_licensor_env_value(): void
    {
        [$order, $grant] = $this->paidAndGranted();
        $this->assertSame(0, Artisan::call('vasey:issue-test-kit-contracts'));
        $contract = SoundKitContract::query()->sole();
        $pdf = app(SoundKitContracts::class)->bytes($contract);
        $this->assertSame([$contract->sha256, $contract->size_bytes], [hash('sha256', $pdf), strlen($pdf)]);
        $this->assertStringStartsWith('sound-kit-contracts/'.$grant->public_id.'/', $contract->storage_path);
        $text = $this->pdfText($pdf);
        $date = SoundKitPayment::query()->sole()->verified_at->format('F j, Y');
        foreach (['Kit: '.$order->snapshot['product']['title'], 'Licensee: Ada Synthetic', 'Licensor: '.self::LICENSOR, 'Purchase date: '.$date,
            'License fee: $25.00', 'Order reference: '.$order->public_id] as $line) {
            $this->assertStringContainsString($line, $text);
        }
        $this->assertStringNotContainsString('{', $text);
        // Issuing again records nothing new and keeps the original.
        $this->assertSame($contract->public_id, app(SoundKitContracts::class)->issue($grant)->public_id);
        $this->assertSame(1, SoundKitContract::query()->count());
        // A changed original is refused on read.
        $path = app(PrivateMediaFiles::class)->resolve($contract->storage_path);
        @chmod($path, 0600);
        file_put_contents($path, 'x', FILE_APPEND);
        $this->expectException(ContractIssuanceException::class);
        app(SoundKitContracts::class)->bytes($contract);
    }

    public function test_a_contract_is_refused_while_the_licensor_is_unset_and_the_purchased_license_text_cannot_change(): void
    {
        [, $grant] = $this->paidAndGranted();
        config(['contracts.vasey_kit_license.licensor_name' => '']);
        $this->assertSame(1, Artisan::call('vasey:issue-test-kit-contracts'));
        $this->assertSame(0, SoundKitContract::query()->count());
        // The published license version the snapshot pins is immutable, so the contract always renders the purchased text.
        try {
            DB::table('license_versions')->where('id', $grant->license_version_id)->update(['authored_source' => self::TEXT."\n\nAdded."]);
            $this->fail('A published license version changed.');
        } catch (QueryException) {
        }
        config(['contracts.vasey_kit_license.licensor_name' => self::LICENSOR]);
        $this->assertSame(0, Artisan::call('vasey:issue-test-kit-contracts'));
        $this->assertSame(1, SoundKitContract::query()->count());
    }

    public function test_delivery_needs_an_entitlement_contract_and_control_and_a_fresh_single_use_authorization(): void
    {
        $product = $this->listed();
        $unpaid = $this->order($product);
        $this->assertSame('DELIVERY_UNAVAILABLE', $this->refusal(fn () => app(SoundKitDelivery::class)->authorize($unpaid->public_id, InventoryFixtures::OWNER, 'archive')));
        [$order, $grant] = $this->paidAndGranted($product);
        $delivery = app(SoundKitDelivery::class);
        // Granted, but no contract and no control yet.
        $this->assertSame('DELIVERY_UNAVAILABLE', $this->refusal(fn () => $delivery->authorize($order->public_id, InventoryFixtures::OWNER, 'archive')));
        app(SoundKitContracts::class)->issue($grant);
        $this->assertSame('DELIVERY_UNAVAILABLE', $this->refusal(fn () => $delivery->authorize($order->public_id, InventoryFixtures::OWNER, 'archive')));
        $this->assertSame('IDEMPOTENCY_CONFLICT', $this->refusal(fn () => $delivery->control($order->public_id, 'enable', 1, 'SYNTHETIC-ENABLE')));
        $this->assertSame(0, Artisan::call('vasey:control-test-kit-delivery', ['order' => $order->public_id, 'action' => 'enable', '--expected-version' => '0', '--reference' => 'SYNTHETIC-ENABLE']));
        // Another owner and an unknown kind are refused.
        $this->assertSame('NOT_FOUND', $this->refusal(fn () => $delivery->authorize($order->public_id, str_repeat('b', 64), 'archive')));
        $this->assertSame('INVALID_REQUEST', $this->refusal(fn () => $delivery->authorize($order->public_id, InventoryFixtures::OWNER, 'stems')));

        $issued = $delivery->authorize($order->public_id, InventoryFixtures::OWNER, 'archive');
        $this->assertSame(64, strlen($issued['token']));
        $this->assertSame(hash('sha256', $issued['token']), SoundKitDeliveryAuthorization::query()->sole()->token_hash);
        $this->assertSame('DELIVERY_UNAVAILABLE', $this->refusal(fn () => $delivery->redeem($order->public_id, InventoryFixtures::OWNER, $issued['authorizationId'], str_repeat('0', 64))));
        $bytes = $this->streamed($delivery->redeem($order->public_id, InventoryFixtures::OWNER, $issued['authorizationId'], $issued['token']));
        $this->assertSame($order->snapshot['archive']['sha256'], hash('sha256', $bytes));
        // Single use.
        $this->assertSame('DELIVERY_UNAVAILABLE', $this->refusal(fn () => $delivery->redeem($order->public_id, InventoryFixtures::OWNER, $issued['authorizationId'], $issued['token'])));
        // Expired after 60 s.
        $late = $delivery->authorize($order->public_id, InventoryFixtures::OWNER, 'contract');
        $this->travel(61)->seconds();
        $this->assertSame('DELIVERY_UNAVAILABLE', $this->refusal(fn () => $delivery->redeem($order->public_id, InventoryFixtures::OWNER, $late['authorizationId'], $late['token'])));
        // The contract download is the recorded original.
        $contract = $delivery->authorize($order->public_id, InventoryFixtures::OWNER, 'contract');
        $this->assertSame(SoundKitContract::query()->sole()->sha256, hash('sha256', $this->streamed($delivery->redeem($order->public_id, InventoryFixtures::OWNER, $contract['authorizationId'], $contract['token']))));
        // Blocking delivery closes new and outstanding authorizations.
        $pending = $delivery->authorize($order->public_id, InventoryFixtures::OWNER, 'archive');
        $delivery->control($order->public_id, 'block', 1, 'SYNTHETIC-BLOCK');
        $this->assertSame('DELIVERY_UNAVAILABLE', $this->refusal(fn () => $delivery->redeem($order->public_id, InventoryFixtures::OWNER, $pending['authorizationId'], $pending['token'])));
        $this->assertSame('DELIVERY_UNAVAILABLE', $this->refusal(fn () => $delivery->authorize($order->public_id, InventoryFixtures::OWNER, 'archive')));
    }

    public function test_a_tampered_archive_is_never_streamed(): void
    {
        [$order, $grant] = $this->deliverable();
        $path = app(PrivateMediaFiles::class)->resolve(SoundKitRevision::query()->find($grant->sound_kit_revision_id)->archive_path);
        @chmod($path, 0600);
        $original = file_get_contents($path);
        file_put_contents($path, substr_replace($original, 'X', 10, 1));
        $issued = app(SoundKitDelivery::class)->authorize($order->public_id, InventoryFixtures::OWNER, 'archive');
        $this->assertSame('DELIVERY_UNAVAILABLE', $this->refusal(fn () => app(SoundKitDelivery::class)->redeem($order->public_id, InventoryFixtures::OWNER, $issued['authorizationId'], $issued['token'])));
        $this->assertNull(SoundKitDeliveryAuthorization::query()->where('public_id', $issued['authorizationId'])->value('redeemed_at'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'sound_kit.test_delivery.refused')->count());
    }

    public function test_the_owner_downloads_over_http_with_a_post_token_and_no_public_path_serves_the_kit(): void
    {
        $product = $this->listed();
        $orderId = $this->postJson('/sound-kits/'.$product->slug.'/orders', ['licenseeName' => 'Ada Synthetic', 'idempotencyKey' => 'kit-http'])->assertCreated()->json('orderId');
        $order = SoundKitOrder::query()->where('public_id', $orderId)->sole();
        $this->postJson('/sound-kit-orders/'.$orderId.'/checkout')->assertOk()->assertJsonPath('status', 'checkout_started');
        $this->gateway->session['status'] = 'complete';
        $this->gateway->session['payment_status'] = 'paid';
        $this->gateway->session['url'] = null;
        $this->gateway->session['payment_intent'] = PaymentFixtures::PAYMENT;
        $this->gateway->payment = PaymentFixtures::payment($this->gateway->session);
        $this->postJson('/sound-kit-orders/'.$orderId.'/checkout/reconcile')->assertOk()->assertJsonPath('status', 'awaiting_contract');
        app(SoundKitContracts::class)->issue(SoundKitGrant::query()->sole());
        $this->getJson('/sound-kit-orders/'.$orderId.'/status')->assertOk()->assertJsonPath('status', 'awaiting_delivery')->assertJsonPath('deliverables', []);
        app(SoundKitDelivery::class)->control($orderId, 'enable', 0, 'SYNTHETIC-ENABLE');
        $state = $this->getJson('/sound-kit-orders/'.$orderId.'/status')->assertOk()->assertJsonPath('status', 'downloadable')->json();
        $this->assertSame(['archive', 'contract'], $state['deliverables']);
        $this->assertSame([], array_intersect(['licensee', 'licenseeName', 'path', 'storagePath', 'sha256', 'token'], array_keys($state)));
        $issued = $this->postJson('/sound-kit-orders/'.$orderId.'/delivery/authorizations', ['kind' => 'archive'])->assertCreated()->json();
        $response = $this->post('/sound-kit-orders/'.$orderId.'/delivery/download', ['authorizationId' => $issued['authorizationId'], 'token' => $issued['token']]);
        $response->assertOk()->assertHeader('Content-Type', 'application/zip')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($order->snapshot['archive']['sha256'], hash('sha256', $response->streamedContent()));
        $this->post('/sound-kit-orders/'.$orderId.'/delivery/download', ['authorizationId' => $issued['authorizationId'], 'token' => $issued['token']])->assertForbidden();
        // The private archive has no public address.
        $relative = SoundKitRevision::query()->find(SoundKitGrant::query()->sole()->sound_kit_revision_id)->archive_path;
        foreach (['/'.$relative, '/storage/'.$relative, '/media/'.basename(dirname($relative))] as $uri) {
            $this->assertNotSame(200, $this->get($uri)->status(), $uri);
        }
    }

    public function test_purchase_evidence_is_immutable_and_a_wrong_amount_or_foreign_grant_cannot_be_inserted(): void
    {
        [$order, $grant] = $this->paidAndGranted();
        $payment = SoundKitPayment::query()->sole();
        foreach ([['sound_kit_orders', $order->id, ['price_minor' => 1]], ['sound_kit_payments', $payment->id, ['amount_minor' => 1]],
            ['sound_kit_grants', $grant->id, ['license_version_id' => $grant->license_version_id]], ['sound_kit_checkout_sessions', null, ['account_id' => 'acct_X']]] as [$table, $id, $values]) {
            try {
                DB::table($table)->when($id !== null, fn ($query) => $query->where('id', $id))->update($values);
                $this->fail($table.' update accepted');
            } catch (QueryException) {
            }
            try {
                DB::table($table)->delete();
                $this->fail($table.' delete accepted');
            } catch (QueryException) {
            }
        }
        $second = $this->order(SoundKitProduct::query()->find($order->sound_kit_product_id));
        $this->gateway->onCreate = fn (array $params): array => CheckoutFixtures::session($params, 'cs_test_SECOND');
        app(SoundKitCheckout::class)->start($second->public_id, InventoryFixtures::OWNER);
        $this->expectException(QueryException::class);
        DB::table('sound_kit_payments')->insert(['public_id' => (string) Str::uuid(), 'sound_kit_order_id' => $second->id, 'account_id' => $second->account_id,
            'provider_session_id' => 'cs_test_OTHER', 'provider_payment_intent_id' => 'pi_OTHER', 'source' => 'reconciliation', 'amount_minor' => 1,
            'currency' => 'USD', 'evidence' => '{}', 'evidence_hash' => str_repeat('a', 64), 'verified_at' => CarbonImmutable::now()]);
    }

    public function test_the_tables_are_classified_for_retention(): void
    {
        $tables = array_column(RetentionSchedule::ENTRIES, 'class', 'table');
        foreach (['sound_kit_orders', 'sound_kit_checkout_sessions', 'sound_kit_payments', 'sound_kit_grants', 'sound_kit_contracts',
            'sound_kit_delivery_controls', 'sound_kit_delivery_authorizations'] as $table) {
            $this->assertSame('not_applicable', $tables[$table] ?? null, $table);
        }
    }

    private function pdfText(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kit-contract-');
        file_put_contents($path, $pdf);
        try {
            $extract = new Process(['pdftotext', '-enc', 'UTF-8', '-layout', $path, '-'], timeout: 30);
            $extract->mustRun();

            return $extract->getOutput();
        } finally {
            @unlink($path);
        }
    }

    /** A generated stand-in signature (a zig-zag stroke, plain-PHP PNG). Never a real signature. */
    private function signaturePng(int $width = 600, int $height = 180): string
    {
        $rows = '';
        for ($y = 0; $y < $height; $y++) {
            $rows .= "\0";
            for ($x = 0; $x < $width; $x++) {
                $phase = intdiv($x, 60) % 2 === 0 ? $x % 60 : 60 - $x % 60;
                $rows .= $x >= 40 && $x < $width - 40 && abs($y - (60 + $phase)) <= 2 ? "\x14\x1e\x3c\xff" : "\xff\xff\xff\x00";
            }
        }
        $deflate = "\x78\x01";
        foreach (str_split($rows, 65535) as $index => $block) {
            $last = ($index + 1) * 65535 >= strlen($rows);
            $deflate .= chr($last ? 1 : 0).pack('v', strlen($block)).pack('v', strlen($block) ^ 0xFFFF).$block;
        }
        $deflate .= hash('adler32', $rows, true);
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IDAT', $deflate).$chunk('IEND', '');
    }
}
