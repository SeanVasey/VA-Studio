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
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryCoverage;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Delivery\ReadTestDeliveryAuthorization;
use App\Domain\Delivery\TestAccessPolicy;
use App\Domain\Delivery\TestDeliveryCoverageLedger;
use App\Domain\Delivery\TransferTestDelivery;
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
 * FP-077 phase 1: test-owner-delivery-v2. The token POST answers 303 to a GET that holds only the authorization ID and is
 * valid only in the session that created the authorization. Ranges resume one transfer; a download counts once, when the
 * distinct bytes served under the authorization cover the file.
 */
class TestOwnerDeliveryResumeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;

    private PrepareTestDeliveryStream $streams;

    private string $csrf;

    /** @var array<string, string> authorization ID => GET path, once its token was presented in this session */
    private array $locations = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configureV2();
        Queue::fake();
        $this->csrf = Str::random(40);
        $this->withSession(['_token' => $this->csrf]);
        $this->gateway = PaymentFixtures::gateway();
        $this->streams = F::observingStreams();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $this->app->instance(PrepareTestDeliveryStream::class, $this->streams);
        // Route throttling is proven by TestOwnerDeliveryHttpTest; these cases need more requests per minute.
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

    private function issueResponse(array $f): TestResponse
    {
        return $this->call('POST', $this->url($f).'/authorizations', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf,
            'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
        ], json_encode(['grantId' => $f['grant']->public_id, 'kind' => 'contract'], JSON_THROW_ON_ERROR));
    }

    private function issue(array $f): array
    {
        return $this->issueResponse($f)->assertCreated()->json('authorization');
    }

    private function present(array $f, array $authorization, array $server = []): TestResponse
    {
        $body = http_build_query(['authorizationId' => $authorization['authorizationId'], 'token' => $authorization['token'],
            '_token' => $this->csrf], '', '&', PHP_QUERY_RFC3986);
        parse_str($body, $fields);

        return $this->call('POST', $this->url($f).'/download', $fields, [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'text/html'] + $server, $body);
    }

    private function location(array $f, array $authorization): string
    {
        return $this->url($f).'/downloads/'.$authorization['authorizationId'];
    }

    /** Presents the token once per session (POST -> 303), then GETs the opaque location with the given range headers. */
    private function fetch(array $f, array $authorization, ?string $range = null, ?string $ifRange = null): TestResponse
    {
        if (! isset($this->locations[$authorization['authorizationId']])) {
            $redirect = $this->present($f, $authorization);
            $redirect->assertStatus(303);
            $this->locations[$authorization['authorizationId']] = (string) parse_url($redirect->headers->get('Location'), PHP_URL_PATH);
        }

        return $this->transfer($this->locations[$authorization['authorizationId']], $range, $ifRange);
    }

    private function transfer(string $location, ?string $range = null, ?string $ifRange = null, string $method = 'GET'): TestResponse
    {
        $server = ['HTTP_ACCEPT' => 'text/html'];
        if ($range !== null) {
            $server['HTTP_RANGE'] = $range;
        }
        if ($ifRange !== null) {
            $server['HTTP_IF_RANGE'] = $ifRange;
        }

        return $this->call($method, $location, [], [], [], $server);
    }

    private function bytes(): string
    {
        return Storage::disk('local')->get(GrantContract::sole()->storage_path);
    }

    private function etag(): string
    {
        return DeliveryByteRange::etag(hash('sha256', $this->bytes()));
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
        $response->assertHeader('Accept-Ranges', 'bytes')->assertHeader('ETag', $this->etag())
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Length', (string) strlen($expected));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame($expected, $response->streamedContent());
        $this->assertFalse(is_resource($this->streams->resources[array_key_last($this->streams->resources)]));
    }

    private function assertError(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code);
        $this->assertPrivate($response);
        $response->assertHeaderMissing('ETag')->assertHeaderMissing('Accept-Ranges')->assertHeaderMissing('Content-Disposition');
        $this->assertNotInstanceOf(StreamedResponse::class, $response->baseResponse);
        $this->assertSame(['code'], array_keys($response->json()));
    }

    private function audits(string $action): int
    {
        return AuditEvent::where('action', $action)->count();
    }

    private function completions(): int
    {
        return TestDeliveryCoverage::whereNotNull('completed_authorization_id')->count();
    }

    public function test_the_token_post_answers_303_to_an_opaque_get_and_a_full_get_counts_once(): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $authorization = $this->issue($f);
        $row = TestDeliveryAuthorization::sole();
        $this->assertSame(TestAccessPolicy::V2, $row->policy_version);

        $redirect = $this->present($f, $authorization);
        $redirect->assertStatus(303);
        $this->assertPrivate($redirect);
        $location = (string) $redirect->headers->get('Location');
        $this->assertSame($this->location($f, $authorization), parse_url($location, PHP_URL_PATH));
        $this->assertNull(parse_url($location, PHP_URL_QUERY));
        $this->assertStringNotContainsString($authorization['token'], $location);
        $this->assertSame('', $redirect->getContent());
        // Presenting the token consumes nothing and reads no private bytes.
        $this->assertSame([0], $this->streams->transactionLevels);
        $this->assertDatabaseCount('test_delivery_redemptions', 0);

        $full = $this->transfer((string) parse_url($location, PHP_URL_PATH));
        $this->assertStream($full, 200, $bytes);
        $full->assertHeaderMissing('Content-Range');
        $this->assertSame('attachment; filename='.$authorization['filename'], $full->headers->get('Content-Disposition'));
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $coverage = TestDeliveryCoverage::sole();
        $this->assertSame([0, strlen($bytes) - 1, strlen($bytes)], [$coverage->first_byte, $coverage->last_byte, $coverage->covered_bytes]);
        $this->assertSame($row->id, $coverage->completed_authorization_id);
        $this->assertSame(1, $this->audits('commerce.delivery.redeemed'));
        $this->assertSame(1, $this->audits('commerce.delivery.completed'));

        // Completed: any further request on this authorization, full or partial, is refused before file access.
        $prepares = $this->streams->transactionLevels;
        foreach ([null, 'bytes=0-9', 'bytes=5-'] as $range) {
            $this->assertError($this->transfer((string) parse_url($location, PHP_URL_PATH), $range), 409, 'DELIVERY_ATTEMPTED');
        }
        $this->assertSame($prepares, $this->streams->transactionLevels);
        $this->assertSame(1, $this->completions());
        $this->get($this->url($f))->assertOk()->assertJsonPath('delivery.history.0.status', 'attempted');
        // A response admitted before completion and recorded after it re-serves bytes without a second count.
        $ledger = app(TestDeliveryCoverageLedger::class);
        $this->assertFalse($ledger->record($row->id, TestDeliveryRedemption::sole()->id, 0, strlen($bytes) - 1));
        $this->assertSame(1, $this->completions());
        $this->assertDatabaseCount('test_delivery_coverage', 2);
        $this->assertSame(1, $this->audits('commerce.delivery.completed'));
    }

    public function test_overlapping_out_of_order_ranges_complete_coverage_exactly_once(): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $size = strlen($bytes);
        $this->assertGreaterThan(64, $size);
        $authorization = $this->issue($f);

        $tail = $this->fetch($f, $authorization, 'bytes=20-');
        $this->assertStream($tail, 206, substr($bytes, 20));
        $tail->assertHeader('Content-Range', 'bytes 20-'.($size - 1).'/'.$size);
        $middle = $this->fetch($f, $authorization, 'bytes=10-30', $this->etag());
        $this->assertStream($middle, 206, substr($bytes, 10, 21));
        $middle->assertHeader('Content-Range', 'bytes 10-30/'.$size);
        // Re-served bytes add nothing; partial responses are not a download.
        $this->assertStream($this->fetch($f, $authorization, 'bytes=-15'), 206, substr($bytes, -15));
        $this->assertSame(0, $this->completions());
        $this->assertSame([$size - 20, $size - 10, $size - 10], TestDeliveryCoverage::orderBy('sequence')->pluck('covered_bytes')->all());

        $this->assertStream($this->fetch($f, $authorization, 'bytes=0-14'), 206, substr($bytes, 0, 15));
        $this->assertSame(1, $this->completions());
        $completed = TestDeliveryCoverage::whereNotNull('completed_authorization_id')->sole();
        $this->assertSame(4, $completed->sequence);
        $this->assertSame('[[0,'.($size - 1).']]', $completed->intervals);
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertSame(1, $this->audits('commerce.delivery.redeemed'));
        $this->assertSame(3, $this->audits('commerce.delivery.resumed'));
        $this->assertSame(1, $this->audits('commerce.delivery.completed'));
        $this->assertError($this->fetch($f, $authorization, 'bytes=0-0'), 409, 'DELIVERY_ATTEMPTED');
        $this->assertSame(1, $this->completions());
        $this->assertDatabaseCount('test_delivery_coverage', 4);
    }

    public function test_partial_and_interrupted_transfers_are_never_counted(): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $authorization = $this->issue($f);
        $this->assertStream($this->fetch($f, $authorization, 'bytes=0-'.(strlen($bytes) - 2)), 206, substr($bytes, 0, -1));
        $this->assertSame(0, $this->completions());
        $this->assertSame(strlen($bytes) - 1, TestDeliveryCoverage::sole()->covered_bytes);

        // A consumer that fails before accepting any byte records no coverage at all.
        $transfer = app(TransferTestDelivery::class)->handle($f['order']->public_id, $f['order']->owner_key, $authorization['authorizationId'],
            session('_test_delivery_session')['secret'], DeliveryByteRange::evaluate('bytes=-1', null, strlen($bytes), hash('sha256', $bytes)));
        try {
            $transfer->writeTo(fn () => throw new \RuntimeException('client went away'));
            $this->fail('The interrupted consumer was not propagated.');
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('test_delivery_coverage', 1);
        $this->assertSame(0, $this->completions());
        $this->assertSame(1, $this->audits('commerce.delivery.resumed'));

        // The last byte, served for real, completes it once.
        $this->assertStream($this->fetch($f, $authorization, 'bytes=-1'), 206, substr($bytes, -1));
        $this->assertSame(1, $this->completions());
    }

    public function test_a_get_without_the_creating_session_is_refused_with_no_bytes_and_no_consumption(): void
    {
        $f = $this->ready();
        $authorization = $this->issue($f);
        $location = $this->location($f, $authorization);
        $owner = session('_quote_owner');
        $binding = session('_test_delivery_session');
        $this->assertIsArray($owner);

        // Token never presented in this session: the URL alone is useless.
        $this->assertError($this->transfer($location), 404, 'DELIVERY_NOT_FOUND');
        $this->assertStream($this->fetch($f, $authorization, 'bytes=0-9'), 206, substr($this->bytes(), 0, 10));
        $binding = session('_test_delivery_session');
        $prepares = $this->streams->transactionLevels;

        // Same owner, but a session that lacks (or holds another) binding secret, even with the token "presented" there.
        foreach ([null, ['secret' => str_repeat('ab', 32), 'presented' => [$authorization['authorizationId']]]] as $foreign) {
            $this->flushSession();
            $this->withSession(['_token' => $this->csrf, '_quote_owner' => $owner] + ($foreign ? ['_test_delivery_session' => $foreign] : []));
            foreach ([null, 'bytes=10-', 'bytes=999999-'] as $range) {
                $this->assertError($this->transfer($location, $range), 404, 'DELIVERY_NOT_FOUND');
            }
            $this->assertError($this->present($f, $authorization), 404, 'DELIVERY_NOT_FOUND');
        }
        // Another owner's session holding the copied binding, and a logged-out (invalidated, empty) session.
        $this->flushSession();
        $this->withSession(['_token' => $this->csrf, '_test_delivery_session' => $binding]);
        $this->assertError($this->transfer($location, 'bytes=10-'), 404, 'DELIVERY_NOT_FOUND');
        $this->flushSession();
        $this->withSession(['_token' => $this->csrf]);
        $this->assertError($this->transfer($location, 'bytes=10-'), 404, 'DELIVERY_NOT_FOUND');

        $this->assertSame($prepares, $this->streams->transactionLevels);
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertDatabaseCount('test_delivery_coverage', 1);
        $this->assertSame(0, $this->audits('commerce.delivery.resumed'));

        // The creating session still resumes.
        $this->flushSession();
        $this->withSession(['_token' => $this->csrf, '_quote_owner' => $owner, '_test_delivery_session' => $binding]);
        $this->assertStream($this->transfer($location, 'bytes=10-'), 206, substr($this->bytes(), 10));
        $this->assertSame(1, $this->completions());
    }

    public function test_a_wrong_token_is_not_found_and_head_or_query_strings_are_refused(): void
    {
        $f = $this->ready();
        $authorization = $this->issue($f);
        $wrong = $authorization;
        $wrong['token'] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->assertError($this->present($f, $wrong), 404, 'DELIVERY_NOT_FOUND');
        $this->assertError($this->transfer($this->location($f, $authorization)), 404, 'DELIVERY_NOT_FOUND');
        $this->fetch($f, $authorization, 'bytes=0-1')->assertStatus(206)->streamedContent();
        $location = $this->location($f, $authorization);
        $this->transfer($location, null, null, 'HEAD')->assertStatus(405)->assertHeader('Allow', 'GET');
        $this->assertError($this->call('GET', $location.'?token='.$authorization['token']), 422, 'INVALID_DELIVERY_REQUEST');
        $this->assertError($this->call('POST', $location, [], [], [], ['HTTP_ACCEPT' => 'application/json']), 405, 'INVALID_DELIVERY_REQUEST');
        // The token POST itself never serves ranges; the read route still refuses range headers.
        $this->assertError($this->present($f, $authorization, ['HTTP_RANGE' => 'bytes=0-9']), 416, 'INVALID_DELIVERY_REQUEST');
        $this->assertError($this->call('GET', $this->url($f), [], [], [], ['HTTP_RANGE' => 'bytes=0-9', 'HTTP_ACCEPT' => 'application/json']), 416, 'INVALID_DELIVERY_REQUEST');
        $this->assertDatabaseCount('test_delivery_coverage', 1);
    }

    public static function ignoredRanges(): array
    {
        return [
            'malformed' => ['bytes=abc', null], 'unknown unit' => ['items=0-1', null], 'reversed' => ['bytes=9-2', null],
            'multi range' => ['bytes=0-1,4-5', null], 'whole file' => ['bytes=0-', null],
            'different validator' => ['bytes=5-', '"'.str_repeat('0', 64).'"'], 'weak validator' => ['bytes=5-', 'W/"x"'],
            'date validator' => ['bytes=5-', 'Sun, 08 Nov 2026 10:00:00 GMT'], 'if-range without range' => [null, 'anything'],
        ];
    }

    #[DataProvider('ignoredRanges')]
    public function test_ignored_ranges_serve_the_whole_file_and_count_once(?string $range, ?string $ifRange): void
    {
        $f = $this->ready();
        $authorization = $this->issue($f);
        $response = $this->fetch($f, $authorization, $range, $ifRange);
        $this->assertStream($response, 200, $this->bytes());
        $response->assertHeaderMissing('Content-Range');
        $this->assertSame(1, $this->completions());
    }

    public function test_unsatisfiable_ranges_are_416_without_file_access_start_or_coverage(): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $size = strlen($bytes);
        $authorization = $this->issue($f);
        $this->fetch($f, $authorization, 'bytes=0-0')->assertStatus(206)->streamedContent();
        $fresh = $this->issue($f);
        $prepares = $this->streams->transactionLevels;
        foreach ([$fresh, $authorization] as $each) {
            foreach (['bytes='.$size.'-', 'bytes='.($size + 10).'-'.($size + 20), 'bytes=-0'] as $range) {
                $response = $this->fetch($f, $each, $range);
                $this->assertError($response, 416, 'DELIVERY_RANGE_NOT_SATISFIABLE');
                $response->assertHeader('Content-Range', 'bytes */'.$size);
            }
        }
        $this->assertSame($prepares, $this->streams->transactionLevels);
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertDatabaseCount('test_delivery_coverage', 1);
        // A failed If-Range ignores even an unsatisfiable range: the whole file is the answer.
        $this->assertStream($this->fetch($f, $fresh, 'bytes='.$size.'-', '"'.str_repeat('0', 64).'"'), 200, $bytes);
        $this->assertSame(1, $this->completions());
    }

    public function test_the_start_window_is_sixty_seconds_and_the_resume_window_is_measured_from_the_first_byte(): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $start = now()->toImmutable();
        $unused = $this->issue($f);
        $used = $this->issue($f);
        $this->present($f, $unused)->assertStatus(303);
        $this->locations[$unused['authorizationId']] = $this->location($f, $unused);
        $this->travelTo($start->addSeconds(59));
        $this->assertStream($this->fetch($f, $used, 'bytes=0-9'), 206, substr($bytes, 0, 10));
        // Below 512 MiB the default resume window is one hour from the first byte (at 59 s), not from issuance.
        $this->travelTo($start->addSeconds(59 + 3599));
        $this->assertStream($this->fetch($f, $used, 'bytes=10-19'), 206, substr($bytes, 10, 10));
        $this->travelTo($start->addSeconds(59 + 3600));
        $prepares = $this->streams->transactionLevels;
        foreach ([$unused, $used] as $authorization) {
            foreach (['bytes=20-', null, 'bytes='.strlen($bytes).'-'] as $range) {
                $this->assertError($this->fetch($f, $authorization, $range), 410, 'DELIVERY_EXPIRED');
            }
        }
        $this->assertSame($prepares, $this->streams->transactionLevels);
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertSame(0, $this->completions());
    }

    public function test_the_resume_window_is_frozen_at_issuance_from_the_configured_tier(): void
    {
        config(['delivery.test_resume_window_seconds.below_512_mib' => 120]);
        $f = $this->ready();
        $authorization = $this->issue($f);
        $start = now()->toImmutable();
        $this->fetch($f, $authorization, 'bytes=0-0')->assertStatus(206)->streamedContent();
        // A later configuration change does not move this authorization's window.
        config(['delivery.test_resume_window_seconds.below_512_mib' => 3600]);
        $this->travelTo($start->addSeconds(119));
        $this->fetch($f, $authorization, 'bytes=1-1')->assertStatus(206)->streamedContent();
        $this->travelTo($start->addSeconds(120));
        $this->assertError($this->fetch($f, $authorization, 'bytes=2-2'), 410, 'DELIVERY_EXPIRED');
        // An unusable configuration makes v2 unavailable instead of silently defaulting.
        foreach ([59, 604801, '1h'] as $invalid) {
            config(['delivery.test_resume_window_seconds.below_512_mib' => $invalid]);
            $this->assertError($this->issueResponse($f), 503, 'DELIVERY_UNAVAILABLE');
        }
        config(['delivery.test_resume_window_seconds.below_512_mib' => 30000]); // Longer than the 512 MiB tier.
        $this->assertError($this->issueResponse($f), 503, 'DELIVERY_UNAVAILABLE');
    }

    public function test_started_but_incomplete_authorizations_are_capped_per_grant_per_rolling_day(): void
    {
        config(['delivery.test_incomplete_authorizations_per_grant_24h' => 2]);
        $f = $this->ready();
        $bytes = $this->bytes();
        $start = now()->toImmutable();
        $first = $this->issue($f);
        $this->fetch($f, $first, 'bytes=0-0')->assertStatus(206)->streamedContent();
        $this->issue($f); // Issued but never started: does not count.
        $second = $this->issue($f);
        $this->fetch($f, $second, 'bytes=0-0')->assertStatus(206)->streamedContent();
        $this->travelTo($start->addSeconds(61));
        $this->assertError($this->issueResponse($f), 429, 'DELIVERY_INCOMPLETE_LIMIT');
        $this->assertDatabaseCount('test_delivery_authorizations', 3);
        // Completing one frees a slot: the cap is about abandoned transfers, not downloads.
        $this->assertStream($this->fetch($f, $second, 'bytes=1-'), 206, substr($bytes, 1));
        $third = $this->issue($f);
        $this->fetch($f, $third, 'bytes=0-0')->assertStatus(206)->streamedContent();
        $this->assertError($this->issueResponse($f), 429, 'DELIVERY_INCOMPLETE_LIMIT');
        // The window rolls: 24 h after the first start, that start no longer counts.
        $this->travelTo($start->addDay()->subSecond());
        $this->assertError($this->issueResponse($f), 429, 'DELIVERY_INCOMPLETE_LIMIT');
        $this->travelTo($start->addDay());
        $this->issue($f);
        $this->assertDatabaseCount('test_delivery_authorizations', 5);
    }

    public function test_the_documented_defaults_are_ten_incomplete_and_one_six_twenty_four_hours(): void
    {
        $this->assertSame(10, app(TestAccessPolicy::class)->incompleteCap());
        $this->assertSame(['below_512_mib' => 3600, 'from_512_mib' => 21600, 'from_4_gib' => 86400], app(TestAccessPolicy::class)->resumeWindows());
    }

    public function test_policy_withdrawal_control_block_and_policy_switch_refuse_transfers_like_redemptions(): void
    {
        $f = $this->ready();
        $unused = $this->issue($f);
        $used = $this->issue($f);
        $this->fetch($f, $used, 'bytes=0-9')->assertStatus(206)->streamedContent();
        $this->present($f, $unused)->assertStatus(303);
        $this->locations[$unused['authorizationId']] = $this->location($f, $unused);
        $prepares = $this->streams->transactionLevels;
        config(['delivery.test_access_enabled' => false]);
        foreach ([$unused, $used] as $authorization) {
            $this->assertError($this->fetch($f, $authorization, 'bytes=10-'), 503, 'DELIVERY_UNAVAILABLE');
        }
        // A v2 authorization is not served while the operator's policy is v1.
        F::configure();
        $this->assertError($this->fetch($f, $used, 'bytes=10-'), 503, 'DELIVERY_UNAVAILABLE');
        F::configureV2();
        app(ManageTestDeliveryControl::class)->handle($f['order']->public_id, true, 1, 'SYNTHETIC-RESUME-BLOCK');
        foreach ([$unused, $used] as $authorization) {
            $this->assertError($this->fetch($f, $authorization, 'bytes=10-'), 503, 'DELIVERY_UNAVAILABLE');
        }
        $this->assertSame($prepares, $this->streams->transactionLevels);
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertDatabaseCount('test_delivery_coverage', 1);
    }

    public function test_a_tampered_file_is_refused_before_any_byte_of_every_response(): void
    {
        $f = $this->ready();
        $bytes = $this->bytes();
        $path = Storage::disk('local')->path(GrantContract::sole()->storage_path);
        $mode = fileperms($path) & 0777;
        $unused = $this->issue($f);
        $used = $this->issue($f);
        $this->fetch($f, $used, 'bytes=0-9')->assertStatus(206)->streamedContent();
        // Change a byte outside every requested range: only whole-file verification can notice it.
        $changed = $bytes;
        $changed[strlen($bytes) - 1] = $changed[strlen($bytes) - 1] === 'X' ? 'Y' : 'X';
        chmod($path, 0600);
        file_put_contents($path, $changed);
        chmod($path, $mode);
        foreach ([$unused, $used] as $authorization) {
            foreach (['bytes=10-19', null] as $range) {
                $response = $this->fetch($f, $authorization, $range);
                $this->assertError($response, 503, 'DELIVERY_UNAVAILABLE');
                $this->assertStringNotContainsString(substr($bytes, 10, 10), $response->getContent());
            }
        }
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
        $this->assertDatabaseCount('test_delivery_coverage', 1);
        $this->assertSame(0, $this->audits('commerce.delivery.resumed'));
    }

    public function test_v1_authorizations_keep_the_v1_post_stream_and_never_redirect_or_resume(): void
    {
        F::configure();
        $f = $this->ready();
        $bytes = $this->bytes();
        $v1 = $this->issue($f);
        $this->assertSame(TestAccessPolicy::V1, TestDeliveryAuthorization::sole()->policy_version);
        $this->assertError($this->present($f, $v1, ['HTTP_RANGE' => 'bytes=0-9']), 416, 'INVALID_DELIVERY_REQUEST');
        $this->assertError($this->transfer($this->location($f, $v1)), 404, 'DELIVERY_NOT_FOUND');
        $pending = $this->issue($f);
        $stream = $this->present($f, $v1);
        $stream->assertOk()->assertHeaderMissing('ETag')->assertHeaderMissing('Accept-Ranges');
        $this->assertSame($bytes, $stream->streamedContent());
        $this->assertError($this->present($f, $v1), 409, 'DELIVERY_ATTEMPTED');
        $this->assertDatabaseCount('test_delivery_coverage', 0);
        // After the operator moves to v2, v1 history still verifies under v1, but v1 tokens are not served.
        F::configureV2();
        $this->assertError($this->present($f, $pending), 503, 'DELIVERY_UNAVAILABLE');
        $history = $this->get($this->url($f))->assertOk()->json('delivery.history');
        $this->assertSame(['unused', 'attempted'], array_column($history, 'status'));
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
    }

    public function test_stems_archive_resumes_with_exact_bytes_through_the_domain_commands(): void
    {
        $f = F::allRoles($this->gateway);
        $issued = app(IssueTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $f['grant']->public_id, 'stems_zip',
            (string) Str::uuid(), sessionSecret: F::SESSION_SECRET);
        $file = app(ReadTestDeliveryAuthorization::class)->forSession($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId,
            F::SESSION_SECRET)['target']['file'];
        $stems = MediaAsset::where('role', 'stems_zip')->where('sha256', $file['sha256'])->sole();
        $bytes = Storage::disk('local')->get($stems->storage_path);
        $this->assertSame($file['size_bytes'], strlen($bytes));
        $transfer = fn (string $header, ?string $sha = null) => app(TransferTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER,
            $issued->authorizationId, F::SESSION_SECRET, DeliveryByteRange::evaluate($header, null, strlen($bytes), $sha ?? hash('sha256', $bytes)));
        $read = function (string $header) use ($transfer): string {
            $out = '';
            $transfer($header)->writeTo(function (string $chunk) use (&$out): void {
                $out .= $chunk;
            });

            return $out;
        };
        $half = intdiv(strlen($bytes), 2);
        $this->assertSame(substr($bytes, $half), $read('bytes='.$half.'-'));
        $this->assertSame(0, $this->completions());
        $this->assertSame(substr($bytes, 0, $half), $read('bytes=0-'.($half - 1)));
        $this->assertSame(1, $this->completions());
        foreach ([['bytes=0-', 'redeemed'], ['bytes='.strlen($bytes).'-', 'redeemed'], ['bytes=1-', 'changed', str_repeat('0', 64)]] as $case) {
            try {
                $transfer($case[0], $case[2] ?? null)->close();
                $this->fail('A completed transfer admitted another response.');
            } catch (DeliveryException $error) {
                $this->assertSame($case[1], $error->reason);
            }
        }
        // A v2 authorization without the binding secret, or with another one, is unknown.
        foreach ([null, str_repeat('cd', 32)] as $secret) {
            try {
                app(TransferTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, $secret,
                    DeliveryByteRange::full(strlen($bytes), hash('sha256', $bytes)));
                $this->fail('A foreign session reached a v2 transfer.');
            } catch (DeliveryException $error) {
                $this->assertSame('not_found', $error->reason);
            }
        }
        // v2 issuance needs a session to bind to.
        try {
            app(IssueTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $f['grant']->public_id, 'stems_zip', (string) Str::uuid());
            $this->fail('A v2 authorization was issued without a session binding.');
        } catch (DeliveryException $error) {
            $this->assertSame('session_required', $error->reason);
        }
    }
}
