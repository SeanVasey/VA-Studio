<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Delivery\ManageTestDeliveryControl;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Models\User;
use App\Http\Middleware\TestDeliveryPrivacy;
use Illuminate\Http\Request;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\ActivationFixtures;
use Tests\Support\CheckoutFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** Real synthetic paid/activated orders; only external provider/rendering transports are fixtures. */
class TestOwnerDeliveryHttpTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;
    private PrepareTestDeliveryStream $streams;
    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite(); $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        $this->csrf = Str::random(40); $this->withSession(['_token' => $this->csrf]);
        $this->gateway = PaymentFixtures::gateway(); $this->streams = F::observingStreams();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $this->app->instance(PrepareTestDeliveryStream::class, $this->streams);
    }

    private function ready(bool $enabled = true): array
    {
        $fixture = F::activate(ActivationFixtures::issue(ContractFixtures::finalize(
            FinalizationFixtures::ownedHttp($this, $this->gateway),
        )), $enabled);
        Queue::fake();
        return $fixture;
    }

    private function url(array $fixture): string { return '/orders/'.$fixture['order']->public_id.'/delivery'; }

    private function issue(array $fixture, ?string $key = null, string $kind = 'contract'): TestResponse
    {
        return $this->issueRaw($this->url($fixture).'/authorizations', json_encode([
            'grantId' => $fixture['grant']->public_id, 'kind' => $kind,
        ], JSON_THROW_ON_ERROR), $key ?? (string) Str::uuid());
    }

    private function issueRaw(string $url, string $body, ?string $key, array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf];
        if ($key !== null) { $server['HTTP_IDEMPOTENCY_KEY'] = $key; }
        return $this->call('POST', $url, [], [], [], array_replace($server, $headers), $body);
    }

    private function download(array $fixture, array $authorization, array $headers = []): TestResponse
    {
        return $this->downloadRaw($this->url($fixture).'/download', http_build_query([
            'authorizationId' => $authorization['authorizationId'], 'token' => $authorization['token'], '_token' => $this->csrf,
        ], '', '&', PHP_QUERY_RFC3986), $headers);
    }

    private function downloadRaw(string $url, string $body, array $headers = []): TestResponse
    {
        // Browser-native form fields accompany the original bytes, so real CSRF sees the same request as PHP-FPM.
        parse_str($body, $fields);
        return $this->call('POST', $url, $fields, [], [], array_replace([
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'text/html',
        ], $headers), $body);
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
        $response->assertHeaderMissing('ETag')->assertHeaderMissing('Last-Modified');
        $this->assertNotSame(304, $response->getStatusCode());
    }

    private function assertError(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code); $this->assertPrivate($response);
        foreach (['exception', 'trace', 'file', 'token', 'evidence', 'storage_path'] as $private) {
            $this->assertArrayNotHasKey($private, $response->json());
        }
    }

    private function realCsrf(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool { return false; }
        });
    }

    public function test_owner_projection_is_bounded_private_database_only_and_cannot_confirm_receipt(): void
    {
        $f = $this->ready(); $before = F::retained(); $calls = $this->gateway->calls; $audits = DB::table('audit_events')->count();
        $contract = GrantContract::sole(); $path = Storage::disk('local')->path($contract->storage_path);
        // A listing does not touch private bytes; missing bytes are a later fresh-authorization failure.
        unlink($path);
        $response = $this->get($this->url($f))->assertOk(); $this->assertPrivate($response);
        $delivery = $response->json('delivery');
        $this->assertSame(['deliverySchema', 'orderId', 'testOnly', 'status', 'items', 'history', 'historyLimit', 'historyHasMore'], array_keys($delivery));
        $this->assertSame(1, $delivery['deliverySchema']); $this->assertSame($f['order']->public_id, $delivery['orderId']);
        $this->assertTrue($delivery['testOnly']); $this->assertSame('available', $delivery['status']);
        $this->assertSame([], $delivery['history']); $this->assertSame(20, $delivery['historyLimit']); $this->assertFalse($delivery['historyHasMore']);
        $this->assertNotEmpty($delivery['items']);
        foreach ($delivery['items'] as $item) {
            $this->assertSame(['grantId', 'kind', 'filename', 'mimeType', 'sizeBytes'], array_keys($item));
            $this->assertSame($f['grant']->public_id, $item['grantId']); $this->assertIsInt($item['sizeBytes']);
            $this->assertGreaterThan(0, $item['sizeBytes']);
        }
        foreach ([$f['order']->owner_key, $contract->storage_path, $contract->content_hash, ...array_values(OrderFixtures::buyer())] as $private) {
            if (is_string($private) && $private !== '') { $response->assertDontSee($private, false); }
        }
        $this->get($this->url($f), ['If-None-Match' => '*', 'If-Modified-Since' => now()->toRfc7231String()])->assertOk();
        $this->assertSame([], $this->streams->transactionLevels); $this->assertSame($before, F::retained());
        $this->assertSame($calls, $this->gateway->calls); $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertDatabaseCount('test_delivery_authorizations', 0); $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_native_attachment_uses_frozen_filename_mime_exact_bytes_and_one_consumed_attempt(): void
    {
        $f = $this->ready(); $contract = GrantContract::sole(); $bytes = Storage::disk('local')->get($contract->storage_path);
        $before = F::retained(); $calls = $this->gateway->calls;
        $issued = $this->issue($f)->assertCreated(); $this->assertPrivate($issued); $authorization = $issued->json('authorization');
        $this->assertSame(['authorizationId', 'token', 'expiresAt', 'filename', 'mimeType'], array_keys($authorization));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $authorization['token']);
        $this->assertSame($f['grant']->public_id.'-contract.pdf', $authorization['filename']);
        $this->assertSame('application/pdf', $authorization['mimeType']);
        $response = $this->download($f, $authorization)->assertOk(); $this->assertPrivate($response);
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        $response->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Length', (string) strlen($bytes));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString($authorization['filename'], $response->headers->get('Content-Disposition'));
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $response->streamedContent()));
        $this->assertFalse(is_resource($this->streams->resources[array_key_last($this->streams->resources)]));
        $this->assertError($this->download($f, $authorization), 409, 'DELIVERY_ATTEMPTED');
        $history = $this->get($this->url($f))->assertOk()->json('delivery.history');
        $this->assertCount(1, $history);
        $this->assertSame(['authorizationId', 'grantId', 'kind', 'issuedAt', 'expiresAt', 'status', 'attemptedAt'], array_keys($history[0]));
        $this->assertSame('attempted', $history[0]['status']); $this->assertNotNull($history[0]['attemptedAt']);
        $this->assertStringNotContainsString($authorization['token'], json_encode($history, JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('test_delivery_authorizations', 1); $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertSame($before, F::retained()); $this->assertSame($calls, $this->gateway->calls); Queue::assertNothingPushed();
    }

    public function test_foreign_and_lost_session_cannot_list_issue_or_redeem_even_with_the_real_token(): void
    {
        $f = $this->ready(); $authorization = $this->issue($f)->assertCreated()->json('authorization'); $prepares = $this->streams->transactionLevels;
        $this->flushSession(); $this->withSession(['_token' => $this->csrf]);
        $foreign = $this->get($this->url($f)); $unknown = $this->get('/orders/'.Str::uuid().'/delivery');
        $this->assertError($foreign, 404, 'DELIVERY_NOT_FOUND'); $this->assertError($unknown, 404, 'DELIVERY_NOT_FOUND');
        $this->assertSame($foreign->json(), $unknown->json());
        $this->assertError($this->issue($f), 404, 'DELIVERY_NOT_FOUND');
        $this->assertError($this->download($f, $authorization), 404, 'DELIVERY_NOT_FOUND');
        $this->assertSame($prepares, $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_matching_email_and_authentication_rotation_never_claim_the_guest_purchase(): void
    {
        $f = $this->ready(); $authorization = $this->issue($f)->assertCreated()->json('authorization'); $prepares = $this->streams->transactionLevels;
        $user = User::factory()->create(['email' => OrderFixtures::buyer()['email']]); $this->actingAs($user);
        $this->assertError($this->get($this->url($f)), 404, 'DELIVERY_NOT_FOUND');
        $this->assertError($this->issue($f), 404, 'DELIVERY_NOT_FOUND');
        $this->assertError($this->download($f, $authorization), 404, 'DELIVERY_NOT_FOUND');
        auth()->logout();
        $this->assertError($this->get($this->url($f)), 404, 'DELIVERY_NOT_FOUND');
        $this->assertSame($prepares, $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_existing_foreign_grant_and_authorization_cannot_be_mixed_with_an_owned_order(): void
    {
        $first = $this->ready(); $firstAuthorization = $this->issue($first)->assertCreated()->json('authorization');
        $this->gateway->onCreate = fn ($params) => CheckoutFixtures::session($params, 'cs_test_SECONDOWNERHTTP');
        // FinalizationFixtures owns the provider payment ID; distinct synthetic provider evidence is returned before verification.
        $this->gateway->onPayment = function (): array { $payment = $this->gateway->payment; $payment['id'] = 'pi_SECONDOWNERHTTP'; return $payment; };
        $this->gateway->onRetrieve = function (): array { $session = $this->gateway->session; $session['payment_intent'] = 'pi_SECONDOWNERHTTP'; return $session; };
        $second = $this->ready(); $prepares = $this->streams->transactionLevels;
        $wrong = $second; $wrong['grant'] = $first['grant'];
        $this->assertError($this->issue($wrong), 404, 'DELIVERY_NOT_FOUND');
        $this->assertError($this->download($second, $firstAuthorization), 404, 'DELIVERY_NOT_FOUND');
        $wrongToken = $firstAuthorization; $wrongToken['token'] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->assertError($this->download($first, $wrongToken), 404, 'DELIVERY_NOT_FOUND');
        $this->assertSame($prepares, $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_authorizations', 1);
        $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_idempotency_replay_and_shared_rolling_budget_preserve_existing_authorizations(): void
    {
        $f = $this->ready(); $key = (string) Str::uuid(); $first = $this->issue($f, $key)->assertCreated()->json('authorization');
        $this->assertError($this->issue($f, $key), 409, 'DELIVERY_ALREADY_ISSUED');
        $this->assertError($this->issue($f, $key, 'master_wav'), 409, 'DELIVERY_CONFLICT');
        $this->issue($f)->assertCreated(); $this->issue($f)->assertCreated();
        $this->assertError($this->issue($f), 429, 'DELIVERY_RATE_LIMITED');
        $this->assertDatabaseCount('test_delivery_authorizations', 3); $this->assertCount(3, $this->streams->transactionLevels);
        $history = $this->get($this->url($f))->assertOk()->json('delivery.history');
        $this->assertCount(3, $history); $this->assertSame(['unused'], array_values(array_unique(array_column($history, 'status'))));
        $this->assertStringNotContainsString($first['token'], json_encode($history, JSON_THROW_ON_ERROR));
        $this->travelTo(now()->addSeconds(60)); $this->issue($f)->assertCreated();
        $this->assertDatabaseCount('test_delivery_authorizations', 4); $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_exact_expiry_is_gone_without_new_private_io_or_consumption(): void
    {
        $f = $this->ready(); $authorization = $this->issue($f)->assertCreated()->json('authorization');
        $this->travelTo(now()->addSeconds(60));
        $this->assertError($this->download($f, $authorization), 410, 'DELIVERY_EXPIRED');
        $this->get($this->url($f))->assertOk()->assertJsonPath('delivery.history.0.status', 'expired')
            ->assertJsonPath('delivery.history.0.attemptedAt', null);
        $this->assertSame([0], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_current_policy_and_control_withdrawal_deny_new_access_but_keep_private_history(): void
    {
        $f = $this->ready(); $authorization = $this->issue($f)->assertCreated()->json('authorization');
        config(['delivery.test_access_enabled' => false]);
        $this->assertError($this->issue($f), 503, 'DELIVERY_UNAVAILABLE');
        $this->assertError($this->download($f, $authorization), 503, 'DELIVERY_UNAVAILABLE');
        $this->get($this->url($f))->assertOk()->assertJsonPath('delivery.status', 'unavailable')->assertJsonCount(1, 'delivery.history');
        config(['delivery.test_access_enabled' => true]);
        app(ManageTestDeliveryControl::class)->handle($f['order']->public_id, true, 1, 'SYNTHETIC-HTTP-BLOCK');
        $this->assertError($this->issue($f), 503, 'DELIVERY_UNAVAILABLE');
        $this->assertError($this->download($f, $authorization), 503, 'DELIVERY_UNAVAILABLE');
        $this->assertSame([0], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public static function invalidIssuanceBodies(): array
    {
        return [
            'array' => ['[]', 422], 'null' => ['null', 422], 'broken' => ['{', 422], 'empty' => ['{}', 422],
            'extra' => ['{"grantId":"GRANT","kind":"contract","filename":"private.pdf"}', 422],
            'nested' => ['{"grantId":{"id":"GRANT"},"kind":"contract"}', 422],
            'duplicate' => ['{"grantId":"GRANT","kind":"master_wav","kind":"contract"}', 422],
            'escaped duplicate' => ['{"grantId":"GRANT","kind":"master_wav","k\\u0069nd":"contract"}', 422],
            'wrong scalar' => ['{"grantId":"GRANT","kind":true}', 422],
            'over limit' => [str_repeat(' ', 4097).'{}', 413],
        ];
    }

    #[DataProvider('invalidIssuanceBodies')]
    public function test_strict_json_rejects_ambiguous_or_oversized_input_before_private_io(string $body, int $status): void
    {
        $f = $this->ready(); $body = str_replace('GRANT', $f['grant']->public_id, $body);
        $this->assertError($this->issueRaw($this->url($f).'/authorizations', $body, (string) Str::uuid()), $status, 'INVALID_DELIVERY_REQUEST');
        $this->assertSame([], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_authorizations', 0);
    }

    public static function invalidRequestBoundaries(): array
    {
        return [
            'query' => ['?download=1', [], 422], 'range' => ['', ['HTTP_RANGE' => 'bytes=0-9'], 416],
            'if range' => ['', ['HTTP_IF_RANGE' => 'private-etag'], 416],
            'form' => ['', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 415],
            'text' => ['', ['CONTENT_TYPE' => 'text/plain'], 415],
            'missing type' => ['', ['CONTENT_TYPE' => ''], 415],
        ];
    }

    #[DataProvider('invalidRequestBoundaries')]
    public function test_issuance_rejects_queries_ranges_and_wrong_media_types(string $query, array $headers, int $status): void
    {
        $f = $this->ready(); $body = json_encode(['grantId' => $f['grant']->public_id, 'kind' => 'contract'], JSON_THROW_ON_ERROR);
        $this->assertError($this->issueRaw($this->url($f).'/authorizations'.$query, $body, (string) Str::uuid(), $headers), $status, 'INVALID_DELIVERY_REQUEST');
        $this->assertSame([], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_authorizations', 0);
    }

    public static function invalidIdempotencyKeys(): array { return [[null], [''], ['not-a-uuid'], [str_repeat('a', 80)], ['{"id":"uuid"}']]; }

    #[DataProvider('invalidIdempotencyKeys')]
    public function test_authorization_requires_one_canonical_idempotency_uuid(?string $key): void
    {
        $f = $this->ready(); $body = json_encode(['grantId' => $f['grant']->public_id, 'kind' => 'contract'], JSON_THROW_ON_ERROR);
        $this->assertError($this->issueRaw($this->url($f).'/authorizations', $body, $key), 422, 'INVALID_DELIVERY_REQUEST');
        $this->assertSame([], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_authorizations', 0);
    }

    public static function invalidDownloadBodies(): array
    {
        return [
            'extra' => ['&filename=private.pdf', 422], 'duplicate token' => ['&token=TOKEN', 422],
            'encoded duplicate token' => ['&%74oken=TOKEN', 422], 'nested' => ['&token%5Bvalue%5D=TOKEN', 422],
            'extra csrf duplicate' => ['&_token=CSRF', 422], 'over limit' => ['&extra='.str_repeat('a', 4096), 413],
        ];
    }

    #[DataProvider('invalidDownloadBodies')]
    public function test_native_form_rejects_extra_nested_duplicate_and_oversized_fields_without_consumption(string $suffix, int $status): void
    {
        $f = $this->ready(); $authorization = $this->issue($f)->assertCreated()->json('authorization');
        $body = http_build_query(['authorizationId' => $authorization['authorizationId'], 'token' => $authorization['token'], '_token' => $this->csrf], '', '&', PHP_QUERY_RFC3986);
        $body .= str_replace(['TOKEN', 'CSRF'], [$authorization['token'], $this->csrf], $suffix);
        $this->assertError($this->downloadRaw($this->url($f).'/download', $body), $status, 'INVALID_DELIVERY_REQUEST');
        $this->assertSame([0], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_read_methods_and_range_requests_never_issue_or_consume_authorizations(): void
    {
        $f = $this->ready(); $authorization = $this->issue($f)->assertCreated()->json('authorization');
        foreach (['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'] as $method) {
            foreach (['authorizations', 'download'] as $action) {
                $response = $this->call($method, $this->url($f).'/'.$action, [], [], [], ['HTTP_ACCEPT' => 'application/json']);
                $this->assertSame(405, $response->getStatusCode(), $method.' '.$action); $this->assertPrivate($response);
                if ($method !== 'HEAD') { $response->assertJsonPath('code', 'INVALID_DELIVERY_REQUEST'); }
            }
        }
        foreach (['Range' => 'bytes=0-9', 'If-Range' => 'private-etag'] as $header => $value) {
            $this->assertError($this->get($this->url($f), [$header => $value]), 416, 'INVALID_DELIVERY_REQUEST');
            $this->assertError($this->download($f, $authorization, ['HTTP_'.strtoupper(str_replace('-', '_', $header)) => $value]), 416, 'INVALID_DELIVERY_REQUEST');
        }
        $this->assertError($this->get($this->url($f).'?token='.$authorization['token']), 422, 'INVALID_DELIVERY_REQUEST');
        $this->assertSame([0], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_authorizations', 1);
        $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_real_csrf_failures_are_generic_private_and_leave_delivery_unconsumed_under_debug(): void
    {
        $f = $this->ready(); $this->realCsrf(); config(['app.debug' => true]);
        $body = json_encode(['grantId' => $f['grant']->public_id, 'kind' => 'contract'], JSON_THROW_ON_ERROR);
        $this->assertError($this->issueRaw($this->url($f).'/authorizations', $body, (string) Str::uuid(), ['HTTP_X_CSRF_TOKEN' => 'wrong-private-token']), 419, 'SESSION_EXPIRED');
        $authorization = $this->issue($f)->assertCreated()->json('authorization');
        $form = http_build_query(['authorizationId' => $authorization['authorizationId'], 'token' => $authorization['token'], '_token' => 'wrong-private-token'], '', '&', PHP_QUERY_RFC3986);
        $failure = $this->downloadRaw($this->url($f).'/download', $form);
        $this->assertError($failure, 419, 'SESSION_EXPIRED'); $failure->assertDontSee($authorization['token'], false)->assertDontSee('wrong-private-token', false);
        $this->assertSame([0], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_redemptions', 0);
        $this->download($f, $authorization)->assertOk()->streamedContent(); $this->assertDatabaseCount('test_delivery_redemptions', 1);
    }

    public function test_debug_exceptions_cannot_expose_paths_buyer_token_or_trace_in_response_or_logs(): void
    {
        $f = $this->ready(); config(['app.debug' => true]); Log::spy();
        $marker = 'private/path buyer@example.invalid private-token-should-not-leak';
        $this->streams->afterPrepare = fn () => throw new RuntimeException($marker);
        $failure = $this->issue($f); $this->assertError($failure, 503, 'DELIVERY_UNAVAILABLE');
        $failure->assertDontSee($marker, false)->assertDontSee('RuntimeException', false);
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => is_string($message)
            && $context === ['exception_class' => RuntimeException::class])->once();
        $this->assertDatabaseCount('test_delivery_authorizations', 0); $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }


    public static function middlewareLoggerFailures(): array { return [[false], [true]]; }

    #[DataProvider('middlewareLoggerFailures')]
    public function test_real_route_session_lock_timeout_is_private_before_owner_or_delivery_work(bool $brokenLogger): void
    {
        $f = $this->ready(); config(['app.debug' => true]); Log::spy();
        if ($brokenLogger) { Log::shouldReceive('error')->andThrow(new RuntimeException('private logger failure')); }
        $store = new class extends ArrayStore {
            public array $requestedLocks = [];
            public function lock($name, $seconds = 0, $owner = null)
            {
                $this->requestedLocks[] = [$name, $seconds];
                return new class($this, $name, $seconds, $owner) extends ArrayLock {
                    public function block($seconds, $callback = null)
                    {
                        throw new LockTimeoutException('private lock owner/session secret');
                    }
                };
            }
        };
        Cache::extend('delivery-timeout', fn () => new Repository($store));
        config(['cache.stores.delivery-timeout' => ['driver' => 'delivery-timeout'], 'session.block_store' => 'delivery-timeout']);
        $response = $this->get($this->url($f));
        $this->assertError($response, 503, 'DELIVERY_UNAVAILABLE'); $response->assertDontSee('private lock owner/session secret', false)->assertDontSee('private logger failure', false);
        $this->assertCount(1, $store->requestedLocks); $this->assertStringStartsWith('session:', $store->requestedLocks[0][0]);
        $this->assertSame(120, $store->requestedLocks[0][1]);
        $this->assertSame([], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_authorizations', 0);
        $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_real_route_throttle_failure_keeps_privacy_headers_and_does_not_touch_private_bytes(): void
    {
        $f = $this->ready(); config(['app.debug' => true]); $before = F::retained();
        // Unknown opaque IDs keep each allowed request database-only while exercising the real shared read limiter.
        $url = '/orders/'.Str::uuid().'/delivery';
        for ($request = 0; $request < 60; $request++) { $this->get($url)->assertNotFound(); }
        $response = $this->get($this->url($f)); $this->assertError($response, 429, 'DELIVERY_RATE_LIMITED');
        $response->assertHeader('Retry-After');
        $this->assertSame([], $this->streams->transactionLevels); $this->assertSame($before, F::retained());
        $this->assertDatabaseCount('test_delivery_authorizations', 0); $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }


    public static function dishonestContentLengths(): array { return [[null], ['0'], ['1']]; }

    #[DataProvider('dishonestContentLengths')]
    public function test_untrusted_body_stream_is_read_only_to_the_limit_before_session_or_controller_work(?string $length): void
    {
        $input = fopen('php://temp', 'w+b'); fwrite($input, str_repeat('a', 32768)); rewind($input);
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($length !== null) { $headers['CONTENT_LENGTH'] = $length; }
        $request = Request::create('/orders/'.Str::uuid().'/delivery/authorizations', 'POST', [], [], [], $headers, $input);
        try {
            $response = app(TestDeliveryPrivacy::class)->handle($request, fn () => throw new RuntimeException('Controller must not execute.'));
            $this->assertError(TestResponse::fromBaseResponse($response), 413, 'INVALID_DELIVERY_REQUEST');
            $this->assertSame(4097, ftell($input));
            $this->assertFalse($request->attributes->has('_test_delivery_body'));
        } finally { fclose($input); }
        $this->assertDatabaseCount('test_delivery_authorizations', 0); $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_raw_discarded_queries_get_bodies_encoding_and_method_overrides_are_rejected_before_delivery(): void
    {
        $url = '/orders/'.Str::uuid().'/delivery';
        foreach (['?&&', '?[]=ignored'] as $query) {
            $this->assertError($this->get($url.$query), 422, 'INVALID_DELIVERY_REQUEST');
        }
        $this->assertError($this->call('GET', $url, [], [], [], [], '[]'), 422, 'INVALID_DELIVERY_REQUEST');
        $this->assertError($this->call('POST', $url.'/authorizations', [], [], [], ['HTTP_CONTENT_ENCODING' => 'gzip'], '{}'), 415, 'INVALID_DELIVERY_REQUEST');
        $this->assertError($this->call('POST', $url.'/authorizations', [], [], [], ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'PUT'], '{}'), 405, 'INVALID_DELIVERY_REQUEST');
        $this->assertError($this->get($url.'/missing'), 404, 'DELIVERY_NOT_FOUND');
        $this->assertSame([], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_authorizations', 0);
        $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_exact_maximum_body_is_accepted_but_native_form_media_and_malformed_encoding_are_not(): void
    {
        $f = $this->ready(); $body = json_encode(['grantId' => $f['grant']->public_id, 'kind' => 'contract'], JSON_THROW_ON_ERROR);
        $authorization = $this->issueRaw($this->url($f).'/authorizations', str_pad($body, 4096, ' '), (string) Str::uuid())->assertCreated()->json('authorization');
        $form = http_build_query(['authorizationId' => $authorization['authorizationId'], 'token' => $authorization['token'], '_token' => $this->csrf], '', '&', PHP_QUERY_RFC3986);
        foreach (['application/json', 'text/plain', 'multipart/form-data; boundary=synthetic'] as $type) {
            $this->assertError($this->downloadRaw($this->url($f).'/download', $form, ['CONTENT_TYPE' => $type]), 415, 'INVALID_DELIVERY_REQUEST');
        }
        $this->assertError($this->downloadRaw($this->url($f).'/download', $form.'&unknown=%4Z'), 422, 'INVALID_DELIVERY_REQUEST');
        $this->assertError($this->downloadRaw($this->url($f).'/download?ignored=1', $form), 422, 'INVALID_DELIVERY_REQUEST');
        $this->assertSame([0], $this->streams->transactionLevels); $this->assertDatabaseCount('test_delivery_authorizations', 1);
        $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public function test_output_consumer_failure_closes_the_open_descriptor_and_keeps_the_committed_attempt(): void
    {
        $f = $this->ready(); $authorization = $this->issue($f)->assertCreated()->json('authorization');
        $response = $this->download($f, $authorization)->assertOk(); config(['app.debug' => true]); Log::spy();
        $resource = $this->streams->resources[array_key_last($this->streams->resources)]; $this->assertTrue(is_resource($resource));
        $marker = 'synthetic output failure private/path token-secret'; $failed = false; $level = ob_get_level();
        ob_start();
        ob_start(function (string $chunk) use (&$failed, $marker): string {
            if (! $failed) { $failed = true; throw new RuntimeException($marker); }
            return '';
        }, 1);
        try { ($response->baseResponse->getCallback())(); }
        finally {
            while (ob_get_level() > $level + 1) { ob_end_clean(); }
            $output = ob_get_clean();
        }
        $this->assertTrue($failed); $this->assertFalse(is_resource($resource));
        $this->assertStringNotContainsString($marker, $output); $this->assertStringNotContainsString('RuntimeException', $output);
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertError($this->download($f, $authorization), 409, 'DELIVERY_ATTEMPTED');
        Log::shouldHaveReceived('warning')->with('Test delivery stream interrupted.', ['exception_class' => RuntimeException::class])->once();
    }

    public function test_post_commit_stream_failure_closes_descriptor_and_does_not_append_debug_or_restore_attempt(): void
    {
        $f = $this->ready(); $authorization = $this->issue($f)->assertCreated()->json('authorization');
        config(['app.debug' => true]); Log::spy();
        $response = $this->download($f, $authorization)->assertOk();
        $resource = $this->streams->resources[array_key_last($this->streams->resources)]; $this->assertTrue(is_resource($resource));
        // Simulate the winning descriptor failing after commit but before the first output chunk.
        fclose($resource);
        $this->assertSame('', $response->streamedContent()); $this->assertFalse(is_resource($resource));
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertError($this->download($f, $authorization), 409, 'DELIVERY_ATTEMPTED');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => is_string($message)
            && array_keys($context) === ['exception_class'] && is_string($context['exception_class']))->once();
    }
}
