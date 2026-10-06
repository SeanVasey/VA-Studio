<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Inquiries\InquiryConversation;
use App\Domain\Inquiries\InquiryException;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\ReadOwnedInquiries;
use App\Domain\Inquiries\SubmitInquiry;
use App\Domain\SiteBuilder\SiteContent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InquiryConversationFixtures as Fixture;
use Tests\TestCase;

class InquiryHistoryTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->utc()->startOfSecond());
        $this->fixture = Fixture::create();
    }

    public function test_original_subject_state_and_time_are_minimal_read_only_summaries(): void
    {
        $inquiry = $this->fixture['inquiry'];
        app(InquiryConversation::class)->reply($inquiry->id, Fixture::message('Private staff message'), $this->fixture['actor']);
        app(InquiryAdministration::class)->transition($inquiry->id, 'read', 0, $this->fixture['actor']);
        $before = $this->retained();
        $history = $this->read();
        $this->assertSame(['inquiryHistorySchema' => 1, 'inquiries' => [[
            'receipt' => $inquiry->public_id, 'subject' => $this->fixture['body']['subject'], 'state' => 'read',
            'createdAt' => $inquiry->created_at->utc()->toIso8601ZuluString(),
        ]], 'limit' => 20, 'nextCursor' => null], $history);
        foreach (['Private staff message', $this->fixture['body']['message'], $this->fixture['body']['email'], Fixture::OWNER,
            $this->fixture['body']['requestKey'], $this->fixture['body']['noticeToken']] as $private) {
            $this->assertStringNotContainsString($private, json_encode($history, JSON_THROW_ON_ERROR));
        }
        $this->assertSame($before, $this->retained());
    }

    public function test_three_fresh_pages_filter_owners_before_the_cursor_and_preserve_insertion_order(): void
    {
        $owned = [$this->fixture['inquiry']->public_id];
        for ($i = 1; $i <= 40; $i++) {
            $this->add('Foreign subject '.$i, str_repeat('b', 64));
            $owned[] = $this->add('Owned subject '.$i);
        }
        $first = $this->read(); $second = $this->read($first['nextCursor']); $third = $this->read($second['nextCursor']);
        $this->assertCount(20, $first['inquiries']); $this->assertCount(20, $second['inquiries']); $this->assertCount(1, $third['inquiries']);
        $this->assertSame(array_reverse($owned), array_column([...$first['inquiries'], ...$second['inquiries'], ...$third['inquiries']], 'receipt'));
        $this->assertNull($third['nextCursor']);
        $newest = $this->add('New inquiry after first read');
        $this->assertSame($newest, $this->read()['inquiries'][0]['receipt']);
        $this->assertSame($second, $this->read($first['nextCursor']));
    }

    public function test_foreign_unknown_and_malformed_cursors_share_one_refusal_and_unrelated_owners_have_no_rows(): void
    {
        $foreign = $this->add('Foreign subject', str_repeat('b', 64));
        foreach ([$foreign, (string) Str::uuid(), 'invalid', strtoupper($foreign)] as $cursor) {
            try { $this->read($cursor); $this->fail('The cursor must be refused.'); }
            catch (InquiryException $error) { $this->assertSame(422, $error->status); $this->assertSame([], $error->errors); }
        }
        $this->assertSame([], app(ReadOwnedInquiries::class)->handle(str_repeat('c', 64))['inquiries']);
    }

    public function test_archived_history_remains_readable_after_intake_operator_and_publication_withdrawal(): void
    {
        app(InquiryAdministration::class)->transition($this->fixture['inquiry']->id, 'archived', 0, $this->fixture['actor']);
        config(['inquiries.enabled' => false, 'inquiries.operator_user_id' => null, 'inquiries.privacy_notice' => null]);
        $site = app(SiteContent::class); $content = $site->current(); $content['contact'] = null;
        $content['navigation'] = array_values(array_filter($content['navigation'], fn ($entry) => $entry['href'] !== '/contact'));
        $release = $site->create($content, 'Synthetic withdrawn contact', $this->fixture['actor']);
        $site->publish($release->id, 1, $this->fixture['actor']);
        $before = $this->retained();
        $this->assertSame('archived', $this->read()['inquiries'][0]['state']);
        $this->assertSame($before, $this->retained());
    }

    public function test_one_bounded_query_does_not_load_messages_or_decrypt_the_twenty_first_payload(): void
    {
        for ($i = 0; $i < 20; $i++) { $this->add(str_repeat('🎛', 160)); }
        $sentinel = $this->fixture['inquiry']->id;
        CustomerInquiry::retrieved(function (CustomerInquiry $row) use ($sentinel): void {
            if ($row->id === $sentinel) { $row->setRawAttributes(array_replace($row->getAttributes(), ['payload' => 'NOT-A-CIPHERTEXT'])); }
        });
        $queries = []; DB::listen(function (QueryExecuted $query) use (&$queries): void { $queries[] = $query->sql; });
        $history = $this->read();
        $this->assertCount(1, $queries); $this->assertStringContainsString('limit 21', strtolower($queries[0]));
        $this->assertStringContainsString('owner_hash', $queries[0]); $this->assertStringNotContainsString('inquiry_messages', $queries[0]);
        $this->assertCount(20, $history['inquiries']); $this->assertNotNull($history['nextCursor']);
        $this->assertLessThan(65536, strlen(json_encode(['history' => $history], JSON_THROW_ON_ERROR)));
        $this->assertSame(str_repeat('🎛', 160), $history['inquiries'][0]['subject']);
    }

    public function test_unverifiable_displayed_subject_fails_closed(): void
    {
        CustomerInquiry::retrieved(function (CustomerInquiry $row): void { $row->payload = ['subject' => str_repeat('x', 161)]; });
        $this->expectException(InquiryException::class);
        $this->read();
    }

    private function add(string $subject, string $owner = Fixture::OWNER): string
    {
        $body = array_replace($this->fixture['body'], ['subject' => $subject, 'requestKey' => (string) Str::uuid()]);
        return app(SubmitInquiry::class)->handle($body, $owner)['receipt'];
    }

    private function read(?string $before = null): array
    {
        return app(ReadOwnedInquiries::class)->handle(Fixture::OWNER, $before);
    }

    private function retained(): array
    {
        return array_map(fn (string $table) => DB::table($table)->orderBy('id')->get()->toJson(), ['customer_inquiries', 'inquiry_messages', 'audit_events']);
    }
}
