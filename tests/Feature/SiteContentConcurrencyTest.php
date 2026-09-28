<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationRevision;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Support\Audit\AuditEvent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteContentRace;
use Tests\TestCase;

class SiteContentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent CMS publication locking is verified on MySQL, not SQLite.');
        }
    }

    public function test_competing_first_publications_create_only_one_history_and_audit_winner(): void
    {
        $site = app(SiteContent::class);
        $actors = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        $releases = [];
        foreach ($actors as $index => $actor) {
            $content = SiteContentSchema::defaults();
            $content['hero']['title'] = 'SYNTHETIC RACE '.$index;
            $releases[] = $site->create($content, 'Race '.$index, $actor);
        }
        $jobs = array_map(fn ($index) => [
            'operation' => 'publish', 'release_id' => $releases[$index]->id, 'revision' => 0, 'actor_id' => $actors[$index]->id,
        ], [0, 1]);
        $race = SiteContentRace::run($this, $jobs);
        $winner = $race['winner'];
        $this->assertSame(1, SitePublication::findOrFail(1)->revision);
        $this->assertEquals($releases[$winner]->content, $site->current());
        $history = SitePublicationRevision::where('revision', 1)->sole();
        $this->assertSame($releases[$winner]->id, $history->release_id);
        $this->assertSame($actors[$winner]->id, $history->actor_id);
        $this->assertSame('publish', $history->operation);
        $this->assertNull($history->previous_release_id);
        $audit = AuditEvent::where('action', 'site.release.publish')->sole();
        $this->assertSame($actors[$winner]->id, $audit->actor_id);
        $this->assertSame($releases[$winner]->id, (int) $audit->subject_id);
        $this->assertDatabaseCount('site_releases', 3);
        $this->assertDatabaseCount('site_publication_revisions', 2);
        $this->assertSame(1, AuditEvent::where('action', 'site.release.baseline_retained')->count());
        $this->assertSame(2, AuditEvent::where('action', 'site.release.created')->count());
    }

    public function test_publish_and_rollback_share_the_same_versioned_mutex_and_reject_aba_stale_editors(): void
    {
        $site = app(SiteContent::class);
        $actors = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        $releases = [];
        foreach (['FIRST', 'SECOND', 'THIRD'] as $title) {
            $content = SiteContentSchema::defaults();
            $content['hero']['title'] = 'SYNTHETIC '.$title;
            $releases[] = $site->create($content, $title, $actors[0]);
        }
        $site->publish($releases[0]->id, 0, $actors[0]);
        $site->publish($releases[1]->id, 1, $actors[0]);
        $jobs = [
            ['operation' => 'publish', 'release_id' => $releases[2]->id, 'revision' => 2, 'actor_id' => $actors[0]->id],
            ['operation' => 'rollback', 'release_id' => $releases[0]->id, 'revision' => 2, 'actor_id' => $actors[1]->id],
        ];
        $race = SiteContentRace::run($this, $jobs);
        $winner = $jobs[$race['winner']];
        $this->assertSame(3, SitePublication::findOrFail(1)->revision);
        $this->assertSame($winner['release_id'], SitePublication::findOrFail(1)->active_release_id);
        $history = SitePublicationRevision::where('revision', 3)->sole();
        $this->assertSame($winner['release_id'], $history->release_id);
        $this->assertSame($releases[1]->id, $history->previous_release_id);
        $this->assertSame($winner['operation'], $history->operation);
        $this->assertSame($winner['actor_id'], $history->actor_id);
        $this->assertDatabaseCount('site_publication_revisions', 4);
        $this->assertSame(3, AuditEvent::whereIn('action', ['site.release.publish', 'site.release.rollback'])->count());

        // Revisit FIRST regardless of the race winner, but never recycle its original revision.
        if ($winner['release_id'] !== $releases[0]->id) {
            $site->rollback($releases[0]->id, 3, $actors[0]);
        }
        $publication = SitePublication::findOrFail(1);
        $count = SitePublicationRevision::count();
        $audits = AuditEvent::count();
        try {
            $site->publish($releases[1]->id, 1, $actors[1]);
            $this->fail('Returning to the same release must not revive an old editor version.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('publication', $exception->errors());
        }
        $this->assertSame($publication->revision, SitePublication::findOrFail(1)->revision);
        $this->assertSame($releases[0]->id, SitePublication::findOrFail(1)->active_release_id);
        $this->assertEquals($releases[0]->content, $site->current());
        $this->assertSame($count, SitePublicationRevision::count());
        $this->assertSame($audits, AuditEvent::count());
    }
}
