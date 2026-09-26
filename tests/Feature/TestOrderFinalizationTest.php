<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Finalization\DispatchTestFinalization;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Finalization\FinalizationAssets;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\ExclusiveSale;
use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\ProcessStripeReceipt;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Domain\Commerce\QuoteException;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Jobs\FinalizeTestPaymentJob;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures as F;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** Test-mode financial evidence creates frozen rights and pending fulfillment, never active downloads. */
class TestOrderFinalizationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        $this->gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
    }

    public function test_verified_payment_atomically_freezes_exclusive_rights_and_consumes_resources_once(): void
    {
        $f = F::confirmed($this->gateway, true, true); $original = $f['original'];
        $orderBefore = $f['order']->refresh()->getAttributes(); $paymentBefore = $f['payment']->refresh()->getAttributes();
        $calls = $this->gateway->calls; $this->travelTo(now()->addSeconds(5));
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertDatabaseCount('order_finalizations', 1); $this->assertDatabaseCount('license_grants', 1);
        $this->assertDatabaseCount('pending_entitlements', 1); $this->assertDatabaseCount('fulfillment_outbox', 1);
        $this->assertDatabaseCount('exclusive_sales', 1);
        $finalization = OrderFinalization::sole(); $grant = LicenseGrant::sole(); $entitlement = PendingEntitlement::sole();
        $this->assertSame('paid', $finalization->outcome); $this->assertNull($finalization->reason);
        $this->assertSame($f['order']->id, $finalization->order_id);
        $this->assertSame($f['payment']->id, $finalization->verified_payment_id);
        $this->assertTrue($finalization->confirmed_at->equalTo($f['payment']->confirmed_at));
        $this->assertTrue($finalization->eligibility_cutoff->equalTo($f['order']->attempt()->sole()->expires_at));
        $this->assertTrue($finalization->finalized_at->equalTo(now()));
        $this->assertSame($f['revision']->id, $grant->offer_revision_id);
        $this->assertSame($f['scope']->id, $grant->rights_scope_id);
        $this->assertSame($grant->id, $entitlement->license_grant_id); $this->assertSame('pending', $entitlement->state);
        $this->assertSame($grant->id, ExclusiveSale::sole()->license_grant_id);
        $this->assertSame('render_test_contract_v1', FulfillmentOutbox::sole()->kind);
        $this->assertSame('grant:0', FulfillmentOutbox::sole()->effect_key);
        foreach ([InventoryReservation::sole(), PromotionUse::sole()] as $resource) {
            $this->assertSame('consumed', $resource->state); $this->assertTrue($resource->consumed_at->equalTo(now()));
        }
        $this->assertSame($original, app(ReadOrder::class)->verify($f['order']->fresh()));
        $this->assertSame($orderBefore, $f['order']->fresh()->getAttributes());
        $this->assertSame($paymentBefore, $f['payment']->fresh()->getAttributes());
        $snapshot = F::retained(); $audits = DB::table('audit_events')->count();
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertSame($snapshot, F::retained()); $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertSame($calls, $this->gateway->calls);
        $this->assertSame('paid', app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER)['status']);
    }

    public function test_nonexclusive_grant_has_no_exclusive_sale_and_no_download_authority(): void
    {
        $f = F::confirmed($this->gateway);
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertDatabaseCount('exclusive_sales', 0); $this->assertDatabaseCount('license_grants', 1);
        $this->assertSame('pending', PendingEntitlement::sole()->state);
        $view = app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('verified', $view['paymentStatus']); $this->assertSame('paid', $view['finalizationStatus']);
        $this->assertSame('pending_contracts', $view['fulfillmentStatus']); $this->assertFalse($view['payable']);
        $this->assertArrayNotHasKey('downloadUrl', $view); $this->assertArrayNotHasKey('contractUrl', $view);
    }

    public function test_missing_payment_cannot_be_finalized_and_prepared_projection_remains_unpaid(): void
    {
        $f = PaymentFixtures::started($this->gateway); $before = F::retained(); $calls = $this->gateway->calls;
        $this->assertSame('unverified', app(FinalizeTestPayment::class)->handle(999999999));
        $view = app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('prepared', $view['status']); $this->assertSame('not_verified', $view['paymentStatus']);
        $this->assertSame('not_started', $view['finalizationStatus']);
        $this->assertSame($before, F::retained()); $this->assertSame($calls, $this->gateway->calls);
    }

    public function test_observed_confirmation_before_cutoff_can_be_finalized_after_expiry(): void
    {
        $f = F::confirmed($this->gateway, true, true);
        $this->travelTo($f['order']->attempt()->sole()->expires_at->addDays(3));
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertTrue(OrderFinalization::sole()->finalized_at->equalTo(now()));
        $this->assertTrue(LicenseGrant::sole()->created_at->equalTo(now()));
        $this->assertSame($f['original'], app(ReadOrder::class)->verify($f['order']->fresh()));
    }

    public static function lateConfirmationTimes(): array { return [['equal', 0], ['after', 1]]; }

    #[DataProvider('lateConfirmationTimes')]
    public function test_confirmation_at_or_after_cutoff_is_a_retained_paid_exception(string $label, int $offset): void
    {
        $f = PaymentFixtures::started($this->gateway, true, true);
        $this->travelTo($f['order']->attempt()->sole()->expires_at->addSeconds($offset));
        $f = F::confirm($f); $before = PaymentFixtures::unchangedBusinessEvidence();
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id), $label);
        $this->assertSame('late_confirmation', OrderFinalization::sole()->reason);
        $this->assertExceptionGraph($f, $before);
        $snapshot = F::retained();
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertSame($snapshot, F::retained());
    }

    public function test_scope_block_after_confirmation_creates_exception_without_releasing_resources(): void
    {
        $f = F::confirmed($this->gateway, true, true);
        app(ManageRightsScope::class)->block($f['scope']->id, true, 0, 'SYNTHETIC-FINALIZATION-BLOCK', $f['actor']);
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertSame('inventory_blocked', OrderFinalization::sole()->reason);
        $this->assertExceptionGraph($f, $before);
        app(ManageRightsScope::class)->block($f['scope']->id, false, 1, 'SYNTHETIC-FINALIZATION-UNBLOCK', $f['actor']);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_missing_purchased_file_remains_retryable_without_substituting_a_new_asset(): void
    {
        $f = F::confirmed($this->gateway, false, true); $before = PaymentFixtures::unchangedBusinessEvidence();
        $asset = $f['media']['master_wav']; Storage::disk($asset->disk)->delete($asset->storage_path);
        $this->assertSame('retry', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertDatabaseCount('order_finalizations', 0); $this->assertDatabaseCount('license_grants', 0);
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
    }

    private function assertExceptionGraph(array $f, array $before): void
    {
        $this->assertDatabaseCount('order_finalizations', 1); $this->assertDatabaseCount('license_grants', 0);
        $this->assertDatabaseCount('pending_entitlements', 0); $this->assertDatabaseCount('exclusive_sales', 0);
        $this->assertDatabaseCount('fulfillment_outbox', 1);
        $this->assertSame('order_paid_exception_v1', FulfillmentOutbox::sole()->kind);
        $this->assertSame('exception', FulfillmentOutbox::sole()->effect_key); $this->assertNull(FulfillmentOutbox::sole()->license_grant_id);
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $view = app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('paid_exception', $view['status']); $this->assertSame('verified', $view['paymentStatus']);
        $this->assertSame('blocked', $view['fulfillmentStatus']);
        $this->assertSame($f['original'], app(ReadOrder::class)->verify($f['order']->fresh()));
    }

    public static function invalidConfiguration(): array
    {
        return [['disabled', false], ['string_enabled', 'true'], ['missing_policy', null], ['unknown_policy', 'unknown'],
            ['live', 'live'], ['wrong_account', 'acct_OTHER'], ['production', 'production'], ['staging', 'staging']];
    }

    #[DataProvider('invalidConfiguration')]
    public function test_finalization_requires_its_own_strict_test_policy_and_account(string $scenario, mixed $value): void
    {
        $f = F::confirmed($this->gateway); $before = F::retained();
        $environment = $this->app->environment();
        try {
            match ($scenario) {
                'disabled', 'string_enabled' => config(['payments.stripe.finalization_enabled' => $value]),
                'missing_policy', 'unknown_policy' => config(['payments.stripe.finalization_policy' => $value]),
                'live' => config(['payments.stripe.mode' => $value]),
                'wrong_account' => config(['payments.stripe.account_id' => $value]),
                'production', 'staging' => $this->app->detectEnvironment(fn () => $value),
            };
            $this->assertSame($scenario === 'wrong_account' ? 'unverified' : 'unavailable', app(FinalizeTestPayment::class)->handle($f['payment']->id));
            $this->assertSame($before, F::retained());
        } finally { $this->app->detectEnvironment(fn () => $environment); }
    }

    public function test_financial_recovery_is_independent_of_new_checkout_and_processing_enablement(): void
    {
        $f = F::confirmed($this->gateway); $calls = $this->gateway->calls;
        config(['payments.stripe.checkout_enabled' => false, 'payments.stripe.processing_enabled' => false,
            'commerce.test_checkout_policy' => null, 'commerce.test_order_policy' => null,
            'commerce.test_inventory_policy' => null, 'commerce.test_pricing_policy' => null]);
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        config(['payments.stripe.finalization_enabled' => false, 'payments.stripe.finalization_policy' => null]);
        $this->assertSame('paid', app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER)['status']);
        $checkout = app(HostedCheckout::class)->status($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('verified', $checkout['paymentStatus']); $this->assertSame('paid', $checkout['finalizationStatus']);
        $this->assertNull($checkout['url']); $this->assertSame($calls, $this->gateway->calls);
    }

    public function test_caller_owned_transaction_cannot_be_partially_finalized(): void
    {
        $f = F::confirmed($this->gateway); $before = F::retained();
        DB::beginTransaction();
        try { $this->assertSame('unavailable', app(FinalizeTestPayment::class)->handle($f['payment']->id)); }
        finally { DB::rollBack(); }
        $this->assertSame($before, F::retained());
    }

    public static function writeFailures(): array
    {
        return [[LicenseGrant::class], [PendingEntitlement::class], [ExclusiveSale::class], [FulfillmentOutbox::class]];
    }

    #[DataProvider('writeFailures')]
    public function test_failure_after_any_child_insert_rolls_back_the_entire_business_effect(string $model): void
    {
        $f = F::confirmed($this->gateway, true, true); $before = F::retained(); $audits = DB::table('audit_events')->count();
        $armed = true;
        Event::listen('eloquent.created: '.$model, function () use (&$armed): void {
            if ($armed) { $armed = false; throw new RuntimeException('SYNTHETIC-ROLLBACK-PRIVATE-MARKER'); }
        });
        $this->assertSame('retry', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertSame($before, F::retained()); $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertDatabaseCount('order_finalizations', 1); $this->assertDatabaseCount('license_grants', 1);
    }

    public function test_payment_and_checkout_replays_preserve_historical_grants_after_resource_consumption(): void
    {
        $f = F::confirmed($this->gateway, true, true);
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id)); $before = F::retained();
        $this->assertSame('awaiting_finalization', app(VerifyTestPayment::class)->reconcile($f['intent']));
        $receipt = PaymentFixtures::receipt($this->gateway->session);
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $view = app(HostedCheckout::class)->reconcile($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('paid', $view['finalizationStatus']); $this->assertSame($before, F::retained());
        $this->assertSame($f['original'], app(ReadOrder::class)->verify($f['order']->fresh()));
    }

    public function test_owner_status_is_read_only_private_and_does_not_expose_grant_or_payment_material(): void
    {
        $f = F::ownedHttp($this, $this->gateway);
        $this->getJson('/orders/'.$f['order']->public_id.'/status')->assertOk()
            ->assertJsonPath('order.paymentStatus', 'verified')->assertJsonPath('order.finalizationStatus', 'awaiting_finalization');
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $before = F::retained(); $calls = $this->gateway->calls; $audits = DB::table('audit_events')->count();
        foreach (['/status', '/checkout?success=true&session_id=cs_test_ATTACKER'] as $suffix) {
            $response = $this->getJson('/orders/'.$f['order']->public_id.$suffix)->assertOk();
            $response->assertHeader('Cache-Control', 'no-store, private');
            $this->assertContains('Cookie', $response->baseResponse->getVary());
            $prefix = $suffix === '/status' ? 'order' : 'checkout';
            $response->assertJsonPath($prefix.'.paymentStatus', 'verified')->assertJsonPath($prefix.'.finalizationStatus', 'paid');
            foreach ([...array_values(OrderFixtures::buyer()), CheckoutFixtures::ACCOUNT, PaymentFixtures::PAYMENT,
                $f['order']->owner_key, $f['intent']->idempotency_key, LicenseGrant::sole()->render_input_ciphertext,
                OrderFinalization::sole()->evidence_hash, 'downloadUrl', 'asset_hash'] as $private) { $response->assertDontSee($private, false); }
        }
        $this->get('/orders/'.$f['order']->public_id.'/checkout/return?success=true')->assertOk();
        $this->assertSame($before, F::retained()); $this->assertSame($calls, $this->gateway->calls);
        $this->assertSame($audits, DB::table('audit_events')->count());
        $this->flushSession();
        $foreign = $this->getJson('/orders/'.$f['order']->public_id.'/status')->assertNotFound();
        $unknown = $this->getJson('/orders/'.Str::uuid().'/status')->assertNotFound();
        $this->assertSame($foreign->json(), $unknown->json());
        $this->getJson('/orders/'.$f['order']->public_id.'/checkout')->assertNotFound();
    }

    public function test_encrypted_render_input_and_outbox_exclude_provider_secrets_and_public_serialization(): void
    {
        $f = F::confirmed($this->gateway); $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $grant = LicenseGrant::sole(); $finalization = OrderFinalization::sole();
        foreach ([[$grant, 'render_input'], [$finalization, 'evidence']] as [$model, $prefix]) {
            $ciphertext = $model->{$prefix.'_ciphertext'}; $plain = Crypt::decryptString($ciphertext);
            $this->assertSame(hash('sha256', $ciphertext), $model->{$prefix.'_hash'});
            $this->assertSame($plain, CanonicalJson::encode(json_decode($plain, true, 128, JSON_THROW_ON_ERROR)));
            $this->assertStringNotContainsString($ciphertext, $model->toJson());
            $this->assertStringNotContainsString(OrderFixtures::buyer()['email'], $model->toJson());
            if ($prefix === 'render_input') { $this->assertStringNotContainsString(CheckoutFixtures::ACCOUNT, $plain); }
            foreach ([PaymentFixtures::PAYMENT, 'client_secret', 'sk_test_', 'checkout.stripe.com'] as $private) {
                $this->assertStringNotContainsString($private, $plain);
            }
        }
        $outbox = FulfillmentOutbox::sole();
        $this->assertSame(['evidence_hash', 'finalization_id', 'grant_id', 'schema_version'], array_keys(json_decode(CanonicalJson::encode($outbox->payload), true, 16, JSON_THROW_ON_ERROR)));
        $this->assertStringNotContainsString(OrderFixtures::buyer()['email'], json_encode($outbox->payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('render_input', json_encode($outbox->payload, JSON_THROW_ON_ERROR));
    }

    public function test_async_dispatch_is_id_only_after_commit_and_rollback_discards_it(): void
    {
        $f = F::confirmed($this->gateway); Queue::fake(); config(['queue.default' => 'database']);
        DB::beginTransaction(); app(DispatchTestFinalization::class)->handle($f['payment']->id);
        Queue::assertNothingPushed(); DB::rollBack(); Queue::assertNothingPushed();
        DB::transaction(function () use ($f): void { app(DispatchTestFinalization::class)->handle($f['payment']->id); Queue::assertNothingPushed(); });
        Queue::assertPushed(FinalizeTestPaymentJob::class, function ($job) use ($f): bool {
            $this->assertSame($f['payment']->id, $job->paymentId); $this->assertSame('payments', $job->queue);
            $this->assertSame(1, $job->tries); $this->assertSame(90, $job->timeout); $this->assertTrue($job->afterCommit);
            $serialized = serialize($job);
            foreach ([OrderFixtures::buyer()['email'], PaymentFixtures::PAYMENT, $f['order']->owner_key] as $private) {
                $this->assertStringNotContainsString($private, $serialized);
            }
            return true;
        });
        $this->assertDatabaseCount('order_finalizations', 0);
    }

    public function test_sync_or_failed_dispatch_leaves_verified_payment_for_manual_recovery(): void
    {
        $f = F::confirmed($this->gateway); Queue::fake(); config(['queue.default' => 'sync']);
        app(DispatchTestFinalization::class)->handle($f['payment']->id); Queue::assertNothingPushed();
        config(['queue.default' => 'database']); Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('SYNTHETIC-QUEUE-FAILURE'));
        app(DispatchTestFinalization::class)->handle($f['payment']->id);
        $this->assertDatabaseCount('verified_payments', 1); $this->assertDatabaseCount('order_finalizations', 0);
        (new FinalizeTestPaymentJob($f['payment']->id))->handle(app(FinalizeTestPayment::class));
        $this->assertDatabaseCount('order_finalizations', 1); $this->assertDatabaseCount('license_grants', 1);
    }

    public function test_cli_scans_verified_orders_and_emits_only_opaque_recovery_locators(): void
    {
        $f = F::confirmed($this->gateway); $calls = $this->gateway->calls;
        $exit = Artisan::call('vasey:finalize-test-payments', ['--limit' => '1']); $output = Artisan::output();
        $this->assertSame(0, $exit); $this->assertStringContainsString($f['order']->public_id, $output);
        $this->assertStringContainsString('paid', $output); $this->assertStringContainsString('NEXT_AFTER='.$f['order']->public_id, $output);
        foreach ([...array_values(OrderFixtures::buyer()), CheckoutFixtures::ACCOUNT, PaymentFixtures::PAYMENT,
            $f['order']->owner_key, $f['intent']->idempotency_key] as $private) { $this->assertStringNotContainsString($private, $output); }
        $before = F::retained();
        $this->assertSame(0, Artisan::call('vasey:finalize-test-payments', ['order' => $f['order']->public_id]));
        $this->assertSame($before, F::retained()); $this->assertSame($calls, $this->gateway->calls);
        $this->assertSame(0, Artisan::call('vasey:finalize-test-payments', ['--after' => $f['order']->public_id]));
        $this->assertStringNotContainsString('NEXT_AFTER=', Artisan::output());
        foreach ([['order' => '1'], ['--limit' => 0], ['--limit' => 101], ['--after' => 'invalid'],
            ['order' => $f['order']->public_id, '--after' => $f['order']->public_id]] as $arguments) {
            $this->assertNotSame(0, Artisan::call('vasey:finalize-test-payments', $arguments));
        }
    }

    public function test_fresh_asset_digest_defeats_a_stale_fast_integrity_result(): void
    {
        $f = F::confirmed($this->gateway, false, true); $before = PaymentFixtures::unchangedBusinessEvidence();
        $asset = $f['media']['master_wav']; $path = Storage::disk($asset->disk)->path($asset->storage_path);
        $provenance = new class extends VerifiedMedia {
            public string $retainedPath;
            public array $transactionLevels = [];
            public function path(\App\Domain\Media\Models\MediaAsset $asset): ?string {
                $this->transactionLevels[] = DB::transactionLevel();
                return $this->retainedPath;
            }
        };
        $provenance->retainedPath = $path; $this->app->instance(VerifiedMedia::class, $provenance);
        $bytes = file_get_contents($path); $bytes[0] = $bytes[0] === 'X' ? 'Y' : 'X';
        // Published fixture files are read-only; deliberately corrupt only this isolated test copy.
        $this->assertTrue(chmod($path, 0600));
        try { $this->assertSame(strlen($bytes), file_put_contents($path, $bytes)); }
        finally { chmod($path, 0440); }
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertSame('asset_unavailable', OrderFinalization::sole()->reason); $this->assertExceptionGraph($f, $before);
        $this->assertSame([0], $provenance->transactionLevels);
    }

    public function test_new_unverified_rights_declaration_cannot_satisfy_retained_purchase_rights(): void
    {
        $f = F::confirmed($this->gateway, true, true); $before = PaymentFixtures::unchangedBusinessEvidence();
        RightsDeclaration::create(['track_id' => $f['track']->id, 'provenance_reference' => 'SYNTHETIC-NEW-RIGHTS',
            'sample_disclosure' => 'Synthetic unresolved rights', 'status' => 'pending']);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertSame('rights_unavailable', OrderFinalization::sole()->reason); $this->assertExceptionGraph($f, $before);
    }

    public function test_published_successor_and_later_asset_loss_do_not_rewrite_original_grant_input(): void
    {
        $f = F::confirmed($this->gateway); $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $grant = LicenseGrant::sole(); $before = $grant->getAttributes();
        $render = json_decode(Crypt::decryptString($grant->render_input_ciphertext), true, 128, JSON_THROW_ON_ERROR);
        $this->assertSame('test_grant_render_input', $render['purpose']);
        $this->assertSame(['email' => OrderFixtures::buyer()['email'], 'identity' => 'unverified_guest',
            'legal_name' => OrderFixtures::buyer()['legalName']], $render['buyer']);
        $this->assertSame($f['original']['policy']['seller'], $render['seller']);
        $this->assertSame($f['original']['lines'][0]['selection'], $render['selection']);
        $this->assertSame($f['original']['lines'][0]['pricing'], $render['pricing']);
        $this->assertSame($f['original']['lines'][0]['disclosure'], $render['disclosure']);
        $this->assertTrue($render['assent']['accepted']);
        $offer = app(SaveOfferDraft::class)->handle($f['offer'], ['price_minor' => 7999], $f['actor']);
        $successor = app(PublishOffer::class)->handle($offer, $f['actor']);
        $this->assertNotSame($f['revision']->id, $successor->id);
        Storage::disk($f['media']['master_wav']->disk)->delete($f['media']['master_wav']->storage_path);
        $this->assertSame($before, LicenseGrant::sole()->getAttributes());
        $this->assertSame($f['original'], app(ReadOrder::class)->verify($f['order']->fresh()));
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
    }

    public static function corruptedTerminalGraph(): array
    {
        return [['finalization_hash'], ['finalization_purpose'], ['finalization_checks'], ['grant_buyer'], ['grant_selection'],
            ['grant_assent'], ['entitlement_hash'], ['outbox_payload'], ['exclusive_scope']];
    }

    #[DataProvider('corruptedTerminalGraph')]
    public function test_historical_reader_rejects_corrupt_graph_even_with_valid_reencryption(string $scenario): void
    {
        $f = F::confirmed($this->gateway, true, true); $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $before = F::retained(); $replacementHash = null;
        $model = match ($scenario) {
            'finalization_hash', 'finalization_purpose', 'finalization_checks' => OrderFinalization::class,
            'grant_buyer', 'grant_selection', 'grant_assent' => LicenseGrant::class,
            'entitlement_hash' => PendingEntitlement::class, 'outbox_payload' => FulfillmentOutbox::class,
            'exclusive_scope' => ExclusiveSale::class,
        };
        Event::listen('eloquent.retrieved: '.$model, function ($record) use ($scenario, &$replacementHash): void {
            if ($scenario === 'finalization_hash') { $record->evidence_hash = str_repeat('0', 64); return; }
            if ($scenario === 'entitlement_hash') { $record->asset_hash = str_repeat('0', 64); return; }
            if ($scenario === 'exclusive_scope') { $record->rights_scope_id++; return; }
            if ($scenario === 'outbox_payload') { $payload = $record->payload; $payload['grant_id'] = (string) Str::uuid(); $record->payload = $payload; return; }
            $prefix = str_starts_with($scenario, 'grant_') ? 'render_input' : 'evidence';
            $payload = json_decode(Crypt::decryptString($record->{$prefix.'_ciphertext'}), true, 128, JSON_THROW_ON_ERROR);
            match ($scenario) {
                'finalization_purpose' => $payload['purpose'] = 'untrusted-finalization-purpose',
                'finalization_checks' => $payload['checks']['scope_controls'][0]['scope_id']++,
                'grant_buyer' => $payload['buyer']['identity'] = 'verified_account',
                'grant_selection' => $payload['selection']['offer_revision_id'] = 999999999,
                'grant_assent' => $payload['assent']['accepted'] = false,
            };
            $cipher = Crypt::encryptString(CanonicalJson::encode($payload)); $replacementHash = hash('sha256', $cipher);
            $record->{$prefix.'_ciphertext'} = $cipher; $record->{$prefix.'_hash'} = $replacementHash;
        });
        if (str_starts_with($scenario, 'grant_')) {
            Event::listen('eloquent.retrieved: '.FulfillmentOutbox::class, function ($record) use (&$replacementHash): void {
                if ($replacementHash !== null) { $payload = $record->payload; $payload['evidence_hash'] = $replacementHash; $record->payload = $payload; }
            });
        }
        try { app(ReadOrder::class)->verify($f['order']->fresh()); $this->fail('Changed retained graph was accepted.'); }
        catch (QuoteException $error) { $this->assertSame('ORDER_CHANGED', $error->errorCode); }
        $this->assertSame('changed', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertSame($before, F::retained());
    }

    public function test_cli_cursor_advances_after_failed_finalization_and_skips_completed_rows_before_limit(): void
    {
        $first = F::confirmed($this->gateway);
        $this->gateway->onCreate = fn (array $params) => CheckoutFixtures::session($params, 'cs_test_SECONDORDER');
        $second = PaymentFixtures::started($this->gateway);
        $this->gateway->session['payment_intent'] = 'pi_SECONDORDER'; $this->gateway->payment['id'] = 'pi_SECONDORDER';
        $second = F::confirm($second); $armed = true;
        Event::listen('eloquent.creating: '.OrderFinalization::class, function ($record) use ($first, &$armed): void {
            if ($armed && $record->order_id === $first['order']->id) { throw new RuntimeException('SYNTHETIC-FAILED-FIRST-ROW'); }
        });
        $this->assertSame(0, Artisan::call('vasey:finalize-test-payments', ['--limit' => '1'])); $output = Artisan::output();
        $this->assertStringContainsString('retry', $output); $this->assertStringContainsString('NEXT_AFTER='.$first['order']->public_id, $output);
        $this->assertDatabaseCount('order_finalizations', 0);
        $this->assertSame(0, Artisan::call('vasey:finalize-test-payments', ['--limit' => '1', '--after' => $first['order']->public_id]));
        $this->assertStringContainsString('NEXT_AFTER='.$second['order']->public_id, Artisan::output());
        $this->assertSame($second['order']->id, OrderFinalization::sole()->order_id);
        $armed = false;
        $this->assertSame(0, Artisan::call('vasey:finalize-test-payments', ['--limit' => '1']));
        $this->assertDatabaseCount('order_finalizations', 2);
        $this->assertSame(0, Artisan::call('vasey:finalize-test-payments', ['--limit' => '1']));
        $this->assertStringNotContainsString('NEXT_AFTER=', Artisan::output());
    }

    public function test_a_semantically_changed_confirmation_cannot_issue_rights_even_with_valid_encryption(): void
    {
        $f = F::confirmed($this->gateway); $before = F::retained(); $calls = $this->gateway->calls;
        Event::listen('eloquent.retrieved: '.VerifiedPayment::class, function ($payment): void {
            $payload = json_decode(Crypt::decryptString($payment->evidence_ciphertext), true, 128, JSON_THROW_ON_ERROR);
            $payload['payment']['amount_received'] = 0;
            $cipher = Crypt::encryptString(CanonicalJson::encode($payload));
            $payment->evidence_ciphertext = $cipher; $payment->evidence_hash = hash('sha256', $cipher);
        });
        $this->assertSame('changed', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertSame($before, F::retained()); $this->assertSame($calls, $this->gateway->calls);
        $this->assertDatabaseCount('license_grants', 0); $this->assertDatabaseCount('order_finalizations', 0);
    }

    public function test_mixed_cart_finalizes_all_lines_with_one_exclusive_sale_and_one_promotion_consumption(): void
    {
        $f = F::confirmedMixedCart($this->gateway); $order = $f['order'];
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertDatabaseCount('order_finalizations', 1); $this->assertDatabaseCount('license_grants', 2);
        $this->assertDatabaseCount('pending_entitlements', 2); $this->assertDatabaseCount('fulfillment_outbox', 2);
        $this->assertDatabaseCount('exclusive_sales', 1);
        $this->assertSame($f['exclusive']['scope']->id, ExclusiveSale::sole()->rights_scope_id);
        $this->assertSame(['grant:0', 'grant:1'], FulfillmentOutbox::orderBy('effect_key')->pluck('effect_key')->all());
        $this->assertSame($order->lines()->orderBy('id')->pluck('id')->all(), LicenseGrant::orderBy('order_line_id')->pluck('order_line_id')->all());
        $this->assertSame('consumed', InventoryReservation::sole()->state); $this->assertSame('consumed', PromotionUse::sole()->state);
        $this->assertSame($f['original'], app(ReadOrder::class)->verify($order->fresh()));
        $before = F::retained(); $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertSame($before, F::retained());
    }

    public function test_one_blocked_mixed_cart_line_prevents_every_grant_and_preserves_pending_promotion(): void
    {
        $f = F::confirmedMixedCart($this->gateway); $before = PaymentFixtures::unchangedBusinessEvidence();
        app(ManageRightsScope::class)->block($f['nonExclusive']['scope']->id, true, 0,
            'SYNTHETIC-MIXED-LINE-BLOCK', $f['nonExclusive']['actor']);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertSame('inventory_blocked', OrderFinalization::sole()->reason);
        $this->assertExceptionGraph($f, $before);
        $this->assertSame('pending', InventoryReservation::sole()->state); $this->assertSame('pending', PromotionUse::sole()->state);
    }

    public function test_second_line_write_failure_rolls_back_the_first_lines_grant_sale_and_outbox(): void
    {
        $f = F::confirmedMixedCart($this->gateway); $before = F::retained(); $created = 0; $seen = null; $armed = true;
        Event::listen('eloquent.created: '.LicenseGrant::class, function () use (&$created, &$seen, &$armed): void {
            $created++;
            if ($armed && $created === 2) {
                $armed = false;
                $seen = ['grants' => LicenseGrant::count(), 'sales' => ExclusiveSale::count(), 'outbox' => FulfillmentOutbox::count()];
                throw new RuntimeException('SYNTHETIC-SECOND-GRANT-FAILURE');
            }
        });
        $this->assertSame('retry', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertSame(['grants' => 2, 'sales' => 1, 'outbox' => 1], $seen);
        $this->assertSame($before, F::retained());
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertDatabaseCount('license_grants', 2); $this->assertDatabaseCount('pending_entitlements', 2);
        $this->assertDatabaseCount('exclusive_sales', 1); $this->assertDatabaseCount('fulfillment_outbox', 2);
        $this->assertSame('consumed', InventoryReservation::sole()->state); $this->assertSame('consumed', PromotionUse::sole()->state);
    }

    public function test_payment_confirmation_dispatches_finalization_only_after_durable_confirmation(): void
    {
        $f = PaymentFixtures::started($this->gateway); Queue::fake(); config(['queue.default' => 'database']);
        $f = F::confirm($f);
        Queue::assertPushed(FinalizeTestPaymentJob::class, function ($job) use ($f): bool {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($f['payment']->id, $job->paymentId);
            $this->assertDatabaseHas('verified_payments', ['id' => $job->paymentId]);
            return true;
        });
        Queue::assertPushed(FinalizeTestPaymentJob::class, 1);
        $this->assertDatabaseCount('order_finalizations', 0);
    }

    public function test_failure_after_resource_consumption_rolls_back_consumption_and_all_rights(): void
    {
        $f = F::confirmed($this->gateway, true, true); $before = F::retained(); $audits = DB::table('audit_events')->count(); $armed = true; $observed = null;
        Event::listen('eloquent.creating: '.\App\Support\Audit\AuditEvent::class, function ($audit) use (&$armed, &$observed): void {
            if ($armed && $audit->action === 'commerce.order.test_finalized') {
                $armed = false;
                $observed = [InventoryReservation::sole()->state, PromotionUse::sole()->state];
                throw new RuntimeException('SYNTHETIC-FINAL-AUDIT-FAILURE');
            }
        });
        $this->assertSame('retry', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertFalse($armed); $this->assertSame(['consumed', 'consumed'], $observed); $this->assertSame($before, F::retained());
        $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertDatabaseCount('license_grants', 1);
    }

    public function test_late_confirmation_with_unreadable_assets_still_records_paid_exception(): void
    {
        $f = PaymentFixtures::started($this->gateway, true, true);
        $this->travelTo($f['order']->attempt()->sole()->expires_at);
        $f = F::confirm($f); $before = PaymentFixtures::unchangedBusinessEvidence();
        Storage::disk($f['media']['master_wav']->disk)->delete($f['media']['master_wav']->storage_path);
        $assets = new class extends FinalizationAssets {
            public int $calls = 0;
            public function inspect(array $original): bool {
                $this->calls++;
                throw new RuntimeException('SYNTHETIC-LATE-ASSET-READ-MUST-NOT-RUN');
            }
        };
        $this->app->instance(FinalizationAssets::class, $assets);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertSame(0, $assets->calls); $this->assertSame('late_confirmation', OrderFinalization::sole()->reason);
        $payload = json_decode(Crypt::decryptString(OrderFinalization::sole()->evidence_ciphertext), true, 128, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('assets_available', $payload['checks']); $this->assertNull($payload['checks']['assets_available']);
        $this->assertExceptionGraph($f, $before);
    }

    public static function missingTerminalChildren(): array
    {
        return [[LicenseGrant::class], [PendingEntitlement::class], [FulfillmentOutbox::class], [ExclusiveSale::class]];
    }

    #[DataProvider('missingTerminalChildren')]
    public function test_missing_terminal_child_blocks_historical_read_and_retry_without_reissuing_rights(string $model): void
    {
        $f = F::confirmed($this->gateway, true, true);
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $before = F::retained(); $calls = $this->gateway->calls; $scopes = $model::getAllGlobalScopes();
        // Simulate unavailable retained rows without weakening their SQL deletion protections.
        $model::addGlobalScope('synthetic-missing-terminal-child', fn ($query) => $query->whereRaw('1 = 0'));
        try {
            $this->assertSame(0, $model::count());
            try { app(ReadOrder::class)->verify($f['order']->fresh()); $this->fail('Incomplete finalization graph was accepted.'); }
            catch (QuoteException $error) { $this->assertSame('ORDER_CHANGED', $error->errorCode); }
            $this->assertSame('changed', app(FinalizeTestPayment::class)->handle($f['payment']->id));
            $this->assertSame($before, F::retained()); $this->assertSame($calls, $this->gateway->calls);
        } finally { $model::setAllGlobalScopes($scopes); }
        $this->assertSame($f['original'], app(ReadOrder::class)->verify($f['order']->fresh()));
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $this->assertSame($before, F::retained());
    }
}
