<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Inquiries\InquiryConversation;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InquiryConversationFixtures as Fixture;
use Tests\TestCase;

class InquiryConversationHttpTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private string $url;

    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.debug' => true, 'app.url' => 'https://audio.example.test']);
        $secret = str_repeat('a', 64);
        $owner = hash_hmac('sha256', "vasey-inquiry-owner-v1\0guest\0".$secret, config('app.key'));
        $this->fixture = Fixture::create($owner);
        $this->csrf = Str::random(40);
        $this->withSession(['_token' => $this->csrf, '_inquiry_owner' => ['context' => 'guest', 'secret' => $secret]]);
        $this->url = 'https://audio.example.test/contact/inquiries/'.$this->fixture['inquiry']->public_id.'/conversation';
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    public function test_real_owner_transport_reads_staff_reply_saves_followup_replays_and_reads_archived_history(): void
    {
        $this->raw('POST', json_encode($this->fixture['body']), url: 'https://audio.example.test/contact/inquiries')->assertOk();
        $first = $this->raw('GET');
        $first->assertOk()->assertJsonPath('messages', [])->assertJsonPath('canReply', true);
        $this->private($first);
        app(InquiryConversation::class)->reply($this->fixture['inquiry']->id, Fixture::message('Synthetic staff response'), $this->fixture['actor']);
        $this->raw('GET')->assertOk()->assertJsonPath('messages.0.message', 'Synthetic staff response');
        $body = json_encode(Fixture::message('Synthetic private follow-up'));
        $saved = $this->raw('POST', $body)->assertCreated();
        $this->private($saved);
        $this->raw('POST', $body)->assertOk()->assertExactJson($saved->json());
        app(InquiryAdministration::class)->transition($this->fixture['inquiry']->id, 'archived', 0, $this->fixture['actor']);
        $this->raw('GET')->assertOk()->assertJsonPath('state', 'archived')->assertJsonPath('canReply', false)->assertJsonCount(2, 'messages');
        $this->raw('POST', $body)->assertOk()->assertExactJson($saved->json());
        $this->raw('POST', json_encode(Fixture::message()))->assertConflict();
        $this->assertDatabaseCount('inquiry_messages', 2);
    }

    public function test_foreign_lost_and_context_rotated_sessions_cannot_read_a_known_receipt(): void
    {
        foreach ([null, ['context' => 'guest', 'secret' => str_repeat('b', 64)], ['context' => 'user:999', 'secret' => str_repeat('a', 64)]] as $owner) {
            $this->withSession(['_inquiry_owner' => $owner]);
            $read = $this->raw('GET')->assertNotFound();
            $this->private($read);
            $read->assertDontSee($this->fixture['body']['message'], false);
            $this->raw('POST', json_encode(Fixture::message()))->assertNotFound();
        }
        $this->assertDatabaseCount('inquiry_messages', 0);
    }

    #[DataProvider('badTransport')]
    public function test_early_transport_failures_are_private_and_never_append(string $method, string $body, array $server, string $suffix, int $status): void
    {
        $response = $this->raw($method, $body, $server, $this->url.$suffix)->assertStatus($status);
        $this->private($response);
        $response->assertDontSee('Synthetic initial message', false);
        $this->assertDatabaseCount('inquiry_messages', 0);
    }

    public static function badTransport(): array
    {
        $body = json_encode(['message' => 'private', 'requestKey' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);

        return [
            'csrf' => ['POST', $body, ['HTTP_X_CSRF_TOKEN' => 'wrong', 'HTTP_SEC_FETCH_SITE' => ''], '', 419],
            'origin' => ['POST', $body, ['HTTP_ORIGIN' => 'https://foreign.example'], '', 403],
            'read origin' => ['GET', '', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], '', 403],
            'query' => ['GET', '', [], '?owner=private', 422],
            'get body' => ['GET', 'private', [], '', 422],
            'range' => ['GET', '', ['HTTP_RANGE' => 'bytes=0-20'], '', 422],
            'method' => ['DELETE', '', [], '', 405],
            'override' => ['POST', $body, ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE'], '', 405],
            'media' => ['POST', $body, ['CONTENT_TYPE' => 'text/plain'], '', 415],
            'size' => ['POST', str_repeat('a', 16385), [], '', 413],
            'duplicate' => ['POST', '{"message":"x","message":"y","requestKey":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"}', [], '', 422],
            'extra' => ['POST', '{"message":"x","requestKey":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa","owner":"fake"}', [], '', 422],
        ];
    }

    private function raw(string $method, string $body = '', array $server = [], ?string $url = null): TestResponse
    {
        return $this->call($method, $url ?? $this->url, [], [], [], array_replace([
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf,
            'HTTP_ORIGIN' => 'https://audio.example.test', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], $server), $body);
    }

    private function private(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
        $response->assertHeaderMissing('ETag')->assertHeaderMissing('Last-Modified');
    }
}
