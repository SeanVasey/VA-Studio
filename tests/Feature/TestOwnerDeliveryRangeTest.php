<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Delivery\DeliveryByteRange;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\ManageTestDeliveryControl;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Delivery\ReadTestDeliveryAuthorization;
use App\Domain\Delivery\RedeemTestDelivery;
use App\Domain\Media\Models\MediaAsset;
use App\Support\Audit\AuditEvent;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\ActivationFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * FP-077: single byte ranges on the redeeming test-delivery download. A range is authorized exactly like a full request,
 * the authorization's one redemption is recorded once, and resumes on the same unexpired authorization never count again.
 */
class TestOwnerDeliveryRangeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;

    private PrepareTestDeliveryStream $streams;

    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        Queue::fake();
        $this->csrf = Str::random(40);
        $this->withSession(['_token' => $this->csrf]);
        $this->gateway = PaymentFixtures::gateway();
        $this->streams = F::observingStreams();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $this->app->instance(PrepareTestDeliveryStream::class, $this->streams);
        // Route throttling is proven by TestOwnerDeliveryHttpTest; these cases need more than ten POSTs per minute.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function ready(): array
    {
        $fixture = F::activate(ActivationFixtures::issue(ContractFixtures::finalize(FinalizationFixtures::ownedHttp($this, $this->gateway))));
        Queue::fake();

        return $fixture;
    }

    private function url(array $f): string
    {
        return '/orders/'.$f['order']->public_id.'/delivery';
    }

    private function issue(array $f): array
    {
        return $this->call('POST', $this->url($f).'/authorizations', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf,
            'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
        ], json_encode(['grantId' => $f['grant']->public_id, 'kind' => 'contract'], JSON_THROW_ON_ERROR))->assertCreated()->json('authorization');
    }

    private function download(array $f, array $authorization, ?string $range = null, ?string $ifRange = null): TestResponse
    {
        $body = http_build_query(['authorizationId' => $authorization['authorizationId'], 'token' => $authorization['token'],
            '_token' => $this->csrf], '', '&', PHP_QUERY_RFC3986);
        parse_str($body, $fields);
        $server = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'text/html'];
        if ($range !== null) {
            $server['HTTP_RANGE'] = $range;
        }
        if ($ifRange !== null) {
            $server['HTTP_IF_RANGE'] = $ifRange;
        }

        return $this->call('POST', $this->url($f).'/download', $fields, [], [], $server, $body);
    }

    private function bytes(): string
    {
        return Storage::disk('local')->get(GrantContract::sole()->storage_path);
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('Last-Modified');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
    }

    private function assertStream(TestResponse $response, int $status, string $expected): void
    {
        $response->assertStatus($status);
        $this->assertPrivate($response);
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        $response->assertHeader('Accept-Ranges', 'bytes')->assertHeader('ETag', DeliveryByteRange::etag(hash('sha256', $this->bytes())))
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Length', (string) strlen($expected));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame($expected, $response->streamedContent());
        $this->assertFalse(is_resource($this->streams->resources[array_key_last($this->streams->resources)]));
    }

    private function assertError(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code);
        $this->assertPrivate($response);
        $response->assertHeaderMissing('ETag')->assertHeaderMissing('Accept-Ranges');
        $this->assertNotInstanceOf(StreamedResponse::class, $response->baseResponse);
        $this->assertSame(['code'], array_keys($response->json()));
    }

    private function audits(string $action): int
    {
        return AuditEvent::where('action', $action)->count();
    }

    public function test_ranges_return_exact_bytes_and_resumes_share_one_redemption_that_full_requests_cannot_reuse(): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $size = strlen($bytes);
        $authorization = $this->issue($f);
        $this->assertGreaterThan(64, $size);

        $first = $this->download($f, $authorization, 'bytes=10-19');
        $this->assertStream($first, 206, substr($bytes, 10, 10));
        $first->assertHeader('Content-Range', 'bytes 10-19/'.$size);
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertSame(1, $this->audits('commerce.delivery.redeemed'));
        $this->assertSame(0, $this->audits('commerce.delivery.resumed'));

        $open = $this->download($f, $authorization, 'bytes=20-');
        $this->assertStream($open, 206, substr($bytes, 20));
        $open->assertHeader('Content-Range', 'bytes 20-'.($size - 1).'/'.$size);
        $suffix = $this->download($f, $authorization, 'bytes=-15', DeliveryByteRange::etag(hash('sha256', $bytes)));
        $this->assertStream($suffix, 206, substr($bytes, -15));
        $suffix->assertHeader('Content-Range', 'bytes '.($size - 15).'-'.($size - 1).'/'.$size);
        $clipped = $this->download($f, $authorization, 'bytes='.($size - 3).'-'.($size + 100));
        $this->assertStream($clipped, 206, substr($bytes, -3));

        // Every served range prepared and verified its own whole snapshot before bytes; none recorded another redemption.
        $this->assertSame([0, 0, 0, 0, 0], $this->streams->transactionLevels); // Issuance preflight plus four ranges.
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertSame(1, $this->audits('commerce.delivery.redeemed'));
        $this->assertSame(3, $this->audits('commerce.delivery.resumed'));

        // A full request, a whole-file range, an ignored range and a failed If-Range are all full requests: refused.
        foreach ([[null, null], ['bytes=0-', null], ['bytes=-'.$size, null], ['bytes=0-'.($size - 1), null], ['bytes=5-x', null],
            ['bytes=0-1,4-5', null], ['bytes=5-', '"'.str_repeat('0', 64).'"'], ['bytes=5-', 'W/'.DeliveryByteRange::etag(hash('sha256', $bytes))]] as [$range, $ifRange]) {
            $this->assertError($this->download($f, $authorization, $range, $ifRange), 409, 'DELIVERY_ATTEMPTED');
        }
        $this->assertCount(5, $this->streams->transactionLevels);
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertDatabaseCount('test_delivery_authorizations', 1);
        $this->get($this->url($f))->assertOk()->assertJsonPath('delivery.history.0.status', 'attempted');
    }

    public function test_full_download_then_matching_if_range_resume_counts_once(): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $authorization = $this->issue($f);
        $full = $this->download($f, $authorization);
        $this->assertStream($full, 200, $bytes);
        $full->assertHeaderMissing('Content-Range');
        $resume = $this->download($f, $authorization, 'bytes=7-', $full->headers->get('ETag'));
        $this->assertStream($resume, 206, substr($bytes, 7));
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertSame(1, $this->audits('commerce.delivery.redeemed'));
        $this->assertSame(1, $this->audits('commerce.delivery.resumed'));
    }

    public static function ignoredRanges(): array
    {
        return [
            'malformed' => ['bytes=abc', null], 'unknown unit' => ['items=0-1', null], 'reversed' => ['bytes=9-2', null],
            'multi range' => ['bytes=0-1,4-5', null], 'whole file' => ['bytes=0-', null],
            'different validator' => ['bytes=5-', '"'.str_repeat('0', 64).'"'], 'date validator' => ['bytes=5-', 'Sun, 08 Nov 2026 10:00:00 GMT'],
            'if-range without range' => [null, 'anything'],
        ];
    }

    #[DataProvider('ignoredRanges')]
    public function test_ignored_ranges_serve_the_full_file_and_record_the_single_redemption(?string $range, ?string $ifRange): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $authorization = $this->issue($f);
        $response = $this->download($f, $authorization, $range, $ifRange);
        $this->assertStream($response, 200, $bytes);
        $response->assertHeaderMissing('Content-Range');
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertSame(1, $this->audits('commerce.delivery.redeemed'));
    }

    public function test_unsatisfiable_ranges_are_416_without_private_file_access_or_consumption(): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $size = strlen($bytes);
        $authorization = $this->issue($f);
        $prepares = $this->streams->transactionLevels;
        foreach (['bytes='.$size.'-', 'bytes='.($size + 10).'-'.($size + 20), 'bytes=-0'] as $range) {
            $response = $this->download($f, $authorization, $range);
            $this->assertError($response, 416, 'DELIVERY_RANGE_NOT_SATISFIABLE');
            $response->assertHeader('Content-Range', 'bytes */'.$size);
        }
        $this->assertSame($prepares, $this->streams->transactionLevels);
        $this->assertDatabaseCount('test_delivery_redemptions', 0);
        $this->assertSame(0, $this->audits('commerce.delivery.redeemed'));
        // A failed If-Range ignores even an unsatisfiable range: the whole file is the answer.
        $this->assertStream($this->download($f, $authorization, 'bytes='.$size.'-', '"'.str_repeat('0', 64).'"'), 200, $bytes);
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertError($this->download($f, $authorization, 'bytes='.$size.'-'), 416, 'DELIVERY_RANGE_NOT_SATISFIABLE');
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertSame(0, $this->audits('commerce.delivery.resumed'));
    }

    public function test_expiry_is_enforced_for_first_ranges_resumes_and_unsatisfiable_ranges(): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $start = now()->toImmutable();
        $unused = $this->issue($f);
        $used = $this->issue($f);
        $this->assertStream($this->download($f, $used, 'bytes=0-9'), 206, substr($bytes, 0, 10));
        $this->travelTo($start->addSeconds(59));
        $this->assertStream($this->download($f, $used, 'bytes=10-'), 206, substr($bytes, 10));
        $this->travelTo($start->addSeconds(60));
        $prepares = $this->streams->transactionLevels;
        foreach ([$unused, $used] as $authorization) {
            foreach (['bytes=10-', 'bytes=-5', 'bytes='.strlen($bytes).'-'] as $range) {
                $this->assertError($this->download($f, $authorization, $range), 410, 'DELIVERY_EXPIRED');
            }
        }
        $this->assertSame($prepares, $this->streams->transactionLevels);
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertSame(1, $this->audits('commerce.delivery.resumed'));
    }

    public function test_policy_withdrawal_and_control_block_refuse_ranges_like_full_requests(): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $unused = $this->issue($f);
        $used = $this->issue($f);
        $this->assertStream($this->download($f, $used, 'bytes=0-9'), 206, substr($bytes, 0, 10));
        $prepares = $this->streams->transactionLevels;
        config(['delivery.test_access_enabled' => false]);
        foreach ([$unused, $used] as $authorization) {
            foreach (['bytes=10-', 'bytes='.strlen($bytes).'-'] as $range) {
                $this->assertError($this->download($f, $authorization, $range), 503, 'DELIVERY_UNAVAILABLE');
            }
        }
        config(['delivery.test_access_enabled' => true]);
        app(ManageTestDeliveryControl::class)->handle($f['order']->public_id, true, 1, 'SYNTHETIC-RANGE-BLOCK');
        foreach ([$unused, $used] as $authorization) {
            $this->assertError($this->download($f, $authorization, 'bytes=10-'), 503, 'DELIVERY_UNAVAILABLE');
        }
        $this->assertSame($prepares, $this->streams->transactionLevels);
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertSame(0, $this->audits('commerce.delivery.resumed'));
    }

    public function test_another_session_or_a_wrong_token_cannot_range_even_a_redeemed_authorization(): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $authorization = $this->issue($f);
        $this->assertStream($this->download($f, $authorization, 'bytes=0-9'), 206, substr($bytes, 0, 10));
        $prepares = $this->streams->transactionLevels;
        $wrong = $authorization;
        $wrong['token'] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->assertError($this->download($f, $wrong, 'bytes=10-'), 404, 'DELIVERY_NOT_FOUND');
        $this->flushSession();
        $this->withSession(['_token' => $this->csrf]);
        foreach (['bytes=10-', 'bytes='.strlen($bytes).'-', null] as $range) {
            $this->assertError($this->download($f, $authorization, $range), 404, 'DELIVERY_NOT_FOUND');
        }
        $this->assertSame($prepares, $this->streams->transactionLevels);
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertSame(0, $this->audits('commerce.delivery.resumed'));
    }

    public function test_tampered_private_file_is_refused_before_any_range_bytes(): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $path = Storage::disk('local')->path(GrantContract::sole()->storage_path);
        $mode = fileperms($path) & 0777;
        $unused = $this->issue($f);
        $used = $this->issue($f);
        $this->assertStream($this->download($f, $used, 'bytes=0-9'), 206, substr($bytes, 0, 10));
        // Bytes outside every requested range change: only whole-file verification can notice it.
        $changed = $bytes;
        $changed[strlen($bytes) - 1] = $changed[strlen($bytes) - 1] === 'X' ? 'Y' : 'X';
        chmod($path, 0600);
        file_put_contents($path, $changed);
        chmod($path, $mode);
        foreach ([$unused, $used] as $authorization) {
            $response = $this->download($f, $authorization, 'bytes=10-19');
            $this->assertError($response, 503, 'DELIVERY_UNAVAILABLE');
            $this->assertStringNotContainsString(substr($bytes, 10, 10), $response->getContent());
        }
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertSame(0, $this->audits('commerce.delivery.resumed'));
    }

    public function test_get_and_issuance_routes_still_refuse_range_headers(): void
    {
        $f = $this->ready();
        foreach (['HTTP_RANGE' => 'bytes=0-9', 'HTTP_IF_RANGE' => '"x"'] as $header => $value) {
            $this->assertError($this->call('GET', $this->url($f), [], [], [], [$header => $value, 'HTTP_ACCEPT' => 'application/json']), 416, 'INVALID_DELIVERY_REQUEST');
        }
        $this->assertDatabaseCount('test_delivery_authorizations', 0);
        $this->assertSame([], $this->streams->transactionLevels);
    }

    public function test_stems_archive_resumes_with_exact_bytes_through_the_domain_command(): void
    {
        $f = F::allRoles($this->gateway);
        $issued = app(IssueTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $f['grant']->public_id, 'stems_zip', (string) Str::uuid());
        $file = app(ReadTestDeliveryAuthorization::class)->forOwner($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, $issued->token())['target']['file'];
        $stems = MediaAsset::where('role', 'stems_zip')->where('sha256', $file['sha256'])->sole();
        $bytes = Storage::disk('local')->get($stems->storage_path);
        $this->assertSame($file['size_bytes'], strlen($bytes));
        $redeem = fn (string $header) => app(RedeemTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER,
            $issued->authorizationId, $issued->token(), range: DeliveryByteRange::evaluate($header, null, strlen($bytes), hash('sha256', $bytes)));
        $read = function (string $header) use ($redeem, $bytes): string {
            $range = DeliveryByteRange::evaluate($header, null, strlen($bytes), hash('sha256', $bytes));
            $out = '';
            $redeem($header)->writeRangeTo(function (string $chunk) use (&$out): void {
                $out .= $chunk;
            }, $range->start, $range->end);

            return $out;
        };
        $half = intdiv(strlen($bytes), 2);
        $this->assertSame(substr($bytes, 0, $half), $read('bytes=0-'.($half - 1)));
        $this->assertSame(substr($bytes, $half), $read('bytes='.$half.'-'));
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        try {
            $redeem('bytes=0-')->close();
            $this->fail('A whole-file selection reused a redemption.');
        } catch (DeliveryException $error) {
            $this->assertSame('redeemed', $error->reason);
        }
        try {
            $redeem('bytes='.strlen($bytes).'-')->close();
            $this->fail('An unsatisfiable selection returned a stream.');
        } catch (DeliveryException $error) {
            $this->assertSame('range_not_satisfiable', $error->reason);
        }
        // A selection evaluated against different bytes is refused before any private file work.
        $prepares = $this->streams->transactionLevels;
        try {
            app(RedeemTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, $issued->token(),
                range: DeliveryByteRange::evaluate('bytes=1-', null, strlen($bytes), str_repeat('0', 64)));
            $this->fail('A selection for other bytes was accepted.');
        } catch (DeliveryException $error) {
            $this->assertSame('changed', $error->reason);
        }
        $this->assertSame($prepares, $this->streams->transactionLevels);
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertSame(1, $this->audits('commerce.delivery.resumed'));
    }
}
