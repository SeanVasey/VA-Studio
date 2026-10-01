<?php

namespace Tests\Feature;

use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InquiryPrivacy;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** Synthetic inquiries exercise the real transport, session, publication and persistence boundaries. */
class CustomerInquiryHttpTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://audio.example.test/contact/inquiries';

    private string $csrf;

    private User $operator;

    private SiteRelease $release;

    private int $requestNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['app.debug' => false, 'app.url' => 'https://audio.example.test']);
        $this->operator = LicenseFixtures::admin();
        config([
            'inquiries.enabled' => true,
            'inquiries.privacy_notice' => 'SYNTHETIC TEST NOTICE: inquiry details are retained for a response.',
            'inquiries.retention_policy_reference' => 'SYNTHETIC-RETENTION-TEST-ONLY',
            'inquiries.operator_user_id' => $this->operator->id,
        ]);
        $this->release = $this->publishContact();
        $this->csrf = Str::random(40);
        $this->withSession(['_token' => $this->csrf]);
    }

    private function publishContact(bool $enabled = true): SiteRelease
    {
        $content = SiteContentSchema::forEditing(SiteContentSchema::defaults());
        $content['contact'] = $enabled ? [
            'title' => 'Synthetic contact', 'description' => 'Synthetic inquiry fixture.',
            'paragraphs' => ['Development records only.'], 'email' => 'operator@example.test',
        ] : null;
        $release = app(SiteContent::class)->create($content, 'Synthetic contact fixture', $this->operator);
        app(SiteContent::class)->publish($release->id, SitePublication::findOrFail(1)->revision, $this->operator);

        return $release;
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'name' => 'Synthetic Buyer', 'email' => 'synthetic-buyer@example.test',
            'subject' => 'Synthetic recording inquiry', 'message' => "Synthetic private inquiry.\nSecond line.",
            'website' => '', 'requestKey' => (string) Str::uuid(),
        ], $changes);
    }

    private function submit(array $payload, array $server = []): TestResponse
    {
        return $this->raw(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $server);
    }

    private function raw(string $body, array $server = [], string $url = self::URL, string $method = 'POST'): TestResponse
    {
        return $this->call($method, $url, [], [], [], array_replace([
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->csrf, 'HTTP_ORIGIN' => 'https://audio.example.test',
            'HTTP_SEC_FETCH_SITE' => 'same-origin', 'REMOTE_ADDR' => '198.51.100.'.(++$this->requestNumber),
        ], $server), $body);
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
        $response->assertHeaderMissing('ETag')->assertHeaderMissing('Last-Modified');
    }

    private function assertError(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code);
        $this->assertPrivate($response);
        $this->assertSame($status === 422 ? ['code', 'message', 'errors'] : ['code', 'message'], array_keys($response->json()));
        if ($status === 422) {
            $errors = $response->json('errors');
            $this->assertIsArray($errors);
            $this->assertNotEmpty($errors);
            $this->assertSame([], array_diff(array_keys($errors), ['name', 'email', 'subject', 'message', 'website', 'requestKey', 'form']));
        }
    }

    private function realCsrf(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    private function editorialHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }

    public function test_current_public_contact_exposes_only_enabled_and_exact_public_notice_setup_props(): void
    {
        $response = $this->get('https://audio.example.test/contact', $this->editorialHeaders())->assertOk();
        $response->assertJsonPath('props.contactInquiryEnabled', true)
            ->assertJsonPath('props.contactInquiryPrivacyNotice', config('inquiries.privacy_notice'))
            ->assertJsonPath('props.sitePreview', false)
            ->assertJsonPath('props.editorial.contactHref', 'mailto:operator%40example.test');
        $response->assertDontSee(config('inquiries.retention_policy_reference'), false)
            ->assertDontSee(hash('sha256', config('inquiries.privacy_notice')), false);
        foreach (['operatorId', 'operator_user_id', 'retentionReference', 'privacyHash', 'inquiry'] as $privateKey) {
            $this->assertArrayNotHasKey($privateKey, $response->json('props'));
        }
        $this->assertFalse(session()->has('_inquiry_owner'));
        $this->assertDatabaseCount('customer_inquiries', 0);
    }

    public function test_public_contact_props_and_submission_both_fail_closed_when_setup_or_persisted_operator_is_withdrawn(): void
    {
        config(['inquiries.enabled' => false]);
        $this->get('https://audio.example.test/contact', $this->editorialHeaders())->assertOk()
            ->assertJsonPath('props.contactInquiryEnabled', false)
            ->assertJsonPath('props.contactInquiryPrivacyNotice', null);
        $this->assertError($this->submit($this->payload()), 404, 'INQUIRY_UNAVAILABLE');

        config(['inquiries.enabled' => true]);
        User::whereKey($this->operator->id)->update(['is_admin' => false]);
        $this->get('https://audio.example.test/contact', $this->editorialHeaders())->assertOk()
            ->assertJsonPath('props.contactInquiryEnabled', false)
            ->assertJsonPath('props.contactInquiryPrivacyNotice', null);
        $this->assertError($this->submit($this->payload()), 404, 'INQUIRY_UNAVAILABLE');
        $this->assertDatabaseCount('customer_inquiries', 0);
    }

    public function test_private_contact_preview_cannot_project_intake_or_policy_props_even_when_public_setup_is_ready(): void
    {
        $draft = app(SiteContent::class)->create($this->release->content, 'Private contact preview fixture', $this->operator);
        $before = AuditEvent::count();
        $response = $this->actingAs($this->operator)->get(
            'https://audio.example.test/admin/site-releases/'.$draft->id.'/preview/contact', $this->editorialHeaders(),
        )->assertOk()->assertJsonPath('props.sitePreview', true);
        $this->assertPrivate($response);
        $this->assertFalse($response->json('props.contactInquiryEnabled') ?? false);
        $this->assertNull($response->json('props.contactInquiryPrivacyNotice'));
        $response->assertDontSee(config('inquiries.privacy_notice'), false)
            ->assertDontSee(config('inquiries.retention_policy_reference'), false);
        $this->assertSame($this->release->id, SitePublication::findOrFail(1)->active_release_id);
        $this->assertSame($before, AuditEvent::count());
        $this->assertDatabaseCount('customer_inquiries', 0);
    }

    public function test_save_is_durable_encrypted_audited_and_exposes_only_an_opaque_receipt_without_sending(): void
    {
        $payload = $this->payload();
        $before = AuditEvent::count();
        $response = $this->submit($payload)->assertCreated();
        $this->assertPrivate($response);
        $inquiry = CustomerInquiry::sole();
        $response->assertExactJson(['state' => 'saved', 'receipt' => $inquiry->public_id]);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $inquiry->public_id);
        $this->assertSame('new', $inquiry->state);
        $this->assertSame(0, $inquiry->version);
        $this->assertSame($this->release->id, $inquiry->site_release_id);
        $this->assertSame($this->release->content_hash, $inquiry->site_content_hash);
        $this->assertSame($this->operator->id, $inquiry->operator_user_id);
        $this->assertSame(config('inquiries.privacy_notice'), $inquiry->privacy_notice);
        $this->assertSame(hash('sha256', config('inquiries.privacy_notice')), $inquiry->privacy_notice_hash);
        $this->assertSame(config('inquiries.retention_policy_reference'), $inquiry->retention_policy_reference);
        foreach (['name', 'email', 'subject', 'message'] as $field) {
            $this->assertSame($payload[$field], $inquiry->payload[$field]);
            $response->assertDontSee($payload[$field], false);
            $this->assertStringNotContainsString($payload[$field], $inquiry->getRawOriginal('payload'));
        }
        $this->assertStringNotContainsString(config('inquiries.privacy_notice'), $inquiry->getRawOriginal('privacy_notice'));
        $this->assertSame($before + 1, AuditEvent::count());
        $audit = AuditEvent::orderByDesc('id')->firstOrFail();
        $this->assertSame(CustomerInquiry::class, $audit->subject_type);
        $this->assertSame($inquiry->id, $audit->subject_id);
        foreach (['name', 'email', 'subject', 'message', 'requestKey'] as $field) {
            $this->assertStringNotContainsString($payload[$field], json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR));
        }
        foreach (['owner_hash', 'payload_hash', 'privacy_notice_hash', 'site_content_hash', 'request_key'] as $field) {
            $response->assertDontSee($inquiry->{$field}, false);
        }
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_exact_replay_ignores_json_field_order_and_whitespace_but_changed_payload_conflicts(): void
    {
        $payload = $this->payload();
        $first = $this->submit($payload)->assertCreated();
        $retained = CustomerInquiry::sole()->getAttributes();
        $audits = AuditEvent::count();
        $body = " \n".json_encode(array_reverse($payload, true), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n ";
        $replay = $this->raw($body)->assertOk()->assertExactJson($first->json());
        $this->assertPrivate($replay);
        $this->assertError($this->submit(array_replace($payload, ['message' => 'Different synthetic private message.'])), 409, 'INQUIRY_REQUEST_CONFLICT');
        $this->assertSame($retained, CustomerInquiry::sole()->getAttributes());
        $this->assertSame($audits, AuditEvent::count());
        $this->assertDatabaseCount('customer_inquiries', 1);
    }

    public function test_lost_and_foreign_sessions_cannot_recover_a_previous_receipt_using_its_request_key(): void
    {
        $payload = $this->payload();
        $first = $this->submit($payload)->assertCreated()->json('receipt');
        $this->flushSession();
        $this->withSession(['_token' => $this->csrf]);
        $foreign = $this->submit($payload);
        $this->assertError($foreign, 409, 'INQUIRY_REQUEST_CONFLICT');
        $foreign->assertDontSee($first, false);
        $this->assertDatabaseCount('customer_inquiries', 1);
        $second = $this->submit($this->payload())->assertCreated()->json('receipt');
        $this->assertNotSame($first, $second);
        $this->flushSession();
        $this->withSession(['_token' => $this->csrf]);
        $this->assertError($this->submit($payload), 409, 'INQUIRY_REQUEST_CONFLICT');
        $third = $this->submit($this->payload())->assertCreated()->json('receipt');
        $this->assertNotContains($third, [$first, $second]);
        $this->assertCount(3, CustomerInquiry::query()->pluck('owner_hash')->unique());
        $this->assertDatabaseCount('customer_inquiries', 3);
    }

    public function test_authentication_context_changes_rotate_inquiry_ownership_even_when_returning_to_an_earlier_user(): void
    {
        $payload = $this->payload();
        $receipts = [$this->submit($payload)->assertCreated()->json('receipt')];
        $first = User::factory()->create();
        foreach ([$first, User::factory()->create(), $first] as $user) {
            $this->actingAs($user);
            $foreign = $this->submit($payload);
            $this->assertError($foreign, 409, 'INQUIRY_REQUEST_CONFLICT');
            $foreign->assertDontSee($receipts[0], false);
            $receipts[] = $this->submit($this->payload())->assertCreated()->json('receipt');
        }
        $this->assertCount(4, array_unique($receipts));
        $this->assertCount(4, CustomerInquiry::query()->pluck('owner_hash')->unique());
    }

    public function test_login_and_logout_without_an_intervening_inquiry_cannot_revive_guest_ownership(): void
    {
        $payload = $this->payload();
        $first = $this->submit($payload)->assertCreated()->json('receipt');
        Auth::login(User::factory()->create());
        Auth::logout();
        $foreign = $this->submit($payload);
        $this->assertError($foreign, 409, 'INQUIRY_REQUEST_CONFLICT');
        $foreign->assertDontSee($first, false);
        $this->assertNotSame($first, $this->submit($this->payload())->assertCreated()->json('receipt'));
        $this->assertDatabaseCount('customer_inquiries', 2);
    }

    public static function invalidFields(): array
    {
        return [
            'missing name' => ['name', null, true], 'empty name' => ['name', '', false],
            'nonstring name' => ['name', 123, false], 'name over Unicode limit' => ['name', str_repeat('é', 121), false],
            'name control' => ['name', "Private\0Name", false],
            'email too long' => ['email', str_repeat('a', 243).'@example.test', false],
            'malformed email' => ['email', 'private@example.test\r\nBcc:other@example.test', false],
            'email object' => ['email', ['address' => 'private@example.test'], false],
            'missing subject' => ['subject', null, true], 'subject too long' => ['subject', str_repeat('界', 161), false],
            'subject boolean' => ['subject', true, false], 'empty subject' => ['subject', '', false],
            'message missing' => ['message', null, true], 'message too long' => ['message', str_repeat('a', 8001), false],
            'empty message' => ['message', '', false], 'message array' => ['message', ['Private'], false],
            'message invalid control' => ['message', "Private\x01Message", false],
            'honeypot filled' => ['website', 'https://private.example.test', false], 'honeypot null' => ['website', null, false],
            'honeypot missing' => ['website', null, true],
            'request key invalid' => ['requestKey', 'not-a-uuid', false], 'request key missing' => ['requestKey', null, true],
            'request key uppercase' => ['requestKey', 'A939278E-7DDB-4BA6-8B33-5ADB441B05DD', false],
            'request key wrong version' => ['requestKey', 'a939278e-7ddb-1ba6-8b33-5adb441b05dd', false],
            'request key wrong variant' => ['requestKey', 'a939278e-7ddb-4ba6-7b33-5adb441b05dd', false],
            'request key padded' => ['requestKey', ' a939278e-7ddb-4ba6-8b33-5adb441b05dd ', false],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_fields_have_strict_types_bounds_and_fixed_errors_without_retaining_invalid_submissions(string $field, mixed $value, bool $missing): void
    {
        $payload = $this->payload([$field => $value]);
        if ($missing) {
            unset($payload[$field]);
        }
        $before = AuditEvent::count();
        $response = $this->submit($payload);
        $this->assertError($response, 422, 'INQUIRY_VALIDATION_FAILED');
        $this->assertTrue(array_key_exists($field, $response->json('errors')) || array_key_exists('form', $response->json('errors')));
        $response->assertDontSee('Private', false)->assertDontSee('private@example.test', false);
        $this->assertDatabaseCount('customer_inquiries', 0);
        $this->assertSame($before, AuditEvent::count());
    }

    public function test_exact_unicode_field_limits_and_exact_body_byte_limit_are_accepted(): void
    {
        $email = str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 61);
        $this->assertSame(254, strlen($email));
        $payload = $this->payload(['name' => str_repeat('é', 120), 'email' => $email, 'subject' => str_repeat('界', 160), 'message' => str_repeat('x', 8000)]);
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertLessThan(16384, strlen($body));
        $response = $this->raw(str_pad($body, 16384, ' '))->assertCreated();
        $this->assertPrivate($response);
        $this->assertSame($payload['name'], CustomerInquiry::sole()->payload['name']);
        $this->assertSame($payload['subject'], CustomerInquiry::sole()->payload['subject']);
        $this->assertSame($payload['message'], CustomerInquiry::sole()->payload['message']);
    }

    public function test_message_bounds_count_unicode_characters_and_literal_markup_is_retained_as_text(): void
    {
        $payload = $this->payload(['name' => '<Synthetic Buyer>', 'subject' => 'Literal <fixture>', 'message' => str_repeat('é', 8000)]);
        $this->assertLessThanOrEqual(16384, strlen(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        $response = $this->submit($payload)->assertCreated();
        $this->assertSame($payload['message'], CustomerInquiry::sole()->payload['message']);
        $this->assertSame($payload['name'], CustomerInquiry::sole()->payload['name']);
        $response->assertDontSee($payload['name'], false)->assertDontSee($payload['subject'], false);
    }

    public function test_validation_errors_are_identical_for_different_private_invalid_values(): void
    {
        $first = $this->submit($this->payload(['email' => 'first-private-value']));
        $second = $this->submit($this->payload(['email' => 'second-private-value']));
        $this->assertError($first, 422, 'INQUIRY_VALIDATION_FAILED');
        $this->assertError($second, 422, 'INQUIRY_VALIDATION_FAILED');
        $this->assertSame($first->json(), $second->json());
        $first->assertDontSee('first-private-value', false);
        $second->assertDontSee('second-private-value', false);
        $this->assertDatabaseCount('customer_inquiries', 0);
    }

    public static function invalidRawBodies(): array
    {
        return [
            'invalid JSON' => ['{broken'], 'empty body' => [''], 'array root' => ['[]'],
            'null root' => ['null'], 'scalar root' => ['"private string"'],
            'two JSON objects' => ['{}{}'], 'UTF8 failure' => ["{\"name\":\"\xFF\"}"],
            'duplicate fields' => ['{"name":"Private","name":"Buyer"}'],
            'escaped duplicate fields' => ['{"name":"Private","\\u006eame":"Buyer"}'],
            'duplicate nested fields' => ['{"name":{"nested":"private","nested":"other"}}'],
            'trailing comma' => ['{"name":"Private",}'],
        ];
    }

    #[DataProvider('invalidRawBodies')]
    public function test_malformed_json_and_duplicate_fields_are_rejected_privately(string $body): void
    {
        $before = AuditEvent::count();
        $this->assertError($this->raw($body), 422, 'INQUIRY_VALIDATION_FAILED');
        $this->assertDatabaseCount('customer_inquiries', 0);
        $this->assertSame($before, AuditEvent::count());
    }

    public function test_complete_duplicate_and_extra_keys_are_rejected_including_unicode_escaped_duplicates(): void
    {
        $payload = $this->payload();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['name', '\\u006eame'] as $key) {
            $body = '{"'.$key.'":"Synthetic Buyer",'.substr($encoded, 1);
            $this->assertError($this->raw($body), 422, 'INQUIRY_VALIDATION_FAILED');
        }
        foreach (['owner_hash', 'state', 'consent', '_token', '_method'] as $key) {
            $this->assertError($this->submit($payload + [$key => 'private-forged-value']), 422, 'INQUIRY_VALIDATION_FAILED');
        }
        $escapedOverride = substr($encoded, 0, -1).',"\\u005fmethod":"INVALID_PRIVATE_METHOD"}';
        $this->assertError($this->raw($escapedOverride), 422, 'INQUIRY_VALIDATION_FAILED');
        $this->assertDatabaseCount('customer_inquiries', 0);
    }

    public function test_raw_queries_media_encoding_and_method_overrides_are_rejected_before_session_work(): void
    {
        $this->flushSession();
        $body = json_encode($this->payload(), JSON_THROW_ON_ERROR);
        foreach (['?ignored=1', '?&&', '?[]=discarded'] as $query) {
            $this->assertError($this->raw($body, [], self::URL.$query), 422, 'INQUIRY_VALIDATION_FAILED');
        }
        foreach (['text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=private', 'application/json; charset=iso-8859-1'] as $type) {
            $this->assertError($this->raw($body, ['CONTENT_TYPE' => $type]), 415, 'INQUIRY_MEDIA_TYPE');
        }
        $this->assertError($this->raw($body, ['HTTP_CONTENT_ENCODING' => 'gzip']), 415, 'INQUIRY_MEDIA_TYPE');
        $this->assertError($this->raw($body, ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'PUT']), 405, 'INQUIRY_INVALID_METHOD');
        $this->assertError($this->raw($body, ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST']), 405, 'INQUIRY_INVALID_METHOD');
        foreach (['HTTP_RANGE' => 'bytes=0-10', 'HTTP_IF_RANGE' => 'private-etag'] as $header => $value) {
            $this->assertError($this->raw($body, [$header => $value]), 422, 'INQUIRY_VALIDATION_FAILED');
        }
        $this->assertNull(session('_inquiry_owner'));
        $this->assertDatabaseCount('customer_inquiries', 0);
    }

    public function test_only_post_is_supported_and_unsupported_methods_are_private_even_without_json_accept(): void
    {
        foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $response = $this->raw('', ['HTTP_ACCEPT' => 'text/html'], method: $method);
            $response->assertStatus(405)->assertHeader('Allow', 'POST');
            $this->assertPrivate($response);
            if ($method !== 'HEAD') {
                $response->assertJsonPath('code', 'INQUIRY_INVALID_METHOD');
            }
        }
        $this->assertDatabaseCount('customer_inquiries', 0);
    }

    public static function dishonestContentLengths(): array
    {
        return [[null], ['0'], ['1']];
    }

    #[DataProvider('dishonestContentLengths')]
    public function test_raw_stream_is_bounded_even_when_content_length_is_absent_or_dishonest(?string $length): void
    {
        $input = fopen('php://temp', 'w+b');
        fwrite($input, str_repeat('x', 32768));
        rewind($input);
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($length !== null) {
            $server['CONTENT_LENGTH'] = $length;
        }
        $request = Request::create(self::URL, 'POST', [], [], [], $server, $input);
        try {
            $response = app(InquiryPrivacy::class)->handle($request, fn () => throw new RuntimeException('Session/controller must not execute.'));
            $this->assertError(TestResponse::fromBaseResponse($response), 413, 'INQUIRY_TOO_LARGE');
            $this->assertSame(16385, ftell($input));
            $this->assertFalse($request->attributes->has('_inquiry_body'));
            $this->assertFalse($request->hasSession());
        } finally {
            fclose($input);
        }
        $this->assertDatabaseCount('customer_inquiries', 0);
    }

    public function test_invalid_or_excessive_declared_lengths_and_one_byte_over_body_limit_are_private(): void
    {
        foreach (['-1', '1.5', 'private-length', '16385'] as $length) {
            $this->assertError($this->raw('{}', ['CONTENT_LENGTH' => $length]), 413, 'INQUIRY_TOO_LARGE');
        }
        $this->assertError($this->raw(str_repeat(' ', 16385), ['CONTENT_LENGTH' => '0']), 413, 'INQUIRY_TOO_LARGE');
        $this->assertDatabaseCount('customer_inquiries', 0);
    }

    public static function untrustedOrigins(): array
    {
        return [
            'foreign host' => [['HTTP_ORIGIN' => 'https://foreign.example.test']],
            'scheme downgrade' => [['HTTP_ORIGIN' => 'http://audio.example.test']],
            'foreign port' => [['HTTP_ORIGIN' => 'https://audio.example.test:444']],
            'null origin' => [['HTTP_ORIGIN' => 'null']],
            'two origins' => [['HTTP_ORIGIN' => 'https://audio.example.test https://foreign.example.test']],
            'origin with credentials' => [['HTTP_ORIGIN' => 'https://private@audio.example.test']],
            'origin with path' => [['HTTP_ORIGIN' => 'https://audio.example.test/private']],
            'cross site fetch' => [['HTTP_SEC_FETCH_SITE' => 'cross-site']],
        ];
    }

    #[DataProvider('untrustedOrigins')]
    public function test_cross_origin_requests_are_denied_without_creating_a_session_owner(array $server): void
    {
        $this->flushSession();
        $this->assertError($this->submit($this->payload(), $server), 403, 'INQUIRY_REQUEST_DENIED');
        $this->assertNull(session('_inquiry_owner'));
        $this->assertDatabaseCount('customer_inquiries', 0);
    }

    public function test_real_csrf_rejects_missing_or_wrong_tokens_and_accepts_matching_session_token_under_debug(): void
    {
        $this->realCsrf();
        config(['app.debug' => true]);
        foreach (['', 'private-wrong-token'] as $token) {
            // Laravel accepts Sec-Fetch-Site: same-origin itself as forgery protection; omit it to exercise tokens.
            $response = $this->submit($this->payload(), ['HTTP_X_CSRF_TOKEN' => $token, 'HTTP_SEC_FETCH_SITE' => '']);
            $this->assertError($response, 419, 'INQUIRY_REQUEST_EXPIRED');
            $response->assertDontSee('private-wrong-token', false)->assertDontSee('trace', false);
        }
        $this->assertDatabaseCount('customer_inquiries', 0);
        $this->submit($this->payload(), ['HTTP_SEC_FETCH_SITE' => ''])->assertCreated();
    }

    public function test_same_origin_token_fallback_accepts_clients_without_fetch_metadata_headers(): void
    {
        $this->realCsrf();
        $response = $this->submit($this->payload(), ['HTTP_ORIGIN' => null, 'HTTP_SEC_FETCH_SITE' => null])->assertCreated();
        $this->assertPrivate($response);
        $this->assertDatabaseCount('customer_inquiries', 1);
    }

    public function test_enabled_gate_and_required_policy_configuration_fail_closed_without_changing_retained_records(): void
    {
        $payload = $this->payload();
        $this->submit($payload)->assertCreated();
        $retained = CustomerInquiry::sole()->getAttributes();
        $settings = [
            ['inquiries.enabled', false, 404], ['inquiries.privacy_notice', '', 404],
            ['inquiries.retention_policy_reference', '', 404], ['inquiries.operator_user_id', null, 404],
            ['inquiries.operator_user_id', 999999, 404],
            ['inquiries.enabled', 'true', 404], ['inquiries.privacy_notice', ['unexpected' => 'private-value'], 404],
            ['inquiries.retention_policy_reference', '<invalid reference>', 404],
        ];
        foreach ($settings as [$key, $value, $status]) {
            $original = config($key);
            config([$key => $value]);
            $this->assertError($this->submit($payload), $status, 'INQUIRY_UNAVAILABLE');
            config([$key => $original]);
        }
        $this->assertSame($retained, CustomerInquiry::sole()->getAttributes());
        $this->submit($payload)->assertOk();
    }

    public function test_a_private_contact_draft_cannot_admit_inquiries_when_the_active_release_has_no_contact(): void
    {
        $site = app(SiteContent::class);
        $baseline = SiteRelease::where('label', 'Original site content')->sole();
        $site->rollback($baseline->id, SitePublication::findOrFail(1)->revision, $this->operator);
        $site->create($this->release->content, 'Unpublished synthetic contact', $this->operator);
        $this->assertError($this->submit($this->payload()), 404, 'INQUIRY_UNAVAILABLE');
        $this->assertDatabaseCount('customer_inquiries', 0);
        $this->assertSame($baseline->id, SitePublication::findOrFail(1)->active_release_id);
    }

    public function test_current_contact_withdrawal_denies_new_and_replayed_submissions_and_preserves_the_original_evidence(): void
    {
        $payload = $this->payload();
        $this->submit($payload)->assertCreated();
        $retained = CustomerInquiry::sole()->getAttributes();
        $this->publishContact(false);
        $this->assertError($this->submit($payload), 404, 'INQUIRY_UNAVAILABLE');
        $this->assertError($this->submit($this->payload()), 404, 'INQUIRY_UNAVAILABLE');
        $this->assertSame($retained, CustomerInquiry::sole()->getAttributes());
        $this->assertDatabaseCount('customer_inquiries', 1);
    }

    public static function withdrawnOperatorPrivileges(): array
    {
        return [['is_admin', false], ['email_verified_at', null]];
    }

    #[DataProvider('withdrawnOperatorPrivileges')]
    public function test_operator_privilege_withdrawal_is_rechecked_from_persistence_for_new_and_replayed_submissions(string $field, mixed $value): void
    {
        $payload = $this->payload();
        $this->submit($payload)->assertCreated();
        $retained = CustomerInquiry::sole()->getAttributes();
        User::whereKey($this->operator->id)->update([$field => $value]);
        $this->assertError($this->submit($payload), 404, 'INQUIRY_UNAVAILABLE');
        $this->assertError($this->submit($this->payload()), 404, 'INQUIRY_UNAVAILABLE');
        $this->assertSame($retained, CustomerInquiry::sole()->getAttributes());
        $this->assertDatabaseCount('customer_inquiries', 1);
    }

    public function test_required_operator_mfa_withdrawal_blocks_submissions_without_losing_the_receipt(): void
    {
        $payload = $this->payload();
        $receipt = $this->submit($payload)->assertCreated()->json('receipt');
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $this->assertError($this->submit($payload), 404, 'INQUIRY_UNAVAILABLE');
        $this->assertError($this->submit($this->payload()), 404, 'INQUIRY_UNAVAILABLE');
        $this->assertSame($receipt, CustomerInquiry::sole()->public_id);
    }

    public function test_site_and_policy_changes_never_rewrite_an_exact_replay_snapshot(): void
    {
        $payload = $this->payload();
        $first = $this->submit($payload)->assertCreated();
        $retained = CustomerInquiry::sole()->getAttributes();
        config(['inquiries.privacy_notice' => 'SYNTHETIC NEW NOTICE', 'inquiries.retention_policy_reference' => 'SYNTHETIC-NEW-REFERENCE']);
        $this->publishContact();
        $audits = AuditEvent::count();
        $this->submit($payload)->assertOk()->assertExactJson($first->json());
        $this->assertSame($retained, CustomerInquiry::sole()->getAttributes());
        $this->assertSame($audits, AuditEvent::count());
    }

    public function test_audit_failure_rolls_back_the_save_returns_truthful_generic_failure_and_allows_retry(): void
    {
        $payload = $this->payload();
        $before = AuditEvent::count();
        $marker = 'private-buyer@example.test secret-inquiry exception';
        config(['app.debug' => true]);
        Log::spy();
        AuditEvent::creating(function (AuditEvent $audit) use ($marker): void {
            if ($audit->subject_type === CustomerInquiry::class) {
                throw new RuntimeException($marker);
            }
        });
        try {
            $response = $this->submit($payload);
            $this->assertError($response, 503, 'INQUIRY_UNAVAILABLE');
            $response->assertDontSee($marker, false)->assertDontSee('RuntimeException', false)->assertDontSee('trace', false);
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('customer_inquiries', 0);
        $this->assertSame($before, AuditEvent::count());
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => is_string($message)
            && $context === ['exception_class' => RuntimeException::class])->once();
        $this->submit($payload)->assertCreated();
        $this->assertDatabaseCount('customer_inquiries', 1);
        $this->assertSame($before + 1, AuditEvent::count());
    }

    public function test_database_insert_failure_returns_no_receipt_or_private_logs_and_can_retry(): void
    {
        $payload = $this->payload();
        config(['app.debug' => true]);
        Log::spy();
        CustomerInquiry::creating(fn () => throw new RuntimeException('synthetic-buyer@example.test private persistence detail'));
        try {
            $response = $this->submit($payload);
            $this->assertError($response, 503, 'INQUIRY_UNAVAILABLE');
            $response->assertDontSee($payload['email'], false)->assertDontSee('private persistence detail', false);
            $this->assertDatabaseCount('customer_inquiries', 0);
        } finally {
            CustomerInquiry::flushEventListeners();
            CustomerInquiry::clearBootedModels();
        }
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => is_string($message)
            && $context === ['exception_class' => RuntimeException::class])->once();
        $this->submit($payload)->assertCreated();
        $this->assertDatabaseCount('customer_inquiries', 1);
    }

    public function test_ip_limiter_survives_session_rotation_and_ignores_forged_forwarded_addresses(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.61'];
        for ($i = 0; $i < 5; $i++) {
            $this->flushSession();
            $this->withSession(['_token' => $this->csrf]);
            $this->assertError($this->submit($this->payload(['email' => 'invalid']), $server + ['HTTP_X_FORWARDED_FOR' => '192.0.2.'.($i + 1)]), 422, 'INQUIRY_VALIDATION_FAILED');
        }
        $response = $this->submit($this->payload(), $server + ['HTTP_X_FORWARDED_FOR' => '192.0.2.99']);
        $this->assertError($response, 429, 'INQUIRY_RATE_LIMITED');
        $response->assertHeader('Retry-After');
        $this->submit($this->payload(), ['REMOTE_ADDR' => '203.0.113.62'])->assertCreated();
        $this->assertDatabaseCount('customer_inquiries', 1);
    }

    public function test_hourly_ip_budget_remains_after_the_minute_window_resets(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.71'];
        for ($minute = 0; $minute < 4; $minute++) {
            for ($i = 0; $i < 5; $i++) {
                $this->assertError($this->submit($this->payload(['email' => 'invalid']), $server), 422, 'INQUIRY_VALIDATION_FAILED');
            }
            $this->travel(61)->seconds();
        }
        $this->assertError($this->submit($this->payload(), $server), 429, 'INQUIRY_RATE_LIMITED');
        $this->assertDatabaseCount('customer_inquiries', 0);
    }

    public static function loggerFailures(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('loggerFailures')]
    public function test_session_lock_timeout_is_generic_private_and_happens_before_persistence(bool $brokenLogger): void
    {
        config(['app.debug' => true]);
        Log::spy();
        if ($brokenLogger) {
            Log::shouldReceive('error')->andThrow(new RuntimeException('private logger failure'));
        }
        $store = new class extends ArrayStore
        {
            public array $requestedLocks = [];

            public function lock($name, $seconds = 0, $owner = null)
            {
                $this->requestedLocks[] = [$name, $seconds];

                return new class($this, $name, $seconds, $owner) extends ArrayLock
                {
                    public function block($seconds, $callback = null)
                    {
                        throw new LockTimeoutException('private inquiry session lock detail');
                    }
                };
            }
        };
        Cache::extend('inquiry-timeout', fn () => new Repository($store));
        config(['cache.stores.inquiry-timeout' => ['driver' => 'inquiry-timeout'], 'session.block_store' => 'inquiry-timeout']);
        $response = $this->submit($this->payload());
        $this->assertError($response, 503, 'INQUIRY_UNAVAILABLE');
        $response->assertDontSee('private inquiry session lock detail', false)->assertDontSee('private logger failure', false);
        $this->assertCount(1, $store->requestedLocks);
        $this->assertStringStartsWith('session:', $store->requestedLocks[0][0]);
        $this->assertDatabaseCount('customer_inquiries', 0);
    }
}
