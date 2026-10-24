<?php

namespace Tests\Feature\Migration;

use App\Domain\Migration\History\ExclusivityAuthority;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExclusivityAuthorityRace;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LegacyHistoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** Independent MySQL processes cannot both move the exclusivity authority from the same ledger head. */
class ExclusivityAuthorityConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const REFUSALS = ['freeze_requires_beatstars', 'authority_already_declared', 'authority_changed_concurrently'];

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Exclusivity authority races require independent MySQL processes.');
        }
    }

    public function test_native_concurrent_freezes_from_one_head_record_exactly_one_event(): void
    {
        [$first, $second] = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        LegacyHistoryFixtures::transition(app(ExclusivityAuthority::class), 'declare', $first, 'SYNTHETIC declaration', true);
        $race = ExclusivityAuthorityRace::run($this, [['actor_id' => $first->id, 'transition' => 'freeze'], ['actor_id' => $second->id, 'transition' => 'freeze']], 0);
        $this->assertTrue($race['observed_wait']);
        $this->assertSame(['outcome' => 'recorded', 'to' => 'frozen', 'sequence' => 2], array_intersect_key($race['results'][0], array_flip(['outcome', 'to', 'sequence'])));
        $this->assertSame('refused', $race['results'][1]['outcome'], json_encode($race['results'][1]));
        $this->assertContains($race['results'][1]['reason'], self::REFUSALS);
        $this->assertSame(['beatstars', 'frozen'], DB::table(ExclusivityAuthority::TABLE)->orderBy('sequence')->pluck('authority')->all());
        $this->assertSame(1, DB::table('audit_events')->where('action', ExclusivityAuthority::TRANSITION_ACTION)->where('context->transition', 'freeze')->count());
    }

    public function test_native_concurrent_declarations_on_an_empty_ledger_record_exactly_one_authority(): void
    {
        [$first, $second] = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        $race = ExclusivityAuthorityRace::run($this, [['actor_id' => $first->id, 'transition' => 'declare'], ['actor_id' => $second->id, 'transition' => 'declare']], null);
        $outcomes = array_column($race['results'], 'outcome');
        sort($outcomes);
        $this->assertSame(['recorded', 'refused'], $outcomes, json_encode($race['results']));
        foreach ($race['results'] as $result) {
            if ($result['outcome'] === 'refused') {
                $this->assertContains($result['reason'], self::REFUSALS);
            }
        }
        $this->assertSame(['beatstars'], DB::table(ExclusivityAuthority::TABLE)->pluck('authority')->all());
        $this->assertSame(1, DB::table('audit_events')->where('action', ExclusivityAuthority::TRANSITION_ACTION)->count());
    }
}
