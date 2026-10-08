<?php

namespace Tests\Feature;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Contracts\RenderTestContract;
use App\Domain\Contracts\RequestTestContract;
use App\Domain\Delivery\ActivateTestFulfillment;
use App\Domain\Delivery\DeliveryAssets;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use App\Domain\Delivery\ReadTestFulfillmentActivation;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\VerifiedMedia;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ActivationFixtures as F;
use Tests\Support\CheckoutFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class TestFulfillmentActivationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;
    private ContractRenderer $renderer;
    private DeliveryAssets $assets;

    protected function setUp(): void
    {
        parent::setUp(); $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        $this->gateway = PaymentFixtures::gateway(); $this->renderer = ContractFixtures::renderer(); $this->assets = F::observingAssets();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway); $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(ContractRenderer::class, $this->renderer); $this->app->instance(DeliveryAssets::class, $this->assets);
    }

    public static function cartShapes(): array { return [[false], [true]]; }

    #[DataProvider('cartShapes')]
    public function test_complete_order_records_one_immutable_proof_without_mutating_pending_rights(bool $mixed): void
    {
        $f = F::issued($this->gateway, $mixed); $before = ContractFixtures::retained(); $calls = $this->gateway->calls;
        $this->assertSame('activated', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $proof = TestFulfillmentActivation::sole(); $this->assertSame($f['order']->id, $proof->order_id);
        $this->assertSame($f['finalization']->id, $proof->order_finalization_id); $this->assertTrue(Str::isUuid($proof->public_id));
        $this->assertSame('test-fulfillment-activation-v1', $proof->policy_version); $this->assertSame(CanonicalJson::VERSION, $proof->canonicalization_version);
        $this->assertSame(hash('sha256', $proof->evidence_ciphertext), $proof->evidence_hash);
        $canonical = Crypt::decryptString($proof->evidence_ciphertext);
        $payload = json_decode($canonical, true, 128, JSON_THROW_ON_ERROR);
        $this->assertSame($canonical, CanonicalJson::encode($payload));
        $this->assertSame($f['grants']->pluck('public_id')->all(), array_column($payload['snapshot']['members'], 'grant_public_id'));
        $entitlementIds = collect($payload['snapshot']['members'])->flatMap(fn ($member) => array_column($member['entitlements'], 'id'))->sort()->values()->all();
        $this->assertSame(PendingEntitlement::orderBy('id')->pluck('id')->all(), $entitlementIds);
        $this->assertTrue($proof->verified_from->lessThanOrEqualTo($proof->verified_through));
        $this->assertTrue($proof->verified_through->lessThanOrEqualTo($proof->activated_at));
        $this->assertTrue($proof->activated_at->lessThanOrEqualTo($proof->verified_from->addSeconds(300)));
        $this->assertSame($proof->id, app(ReadTestFulfillmentActivation::class)->forOrder($f['order']->fresh())->id);
        $this->assertSame([0], $this->assets->transactionLevels); $this->assertSame($before, ContractFixtures::retained());
        $this->assertSame(['pending'], PendingEntitlement::pluck('state')->unique()->values()->all());
        $this->assertSame($calls, $this->gateway->calls); $original = $proof->getAttributes(); $this->travelTo(now()->addDay());
        $this->assertSame('activated', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->assertSame($original, TestFulfillmentActivation::sole()->getAttributes()); $this->assertSame([0], $this->assets->transactionLevels);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.fulfillment.test_activated')->count());
        $this->assertCount($mixed ? 2 : 1, $this->renderer->calls);
    }

    public static function incompleteContracts(): array { return [['not_requested'], ['quarantined']]; }

    #[DataProvider('incompleteContracts')]
    public function test_one_unissued_mixed_cart_contract_prevents_any_activation_without_rendering(string $state): void
    {
        $f = ContractFixtures::paid($this->gateway, true);
        $first = app(RequestTestContract::class)->handle($f['grants'][0]->id);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($first->id));
        if ($state === 'quarantined') {
            $request = app(RequestTestContract::class)->handle($f['grants'][1]->id);
            $this->renderer->onRender = fn () => throw new ContractIssuanceException('unsupported_input');
            $this->assertSame('quarantined', app(RenderTestContract::class)->handle($request->id));
        }
        $before = ContractFixtures::retained(); $renders = count($this->renderer->calls);
        $this->assertSame('pending_contracts', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->assertDatabaseCount('test_fulfillment_activations', 0); $this->assertSame([], $this->assets->transactionLevels);
        $this->assertSame($before, ContractFixtures::retained()); $this->assertCount($renders, $this->renderer->calls);
    }

    public static function unavailableFiles(): array { return [['original', false], ['original', true], ['asset', false], ['asset', true]]; }

    #[DataProvider('unavailableFiles')]
    public function test_missing_or_corrupt_originals_and_assets_require_exact_restore_before_activation(string $kind, bool $corrupt): void
    {
        $f = F::issued($this->gateway, true); $before = ContractFixtures::retained();
        $record = $kind === 'original' ? GrantContract::orderBy('id')->firstOrFail() : MediaAsset::findOrFail(PendingEntitlement::orderBy('id')->firstOrFail()->media_asset_id);
        $path = Storage::disk($record->disk)->path($record->storage_path); $bytes = file_get_contents($path); $mode = fileperms($path) & 0777;
        if ($corrupt) {
            $changed = $bytes; $changed[12] = $changed[12] === 'X' ? 'Y' : 'X';
            $this->assertTrue(chmod($path, 0600)); $this->assertSame(strlen($changed), file_put_contents($path, $changed)); $this->assertTrue(chmod($path, $mode));
        } else { $this->assertTrue(unlink($path)); }
        $this->assertSame($kind === 'original' ? 'original_unavailable' : 'asset_unavailable', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->assertDatabaseCount('test_fulfillment_activations', 0); $this->assertSame($before, ContractFixtures::retained());
        if ($corrupt) { $this->assertTrue(chmod($path, 0600)); }
        $this->assertSame(strlen($bytes), file_put_contents($path, $bytes)); $this->assertTrue(chmod($path, $mode));
        $this->assertSame('activated', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->assertDatabaseCount('test_fulfillment_activations', 1); $this->assertSame($before, ContractFixtures::retained()); $this->assertCount(2, $this->renderer->calls);
    }

    public function test_cached_media_success_cannot_hide_same_size_corruption_from_activation(): void
    {
        $f = F::issued($this->gateway); $asset = MediaAsset::findOrFail(PendingEntitlement::sole()->media_asset_id);
        $path = app(VerifiedMedia::class)->path($asset); $this->assertNotNull($path); $before = ContractFixtures::retained();
        $bytes = file_get_contents($path); $changed = $bytes; $changed[12] = $changed[12] === 'X' ? 'Y' : 'X';
        $mode = fileperms($path) & 0777; chmod($path, 0600); $this->assertSame(strlen($bytes), file_put_contents($path, $changed)); chmod($path, $mode);
        // Model a retained positive integrity-cache entry independently of filesystem timestamp granularity.
        Cache::partialMock()->shouldReceive('get')->with(Mockery::on(fn ($key) => is_string($key) && str_starts_with($key, 'media-integrity:')))->andReturn(true);
        $this->assertSame($path, app(VerifiedMedia::class)->path($asset));
        $this->assertSame('asset_unavailable', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->assertDatabaseCount('test_fulfillment_activations', 0); $this->assertSame($before, ContractFixtures::retained());
    }

    public static function invalidWindows(): array { return [[301], [-1]]; }

    #[DataProvider('invalidWindows')]
    public function test_stale_or_backwards_verification_window_requires_a_fresh_attempt(int $seconds): void
    {
        $f = F::issued($this->gateway); $before = ContractFixtures::retained(); $start = now()->toImmutable();
        $this->assets->afterVerify = function () use ($seconds): void { $this->travelTo(now()->addSeconds($seconds)); };
        $this->assertSame('retry', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->assertDatabaseCount('test_fulfillment_activations', 0); $this->assertSame($before, ContractFixtures::retained());
        $this->assets->afterVerify = null; $this->travelTo($start->addSeconds(302));
        $this->assertSame('activated', app(ActivateTestFulfillment::class)->handle($f['order']->id));
    }

    public function test_changed_original_binding_after_physical_checks_prevents_activation(): void
    {
        $f = F::issued($this->gateway); $before = ContractFixtures::retained();
        $this->assets->afterVerify = function (): void {
            Event::listen('eloquent.retrieved: '.GrantContract::class, function ($contract): void { $contract->input_hash = str_repeat('0', 64); });
        };
        $this->assertSame('changed', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->assertDatabaseCount('test_fulfillment_activations', 0); $this->assertSame($before, ContractFixtures::retained());
    }

    public function test_lock_wait_is_included_in_the_maximum_verification_age(): void
    {
        $f = F::issued($this->gateway); $before = ContractFixtures::retained(); $advanced = false;
        Event::listen('eloquent.retrieved: '.Order::class, function ($order) use ($f, &$advanced): void {
            if (! $advanced && $order->id === $f['order']->id && DB::transactionLevel() > 0) {
                $advanced = true; $this->travelTo(now()->addSeconds(301));
            }
        });
        $this->assertSame('retry', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->assertTrue($advanced); $this->assertSame([0], $this->assets->transactionLevels);
        $this->assertDatabaseCount('test_fulfillment_activations', 0); $this->assertSame($before, ContractFixtures::retained());
    }

    public static function changedHistoricalPayloads(): array { return [['omit_member'], ['enable_access']]; }

    #[DataProvider('changedHistoricalPayloads')]
    public function test_reencrypted_changed_activation_payload_cannot_pass_the_historical_reader(string $change): void
    {
        $f = F::issued($this->gateway, true); $this->assertSame('activated', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $proof = TestFulfillmentActivation::sole(); $original = $proof->evidence_ciphertext; $before = ContractFixtures::retained();
        Event::listen('eloquent.retrieved: '.TestFulfillmentActivation::class, function ($activation) use ($change): void {
            $payload = json_decode(Crypt::decryptString($activation->evidence_ciphertext), true, 128, JSON_THROW_ON_ERROR);
            if ($change === 'omit_member') { array_pop($payload['snapshot']['members']); }
            else { $payload['policy']['download_access'] = 'enabled'; }
            $activation->evidence_ciphertext = Crypt::encryptString(CanonicalJson::encode($payload));
            $activation->evidence_hash = hash('sha256', $activation->evidence_ciphertext);
        });
        try { app(ReadTestFulfillmentActivation::class)->forOrder($f['order']->fresh()); $this->fail('Changed historical activation was accepted.'); }
        catch (DeliveryException $error) { $this->assertSame('changed', $error->reason); }
        $this->assertSame('changed', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->assertSame($original, DB::table('test_fulfillment_activations')->where('id', $proof->id)->value('evidence_ciphertext'));
        $this->assertSame($before, ContractFixtures::retained()); $this->assertSame([0], $this->assets->transactionLevels);
    }

    public function test_audit_failure_rolls_back_the_activation_and_retry_records_one_proof(): void
    {
        $f = F::issued($this->gateway); $before = ContractFixtures::retained(); $audits = DB::table('audit_events')->count(); $armed = true;
        Event::listen('eloquent.creating: '.AuditEvent::class, function ($audit) use (&$armed): void {
            if ($armed && $audit->action === 'commerce.fulfillment.test_activated') { $armed = false; throw new RuntimeException('SYNTHETIC-ACTIVATION-AUDIT-FAILURE'); }
        });
        $this->assertSame('retry', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->assertFalse($armed); $this->assertDatabaseCount('test_fulfillment_activations', 0); $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertSame($before, ContractFixtures::retained());
        $this->assertSame('activated', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->assertDatabaseCount('test_fulfillment_activations', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.fulfillment.test_activated')->count());
    }

    public function test_open_secondary_transaction_blocks_activation_before_physical_verification(): void
    {
        $f = F::issued($this->gateway); $before = ContractFixtures::retained();
        config(['database.connections.activation_guard' => config('database.connections.'.config('database.default'))]);
        $connection = DB::connection('activation_guard'); $connection->beginTransaction();
        try { $this->assertSame('unavailable', app(ActivateTestFulfillment::class)->handle($f['order']->id)); }
        finally { $connection->rollBack(); DB::purge('activation_guard'); }
        $this->assertSame([], $this->assets->transactionLevels); $this->assertSame($before, ContractFixtures::retained());
        $this->assertDatabaseCount('test_fulfillment_activations', 0);
    }

    public static function deniedPolicies(): array { return [['disabled'], ['string_enabled'], ['missing_policy'], ['extra_policy'], ['live'], ['wrong_account'], ['production'], ['preview'], ['staging_live']]; }

    #[DataProvider('deniedPolicies')]
    public function test_activation_requires_its_own_strict_policy_and_account(string $scenario): void
    {
        $f = F::issued($this->gateway); $before = ContractFixtures::retained(); $environment = $this->app->environment();
        $policy = F::policy(); $policy['extra'] = true;
        try {
            match ($scenario) {
                'disabled' => config(['delivery.test_activation_enabled' => false]),
                'string_enabled' => config(['delivery.test_activation_enabled' => 'true']),
                'missing_policy' => config(['delivery.test_activation_policy' => null]),
                'extra_policy' => config(['delivery.test_activation_policy' => json_encode($policy, JSON_THROW_ON_ERROR)]),
                'live' => config(['payments.stripe.mode' => 'live']),
                'wrong_account' => config(['payments.stripe.account_id' => 'acct_ANOTHERACCOUNT']),
                'production', 'preview' => $this->app->detectEnvironment(fn () => $scenario),
                'staging_live' => [$this->app->detectEnvironment(fn () => 'staging'), config(['payments.stripe.mode' => 'live'])],
            };
            $this->assertSame('unavailable', app(ActivateTestFulfillment::class)->handle($f['order']->id));
            $this->assertDatabaseCount('test_fulfillment_activations', 0); $this->assertSame([], $this->assets->transactionLevels);
            $this->assertSame($before, ContractFixtures::retained());
        } finally { $this->app->detectEnvironment(fn () => $environment); }
    }

    public function test_older_enablement_flags_are_not_required_for_activation_of_retained_evidence(): void
    {
        $f = F::issued($this->gateway);
        config(['payments.stripe.checkout_enabled' => false, 'payments.stripe.processing_enabled' => false, 'payments.stripe.finalization_enabled' => false,
            'contracts.test_issuance_enabled' => false, 'contracts.test_issuance_policy' => null,
            'payments.stripe.finalization_policy' => null, 'commerce.test_checkout_policy' => null, 'commerce.test_order_policy' => null]);
        $this->assertSame('activated', app(ActivateTestFulfillment::class)->handle($f['order']->id));
    }

    public function test_historical_activation_survives_flag_withdrawal_and_file_loss_without_claiming_current_health(): void
    {
        $f = F::issued($this->gateway); $this->assertSame('activated', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $before = TestFulfillmentActivation::sole()->getAttributes(); $contract = GrantContract::sole();
        $this->assertTrue(unlink(Storage::disk('local')->path($contract->storage_path)));
        config(['delivery.test_activation_enabled' => false, 'delivery.test_activation_policy' => null]);
        $proof = app(ReadTestFulfillmentActivation::class)->forOrder($f['order']->fresh());
        $this->assertSame($before, $proof->getAttributes());
        $this->assertSame('unavailable', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        config(['delivery.test_activation_enabled' => true, 'delivery.test_activation_policy' => json_encode(F::policy(), JSON_THROW_ON_ERROR)]);
        $this->assertSame('activated', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->assertSame($before, TestFulfillmentActivation::sole()->getAttributes()); $this->assertSame([0], $this->assets->transactionLevels);
    }

    public static function missingHistoricalParents(): array { return [[ContractRenderRequest::class], [GrantContract::class]]; }

    #[DataProvider('missingHistoricalParents')]
    public function test_recorded_activation_with_missing_parent_metadata_is_changed_and_never_repaired(string $model): void
    {
        $f = F::issued($this->gateway); $this->assertSame('activated', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $proof = TestFulfillmentActivation::sole()->getAttributes(); $before = ContractFixtures::retained(); $scopes = $model::getAllGlobalScopes();
        $model::addGlobalScope('synthetic-missing-activation-parent', fn ($query) => $query->whereRaw('1 = 0'));
        try {
            $this->assertSame('changed', app(ActivateTestFulfillment::class)->handle($f['order']->id));
            try { app(ReadTestFulfillmentActivation::class)->forOrder($f['order']->fresh()); $this->fail('Incomplete retained activation was accepted.'); }
            catch (DeliveryException $error) { $this->assertSame('changed', $error->reason); }
        } finally { $model::setAllGlobalScopes($scopes); }
        $this->assertSame($proof, TestFulfillmentActivation::sole()->getAttributes()); $this->assertSame($before, ContractFixtures::retained());
        $this->assertSame([0], $this->assets->transactionLevels); $this->assertCount(1, $this->renderer->calls);
    }

    public function test_unpaid_or_late_paid_exception_order_cannot_activate(): void
    {
        $f = PaymentFixtures::started($this->gateway);
        $this->assertSame('ineligible', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->travelTo($f['order']->attempt()->sole()->expires_at); $f = FinalizationFixtures::confirm($f);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $before = ContractFixtures::retained();
        $this->assertSame('ineligible', app(ActivateTestFulfillment::class)->handle($f['order']->id));
        $this->assertDatabaseCount('test_fulfillment_activations', 0); $this->assertSame($before, ContractFixtures::retained());
    }

    public function test_scanner_continues_after_failed_order_and_only_prints_opaque_bounded_results(): void
    {
        $first = F::issued($this->gateway);
        $this->gateway->onCreate = fn (array $params) => CheckoutFixtures::session($params, 'cs_test_ACTIVATIONSECOND');
        $second = PaymentFixtures::started($this->gateway);
        $this->gateway->session['payment_intent'] = 'pi_ACTIVATIONSECOND'; $this->gateway->payment['id'] = 'pi_ACTIVATIONSECOND';
        $second = F::issue(ContractFixtures::finalize(FinalizationFixtures::confirm($second)));
        $original = GrantContract::where('license_grant_id', $first['grant']->id)->sole();
        $path = Storage::disk('local')->path($original->storage_path); $bytes = file_get_contents($path); $this->assertTrue(unlink($path));
        $this->assertSame(0, Artisan::call('vasey:activate-test-fulfillment', ['--limit' => '1'])); $output = Artisan::output();
        $this->assertStringContainsString($first['order']->public_id.' original_unavailable', $output);
        $this->assertStringContainsString('NEXT_AFTER='.$first['order']->public_id, $output);
        $this->assertSame(0, Artisan::call('vasey:activate-test-fulfillment', ['--limit' => '1', '--after' => $first['order']->public_id]));
        $secondOutput = Artisan::output();
        $this->assertSame($second['order']->id, TestFulfillmentActivation::sole()->order_id);
        foreach ([...array_values(OrderFixtures::buyer()), $original->storage_path, $original->pdf_hash, CheckoutFixtures::ACCOUNT, PaymentFixtures::PAYMENT] as $private) {
            $this->assertStringNotContainsString($private, $output.$secondOutput);
        }
        $this->assertSame(strlen($bytes), file_put_contents($path, $bytes)); $this->assertTrue(chmod($path, 0400));
        $this->assertSame(0, Artisan::call('vasey:activate-test-fulfillment', ['--limit' => '1'])); $this->assertDatabaseCount('test_fulfillment_activations', 2);
        $this->assertSame(0, Artisan::call('vasey:activate-test-fulfillment', ['--limit' => '1'])); $this->assertStringNotContainsString('NEXT_AFTER=', Artisan::output());
        foreach ([['order' => '1'], ['--after' => 'bad'], ['--after' => (string) Str::uuid()], ['--limit' => '0'], ['--limit' => '101'],
            ['order' => $first['order']->public_id, '--after' => $first['order']->public_id]] as $arguments) {
            $this->assertNotSame(0, Artisan::call('vasey:activate-test-fulfillment', $arguments));
        }
    }
}
