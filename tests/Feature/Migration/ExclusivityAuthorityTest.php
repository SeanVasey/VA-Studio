<?php

namespace Tests\Feature\Migration;

use App\Domain\Migration\History\ExclusivityAuthority;
use App\Domain\Migration\History\HistoryDelta;
use App\Domain\Migration\History\LegacyArchiveRefusal;
use App\Domain\Migration\History\LegacyHistoryArchive;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyHistoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** One exclusive authority at a time: BeatStars frozen first, handover only after the post-freeze delta reconciles. */
class ExclusivityAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private ExclusivityAuthority $authority;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->authority = new ExclusivityAuthority;
        $this->operator = LicenseFixtures::admin();
    }

    protected function tearDown(): void
    {
        LegacyHistoryFixtures::cleanup();
        parent::tearDown();
    }

    public function test_the_full_cutover_admits_exactly_one_authority_and_refuses_a_second(): void
    {
        $this->assertNull($this->authority->current());
        $this->assertFalse($this->authority->storeMaySellExclusives());
        $this->assertRefused('authority_not_declared', 'freeze');
        $this->assertSame('beatstars', $this->record('declare')['to']);
        $this->assertRefused('authority_already_declared', 'declare');
        [$baseline, $later] = $this->archived();
        $this->assertRefused('handover_requires_freeze', 'handover', $this->evidence($baseline, $later));

        $this->travelTo('2026-09-15 12:00:00');
        $this->assertSame(['from' => 'beatstars', 'to' => 'frozen', 'sequence' => 2], array_intersect_key($this->record('freeze'), array_flip(['from', 'to', 'sequence'])));
        $this->assertFalse($this->authority->storeMaySellExclusives());

        $before = LegacyHistoryFixtures::nonArchiveState();
        $dry = LegacyHistoryFixtures::transition($this->authority, 'handover', $this->operator, 'x', false, $this->evidence($baseline, $later));
        $this->assertSame('not_written', $dry['outcome']);
        $this->assertSame(2, DB::table(ExclusivityAuthority::TABLE)->count());
        $handover = $this->record('handover', $this->evidence($baseline, $later));
        $this->assertSame(['frozen', 'vasey_store', 3], [$handover['from'], $handover['to'], $handover['sequence']]);
        $this->assertSame($handover['delta_sha256'], (new HistoryDelta)->compare($baseline['snapshot'], $later['snapshot'])['delta_sha256']);
        $this->assertTrue($this->authority->storeMaySellExclusives());
        $this->assertSame($before, LegacyHistoryFixtures::nonArchiveState());

        $this->assertRefused('second_authority_refused', 'handover', $this->evidence($baseline, $later));
        $this->assertRefused('authority_already_declared', 'declare');
        $this->assertRefused('freeze_requires_beatstars', 'freeze');
        $this->assertRefused('unfreeze_requires_freeze', 'unfreeze');

        // Rollback stops the store first; reopening BeatStars after a store period is not admitted here.
        $this->assertSame('frozen', $this->record('revert')['to']);
        $this->assertFalse($this->authority->storeMaySellExclusives());
        $this->assertRefused('source_resale_unproven', 'unfreeze');
        $this->assertSame(['beatstars', 'frozen', 'vasey_store', 'frozen'], DB::table(ExclusivityAuthority::TABLE)->orderBy('sequence')->pluck('authority')->all());
        $this->assertSame(4, DB::table('audit_events')->where('action', ExclusivityAuthority::TRANSITION_ACTION)->count());
        $this->assertStringNotContainsString('SYNTHETIC evidence', DB::table(ExclusivityAuthority::TABLE)->get()->toJson());
    }

    public function test_a_freeze_that_followed_beatstars_can_be_lifted(): void
    {
        $this->record('declare');
        $this->record('freeze');
        $this->assertSame('beatstars', $this->record('unfreeze')['to']);
        $this->assertSame('frozen', $this->record('freeze')['to']);
    }

    public function test_handover_needs_a_post_freeze_export_archived_reconciled_and_a_reconciled_delta(): void
    {
        $this->record('declare');
        $baseline = $this->load('baseline');
        $later = $this->load('later');
        $this->travelTo('2026-09-15 12:00:00');
        $this->record('freeze');
        $this->assertRefused('baseline_not_archived', 'handover', $this->evidence($baseline, $later));
        $this->import($baseline);
        $this->assertRefused('later_not_archived', 'handover', $this->evidence($baseline, $later));
        $this->import($later, 3);
        $this->assertRefused('later_not_reconciled', 'handover', $this->evidence($baseline, $later));
        $this->import($later);

        // An export whose watermark precedes the freeze cannot prove that nothing was sold before the freeze took hold.
        $late = $this->load('baseline', fn (array $parts): array => ['mapping' => str_replace(['2026-09-01T01:00:00Z', '2026-09-01T00:00:00Z'],
            ['2026-09-10T01:00:00Z', '2026-09-10T00:00:00Z'], $parts['mapping'])] + $parts);
        $this->import($late);
        $this->assertRefused('later_export_precedes_freeze', 'handover', $this->evidence($baseline, $late));
        // The baseline must be the latest archive before the later export: here the 2026-09-10 one is.
        $this->assertRefused('baseline_not_latest', 'handover', $this->evidence($baseline, $later));
        $this->assertSame('vasey_store', $this->record('handover', $this->evidence($late, $later))['to']);
    }

    public function test_an_unexplained_post_freeze_delta_blocks_the_handover_until_dispositioned(): void
    {
        $this->record('declare');
        $baseline = $this->load('baseline');
        $later = $this->load('later', function (array $parts): array {
            $parts['orders'] = preg_replace('/^synthetic-ord-1003,.*\n/m', '', $parts['orders']);
            $parts['contracts'] = preg_replace('/^synthetic-contract-1003-1,.*\n/m', '', $parts['contracts']);
            unset($parts['files']['synthetic-contract-1003-1.txt']);

            return $parts;
        });
        $this->import($baseline);
        $this->import($later);
        $this->travelTo('2026-09-15 12:00:00');
        $this->record('freeze');
        $this->assertRefused('delta_unreconciled', 'handover', $this->evidence($baseline, $later));
        $delta = (new HistoryDelta)->compare($baseline['snapshot'], $later['snapshot']);
        $items = [];
        foreach ($delta['items'] as $item) {
            if ($item['blocking']) {
                $items[$item['cohort'].':'.$item['record_key']] = ['disposition' => 'retained_prior_record', 'reason' => 'SYNTHETIC refunded order purged at source'];
            }
        }
        $dispositions = json_encode(['schema_version' => 1, 'purpose' => HistoryDelta::DISPOSITIONS_PURPOSE,
            'baseline_snapshot_sha256' => $delta['baseline']['snapshot_sha256'], 'later_snapshot_sha256' => $delta['later']['snapshot_sha256'], 'items' => $items]);
        $this->assertSame('vasey_store', $this->record('handover', $this->evidence($baseline, $later, $dispositions))['to']);
    }

    public function test_command_is_dry_run_by_default_and_a_write_needs_attestation_and_the_password(): void
    {
        $this->artisan('vasey:exclusivity-authority', ['action' => 'status'])
            ->expectsOutput('{"authority":null,"code":"status","database_writes":0,"sequence":0,"store_may_sell_exclusives":false}')->assertExitCode(0);
        $this->artisan('vasey:exclusivity-authority', ['action' => 'declare', '--actor-id' => (string) $this->operator->id])->assertExitCode(0);
        $this->assertSame(0, DB::table(ExclusivityAuthority::TABLE)->count());
        $this->artisan('vasey:exclusivity-authority', ['action' => 'declare', '--actor-id' => (string) $this->operator->id, '--reference' => 'SYNTHETIC', '--write' => true])
            ->expectsOutputToContain('"reason":"attestation_required"')->assertExitCode(1);
        $this->artisan('vasey:exclusivity-authority', ['action' => 'declare', '--actor-id' => (string) $this->operator->id, '--reference' => 'SYNTHETIC', '--write' => true, '--attest' => true])
            ->expectsQuestion('Staff password (hidden)', 'wrong-password')->expectsOutputToContain('"reason":"actor_refused"')->assertExitCode(1);
        $this->assertSame(0, DB::table(ExclusivityAuthority::TABLE)->count());
        $this->assertSame(0, DB::table('audit_events')->count());
        $this->artisan('vasey:exclusivity-authority', ['action' => 'declare', '--actor-id' => (string) $this->operator->id, '--reference' => 'SYNTHETIC', '--write' => true, '--attest' => true])
            ->expectsQuestion('Staff password (hidden)', 'password')->expectsOutputToContain('"code":"transition_recorded"')->assertExitCode(0);
        $this->assertSame('beatstars', $this->authority->current()->authority);
        $this->assertSame(1, DB::table('audit_events')->where('action', StaffWriteAuthority::ATTESTATION_ACTION)->count());
        $this->artisan('vasey:exclusivity-authority', ['action' => 'declare', '--actor-id' => (string) $this->operator->id, '--reference' => 'SYNTHETIC', '--write' => true, '--attest' => true])
            ->expectsQuestion('Staff password (hidden)', 'password')->expectsOutputToContain('"reason":"authority_already_declared"')->assertExitCode(1);
    }

    public function test_hosted_environments_are_refused_unless_the_reviewed_configuration_names_them(): void
    {
        foreach (['staging', 'production'] as $environment) {
            $this->app['env'] = $environment;
            config(['app.env' => $environment]);
            $this->artisan('vasey:exclusivity-authority', ['action' => 'status'])->expectsOutputToContain('"reason":"environment_refused"')->assertExitCode(1);
        }
        config(['legacy-archive.non_local_environments' => ['staging'], 'app.env' => 'staging']);
        $this->app['env'] = 'staging';
        $this->artisan('vasey:exclusivity-authority', ['action' => 'status'])->expectsOutputToContain('"code":"status"')->assertExitCode(0);
        config(['app.env' => 'local']);
        $this->artisan('vasey:exclusivity-authority', ['action' => 'status'])->expectsOutputToContain('"reason":"environment_ambiguous"')->assertExitCode(1);
    }

    private function record(string $transition, ?array $handover = null): array
    {
        return LegacyHistoryFixtures::transition($this->authority, $transition, $this->operator, 'SYNTHETIC evidence for '.$transition, true, $handover);
    }

    private function assertRefused(string $reason, string $transition, ?array $handover = null): void
    {
        $count = DB::table(ExclusivityAuthority::TABLE)->count();
        foreach ([false, true] as $write) {
            try {
                LegacyHistoryFixtures::transition($this->authority, $transition, $this->operator, 'SYNTHETIC', $write, $handover);
                $this->fail($transition.' was admitted; expected '.$reason);
            } catch (LegacyArchiveRefusal $refusal) {
                $this->assertSame($reason, $refusal->reason);
            }
        }
        $this->assertSame($count, DB::table(ExclusivityAuthority::TABLE)->count());
    }

    private function load(string $package, ?callable $change = null): array
    {
        $loaded = (new LegacyHistoryArchive)->load(LegacyHistoryFixtures::privateCopy($package, $change));
        $this->assertSame([], $loaded['result']['findings']);

        return ['snapshot' => $loaded['result']['snapshot'], 'sha' => $loaded['result']['snapshot_sha256'], 'files' => $loaded['source']['contracts']];
    }

    private function import(array $package, int $limit = 1000): void
    {
        LegacyHistoryFixtures::import($package['snapshot'], $package['sha'], $package['files'], $this->operator, $limit);
    }

    /** @return array{0: array, 1: array} both packages archived */
    private function archived(): array
    {
        $baseline = $this->load('baseline');
        $later = $this->load('later');
        $this->import($baseline);
        $this->import($later);

        return [$baseline, $later];
    }

    private function evidence(array $baseline, array $later, ?string $dispositions = null): array
    {
        return ['baseline' => $baseline['snapshot'], 'later' => $later['snapshot'], 'dispositions' => $dispositions];
    }
}
