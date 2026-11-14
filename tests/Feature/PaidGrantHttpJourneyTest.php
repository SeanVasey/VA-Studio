<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\TaxExemptions;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Grants\Paid\PaidGrantPolicy;
use App\Domain\Grants\Paid\PaidGrantRecords;
use App\Domain\Grants\Paid\PaidGrantRendererProcess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\PaidGrantRootHttpFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Actual SMTP-enrolled buyer + frozen synthetic paid source. No live payment/legal facts are certified. */
final class PaidGrantHttpJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidGrantDependencyFixtures;
    use PaidGrantRootHttpFixture;
    use ProductionCheckoutJourneyFixture;

    protected function beforeRefreshingDatabase(): void
    {
        $this->preparePaidDependencies();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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
        $process = new Process(['python3', $this->paidDependencyPath('tests/Support/production_identity_smtp_sink.py'), $mode, $capture], timeout: 20);
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
        $document = $this->call('POST', '/paid-grants/origins/'.$origin['id'].'/document', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk();
        // Condition C13: the document answer also says whether other work holds this buyer's preparation (token-free boolean).
        $this->assertSame(['origin', 'busy'], array_keys($document->json()));
        $this->assertFalse($document->json('busy'));
        $complete = $document->json('origin');
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

    public function test_two_line_http_order_retains_its_first_original_across_second_line_failure_and_retry(): void
    {
        $f = $this->payable(false);
        $second = QuoteFixtures::selection(6001);
        $items = [...$f['catalog']['items'], ...$second['items']];
        $basis = (new TaxExemptions($f['access']))->qualify($f['buyer']['principal'], $f['buyer']['user'], $f['authority']['public_id'], $items,
            ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:two-line-buyer-bound-qualification',
                'source_sha256' => hash('sha256', 'NONBINDING SYNTHETIC TWO-LINE BUYER EXEMPTION'),
                'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')],
            'synthetic-two-line-qualified-buyer', $f['catalog']['actor']);
        $review = $f['checkout']->review($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id, $items, $basis['public_id'],
            ['legalName' => 'Declared synthetic two-line buyer'], 'synthetic-two-line-review');
        $order = $f['checkout']->accept($f['buyer']['principal'], $f['buyer']['user'], $review['reviewId'], $review['reviewHash'], true, 'synthetic-two-line-accept');
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $order['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $order['orderId'])['paymentStatus']);
        $this->login($f['buyer']);
        $origin = $this->call('POST', '/paid-grants/orders/'.$order['orderId'].'/finalize', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk()->json('origin');
        $this->assertCount(2, $origin['lines']);
        $calls = 0;
        app()->instance(PaidGrantRendererProcess::class, new PaidGrantRendererProcess(static function ($command, $directory, $environment, $input) use (&$calls): Process {
            if (++$calls === 2) {
                throw new ContractIssuanceException('render_failed');
            }

            return new Process($command, $directory, $environment, $input);
        }));

        $failure = $this->call('POST', '/paid-grants/origins/'.$origin['id'].'/document', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(503);

        $this->assertPrivate($failure);
        $this->assertSame(2, $calls, 'Line one renders through the real child before line two fails.');
        $firstOriginal = (array) DB::table('paid_originals')->sole();
        $firstManifest = PaidGrantRecords::decode($firstOriginal);
        $firstPdf = file_get_contents(Storage::disk('local')->path($firstManifest['artifact']['storage_path']));
        $this->assertStringStartsWith('%PDF-', $firstPdf);
        $this->assertSame($firstManifest['artifact']['pdf_hash'], hash('sha256', $firstPdf));
        $this->assertSame(['complete', 'failed'], DB::table('paid_document_work')->orderBy('id')->pluck('state')->all());
        $this->assertDatabaseCount('paid_fulfillments', 0);
        $this->postJson('/paid-grants/origins/'.$origin['id'].'/lines/'.$origin['lines'][0]['id'].'/authorize',
            ['requestKey' => (string) Str::uuid(), 'originHash' => $origin['lines'][0]['originHash'], 'kind' => 'master_wav', 'nonce' => bin2hex(random_bytes(32))])->assertStatus(409);
        $this->assertDatabaseCount('paid_authorizations', 0);
        $this->assertDatabaseCount('paid_redemptions', 0);
        app()->forgetInstance(PaidGrantRendererProcess::class);

        $retry = $this->call('POST', '/paid-grants/origins/'.$origin['id'].'/document', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk();

        $this->assertPrivate($retry);
        $this->assertFalse($retry->json('busy'));
        $this->assertTrue($retry->json('origin.fulfilled'));
        $this->assertSame($firstOriginal, (array) DB::table('paid_originals')->where('id', $firstOriginal['id'])->sole());
        $this->assertSame($firstPdf, file_get_contents(Storage::disk('local')->path($firstManifest['artifact']['storage_path'])));
        $this->assertSame([1, 2], array_map('intval', DB::table('paid_document_work')->orderBy('id')->pluck('attempts')->all()));
        $this->assertDatabaseCount('paid_originals', 2);
        $this->assertDatabaseCount('paid_fulfillments', 1);
        foreach ($origin['lines'] as $position => $line) {
            $row = DB::table('paid_grant_origins')->where('public_id', $line['id'])->sole();
            $original = (array) DB::table('paid_originals')->where('origin_id', $row->id)->sole();
            foreach (['master_wav', 'contract'] as $kind) {
                $authorization = $this->postJson('/paid-grants/origins/'.$origin['id'].'/lines/'.$line['id'].'/authorize',
                    ['requestKey' => (string) Str::uuid(), 'originHash' => $line['originHash'], 'kind' => $kind, 'nonce' => bin2hex(random_bytes(32))])->assertOk()->json('authorization');
                $path = $kind === 'contract' ? PaidGrantRecords::decode($original)['artifact']['storage_path']
                    : ($position === 0 ? $f['catalog'] : $second)['media']['master_wav']->storage_path;
                $bytes = file_get_contents(Storage::disk('local')->path($path));
                $raw = http_build_query(['token' => $authorization['token'], '_token' => str_repeat('c', 40)], '', '&', PHP_QUERY_RFC3986);
                parse_str($raw, $form);
                $response = $this->call('POST', '/paid-grants/authorizations/'.$authorization['id'].'/redeem', $form, [], [],
                    ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $raw)->assertOk();
                $this->assertPrivate($response);
                $response->assertHeader('X-Paid-Grant-Sha256', hash('sha256', $bytes));
                $this->assertSame($bytes, $response->streamedContent());
            }
        }
        $status = $this->get('/paid-grants/origins/'.$origin['id'].'/downloads')->assertOk();
        $this->assertSame([2, 2], array_column($status->json('status.lines'), 'attemptCount'));
        $this->assertSame($firstOriginal, (array) DB::table('paid_originals')->where('id', $firstOriginal['id'])->sole());
        $this->assertDatabaseCount('paid_redemptions', 4);
        $this->assertDatabaseCount('paid_order_origins', 1);
        $this->assertDatabaseCount('paid_grant_origins', 2);
        $this->assertDatabaseCount('license_grants', 0);
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
