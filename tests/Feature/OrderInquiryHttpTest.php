<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\Order;
use App\Support\InquiryOwner;
use App\Support\QuoteOwner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\OrderInquiryFixtures as Fixture;
use Tests\TestCase;

class OrderInquiryHttpTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private Order $order;

    private string $csrf;

    private array $body;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        Fixture::configure();
        $this->csrf = Str::random(40);
        $this->withSession(['_token' => $this->csrf]);
        $request = Request::create('https://audio.example.test');
        $request->setLaravelSession(app('session.store'));
        $this->order = Fixture::guest(app(QuoteOwner::class)->forRequest($request));
        app(InquiryOwner::class)->forRequest($request);
        $this->body = Fixture::body();
    }

    public function test_original_session_can_lazily_open_create_retry_and_read_the_exact_private_reference(): void
    {
        $before = $this->order->fresh()->getAttributes();
        $setup = $this->raw()->assertOk()->assertExactJson(['orderInquiry' => ['orderInquirySchema' => 1,
            'orderId' => $this->order->public_id, 'testOnly' => true, 'privacyNotice' => config('inquiries.privacy_notice'), 'noticeToken' => $this->body['noticeToken']]]);
        $this->private($setup);
        $saved = $this->raw('POST', json_encode($this->body))->assertCreated();
        $this->private($saved);
        $receipt = $saved->json('receipt');
        $this->assertSame(['state', 'receipt'], array_keys($saved->json()));
        $this->raw('POST', json_encode($this->body))->assertOk()->assertExactJson(['state' => 'saved', 'receipt' => $receipt]);
        $contextUrl = '/contact/inquiries/'.$receipt.'/order-context';
        $context = $this->raw(url: $contextUrl)->assertOk()->assertExactJson(['context' => ['orderInquiryContextSchema' => 1,
            'order' => ['id' => $this->order->public_id, 'testOnly' => true]]]);
        $this->private($context);
        $context->assertDontSee($this->order->owner_key)->assertDontSee($this->body['email'])->assertDontSee('payload_hash');
        config(['inquiries.test_order_inquiries_enabled' => false, 'inquiries.enabled' => false]);
        $this->raw(url: $contextUrl)->assertOk()->assertExactJson($context->json());
        $this->raw()->assertNotFound();
        $this->assertSame($before, $this->order->fresh()->getAttributes());
        $this->flushSession();
        $unknown = $this->raw(url: '/contact/inquiries/'.Str::uuid().'/order-context')->assertNotFound();
        $this->raw(url: $contextUrl)->assertNotFound()->assertExactJson($unknown->json());
    }

    #[DataProvider('invalidTransport')]
    public function test_transport_refusals_precede_order_or_inquiry_access(string $method, string $body, array $headers, string $suffix, int $status): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $response = $this->raw($method, $body, $headers, $this->path().$suffix)->assertStatus($status);
        $this->private($response);
        $this->assertSame([], array_values(array_filter($queries, fn ($query) => str_contains($query, 'customer_inquiries') || str_contains($query, 'inquiry_order_contexts') || str_contains($query, '"orders"') || str_contains($query, '`orders`'))));
    }

    public static function invalidTransport(): array
    {
        return [
            'head' => ['HEAD', '', [], '', 405], 'delete' => ['DELETE', '', [], '', 405],
            'GET body' => ['GET', 'x', [], '', 422], 'GET query' => ['GET', '', [], '?&&', 422],
            'POST query' => ['POST', '{}', [], '?private=1', 422], 'range' => ['GET', '', ['HTTP_RANGE' => 'bytes=0-2'], '', 422],
            'override' => ['POST', '{}', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'GET'], '', 405],
            'foreign' => ['GET', '', ['HTTP_ORIGIN' => 'https://other.example'], '', 403],
            'cross site' => ['POST', '{}', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], '', 403],
            'wrong media' => ['POST', '{}', ['CONTENT_TYPE' => 'text/plain'], '', 415],
            'encoding' => ['POST', '{}', ['HTTP_CONTENT_ENCODING' => 'gzip'], '', 415],
            'oversize' => ['POST', str_repeat('x', 16385), [], '', 413],
        ];
    }

    public function test_duplicate_or_extra_order_identity_fields_are_rejected_and_context_is_read_only(): void
    {
        foreach ([array_replace($this->body, ['orderId' => $this->order->public_id]), array_replace($this->body, ['owner' => $this->order->owner_key])] as $body) {
            $this->raw('POST', json_encode($body))->assertUnprocessable();
        }
        $json = json_encode($this->body);
        $this->raw('POST', substr($json, 0, -1).',"requestKey":"'.$this->body['requestKey'].'"}')->assertUnprocessable();
        $saved = $this->raw('POST', $json)->assertCreated();
        $this->raw('POST', '{}', url: '/contact/inquiries/'.$saved->json('receipt').'/order-context')->assertStatus(405)->assertHeader('Allow', 'GET');
        $this->assertDatabaseCount('inquiry_order_contexts', 1);
    }

    public function test_real_csrf_and_revoked_account_use_private_generic_responses(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        foreach (['', 'bad-token'] as $token) {
            $response = $this->raw('POST', json_encode($this->body), ['HTTP_X_CSRF_TOKEN' => $token, 'HTTP_SEC_FETCH_SITE' => ''])->assertStatus(419);
            $this->private($response);
        }
        $this->raw('POST', json_encode($this->body), ['HTTP_SEC_FETCH_SITE' => ''])->assertCreated();
        $account = CustomerFixtures::account();
        $this->postJson('/account/sign-in', ['email' => $account['user']->email, 'password' => CustomerFixtures::PASSWORD], ['X-CSRF-TOKEN' => session()->token()])->assertOk();
        CustomerFixtures::withdraw($account);
        $denied = $this->raw()->assertNotFound();
        $this->private($denied);
        $this->assertSame('INQUIRY_UNAVAILABLE', $denied->json('code'));
    }

    public function test_unexpected_failure_does_not_disclose_private_values_even_under_debug(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_contains($query->sql, 'site_publications')) {
                $armed = false;
                throw new RuntimeException('PRIVATE ORDER ID AND MESSAGE');
            }
        });
        $response = $this->raw()->assertStatus(503);
        $this->private($response);
        $response->assertDontSee('PRIVATE ORDER ID')->assertDontSee('RuntimeException')->assertDontSee('trace');
        Log::shouldHaveReceived('error')->with('Order inquiry request failed.', ['exception_class' => RuntimeException::class])->once();
    }

    private function path(): string
    {
        return '/contact/inquiries/for-order/'.$this->order->public_id;
    }

    private function raw(string $method = 'GET', string $body = '', array $headers = [], ?string $url = null): TestResponse
    {
        return $this->call($method, 'https://audio.example.test'.($url ?? $this->path()), [], [], [], array_replace([
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf,
            'HTTP_ORIGIN' => 'https://audio.example.test', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], $headers), $body);
    }

    private function private(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Vary', 'Cookie');
    }
}
