<?php

namespace Tests\Feature;

use App\Domain\Media\MalwareScanner;
use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SiteReleaseImage;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteImageProcessor;
use App\Domain\SiteBuilder\SiteImageReferences;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use App\Support\Diagnostics\InstallationReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\Support\SiteImageFixtures as F;
use Tests\TestCase;

class SiteImageReleaseTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $this->actor = LicenseFixtures::admin();
    }

    /** @param  array<string, SiteImage>  $images */
    private function content(array $images, string $marker = 'SYNTHETIC IMAGES'): array
    {
        $content = SiteEditorialFixtures::content($marker);
        $content['schema_version'] = 3;
        $content['images'] = [
            'hero' => isset($images['hero_desktop']) ? ['desktop' => ['id' => $images['hero_desktop']->id], 'mobile' => ['id' => $images['hero_mobile']->id], 'alt' => 'Synthetic hero'] : null,
            'studio' => isset($images['studio']) ? ['id' => $images['studio']->id, 'alt' => 'Synthetic studio'] : null,
            'share' => isset($images['share']) ? ['id' => $images['share']->id, 'alt' => 'Synthetic share'] : null,
        ];

        return $content;
    }

    /** @return array<string, SiteImage> */
    private function images(string ...$slots): array
    {
        $images = [];
        foreach ($slots ?: array_keys(F::SIZES) as $slot) {
            $images[$slot] = F::ready($slot, $this->actor);
        }

        return $images;
    }

    /** @return array<string, list<string>> */
    private function refusal(callable $attempt): array
    {
        try {
            $attempt();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
        $this->fail('The attempt should have been refused.');
    }

    private function damageFile(SiteImage $image): string
    {
        $variant = $image->variants()->where('format', 'jpeg')->orderBy('width')->firstOrFail();
        $path = Storage::disk('local')->path($variant->storage_path);
        $original = (string) file_get_contents($path);
        file_put_contents($path, strrev($original));

        return $original;
    }

    public function test_a_release_pins_each_ready_image_and_indexes_it(): void
    {
        $images = $this->images();
        $release = app(SiteContent::class)->create($this->content($images), 'Synthetic images', $this->actor);

        $this->assertSame(3, $release->schema_version);
        $pinned = $release->content['images'];
        $this->assertSame(['id' => $images['hero_desktop']->id, 'manifest' => $images['hero_desktop']->manifest_sha256], $pinned['hero']['desktop']);
        $this->assertSame(['id' => $images['hero_mobile']->id, 'manifest' => $images['hero_mobile']->manifest_sha256], $pinned['hero']['mobile']);
        $this->assertSame($images['studio']->manifest_sha256, $pinned['studio']['manifest']);
        $this->assertSame($images['share']->manifest_sha256, $pinned['share']['manifest']);
        // The release hash covers the pinned references.
        $this->assertSame(CanonicalJson::hash($release->content), $release->content_hash);
        $indexed = SiteReleaseImage::query()->where('site_release_id', $release->id)->pluck('site_image_id', 'slot')->map(fn ($id): int => (int) $id)->sortKeys()->all();
        $this->assertSame(collect($images)->map(fn (SiteImage $image): int => $image->id)->sortKeys()->all(), $indexed);
        $this->assertEquals(collect($images)->map(fn (SiteImage $image): int => $image->id)->all(),
            AuditEvent::query()->where('action', 'site.release.created')->sole()->context['images']);
        $this->assertEquals($release->content, app(SiteContent::class)->preview($release->id, $this->actor));
    }

    public function test_release_creation_refuses_images_that_are_missing_unready_of_another_slot_or_changed(): void
    {
        $images = $this->images('studio', 'hero_desktop');
        $waiting = F::quarantined('studio', F::jpeg(1440, 630), 1440, 630, $this->actor);
        $this->scannerRejects();
        $failed = app(SiteImageProcessor::class)->handle(F::quarantined('studio', F::jpeg(1440, 630), 1440, 630, $this->actor)->id);
        $this->assertSame('failed', $failed->status);
        $base = $this->content([]);
        $studio = fn (array $reference): array => array_replace_recursive($base, ['images' => ['studio' => $reference + ['alt' => 'Synthetic studio']]]);
        $cases = [
            'missing image' => [$studio(['id' => 999999]), 'content.images.studio'],
            'image still waiting' => [$studio(['id' => $waiting->id]), 'content.images.studio'],
            'failed image' => [$studio(['id' => $failed->id]), 'content.images.studio'],
            'image of another slot' => [$studio(['id' => $images['hero_desktop']->id]), 'content.images.studio'],
            'another manifest' => [$studio(['id' => $images['studio']->id, 'manifest' => str_repeat('e', 64)]), 'content.images.studio'],
            'id as text' => [$studio(['id' => (string) $images['studio']->id]), 'content.images.studio'],
            'no description' => [array_replace_recursive($base, ['images' => ['studio' => ['id' => $images['studio']->id, 'alt' => '']]]), 'content.images.studio.alt'],
            'markup in the description' => [array_replace_recursive($base, ['images' => ['studio' => ['id' => $images['studio']->id, 'alt' => '<b>Studio</b>']]]), 'content.images.studio.alt'],
            'one hero image' => [array_replace_recursive($base, ['images' => ['hero' => ['desktop' => ['id' => $images['hero_desktop']->id], 'mobile' => null, 'alt' => 'Hero']]]), 'content.images.hero.mobile'],
            'unknown slot' => [array_replace_recursive($base, ['images' => ['logo' => null]]), 'content.images'],
            'images in version 2' => [array_replace($base, ['schema_version' => 2]), 'content'],
        ];
        foreach ($cases as $case => [$content, $field]) {
            $errors = $this->refusal(fn () => app(SiteContent::class)->create($content, 'Refused '.$case, $this->actor));
            $this->assertArrayHasKey($field, $errors, $case.': '.json_encode($errors));
        }
        $this->assertDatabaseCount('site_releases', 0);
        $this->assertDatabaseCount('site_release_images', 0);
        // The supplied manifest may be the current one.
        $release = app(SiteContent::class)->create($studio(['id' => $images['studio']->id, 'manifest' => $images['studio']->manifest_sha256]), 'Current manifest', $this->actor);
        $this->assertSame(3, $release->schema_version);
    }

    public function test_image_free_content_keeps_its_version_and_version_three_without_images_is_readable(): void
    {
        $site = app(SiteContent::class);
        $v2 = $site->create(SiteEditorialFixtures::content(), 'Image free', $this->actor);
        $this->assertSame(2, $v2->schema_version);
        $this->assertArrayNotHasKey('images', $v2->content);
        $v3 = $site->create($this->content([]), 'Explicit built-in images', $this->actor);
        $this->assertSame(['hero' => null, 'studio' => null, 'share' => null], $v3->content['images']);
        $this->assertSame([], SiteImageReferences::of($v3->content));
        $site->publish($v3->id, 0, $this->actor);
        $this->assertSame(3, $site->current()['schema_version']);
        $this->get('/')->assertOk();
    }

    public function test_the_index_accepts_only_ready_images_of_the_slot_before_the_release_is_scheduled_or_published(): void
    {
        $refused = function (callable $statement, string $case): void {
            try {
                $statement();
                $this->fail("The database accepted: {$case}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        $images = $this->images('studio', 'share');
        $site = app(SiteContent::class);
        $release = $site->create($this->content(['studio' => $images['studio']]), 'Indexed', $this->actor);
        $imageFree = $site->create(SiteEditorialFixtures::content(), 'Image free', $this->actor);
        $waiting = F::quarantined('share', F::jpeg(1200, 630), 1200, 630, $this->actor);
        $row = fn (int $release, string $slot, int $image): array => ['site_release_id' => $release, 'slot' => $slot, 'site_image_id' => $image, 'created_at' => now()];
        $refused(fn () => DB::table('site_release_images')->insert($row($release->id, 'share', $images['studio']->id)), 'an image of another slot');
        $refused(fn () => DB::table('site_release_images')->insert($row($release->id, 'share', $waiting->id)), 'an image that is not ready');
        $refused(fn () => DB::table('site_release_images')->insert($row($imageFree->id, 'share', $images['share']->id)), 'a release without images');
        $refused(fn () => DB::table('site_release_images')->insert($row($release->id, 'logo', $images['share']->id)), 'an unknown slot');
        $refused(fn () => DB::table('site_release_images')->insert($row($release->id, 'studio', $images['studio']->id)), 'a second row for a slot');
        $indexed = DB::table('site_release_images')->where('site_release_id', $release->id);
        $refused(fn () => $indexed->update(['site_image_id' => $images['share']->id]), 'a changed row');
        $refused(fn () => DB::table('site_release_images')->where('site_release_id', $release->id)->delete(), 'a deleted row');

        $scheduled = $site->create($this->content([], 'SYNTHETIC SCHEDULED'), 'Scheduled', $this->actor);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
        $site->schedule($scheduled->id, CarbonImmutable::parse('2026-10-01 12:30:00', 'UTC'), 0, $this->actor);
        $refused(fn () => DB::table('site_release_images')->insert($row($scheduled->id, 'share', $images['share']->id)), 'a scheduled release');
        $site->publish($release->id, 0, $this->actor, $site->pendingSchedule()->id);
        $refused(fn () => DB::table('site_release_images')->insert($row($release->id, 'share', $images['share']->id)), 'a published release');

        $migration = require database_path('migrations/2026_09_30_000028_site_release_images.php');
        $this->expectException(LogicException::class);
        $migration->down();
    }

    public function test_publishing_and_restoring_check_every_stored_image_file_first(): void
    {
        $images = $this->images('studio');
        $site = app(SiteContent::class);
        $withImage = $site->create($this->content($images), 'With a studio image', $this->actor);
        $original = $this->damageFile($images['studio']);

        $errors = $this->refusal(fn () => $site->publish($withImage->id, 0, $this->actor));
        $this->assertStringContainsString('failed its integrity check', $errors['publication'][0]);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertDatabaseCount('site_publication_revisions', 0);

        $variant = $images['studio']->variants()->where('format', 'jpeg')->orderBy('width')->firstOrFail();
        file_put_contents(Storage::disk('local')->path($variant->storage_path), $original);
        $site->publish($withImage->id, 0, $this->actor);
        $imageFree = $site->create(SiteEditorialFixtures::content('SYNTHETIC LATER'), 'Later', $this->actor);
        $site->publish($imageFree->id, 1, $this->actor);
        $this->damageFile($images['studio']);
        $errors = $this->refusal(fn () => $site->rollback($withImage->id, 2, $this->actor));
        $this->assertStringContainsString('failed its integrity check', $errors['publication'][0]);
        $this->assertSame($imageFree->id, SitePublication::findOrFail(1)->active_release_id);
    }

    public function test_a_damaged_file_fails_only_that_image_and_staff_can_still_restore_another_release(): void
    {
        $images = $this->images('studio');
        $site = app(SiteContent::class);
        $imageFree = $site->create(SiteEditorialFixtures::content('SYNTHETIC BEFORE'), 'Before', $this->actor);
        $site->publish($imageFree->id, 0, $this->actor);
        $withImage = $site->create($this->content($images, 'SYNTHETIC WITH IMAGE'), 'With a studio image', $this->actor);
        $site->publish($withImage->id, 1, $this->actor);
        $variant = $images['studio']->variants()->where('format', 'jpeg')->orderBy('width')->firstOrFail();
        $url = '/site-images/'.$variant->sha256.'.jpg';
        $this->get($url)->assertOk();
        $this->damageFile($images['studio']);

        // The page still renders with the release's copy; only the damaged file is refused, and the built-in image is not swapped in.
        $page = $this->get('/')->assertOk()->assertSee('SYNTHETIC WITH IMAGE HOME')->assertSee('Synthetic studio');
        $this->assertStringNotContainsString('/images/video-studio.jpg', (string) $page->getContent());
        $this->get($url)->assertNotFound();
        $site->rollback($imageFree->id, 2, $this->actor);
        $this->get('/')->assertOk()->assertSee('SYNTHETIC BEFORE HOME');
    }

    public function test_the_doctor_warns_when_a_stored_file_of_an_active_image_is_damaged(): void
    {
        $status = fn (): string => array_column(app(InstallationReport::class)->collect()['checks'], 'status', 'id')['site_images'];
        $this->assertSame('pass', $status());
        $images = $this->images('studio');
        $site = app(SiteContent::class);
        $site->publish($site->create($this->content($images), 'Doctor', $this->actor)->id, 0, $this->actor);
        $this->assertSame('pass', $status());
        $this->damageFile($images['studio']);
        $this->assertSame('warn', $status());
    }

    private function scannerRejects(): void
    {
        app(MalwareScanner::class)->reject = true;
    }
}
