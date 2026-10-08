<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRecords;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Codex P2 (4): the library page is the newest origins by creation time, selected in SQL, however many origins an
 * account holds. The neutral test runs on every driver with real origins whose UUIDs sort opposite to their age. The
 * 1,001-filler case below is SQLite only (it needs the append-only guards removed and restored, written for SQLite
 * `sqlite_master`); on another driver it only asserts the driver and returns. The native MySQL run of the neutral
 * test is pending the independent reviewer.
 */
final class ProductionFreeGrantLibraryWindowTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_page_is_the_newest_origins_even_when_their_uuids_sort_lowest(): void
    {
        $this->freeSetup();
        [$owner, $ids] = $this->origins([
            'ffffffff-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000002', '00000000-0000-4000-8000-000000000003']);

        $page = (new ProductionFreeGrantLibrary(limit: 2))->index($owner['principal'], $owner['user']);

        $this->assertSame(3, $page['total']);
        $this->assertSame(2, $page['limit']);
        $this->assertSame([$ids[2], $ids[1]], array_column($page['items'], 'id'));
        $this->assertSame(array_reverse($ids), array_column((new ProductionFreeGrantLibrary)->index($owner['principal'], $owner['user'])['items'], 'id'));
    }

    public function test_the_newest_origins_are_listed_when_the_account_holds_more_than_the_scan_cap_on_sqlite(): void
    {
        $this->freeSetup();
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertNotSame('sqlite', DB::getDriverName());

            return;
        }
        [$owner, $ids] = $this->origins([]);
        $this->insertFillers(1001);

        $page = (new ProductionFreeGrantLibrary(limit: 3))->index($owner['principal'], $owner['user']);

        $this->assertSame(1004, $page['total']);
        $this->assertSame(3, $page['limit']);
        $this->assertSame(array_reverse($ids), array_column($page['items'], 'id'));
    }

    /**
     * Three origins of one account, ten seconds apart. With `$uuids` the origin ids are forced in that order.
     *
     * @param  list<string>  $uuids
     * @return array{0:array,1:list<string>}
     */
    private function origins(array $uuids): array
    {
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $ids = [];
        $base = CarbonImmutable::now('UTC');
        foreach (range(0, 2) as $i) {
            CarbonImmutable::setTestNow($base->addSeconds(10 * $i));
            $definition = $this->openDefinition();
            $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
            $input = $this->assentInput($review);
            if ($uuids !== []) {
                // The origin id is the only UUID `accept` generates.
                $forced = $uuids[$i];
                Str::createUuidsUsing(fn () => Uuid::fromString($forced));
            }
            try {
                $ids[] = $grants->accept($definition['id'], $input, $owner['principal'], $owner['user'])['id'];
            } finally {
                Str::createUuidsNormally();
            }
            if ($uuids !== []) {
                $this->assertSame($uuids[$i], $ids[$i]);
            }
        }
        CarbonImmutable::setTestNow();

        return [$owner, $ids];
    }

    private function insertFillers(int $count): void
    {
        $table = 'production_free_origins';
        $triggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ? ORDER BY rowid", [$table]);
        $template = (array) DB::table($table)->first();
        $this->assertNotEmpty($triggers);
        DB::statement('PRAGMA foreign_keys = OFF');
        foreach ($triggers as $trigger) {
            DB::statement('DROP TRIGGER "'.$trigger->name.'"');
        }
        try {
            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $row = [...$template, 'id' => sprintf('00000000-0000-4000-8000-%012d', $i), 'definition_id' => (string) Str::uuid(),
                    'request_key_hash' => hash('sha256', 'filler'.$i), 'created_at' => '2020-01-01 00:00:00',
                    'payload_ciphertext' => ProductionFreeGrantRecords::encrypt(['filler' => $i])];
                $row['seal'] = ProductionFreeGrantRecords::seal($table, $row);
                $rows[] = $row;
            }
            foreach (array_chunk($rows, 50) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        } finally {
            foreach ($triggers as $trigger) {
                DB::statement($trigger->sql);
            }
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
}
