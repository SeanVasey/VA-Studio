<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SiteReleaseImage;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Domain\SiteBuilder\SiteImageReferences;
use App\Domain\SiteBuilder\SiteRelatedTracks;
use App\Filament\Forms\PreserveTrackIdState;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use App\Support\Diagnostics\InstallationReport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\Support\SiteImageFixtures;
use Tests\Support\SiteRelatedTrackFixtures as F;
use Tests\TestCase;

class SiteRelatedTrackContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
    }

    public static function invalidIds(): array
    {
        return [
            'numeric string' => [['1']], 'float' => [[1.0]], 'boolean' => [[true]], 'null' => [[null]],
            'zero' => [[0]], 'negative' => [[-1]], 'duplicates' => [[1, 1]],
            'sparse list' => [[1 => 2]], 'seventh item' => [range(1, 7)],
            'object payload' => [[['id' => 1, 'href' => '/tracks/one']]], 'not a list' => ['1'],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_retained_ids_are_strict_native_integer_lists(mixed $ids): void
    {
        $content = F::content();
        $content['blog']['entries'][0]['related_track_ids'] = $ids;
        $this->expectException(ValidationException::class);
        SiteContentSchema::validate($content);
    }

    public function test_order_empty_lists_cross_entry_reuse_and_all_existing_entry_bounds_are_preserved(): void
    {
        $content = F::content([6, 2, 1, 5, 3, 4], [6, 2]);
        $this->assertSame($content, SiteContentSchema::validate($content));
        $this->assertSame([], $content['blog']['entries'][1]['related_track_ids']);
        foreach (['blog', 'videos'] as $section) {
            $template = $content[$section]['entries'][0];
            $content[$section]['entries'] = array_map(fn (int $id): array => array_replace($template, ['slug' => 'entry-'.$id, 'related_track_ids' => range(1, 6)]), range(1, 30));
        }
        $this->assertSame($content, SiteContentSchema::validate($content));
        $content['blog']['entries'][] = array_replace($content['blog']['entries'][0], ['slug' => 'overflow']);
        $this->expectException(ValidationException::class);
        SiteContentSchema::validate($content);
    }

    public static function invalidShape(): array
    {
        return ['missing required IDs' => ['ids'], 'missing inherited images' => ['images'], 'unknown href snapshot' => ['href'], 'unknown future schema' => ['version']];
    }

    #[DataProvider('invalidShape')]
    public function test_schema_four_requires_its_exact_shape(string $kind): void
    {
        $content = F::content();
        match ($kind) {
            'ids' => $content['blog']['entries'][0] = array_diff_key($content['blog']['entries'][0], ['related_track_ids' => true]),
            'images' => $content = array_diff_key($content, ['images' => true]),
            'href' => $content['videos']['entries'][0]['href'] = '/tracks/forged',
            'version' => $content['schema_version'] = 5,
        };
        $this->expectException(ValidationException::class);
        SiteContentSchema::validate($content);
    }

    public static function formIds(): array
    {
        return [
            'integer' => [42, 42], 'canonical digits' => ['42', 42], 'maximum' => [(string) PHP_INT_MAX, PHP_INT_MAX],
            'zero' => ['0', null], 'leading zero' => ['042', null], 'exponent' => ['4.2e1', null], 'decimal' => ['42.0', null],
            'float' => [42.0, null], 'true' => [true, null], 'space' => [' 42', null], 'negative' => [-1, null],
            'overflow' => [(string) PHP_INT_MAX.'0', null], 'null' => [null, null],
        ];
    }

    #[DataProvider('formIds')]
    public function test_form_conversion_accepts_only_lossless_canonical_ids(mixed $value, ?int $expected): void
    {
        $this->assertSame($expected, SiteRelatedTracks::formId($value));
    }

    public function test_select_browser_state_keeps_the_entire_php_integer_range_exact_without_coercing_hostile_scalars(): void
    {
        $cast = new PreserveTrackIdState;
        $state = $cast->set(PHP_INT_MAX);
        $this->assertSame((string) PHP_INT_MAX, $state);
        $this->assertSame('{"initialState":"'.PHP_INT_MAX.'"}', json_encode(['initialState' => $state], JSON_THROW_ON_ERROR));
        $this->assertSame(PHP_INT_MAX, SiteRelatedTracks::formId($cast->get($state)));
        $this->assertTrue($cast->get($cast->set(true)));
        $this->assertSame(42.0, $cast->get($cast->set(42.0)));
        $this->assertNull($cast->get(['id' => 42]));
        $this->assertNull($cast->set(['id' => 42]));
    }

    public function test_creation_checks_retained_url_identity_without_demanding_current_availability(): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = app(PublishTrack::class)->unpublish($fixture['track'], $actor);
        $draft = Track::create(['title' => 'Unpublished synthetic identity', 'slug' => 'unreserved-synthetic', 'artist' => 'Test']);
        $site = app(SiteContent::class);
        $audits = AuditEvent::count();
        foreach ([$draft->id, PHP_INT_MAX] as $id) {
            try {
                $site->create(F::content([$id]), 'Invalid identity', $actor);
                $this->fail('An unreserved or missing track cannot enter a release.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('content.blog.entries.0.related_track_ids.0', $exception->errors());
            }
        }
        $this->assertDatabaseCount('site_releases', 0);
        $this->assertSame($audits, AuditEvent::count());
        $release = $site->create(F::content([$track->id], [$track->id]), 'Withdrawn identity retained', $actor);
        $site->publish($release->id, 0, $actor);
        $this->assertSame($release->content, $site->current());
        $this->assertSame($track->id, $site->preview($release->id, $actor)['blog']['entries'][0]['related_track_ids'][0]);
    }

    public function test_historical_versions_hashes_and_revisions_survive_cross_version_publication_and_aba(): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $contents = [SiteEditorialFixtures::legacy(), SiteEditorialFixtures::content('V2'), array_replace(SiteEditorialFixtures::content('V3'), ['schema_version' => 3, 'images' => SiteContentSchema::NO_IMAGES]), F::content()];
        $releases = [];
        foreach ($contents as $index => $content) {
            $releases[] = $site->create($content, 'Retained v'.($index + 1), $actor);
            $site->publish($releases[$index]->id, $index, $actor);
        }
        $site->rollback($releases[1]->id, 4, $actor);
        $site->rollback($releases[3]->id, 5, $actor);
        try {
            $site->rollback($releases[1]->id, 4, $actor);
            $this->fail('Returning to v4 cannot revive an old revision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('publication', $exception->errors());
        }
        $this->assertSame(6, SitePublication::findOrFail(1)->revision);
        foreach ($releases as $index => $release) {
            $this->assertSame($contents[$index], $release->fresh()->content);
            $this->assertSame(CanonicalJson::hash($contents[$index]), $release->fresh()->content_hash);
        }
        foreach ([2, 3] as $version) {
            $old = $contents[$version - 1];
            $old['blog']['entries'][0]['related_track_ids'] = [];
            try {
                SiteContentSchema::validate($old);
                $this->fail('Older schemas must still reject the new key.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('content.blog.entries.0', $exception->errors());
            }
        }
    }

    public function test_fresh_authority_and_audit_failure_remain_atomic_for_schema_four(): void
    {
        $actor = LicenseFixtures::admin();
        User::whereKey($actor->id)->update(['is_admin' => false]);
        try {
            app(SiteContent::class)->create(F::content(), 'Revoked', $actor);
            $this->fail('Stale staff authority cannot create a release.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('site_releases', 0);
        }
        User::whereKey($actor->id)->update(['is_admin' => true]);
        $audits = AuditEvent::count();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'site.release.created') {
                throw new \RuntimeException('Synthetic related audit failure');
            }
        });
        try {
            app(SiteContent::class)->create(F::content(), 'Atomic', $actor);
            $this->fail('A missing audit must roll back the release.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic related audit failure', $exception->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('site_releases', 0);
        $this->assertSame($audits, AuditEvent::count());
    }

    public function test_image_bearing_schema_four_pins_indexes_checks_files_and_reports_damage(): void
    {
        $actor = LicenseFixtures::admin();
        $image = SiteImageFixtures::ready('studio', $actor);
        $content = F::content();
        $content['images']['studio'] = ['id' => $image->id, 'alt' => 'Synthetic studio'];
        $site = app(SiteContent::class);
        $release = $site->create($content, 'V4 image', $actor);
        $this->assertSame(['id' => $image->id, 'manifest' => $image->manifest_sha256], SiteImageReferences::of($release->content)['studio']);
        $this->assertSame($image->id, SiteReleaseImage::where('site_release_id', $release->id)->sole()->site_image_id);
        $this->assertSame([$image->id], array_values(AuditEvent::where('action', 'site.release.created')->sole()->context['images']));
        $site->publish($release->id, 0, $actor);
        $this->assertSame($release->content, $site->current());
        $report = fn (): array => collect(app(InstallationReport::class)->collect()['checks'])->firstWhere('id', 'site_images');
        $this->assertSame('pass', $report()['status']);
        $variant = $image->variants()->firstOrFail();
        $path = Storage::disk('local')->path($variant->storage_path);
        chmod($path, 0600);
        file_put_contents($path, strrev((string) file_get_contents($path)));
        $this->assertSame('warn', $report()['status']);
        $this->expectException(ValidationException::class);
        $site->rollback($release->id, 1, $actor);
    }
}
