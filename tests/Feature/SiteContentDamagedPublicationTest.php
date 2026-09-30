<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * Damage the guards cannot stop: MySQL TRUNCATE skips delete triggers, and a bad restore or manual repair can bypass them.
 * Each test drops a guard in a disposable database that is rebuilt afterwards.
 */
class SiteContentDamagedPublicationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.debug' => false]);
    }

    private function assertUnavailable(TestResponse $response): void
    {
        $response->assertStatus(503)->assertHeaderMissing('Location')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Retry-After', '60');
        foreach (['integrity', 'publication', 'missing'] as $private) {
            $this->assertStringNotContainsString($private, (string) $response->getContent());
        }
    }

    private function publishLive(): array
    {
        $actor = LicenseFixtures::admin();
        $content = SiteContentSchema::defaults();
        $content['hero']['title'] = 'SYNTHETIC LIVE';
        $live = app(SiteContent::class)->create($content, 'Synthetic live', $actor);
        app(SiteContent::class)->publish($live->id, 0, $actor);
        $content['hero']['title'] = 'SYNTHETIC REPLACEMENT';

        return [$actor, $live, app(SiteContent::class)->create($content, 'Synthetic replacement', $actor)];
    }

    private function assertPublishingRefused(int $releaseId, int $revision, User $actor): void
    {
        try {
            app(SiteContent::class)->publish($releaseId, $revision, $actor);
            $this->fail('Publishing over a damaged publication record must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(['publication' => ['The retained site publication failed its integrity check.']], $exception->errors());
        }
    }

    public function test_a_missing_publication_record_fails_closed_and_is_logged(): void
    {
        DB::unprepared('DROP TRIGGER site_publications_retain');
        DB::table('site_publications')->where('id', 1)->delete();
        Log::spy();

        $this->assertUnavailable($this->get('/'));
        $this->assertUnavailable($this->get('/about'));
        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context) => $message === 'Published site content is unavailable.'
            && $context === ['reason' => 'missing', 'revision' => null, 'release_id' => null]);
    }

    public function test_a_damaged_publication_record_refuses_publishing_and_sends_staff_to_a_backup(): void
    {
        [$actor, $live, $replacement] = $this->publishLive();
        // The pointer now names a revision with no history row.
        DB::unprepared('DROP TRIGGER site_publications_transition');
        DB::table('site_publications')->where('id', 1)->update(['revision' => 2]);
        Log::spy();

        $this->assertUnavailable($this->get('/'));
        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context) => $message === 'Published site content is unavailable.'
            && $context === ['reason' => 'publication', 'revision' => 2, 'release_id' => $live->id]);

        $releases = DB::table('site_releases')->count();
        $revisions = DB::table('site_publication_revisions')->count();
        $this->assertPublishingRefused($replacement->id, 2, $actor);

        $this->actingAs($actor);
        $page = Livewire::test(ListSiteReleases::class)->mountAction('createDraft');
        $notification = collect(session('filament.claimed_notifications', session('filament.notifications', [])))
            ->firstWhere('title', 'The published site content is unavailable');
        $this->assertNotNull($notification);
        $this->assertStringContainsString('Restore verified data from a backup', (string) $notification['body']);
        $page->assertSet('mountedActions', []);
        $this->assertDatabaseCount('site_releases', $releases);
        $this->assertDatabaseCount('site_publication_revisions', $revisions);
    }

    public function test_history_naming_a_release_that_is_gone_fails_the_integrity_check_instead_of_answering_not_found(): void
    {
        [$actor, $live, $replacement] = $this->publishLive();
        // A restore with foreign-key checks off, as a plain dump restore runs, can drop the active release row.
        DB::unprepared('DROP TRIGGER site_releases_immutable_delete');
        $sqlite = DB::getDriverName() === 'sqlite';
        DB::statement($sqlite ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS = 0');
        try {
            DB::table('site_releases')->where('id', $live->id)->delete();
        } finally {
            DB::statement($sqlite ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');
        }
        Log::spy();

        $this->assertUnavailable($this->get('/'));
        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context) => $message === 'Published site content is unavailable.'
            && $context === ['reason' => 'publication', 'revision' => 1, 'release_id' => $live->id]);
        $this->assertPublishingRefused($replacement->id, 1, $actor);
    }

    public function test_a_seed_record_recreated_after_publication_fails_closed_instead_of_showing_code_defaults(): void
    {
        [$actor, , $replacement] = $this->publishLive();
        DB::unprepared('DROP TRIGGER site_publications_retain');
        DB::table('site_publications')->where('id', 1)->delete();
        // The singleton guard admits the original empty record, which alone would look like a never-published site.
        DB::table('site_publications')->insert(['id' => 1, 'revision' => 0, 'active_release_id' => null, 'updated_at' => null]);
        Log::spy();

        $response = $this->get('/');
        $this->assertUnavailable($response);
        $this->assertStringNotContainsString(SiteContentSchema::defaults()['hero']['title'], (string) $response->getContent());
        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context) => $message === 'Published site content is unavailable.'
            && $context === ['reason' => 'publication', 'revision' => 0, 'release_id' => null]);
        $this->assertPublishingRefused($replacement->id, 0, $actor);
        $this->assertDatabaseCount('site_publication_revisions', 2);
    }
}
