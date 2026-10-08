<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRecords;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Codex P2 (4): the library page is the newest origins by creation time, selected in SQL, however many origins an
 * account holds. The 1,001 older filler rows below sort below every real UUID, so the previous "first 1,000 by id,
 * then sort" window held only fillers. They are inserted with the append-only guards removed and then restored,
 * which only this test does; they are never part of the returned page.
 */
final class ProductionFreeGrantLibraryWindowTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_newest_origins_are_listed_when_the_account_holds_more_than_the_scan_cap(): void
    {
        $this->freeSetup();
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $ids = [];
        $base = CarbonImmutable::now('UTC');
        foreach (range(0, 2) as $i) {
            CarbonImmutable::setTestNow($base->addSeconds(10 * $i));
            $definition = $this->openDefinition();
            $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
            $ids[] = $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user'])['id'];
        }
        CarbonImmutable::setTestNow();
        $this->insertFillers(1001);

        $page = (new ProductionFreeGrantLibrary(limit: 3))->index($owner['principal'], $owner['user']);

        $this->assertSame(1004, $page['total']);
        $this->assertSame(3, $page['limit']);
        $this->assertSame(array_reverse($ids), array_column($page['items'], 'id'));
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
