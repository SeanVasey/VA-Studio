<?php

namespace Tests\Feature;

use App\Domain\Grants\Free\FreeGrantDocuments;
use App\Domain\Grants\Free\FreeGrantDownloads;
use App\Domain\Grants\Free\FreeGrantException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FreeGrantFixtures;
use Tests\TestCase;

final class FreeGrantPreparationStatusTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_abandoned_live_claim_is_closed_then_exact_deadline_status_admits_retry_under_the_original_assent(): void
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $f = FreeGrantFixtures::source();
        $d = FreeGrantFixtures::publish($f);
        $origin = FreeGrantFixtures::accept($f, $d)['origin'];
        $before = (array) DB::table('free_origins')->sole();
        $deadline = now()->utc()->addSeconds(300);
        DB::table('free_document_work')->update(['state' => 'claimed', 'attempts' => 1, 'claim_id' => (string) Str::uuid(), 'expires_at' => $deadline->format('Y-m-d H:i:s')]);
        $status = (new FreeGrantDownloads)->status($origin['id'], $f['customer']['principal'], $f['customer']['user']);
        $this->assertFalse($status['renderRetryAllowed']);
        $this->assertSame($deadline->format('Y-m-d H:i:s'), $status['renderRetryAfter']);
        try {
            (new FreeGrantDocuments)->issue($origin['id'], $origin['originHash'], $f['customer']['principal'], $f['customer']['user']);
            $this->fail('Live competing claim must stay closed.');
        } catch (FreeGrantException $error) {
            $this->assertSame(409, $error->status);
        }
        $this->travelTo($deadline);
        $this->assertTrue((new FreeGrantDownloads)->status($origin['id'], $f['customer']['principal'], $f['customer']['user'])['renderRetryAllowed']);
        $completed = (new FreeGrantDocuments)->issue($origin['id'], $origin['originHash'], $f['customer']['principal'], $f['customer']['user']);
        $this->assertSame('complete', $completed['documentStatus']);
        $this->assertSame(2, $completed['renderAttempts']);
        $this->assertFalse((new FreeGrantDownloads)->status($origin['id'], $f['customer']['principal'], $f['customer']['user'])['renderRetryAllowed']);
        $this->assertSame($before, (array) DB::table('free_origins')->sole());
        $this->assertDatabaseCount('free_originals', 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_consumed_and_expired_authorization_status_is_bounded_latest_twenty_and_never_returns_secrets_or_paths(): void
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $f = FreeGrantFixtures::source();
        $d = FreeGrantFixtures::publish($f);
        $origin = FreeGrantFixtures::accept($f, $d)['origin'];
        (new FreeGrantDocuments)->issue($origin['id'], $origin['originHash'], $f['customer']['principal'], $f['customer']['user']);
        $downloads = new FreeGrantDownloads;
        $tokens = [];
        for ($index = 0; $index < 22; $index++) {
            $auth = $downloads->authorize($origin['id'], ['requestKey' => (string) Str::uuid(), 'originHash' => $origin['originHash'], 'kind' => 'contract', 'nonce' => bin2hex(random_bytes(32))], $f['customer']['principal'], $f['customer']['user']);
            $tokens[] = $auth['token'];
            if ($index === 0) {
                $downloads->redeem($auth['id'], $auth['token'], $f['customer']['principal'], $f['customer']['user'])->stream->close();
            }
            $this->travel(60)->seconds();
        }
        $status = $downloads->status($origin['id'], $f['customer']['principal'], $f['customer']['user']);
        $this->assertSame(1, $status['attemptCount']);
        $this->assertCount(20, $status['history']);
        $this->assertSame($auth['id'], $status['history'][0]['id']);
        $this->assertSame('expired', $status['history'][0]['status']);
        $body = json_encode($status, JSON_THROW_ON_ERROR);
        foreach ($tokens as $token) {
            $this->assertStringNotContainsString($token, $body);
        }
        $this->assertStringNotContainsString('storage_path', $body);
        $this->assertStringNotContainsString('owner_key', $body);
        $this->assertStringNotContainsString('payload', $body);
    }
}
