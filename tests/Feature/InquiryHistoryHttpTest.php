<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\InquiryConversationFixtures as Fixture;
use Tests\TestCase;

class InquiryHistoryHttpTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;
    private const URL = 'https://audio.example.test/contact/inquiries/history';

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->fakePrivateMediaStorage();
        config(['app.debug' => true, 'app.url' => 'https://audio.example.test']);
        $secret = str_repeat('a', 64);
        $owner = hash_hmac('sha256', "vasey-inquiry-owner-v1\0guest\0".$secret, config('app.key'));
        $this->fixture = Fixture::create($owner);
        $this->withSession(['_token' => Str::random(40), '_inquiry_owner' => ['context' => 'guest', 'secret' => $secret]]);
    }

    public function test_owner_history_is_private_and_disabled_intake_does_not_admit_new_work(): void
    {
        config(['inquiries.enabled' => false]);
        $before = DB::table('audit_events')->count();
        $read = $this->raw()->assertOk()->assertExactJson(['history' => [
            'inquiryHistorySchema' => 1, 'inquiries' => [[
                'receipt' => $this->fixture['inquiry']->public_id, 'subject' => $this->fixture['body']['subject'],
                'state' => 'new', 'createdAt' => $this->fixture['inquiry']->created_at->utc()->toIso8601ZuluString(),
            ]], 'limit' => 20, 'nextCursor' => null,
        ]]);
        $this->private($read);
        $this->raw(url: self::URL.'/before/'.$this->fixture['inquiry']->public_id)->assertOk()->assertJsonPath('history.inquiries', []);
        $this->assertDatabaseCount('customer_inquiries', 1); $this->assertDatabaseCount('inquiry_messages', 0);
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_foreign_lost_and_authentication_rotated_sessions_have_no_rows_or_cursor_oracle(): void
    {
        foreach ([null, ['context' => 'guest', 'secret' => str_repeat('b', 64)], ['context' => 'user:999', 'secret' => str_repeat('a', 64)]] as $owner) {
            $this->withSession(['_inquiry_owner' => $owner]);
            $read = $this->raw()->assertOk()->assertJsonPath('history.inquiries', [])->assertJsonPath('history.nextCursor', null);
            $this->private($read);
            $foreign = $this->raw(url: self::URL.'/before/'.$this->fixture['inquiry']->public_id)->assertStatus(422);
            $unknown = $this->raw(url: self::URL.'/before/'.Str::uuid())->assertStatus(422);
            $this->assertSame($unknown->getContent(), $foreign->getContent()); $this->private($foreign);
            $read->assertDontSee($this->fixture['body']['subject'], false);
        }
        $this->actingAs(User::factory()->create(['email' => $this->fixture['body']['email']]));
        $this->raw()->assertOk()->assertJsonPath('history.inquiries', []);
    }

    #[DataProvider('invalidTransport')]
    public function test_strict_private_transport_never_reaches_the_history_reader(string $method, string $body, array $server, string $suffix, int $status): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void { $queries[] = $query->sql; });
        $read = $this->raw($method, $body, $server, self::URL.$suffix)->assertStatus($status);
        $this->private($read); $read->assertDontSee($this->fixture['body']['subject'], false);
        $this->assertSame([], array_values(array_filter($queries, fn ($query) => str_contains($query, 'customer_inquiries'))));
        $this->assertDatabaseCount('customer_inquiries', 1); $this->assertDatabaseCount('inquiry_messages', 0);
    }

    public static function invalidTransport(): array
    {
        return [
            'post' => ['POST', '{}', [], '', 405], 'head' => ['HEAD', '', [], '', 405], 'options' => ['OPTIONS', '', [], '', 405],
            'body' => ['GET', 'x', [], '', 422], 'query' => ['GET', '', [], '?before=private', 422],
            'discarded query' => ['GET', '', [], '?&&', 422],
            'range' => ['GET', '', ['HTTP_RANGE' => 'bytes=0-9'], '', 422],
            'if range' => ['GET', '', ['HTTP_IF_RANGE' => 'private'], '', 422],
            'override' => ['GET', '', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST'], '', 422],
            'cross origin' => ['GET', '', ['HTTP_ORIGIN' => 'https://foreign.example'], '', 403],
            'cross site' => ['GET', '', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], '', 403],
        ];
    }

    public function test_history_and_conversation_share_the_existing_read_rate_budget(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->raw()->assertOk();
            $this->raw(url: 'https://audio.example.test/contact/inquiries/'.$this->fixture['inquiry']->public_id.'/conversation')->assertOk();
        }
        $this->private($this->raw()->assertStatus(429));
    }

    public function test_unexpected_failures_never_expose_private_exception_arguments_under_debug(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'customer_inquiries')) { throw new RuntimeException('PRIVATE-SUBJECT /private/path'); }
        });
        Log::spy();
        $read = $this->raw()->assertStatus(503); $this->private($read);
        $read->assertDontSee('PRIVATE-SUBJECT', false)->assertDontSee('/private/path', false)->assertDontSee('RuntimeException', false);
        Log::shouldHaveReceived('error')->with('Inquiry history failed.', ['exception_class' => RuntimeException::class])->once();
    }

    private function raw(string $method = 'GET', string $body = '', array $server = [], ?string $url = null): TestResponse
    {
        return $this->call($method, $url ?? self::URL, [], [], [], array_replace([
            'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'https://audio.example.test', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], $server), $body);
    }

    private function private(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('ETag')->assertHeaderMissing('Last-Modified');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
    }
}
