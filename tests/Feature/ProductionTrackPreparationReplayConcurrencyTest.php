<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Domain\Commerce\ProductionPreparation\ReadProductionTrackPreparationPacket;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTrackPreparationFixtures as Fixture;
use Tests\Support\ProductionTrackPreparationReplayRace;
use Tests\TestCase;

class ProductionTrackPreparationReplayConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Packet replay races require two independent MySQL sessions and an observed exact actor record wait; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Http::preventStrayRequests();
        $this->fakePrivateMediaStorage();
    }

    public static function commitOrders(): array
    {
        return ['worker zero first' => [0], 'worker one first' => [1]];
    }

    #[DataProvider('commitOrders')]
    public function test_same_capture_miss_gap_replays_exact_packet_in_both_commit_orders(int $first): void
    {
        $f = Fixture::prepared();
        $before = Fixture::rows();
        $input = ['actor_id' => $f['actor']->id, 'capture' => $f['capture']];
        $results = ProductionTrackPreparationReplayRace::run($this, [$input, $input], $first);
        $this->assertSame(['saved', 'saved'], array_column($results, 'status'));
        $this->assertSame($results[0]['packet'], $results[1]['packet']);
        $this->assertSame(1, $results[$first]['encryptions']);
        $this->assertSame(0, $results[1 - $first]['encryptions']);
        $this->assertRetainedOnce($f, $before, $results[$first]['packet']);
    }

    #[DataProvider('commitOrders')]
    public function test_changed_capture_cannot_recover_winner_in_either_worker_order(int $first): void
    {
        $f = Fixture::prepared();
        $changed = $f['capture'];
        $changed['request']['items'][0]['offerId']++;
        unset($changed['signature']);
        $changed['signature'] = PacketEvidence::signature('production-packet-review-v1', $changed);
        $before = Fixture::rows();
        $inputs = [[], []];
        $inputs[$first] = ['actor_id' => $f['actor']->id, 'capture' => $f['capture']];
        $inputs[1 - $first] = ['actor_id' => $f['actor']->id, 'capture' => $changed];
        $results = ProductionTrackPreparationReplayRace::run($this, $inputs, $first);
        $this->assertSame('saved', $results[$first]['status']);
        $this->assertSame('blocked', $results[1 - $first]['status']);
        $this->assertNull($results[1 - $first]['packet']);
        $this->assertSame(1, $results[$first]['encryptions']);
        $this->assertSame(0, $results[1 - $first]['encryptions']);
        $this->assertRetainedOnce($f, $before, $results[$first]['packet']);
    }

    private function assertRetainedOnce(array $f, array $before, array $packet): void
    {
        $this->assertDatabaseCount(PacketEvidence::PACKETS, 1);
        $this->assertDatabaseCount(PacketEvidence::LINES, 1);
        $this->assertSame(count($before['audit_events']) + 1, DB::table('audit_events')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.production_preparation.packet_retained')->count());
        $row = (array) DB::table(PacketEvidence::PACKETS)->sole();
        $this->assertSame($row['id'], $packet['id']);
        $this->assertSame($row['public_id'], $packet['public_id']);
        $this->assertSame($row['payload_hash'], $packet['payload_hash']);
        $this->assertSame(CanonicalJson::hash($row), $packet['row_hash']);
        $body = app(ReadProductionTrackPreparationPacket::class)->recover($f['key'], $f['actor']);
        $this->assertSame($f['capture']['request'], $body['request']);
        foreach (['payable', 'execution_allowed', 'external_facts_verified', 'private_bytes_verified'] as $field) {
            $this->assertFalse($body['selection'][$field]);
        }
        $after = Fixture::rows();
        foreach (['quotes', 'orders', 'inventory_reservations', 'license_grants', 'checkout_intents'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
        Http::assertNothingSent();
    }
}
