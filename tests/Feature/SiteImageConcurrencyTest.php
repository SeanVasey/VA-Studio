<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\Models\SiteReleaseImage;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
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
}
