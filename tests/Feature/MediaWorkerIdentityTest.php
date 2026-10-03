<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\QueueMediaProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class MediaWorkerIdentityTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent media worker identity drift requires MySQL.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
    }

    public static function identities(): array
    {
        return ['requester' => ['requester'], 'source' => ['source'], 'source track' => ['track']];
    }

    #[DataProvider('identities')]
    public function test_locked_completion_refuses_changed_retained_identity_without_redirecting_attribution(string $field): void
    {
        $actor = LicenseFixtures::admin();
        $other = LicenseFixtures::admin();
        $track = Track::create(['title' => 'Synthetic original', 'slug' => 'synthetic-original']);
        $target = Track::create(['title' => 'Synthetic other', 'slug' => 'synthetic-other']);
        $source = MediaFixtures::source($track, 'artwork');
        $otherSource = MediaFixtures::source($target, 'artwork');
        $run = app(QueueMediaProcessing::class)->handle($source, $actor);
        $audits = DB::table('audit_events')->count();
        config(['database.connections.media_identity_mutator' => DB::connection()->getConfig()]);
        $mutator = DB::connection('media_identity_mutator');
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
            (int) $mutator->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $active = true;
        $changed = false;
        $users = [];
        DB::listen(function ($query) use (&$active, &$changed, &$users, $mutator, $field, $run, $source, $target, $otherSource, $other): void {
            if (! $active || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'from `users`')
                || ! str_contains($query->sql, 'for update')) {
                return;
            }
            $users[] = (int) $query->bindings[0];
            if ($changed) {
                return;
            }
            $changed = true;
            if ($field === 'track') {
                $mutator->table('media_assets')->where('id', $source->id)->update(['track_id' => $target->id]);
            } else {
                $mutator->table('media_processing_runs')->where('id', $run->id)->update($field === 'requester'
                    ? ['requested_by' => $other->id] : ['source_asset_id' => $otherSource->id]);
            }
        });
        try {
            app(MediaProcessor::class)->handle($run->id);
            $this->fail('Completion followed an untrusted changed requester/source identity.');
        } catch (MediaFailure $error) {
            $this->assertSame('claim_lost', $error->failureCode);
        } finally {
            $active = false;
            DB::purge('media_identity_mutator');
        }
        $this->assertTrue($changed);
        $this->assertSame([$actor->id], array_values(array_unique($users)), 'No replacement requester lock may follow resource locks.');
        $this->assertSame([], $run->outputs()->get()->all());
        $this->assertSame([], Storage::disk('local')->allFiles('media/revisions'));
        $current = $run->fresh();
        if ($field === 'requester') {
            $this->assertSame('processing', $current->status);
            $this->assertSame($other->id, $current->requested_by);
            $this->assertSame($audits, DB::table('audit_events')->count());
        } else {
            $this->assertSame('failed', $current->status);
            $this->assertSame('claim_lost', $current->failure_code);
            $this->assertSame($actor->id, $current->requested_by);
            $this->assertSame($audits + 1, DB::table('audit_events')->count());
            $this->assertSame($actor->id, DB::table('audit_events')->orderByDesc('id')->value('actor_id'));
        }
        $this->assertSame(0, DB::transactionLevel());
    }
}
