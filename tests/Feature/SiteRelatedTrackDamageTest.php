<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationSchedule;
use App\Domain\SiteBuilder\SiteContent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\Support\SiteImageFixtures;
use Tests\Support\SiteRelatedTrackFixtures as F;
use Tests\TestCase;

/** Corruption behind a dropped immutable guard belongs only to this freshly migrated disposable database. */
class SiteRelatedTrackDamageTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static function damagedEvidence(): array
    {
        return ['hash mismatch' => [false], 'correct hash but invalid native ID' => [true]];
    }

    #[DataProvider('damagedEvidence')]
    public function test_damaged_active_v4_fails_closed_and_an_intact_historical_release_can_recover(bool $invalidId): void
    {
        $this->withoutVite();
        config(['app.debug' => false]);
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $before = $site->create(SiteEditorialFixtures::content('SAFE HISTORICAL'), 'Safe v2', $actor);
        $site->publish($before->id, 0, $actor);
        $release = $site->create(F::content(), 'V4 corrupted after publication', $actor);
        $site->publish($release->id, 1, $actor);
        $content = $release->content;
        if ($invalidId) {
            $content['blog']['entries'][0]['related_track_ids'] = ['1'];
        } else {
            $content['blog']['entries'][0]['title'] = 'Changed bytes without the retained hash';
        }
        DB::unprepared('DROP TRIGGER site_releases_immutable_update');
        DB::table('site_releases')->where('id', $release->id)->update([
            'content' => json_encode($content, JSON_THROW_ON_ERROR),
            'content_hash' => $invalidId ? CanonicalJson::hash($content) : $release->content_hash,
        ]);
        if ($invalidId) {
            // Even matching release/history hashes cannot make malformed v4 content valid. Only this disposable database
            // removes both immutable guards; the intact pointer can then recover by restoring the older release.
            DB::unprepared('DROP TRIGGER site_publication_revisions_immutable_update');
            DB::table('site_publication_revisions')->where('revision', 2)->update(['content_hash' => CanonicalJson::hash($content)]);
        }
        $this->get('/blog/first-note')->assertStatus(503)->assertDontSee('SYNTHETIC RELATED', false);
        try {
            $site->preview($release->id, $actor);
            $this->fail('Corrupt v4 cannot enter a staff preview.');
        } catch (ValidationException) {
            $this->assertSame(2, SitePublication::findOrFail(1)->revision);
        }
        $site->rollback($before->id, 2, $actor);
        $this->get('/blog/first-note')->assertOk()->assertSee('SAFE HISTORICAL FIRST NOTE');
        $this->assertSame(3, SitePublication::findOrFail(1)->revision);
    }

    public function test_scheduler_checks_schema_four_image_files_before_activation(): void
    {
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $actor = LicenseFixtures::admin();
        $image = SiteImageFixtures::ready('studio', $actor);
        $content = F::content();
        $content['images']['studio'] = ['id' => $image->id, 'alt' => 'Scheduled v4 studio'];
        $site = app(SiteContent::class);
        $release = $site->create($content, 'Scheduled v4 image', $actor);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
        $schedule = $site->schedule($release->id, CarbonImmutable::parse('2026-10-01 12:05:00', 'UTC'), 0, $actor);
        $variant = $image->variants()->firstOrFail();
        $path = Storage::disk('local')->path($variant->storage_path);
        chmod($path, 0600);
        file_put_contents($path, strrev((string) file_get_contents($path)));
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:05:00', 'UTC'));
        $this->assertSame(['schedule_id' => $schedule->id, 'outcome' => 'integrity'], $site->runDueSchedule());
        $this->assertSame(['failed', 'integrity'], [$schedule->fresh()->state, $schedule->fresh()->outcome]);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertNull(SitePublication::findOrFail(1)->active_release_id);
        $this->assertSame(0, SitePublicationSchedule::where('state', 'pending')->count());
    }
}
