<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\Models\SiteReleaseImage;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\SiteContentRace;
use Tests\Support\SiteImageFixtures as F;
use Tests\TestCase;

class SiteImageConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent site image claims are verified on MySQL, not SQLite.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    /** Both workers try to process the same image; exactly one claims and prepares it. */
    private function race(SiteImage $image, int $first, int $attempts): void
    {
        $job = ['operation' => 'process_site_image', 'image_id' => $image->id];
        SiteContentRace::run($this, [$job, $job], function (array $results, int $winner, int $loser) use ($attempts): void {
            $this->assertSame(['processed', 'ready', $attempts], [$results[$winner]['result'], $results[$winner]['status'], $results[$winner]['attempts']]);
            // The loser found the winner's live claim and left the image alone.
            $this->assertSame(['processed', $attempts], [$results[$loser]['result'], $results[$loser]['attempts']]);
            $this->assertContains($results[$loser]['status'], ['processing', 'ready']);
        }, $first, ['table' => 'site_images', 'id' => $image->id]);

        $ready = $image->fresh();
        $this->assertSame(['ready', $attempts, null], [$ready->status, $ready->attempts, $ready->claim_token]);
        $this->assertSame(6, $ready->variants()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'site.image.processed')->where('subject_id', $image->id)->count());
        $directory = dirname($ready->variants()->firstOrFail()->storage_path);
        $this->assertCount(6, Storage::disk('local')->files($directory));
        // No second run left promoted files behind.
        $this->assertSame([$directory], Storage::disk('local')->directories('site-images/revisions'));
    }

    public function test_competing_processors_claim_a_waiting_image_once_in_either_lock_order(): void
    {
        foreach ([0, 1] as $first) {
            $image = F::quarantined('studio', F::jpeg(1440, 630), 1440, 630, LicenseFixtures::admin());
            $this->race($image, $first, 1);
            $this->assertSame(1, AuditEvent::query()->where('action', 'site.image.processing')->where('subject_id', $image->id)->count());
            Storage::disk('local')->deleteDirectory('site-images/revisions');
        }
    }

    public function test_competing_processors_take_over_an_expired_claim_once(): void
    {
        $image = F::quarantined('studio', F::jpeg(1440, 630), 1440, 630, LicenseFixtures::admin());
        // A worker that crashed while holding the claim.
        $image->forceFill(['status' => 'processing', 'claim_token' => (string) Str::uuid(), 'claimed_until' => now()->subMinute(), 'attempts' => 1])->save();

        $this->race($image, 0, 2);
    }

    public static function orders(): array
    {
        return ['scheduler takes the lock first' => [0], 'staff take the lock first' => [1]];
    }

    /** The scheduler checks an image release's files before its lock; a manual publication racing it still yields one outcome. */
    #[DataProvider('orders')]
    public function test_a_scheduled_image_release_and_a_manual_publication_serialize_and_only_the_winner_is_served(int $first): void
    {
        MediaFixtures::configure();
        $scheduler = LicenseFixtures::admin();
        $staff = LicenseFixtures::admin();
        $studio = F::ready('studio', $scheduler);
        $content = SiteContentSchema::forEditing(SiteContentSchema::defaults());
        $content['schema_version'] = 3;
        $content['images'] = ['hero' => null, 'studio' => ['id' => $studio->id, 'alt' => 'Synthetic scheduled studio'], 'share' => null];
        // Workers read the real clock, so the schedule is created in the past and is due, within its grace window, when they run.
        $real = CarbonImmutable::now('UTC');
        $this->travelTo($real->subMinutes(10));
        $scheduled = app(SiteContent::class)->create($content, 'Scheduled with an image', $scheduler);
        $schedule = app(SiteContent::class)->schedule($scheduled->id, $real->subMinutes(5)->startOfMinute(), 0, $scheduler);
        $this->travelBack();
        $manual = app(SiteContent::class)->create(SiteContentSchema::defaults(), 'Manual without images', $staff);

        $race = SiteContentRace::run($this, [
            ['operation' => 'run_schedule'],
            ['operation' => 'publish', 'release_id' => $manual->id, 'revision' => 0, 'actor_id' => $staff->id, 'expected_schedule_id' => $schedule->id],
        ], function (array $results, int $winner): void {
            if ($winner === 0) {
                $this->assertSame(['schedule', 'published', 1], [$results[0]['result'], $results[0]['outcome'], $results[0]['revision']]);
                $this->assertSame('rejected', $results[1]['result']);
                $this->assertStringContainsString('published site changed', $results[1]['errors']['publication'][0]);
            } else {
                $this->assertSame(['published', 1], [$results[1]['result'], $results[1]['revision']]);
                $this->assertSame(['schedule', 'already_resolved'], [$results[0]['result'], $results[0]['outcome']]);
            }
        }, $first);

        $this->assertSame($first, $race['winner']);
        $publication = SitePublication::findOrFail(1);
        $this->assertSame([$first === 0 ? $scheduled->id : $manual->id, 1], [$publication->active_release_id, $publication->revision]);
        $this->assertSame($first === 0 ? 'published' : 'superseded', $schedule->fresh()->state);
        // An image is public only once a release using it has been live: the scheduled release's image, if it won.
        $jpeg = $studio->variants()->where('format', 'jpeg')->firstOrFail();
        $response = $this->get('/site-images/'.$jpeg->sha256.'.jpg');
        $first === 0 ? $response->assertOk() : $response->assertNotFound();
    }

    public function test_a_release_created_while_its_image_completes_pins_the_whole_manifest_or_is_refused(): void
    {
        $actor = LicenseFixtures::admin();
        foreach ([0, 1] as $first) {
            $image = F::quarantined('studio', F::jpeg(1440, 630), 1440, 630, $actor);
            $content = SiteContentSchema::forEditing(SiteContentSchema::defaults());
            $content['schema_version'] = 3;
            $content['images'] = ['hero' => null, 'studio' => ['id' => $image->id, 'alt' => 'Synthetic studio'], 'share' => null];
            $jobs = [
                // The processor's claim passes; the race is between its completion and the release reading the image.
                ['operation' => 'process_site_image', 'image_id' => $image->id, 'skip_locks' => 1],
                ['operation' => 'create_site_release', 'content' => $content, 'label' => 'Synthetic race '.$first, 'actor_id' => $actor->id],
            ];
            SiteContentRace::run($this, $jobs, function (array $results) use ($first): void {
                $this->assertSame(['processed', 'ready'], [$results[0]['result'], $results[0]['status']]);
                if ($first === 0) {
                    $this->assertSame('created', $results[1]['result']);
                } else {
                    $this->assertSame('rejected', $results[1]['result']);
                    $this->assertArrayHasKey('content.images.studio', $results[1]['errors']);
                }
            }, $first, ['table' => 'site_images', 'id' => $image->id]);

            $ready = $image->fresh();
            $this->assertSame('ready', $ready->status);
            $release = SiteRelease::query()->where('label', 'Synthetic race '.$first)->first();
            if ($first === 0) {
                // Never a partial manifest: the release pins exactly what completion recorded, and verifies.
                $this->assertSame($ready->manifest_sha256, $release->content['images']['studio']['manifest']);
                $this->assertSame([$image->id], SiteReleaseImage::query()->where('site_release_id', $release->id)->pluck('site_image_id')->map(fn ($id): int => (int) $id)->all());
                $this->assertEquals($release->content, app(SiteContent::class)->preview($release->id, $actor));
            } else {
                $this->assertNull($release);
            }
        }
    }

    public function test_a_release_pinning_a_ready_image_and_one_completing_does_not_deadlock(): void
    {
        $actor = LicenseFixtures::admin();
        MediaFixtures::configure();
        foreach ([0, 1] as $first) {
            // The ready image has the lower id, so its variants sit just before where the completing image's go.
            $studio = F::ready('studio', $actor);
            $share = F::quarantined('share', F::jpeg(1200, 630), 1200, 630, $actor);
            $content = SiteContentSchema::forEditing(SiteContentSchema::defaults());
            $content['schema_version'] = 3;
            $content['images'] = ['hero' => null, 'studio' => ['id' => $studio->id, 'alt' => 'Synthetic studio'], 'share' => ['id' => $share->id, 'alt' => 'Synthetic share']];
            $jobs = [
                // Both let their first image lock pass: the processor its claim, the release the ready studio image.
                ['operation' => 'process_site_image', 'image_id' => $share->id, 'skip_locks' => 1],
                ['operation' => 'create_site_release', 'content' => $content, 'label' => 'Synthetic pair '.$first, 'actor_id' => $actor->id, 'skip_locks' => 1],
            ];
            SiteContentRace::run($this, $jobs, function (array $results) use ($first): void {
                $this->assertSame(['processed', 'ready'], [$results[0]['result'], $results[0]['status']]);
                $this->assertSame($first === 0 ? 'created' : 'rejected', $results[1]['result']);
            }, $first, ['table' => 'site_images', 'id' => $share->id]);

            $release = SiteRelease::query()->where('label', 'Synthetic pair '.$first)->first();
            if ($first === 0) {
                $this->assertSame([$studio->manifest_sha256, $share->fresh()->manifest_sha256],
                    [$release->content['images']['studio']['manifest'], $release->content['images']['share']['manifest']]);
                $this->assertEquals($release->content, app(SiteContent::class)->preview($release->id, $actor));
            } else {
                $this->assertNull($release);
            }
        }
    }
}
