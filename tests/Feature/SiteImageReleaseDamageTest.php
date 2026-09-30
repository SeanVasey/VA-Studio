<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\SiteContent;
use App\Models\User;
use App\Support\Diagnostics\InstallationReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\Support\SiteImageFixtures as F;
use Tests\TestCase;

/** The scheduler outside a test transaction, and image evidence changed behind guards dropped in a disposable database. */
class SiteImageReleaseDamageTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $this->actor = LicenseFixtures::admin();
        config(['app.debug' => false]);
    }

    private function withStudio(SiteImage $studio, string $marker): array
    {
        $content = SiteEditorialFixtures::content($marker);
        $content['schema_version'] = 3;
        $content['images'] = ['hero' => null, 'studio' => ['id' => $studio->id, 'alt' => 'Synthetic studio'], 'share' => null];

        return $content;
    }

    public function test_a_scheduled_release_whose_image_file_is_damaged_fails_its_integrity_check(): void
    {
        $studio = F::ready('studio', $this->actor);
        $site = app(SiteContent::class);
        $release = $site->create($this->withStudio($studio, 'SYNTHETIC SCHEDULED'), 'Scheduled with an image', $this->actor);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
        $schedule = $site->schedule($release->id, CarbonImmutable::parse('2026-10-01 12:05:00', 'UTC'), 0, $this->actor);
        $variant = $studio->variants()->where('format', 'webp')->orderByDesc('width')->firstOrFail();
        $path = Storage::disk('local')->path($variant->storage_path);
        file_put_contents($path, strrev((string) file_get_contents($path)));

        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:05:00', 'UTC'));
        $this->assertSame(['schedule_id' => $schedule->id, 'outcome' => 'integrity'], $site->runDueSchedule());
        $this->assertSame(['failed', 'integrity'], [$schedule->fresh()->state, $schedule->fresh()->outcome]);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
    }

    public static function changedEvidence(): array
    {
        return [
            'a variant hash' => ['site_image_variants_immutable', fn (SiteImage $image) => DB::table('site_image_variants')
                ->where('id', $image->variants()->firstOrFail()->id)->update(['sha256' => str_repeat('0', 64)])],
            'the release index' => ['site_release_images_retain', fn (SiteImage $image) => DB::table('site_release_images')
                ->where('site_image_id', $image->id)->delete()],
            'the scan evidence' => ['site_images_transition', fn (SiteImage $image) => DB::table('site_images')->where('id', $image->id)
                ->update(['evidence' => json_encode(['source_scan' => ['engine' => 'unverified', 'status' => 'clean', 'sha256' => $image->source_sha256]])])],
        ];
    }

    #[DataProvider('changedEvidence')]
    public function test_changed_image_evidence_fails_the_release_closed_and_staff_can_restore_another(string $guard, \Closure $change): void
    {
        $studio = F::ready('studio', $this->actor);
        $site = app(SiteContent::class);
        $before = $site->create(SiteEditorialFixtures::content('SYNTHETIC BEFORE'), 'Before', $this->actor);
        $site->publish($before->id, 0, $this->actor);
        $withImage = $site->create($this->withStudio($studio, 'SYNTHETIC WITH IMAGE'), 'With an image', $this->actor);
        $site->publish($withImage->id, 1, $this->actor);
        $this->get('/')->assertOk()->assertSee('SYNTHETIC WITH IMAGE HOME');

        DB::unprepared('DROP TRIGGER '.$guard);
        $change($studio);
        Log::spy();

        $this->get('/')->assertStatus(503);
        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context) => $message === 'Published site content is unavailable.'
            && $context['reason'] === 'release');
        try {
            $site->preview($withImage->id, $this->actor);
            $this->fail('A release with changed image evidence must not preview.');
        } catch (ValidationException $exception) {
            $this->assertSame(['publication' => ['An image in the retained site release failed its integrity check.']], $exception->errors());
        }
        // The pointer check never looks at images, so restoring an intact release works.
        $site->rollback($before->id, 2, $this->actor);
        $this->get('/')->assertOk()->assertSee('SYNTHETIC BEFORE HOME');
    }

    public static function lostRows(): array
    {
        return [
            'the image row' => ['site_images_retain', fn (SiteImage $image) => DB::table('site_images')->where('id', $image->id)->delete()],
            'its variant rows' => ['site_image_variants_retain', fn (SiteImage $image) => DB::table('site_image_variants')->where('site_image_id', $image->id)->delete()],
        ];
    }

    /** With nothing left to hash, the doctor's file check must not pass by default. */
    #[DataProvider('lostRows')]
    public function test_the_doctor_warns_when_an_active_image_has_no_files_to_check(string $guard, \Closure $lose): void
    {
        $studio = F::ready('studio', $this->actor);
        $site = app(SiteContent::class);
        $site->publish($site->create($this->withStudio($studio, 'SYNTHETIC DOCTOR'), 'Doctor', $this->actor)->id, 0, $this->actor);
        $status = fn (): string => array_column(app(InstallationReport::class)->collect()['checks'], 'status', 'id')['site_images'];
        $this->assertSame('pass', $status());

        DB::unprepared('DROP TRIGGER '.$guard);
        Schema::withoutForeignKeyConstraints(fn () => $lose($studio));
        $this->assertSame('warn', $status());
    }
}
