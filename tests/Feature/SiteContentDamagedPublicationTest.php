<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
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
        $actor = LicenseFixtures::admin();
        $content = SiteContentSchema::defaults();
        $content['hero']['title'] = 'SYNTHETIC LIVE';
        $live = app(SiteContent::class)->create($content, 'Synthetic live', $actor);
        app(SiteContent::class)->publish($live->id, 0, $actor);
        $content['hero']['title'] = 'SYNTHETIC REPLACEMENT';
        $replacement = app(SiteContent::class)->create($content, 'Synthetic replacement', $actor);
        // The pointer now names a revision with no history row.
        DB::unprepared('DROP TRIGGER site_publications_transition');
        DB::table('site_publications')->where('id', 1)->update(['revision' => 2]);
        Log::spy();

        $this->assertUnavailable($this->get('/'));
        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context) => $message === 'Published site content is unavailable.'
            && $context === ['reason' => 'publication', 'revision' => 2, 'release_id' => $live->id]);

        $releases = DB::table('site_releases')->count();
        $revisions = DB::table('site_publication_revisions')->count();
        try {
            app(SiteContent::class)->publish($replacement->id, 2, $actor);
            $this->fail('Publishing over a damaged publication record must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(['publication' => ['The retained site publication failed its integrity check.']], $exception->errors());
        }

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
}
