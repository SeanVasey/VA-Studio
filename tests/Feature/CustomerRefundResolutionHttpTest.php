<?php

namespace Tests\Feature;

use App\Domain\Commerce\RefundResolution\ReadOwnedTestRefundResolution;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CustomerFixtures;
use Tests\TestCase;

class CustomerRefundResolutionHttpTest extends TestCase
{
    private const URL = 'https://audio.example.test/orders/00000000-0000-4000-8000-000000000001/exception-resolution';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CustomerFixtures::configure();
        config(['app.debug' => true, 'app.url' => 'https://audio.example.test']);
    }

    public static function invalidTransport(): array
    {
        return [
            'post' => ['POST', '{}', [], '', 405], 'head' => ['HEAD', '', [], '', 405],
            'options' => ['OPTIONS', '', [], '', 405], 'delete' => ['DELETE', '', [], '', 405],
            'body without length' => ['GET', 'PRIVATE', [], '', 422],
            'body with false length' => ['GET', 'PRIVATE', ['CONTENT_LENGTH' => '0'], '', 422],
            'query' => ['GET', '', [], '?private=value', 422], 'discarded query' => ['GET', '', [], '?&&', 422],
            'range' => ['GET', '', ['HTTP_RANGE' => 'bytes=0-9'], '', 416],
            'if range' => ['GET', '', ['HTTP_IF_RANGE' => 'private'], '', 416],
            'override' => ['GET', '', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST'], '', 422],
            'encoding' => ['GET', '', ['HTTP_CONTENT_ENCODING' => 'gzip'], '', 415],
            'cross origin' => ['GET', '', ['HTTP_ORIGIN' => 'https://foreign.example'], '', 403],
            'cross site' => ['GET', '', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], '', 403],
        ];
    }

    #[DataProvider('invalidTransport')]
    public function test_transport_refusal_is_private_and_never_invokes_the_reader(string $method, string $body, array $server, string $suffix, int $status): void
    {
        $called = false;
        $this->app->bind(ReadOwnedTestRefundResolution::class, function () use (&$called): never {
            $called = true;
            throw new RuntimeException('PRIVATE READER SHOULD NOT RUN');
        });
        $response = $this->raw($method, $body, $server, self::URL.$suffix)->assertStatus($status);
        $this->private($response);
        if ($status === 405) {
            $response->assertHeader('Allow', 'GET');
        }
        $this->assertFalse($called);
    }

    public function test_routing_and_debug_middleware_failures_use_the_same_private_boundary(): void
    {
        $this->private($this->raw(url: self::URL.'/unknown')->assertNotFound());
        Route::get('/orders/{order}/exception-resolution/failure', fn () => throw new RuntimeException('PRIVATE provider-id /private/path'));
        Log::spy();
        $this->private($this->raw(url: self::URL.'/failure')->assertStatus(503));
        Log::shouldHaveReceived('error')->with('Test resolution request failed.', ['exception_class' => RuntimeException::class])->once();
    }

    public function test_lock_timeout_is_generic_and_private(): void
    {
        Route::get('/orders/{order}/exception-resolution/lock', fn () => throw new LockTimeoutException('PRIVATE SESSION'));
        $this->private($this->raw(url: self::URL.'/lock')->assertStatus(503));
    }

    public function test_existing_read_rate_budget_is_retained_with_private_retry_metadata(): void
    {
        Route::get('/orders/{order}/exception-resolution/limited', fn () => response()->json(['safe' => true]))
            ->middleware('throttle:60,1,quotes-read');
        for ($index = 0; $index < 60; $index++) {
            $this->raw(url: self::URL.'/limited')->assertOk();
        }
        $response = $this->raw(url: self::URL.'/limited')->assertStatus(429)->assertHeader('Retry-After');
        $this->private($response);
    }

    private function raw(string $method = 'GET', string $body = '', array $server = [], ?string $url = null): TestResponse
    {
        return $this->call($method, $url ?? self::URL, [], [], [], array_replace([
            'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'https://audio.example.test', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], $server), $body);
    }

    private function private(TestResponse $response): void
    {
        $response->assertExactJson(['code' => 'TEST_RESOLUTION_UNAVAILABLE', 'message' => 'This test order resolution could not be loaded.'])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('ETag')->assertHeaderMissing('Last-Modified')->assertHeaderMissing('Accept-Ranges');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
    }
}
