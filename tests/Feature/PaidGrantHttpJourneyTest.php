<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Grants\Paid\PaidGrantPolicy;
use App\Domain\Grants\Paid\PaidGrantRecords;
use App\Http\Middleware\PaidGrantPrivacy;
use App\Providers\ProductionCheckoutServiceProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/** Actual SMTP-enrolled buyer + frozen synthetic paid source. No live payment/legal facts are certified. */
final class PaidGrantHttpJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    private const DEPENDENCY = '/workspace/.va-studio-dependencies/paid/90d09a5-e6c02b9';

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        if (DB::getDriverName() === 'mysql') {
            $this->assertSame(getenv('DB_DATABASE'), DB::getDatabaseName(), 'The externally selected disposable testing schema is required.');
        }
        $this->assertFileExists(self::DEPENDENCY.'/source-map.json', 'Exact provisional producer/identity fixture snapshot is required.');
        app('migrator')->path(self::DEPENDENCY.'/database/migrations');
        app()->register(ProductionCheckoutServiceProvider::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->app->make(Kernel::class)->prependMiddleware(PaidGrantPrivacy::class);
        $this->app->make(ExceptionHandler::class)->respondUsing(static function ($response, $error, $request) {
            return PaidGrantPrivacy::matches($request) ? PaidGrantPrivacy::error($response->getStatusCode()) : $response;
        });
        Route::middleware('web')->group(base_path('routes/paid-grants.php'));
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.committed_read_receipts_enabled' => true,
            'production_checkout.committed_read_receipt_version' => 'production-checkout-committed-read-v1',
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => 'identity-historical-committed-receipt-v1',
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'paid-grants.rehearsal_enabled' => true,
            'paid-grants.delivery_policy' => ['schema_version' => 1, 'version' => 'explicit-synthetic-delivery-v1', 'purpose' => 'paid-original-delivery',
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
        Queue::fake();
    }

    protected function smtp(string $mode, int $noticeId): array
    {
        $capture = tempnam(sys_get_temp_dir(), 'va-paid-synthetic-smtp-');
        $process = new Process(['python3', self::DEPENDENCY.'/tests/Support/production_identity_smtp_sink.py', $mode, $capture], timeout: 20);
        $process->start();
        try {
            $process->waitUntil(fn (): bool => preg_match('/\A[0-9]+\n/', $process->getOutput()) === 1);
            app()->instance(IdentityNoticeTransport::class, new LoopbackSmtp((int) trim($process->getOutput())));
            (new WorkIdentityNotice)->process($noticeId);
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            return filesize($capture) > 0 ? json_decode(file_get_contents($capture), true, 8, JSON_THROW_ON_ERROR) : [];
        } finally {
            $process->stop(0);
            unlink($capture);
        }
    }

    private function login(array $buyer): void
    {
        $this->actingAs($buyer['user'], 'customer')->withSession(['_token' => str_repeat('c', 40),
            '_production_customer_identity' => ['binding_digest' => $buyer['principal']->sessionBindingDigest()]]);
    }

    private function assertPrivate($response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
    }

    public function test_actual_typed_buyer_http_finalization_paid_pdf_and_native_file_delivery_preserve_originals(): void
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $this->login($f['buyer']);
        $page = $this->get('/paid-grants')->assertOk()->assertSee('PaidGrants')->assertDontSee('Declared synthetic buyer', false);
        $this->assertPrivate($page);
        $origin = $this->call('POST', '/paid-grants/orders/'.$f['order']['orderId'].'/finalize', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk()->json('origin');
        $this->assertFalse($origin['fulfilled']);
        $complete = $this->call('POST', '/paid-grants/origins/'.$origin['id'].'/document', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk()->json('origin');
        $this->assertTrue($complete['fulfilled']);
        $original = (array) DB::table('paid_originals')->sole();
        $firstBatch = (array) DB::table('paid_order_origins')->sole();
        foreach (['master_wav', 'contract'] as $kind) {
            $auth = $this->postJson('/paid-grants/origins/'.$origin['id'].'/lines/'.$origin['lines'][0]['id'].'/authorize',
                ['requestKey' => (string) Str::uuid(), 'originHash' => $origin['lines'][0]['originHash'], 'kind' => $kind, 'nonce' => bin2hex(random_bytes(32))])->assertOk()->json('authorization');
            $path = $kind === 'master_wav' ? $f['catalog']['media']['master_wav']->storage_path : PaidGrantRecords::decode($original)['artifact']['storage_path'];
            $bytes = file_get_contents(Storage::disk('local')->path($path));
            $raw = http_build_query(['token' => $auth['token'], '_token' => str_repeat('c', 40)], '', '&', PHP_QUERY_RFC3986);
            parse_str($raw, $form);
            $response = $this->call('POST', '/paid-grants/authorizations/'.$auth['id'].'/redeem', $form, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $raw)->assertOk();
            $this->assertPrivate($response);
            $response->assertHeader('Content-Type', $auth['mimeType'])->assertHeader('Content-Length', (string) strlen($bytes))->assertHeader('X-Paid-Grant-Sha256', hash('sha256', $bytes));
            $this->assertSame($bytes, $response->streamedContent());
            $this->postJson('/paid-grants/authorizations/'.$auth['id'].'/redeem', ['token' => $auth['token']])->assertStatus(409);
        }
        $status = $this->get('/paid-grants/origins/'.$origin['id'].'/downloads')->assertOk()->assertJsonPath('status.lines.0.attemptCount', 2)
            ->assertDontSee($auth['token'], false)->assertDontSee($path, false);
        $this->assertPrivate($status);
        $this->get('/paid-grants/index')->assertOk()->assertJsonPath('origins.0.id', $origin['id']);
        $this->assertSame($original, (array) DB::table('paid_originals')->sole());
        $this->assertSame($firstBatch, (array) DB::table('paid_order_origins')->sole());
        $this->assertDatabaseCount('paid_redemptions', 2);
        $this->assertDatabaseCount('license_grants', 0);
        $other = $this->enrollThroughLocalSmtp('different-paid-http@example.test');
        $this->login($other);
        $denial = $this->get('/paid-grants/origins/'.$origin['id'])->assertNotFound()->assertDontSee('Declared synthetic buyer', false);
        $this->assertPrivate($denial);
    }

    public function test_unknown_controller_failure_reaches_fixed_sanitized_reporter_without_request_or_exception_text(): void
    {
        $buyer = $this->enrollThroughLocalSmtp();
        $this->login($buyer);
        app()->afterResolving(PaidGrantPolicy::class, static function (): void {
            throw new \RuntimeException('PRIVATE-EXCEPTION-SENTINEL');
        });
        Log::shouldReceive('error')->once()->with('Paid grant request failed.', ['exception_class' => \RuntimeException::class]);
        $response = $this->postJson('/paid-grants/orders/'.Str::uuid().'/finalize', ['private' => 'PRIVATE-BODY-SENTINEL'])->assertStatus(503)
            ->assertDontSee('PRIVATE-BODY-SENTINEL', false)->assertDontSee('PRIVATE-EXCEPTION-SENTINEL', false);
        $this->assertPrivate($response);
    }

    public static function lateBodyWithdrawals(): array
    {
        return [['paid-policy', 403], ['buyer-credential', 403], ['producer-receipt-policy', 503]];
    }

    #[DataProvider('lateBodyWithdrawals')]
    public function test_actual_private_json_body_seal_closes_withdrawal_after_response_and_before_first_original_byte(string $kind, int $status): void
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $this->login($f['buyer']);
        $response = $this->call('POST', '/paid-grants/orders/'.$f['order']['orderId'].'/finalize', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk();
        $this->assertPrivate($response);
        $this->assertDatabaseCount('paid_order_origins', 1);
        if ($kind === 'paid-policy') {
            config(['paid-grants.rehearsal_enabled' => false]);
        } elseif ($kind === 'buyer-credential') {
            DB::table('users')->where('id', $f['buyer']['user']->id)->update(['password' => Hash::make('WITHDRAWN AFTER RESPONSE')]);
        } else {
            config(['production_checkout.committed_read_receipts_enabled' => false]);
            Log::shouldReceive('error')->once()->with('Paid grant request failed.', ['exception_class' => CheckoutException::class]);
        }
        $body = $response->streamedContent();
        $this->assertSame(['error' => 'Paid grant request unavailable.', 'status' => $status], json_decode($body, true, 4, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Declared synthetic buyer', $body);
        $this->assertStringNotContainsString('termsText', $body);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        $this->assertDatabaseCount('paid_fulfillments', 0);
    }

    public static function refusals(): array
    {
        return [['duplicate'], ['escaped-duplicate'], ['nested-duplicate'], ['oversized'], ['query'], ['get-body'], ['range'], ['origin'], ['csrf'], ['production'], ['credential'], ['native-duplicate'], ['old-session']];
    }

    #[DataProvider('refusals')]
    public function test_private_intake_and_original_typed_session_refuse_before_paid_origin_or_private_projection(string $case): void
    {
        $buyer = $this->enrollThroughLocalSmtp();
        $this->login($buyer);
        $id = (string) Str::uuid();
        $url = '/paid-grants/orders/'.$id.'/finalize';
        $raw = fn (string $body) => $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        if ($case === 'csrf') {
            $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });
        }
        if ($case === 'production') {
            $this->app->detectEnvironment(fn () => 'production');
        }
        if ($case === 'credential') {
            DB::table('users')->where('id', $buyer['user']->id)->update(['password' => Hash::make('PRIVATE WITHDRAWN CREDENTIAL')]);
        }
        if ($case === 'old-session') {
            $this->withSession(['_production_customer_identity' => null, '_customer_access' => ['account_id' => $buyer['principal']->accountId]]);
        }
        $response = match ($case) {
            'duplicate' => $raw('{"private":"PRIVATE-SENTINEL","private":"other"}'),
            'escaped-duplicate' => $raw('{"private":"PRIVATE-SENTINEL","\\u0070rivate":"other"}'),
            'nested-duplicate' => $raw('{"private":{"owner":1,"owner":2}}'),
            'oversized' => $raw(str_repeat('x', 65537)),
            'query' => $this->get('/paid-grants/index?owner=PRIVATE-SENTINEL'),
            'get-body' => $this->call('GET', '/paid-grants/unknown', [], [], [], [], str_repeat('x', 100000)),
            'range' => $this->get('/paid-grants/index', ['Range' => 'bytes=0-1']),
            'origin' => $this->postJson($url, [], ['Origin' => 'https://foreign.invalid']),
            'native-duplicate' => $this->call('POST', '/paid-grants/authorizations/'.$id.'/redeem', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'token='.str_repeat('a', 43).'&%74oken='.str_repeat('b', 43).'&_token='.str_repeat('c', 40)),
            default => $this->postJson($url, [], $case === 'production' ? ['X-CSRF-TOKEN' => str_repeat('c', 40)] : []),
        };
        $expected = match ($case) {
            'oversized' => 413, 'origin', 'credential', 'old-session' => 403, 'production' => 404, 'csrf' => 419, default => 422
        };
        $response->assertStatus($expected)->assertJsonPath('code', 'PAID_GRANT_UNAVAILABLE')->assertDontSee('PRIVATE-SENTINEL', false)->assertDontSee($buyer['user']->email, false);
        $this->assertPrivate($response);
        $this->assertDatabaseCount('paid_order_origins', 0);
        $this->app->detectEnvironment(fn () => 'testing');
    }
}
