<?php

namespace Tests\Feature;

use App\Application\Media\IngestMediaUpload;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\BindStemsToRecording;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\QueueMediaProcessing;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class MediaWriterAuthorityTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    public static function writers(): array
    {
        return ['queue' => ['queue'], 'intake' => ['intake'], 'bind' => ['bind']];
    }

    private function fixture(string $writer): array
    {
        MediaFixtures::configure();
        $actor = LicenseFixtures::admin();
        $track = Track::create(['title' => 'Synthetic media writer', 'slug' => 'synthetic-media-writer']);
        $source = MediaFixtures::source($track, 'artwork');
        if ($writer === 'bind') {
            $media = MediaFixtures::readyTrackMedia($track, $actor);
            $stemsSource = MediaFixtures::source($track, 'stems_zip', StemsFixtures::zip([['name' => 'Tone.wav']]));
            $run = app(QueueMediaProcessing::class)->handle($stemsSource, $actor);
            app(MediaProcessor::class)->handle($run->id);
            $stems = $run->outputs()->sole();
            $data = ['master_asset_id' => $media['master_wav']->id, 'preview_asset_id' => $media['preview_tagged']->id,
                'verification_reference' => 'SYNTHETIC-RECORDING', 'same_recording_confirmed' => true];
        }

        return compact('actor', 'track', 'source') + ($writer === 'bind' ? compact('stems', 'data') : []);
    }

    public static function withdrawnActors(): array
    {
        $cases = [];
        foreach (self::writers() as $label => [$writer]) {
            foreach (['role', 'email', 'deleted', 'unsaved', 'customer'] as $state) {
                $cases[$label.' / '.$state] = [$writer, $state];
            }
        }

        return $cases;
    }

    private function write(string $writer, array $fixture, User $actor): mixed
    {
        return match ($writer) {
            'queue' => app(QueueMediaProcessing::class)->handle($fixture['source'], $actor),
            'intake' => app(IngestMediaUpload::class)->handle($fixture['track'],
                UploadedFile::fake()->createWithContent('synthetic.png', MediaFixtures::png()), 'artwork', $actor),
            'bind' => app(BindStemsToRecording::class)->handle($fixture['stems'], $fixture['data'], $actor),
        };
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['tracks', 'media_assets', 'media_processing_runs', 'stems_recordings', 'audit_events']);
    }

    #[DataProvider('writers')]
    public function test_current_actor_is_read_inside_the_transaction_before_any_catalog_read(string $writer): void
    {
        $fixture = $this->fixture($writer);
        $active = true;
        $trace = [];
        DB::listen(function (QueryExecuted $query) use (&$active, &$trace): void {
            if ($active && preg_match('/\Aselect\b.*\bfrom\s+["`]?(users|tracks|media_assets|media_processing_runs|stems_recordings)["`]?(?:\s|$)/i', $query->sql, $matches)) {
                $trace[] = ['table' => $matches[1], 'level' => DB::transactionLevel()];
            }
        });
        try {
            $saved = $this->write($writer, $fixture, $fixture['actor']);
        } finally {
            $active = false;
        }
        $this->assertNotEmpty($trace);
        $this->assertSame('users', $trace[0]['table']);
        $this->assertNotEmpty(array_filter($trace, fn ($row) => $row['table'] === 'users' && $row['level'] >= 1));
        $this->assertSame([], array_values(array_filter($trace, fn ($row) => $row['level'] < 1 && ($writer !== 'intake' || $row['table'] !== 'users'))),
            'Current authority and catalog reads must belong to the mutation transaction.');
        $this->assertTrue($saved->exists);
        $this->assertSame($fixture['actor']->id, DB::table('audit_events')->orderByDesc('id')->value('actor_id'));
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('withdrawnActors')]
    public function test_missing_or_withdrawn_persisted_authority_has_no_catalog_or_audit_effect(string $writer, string $state): void
    {
        $fixture = $this->fixture($writer);
        $actor = $fixture['actor'];
        if ($state === 'role' || $state === 'email') {
            DB::table('users')->where('id', $actor->id)->update($state === 'role' ? ['is_admin' => false] : ['email_verified_at' => null]);
        } elseif ($state === 'deleted') {
            $actor = LicenseFixtures::admin();
            User::findOrFail($actor->id)->delete();
        } elseif ($state === 'unsaved') {
            $actor = (new User)->forceFill(['id' => $actor->id, 'is_admin' => true, 'email_verified_at' => now()]);
        } else {
            $actor = User::factory()->create();
            $actor->forceFill(['is_admin' => true, 'email_verified_at' => now()]);
        }
        $before = $this->evidence();
        try {
            $this->write($writer, $fixture, $actor);
            $this->fail('Unavailable persisted authority mutated the catalog.');
        } catch (AuthorizationException) {
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('writers')]
    public function test_existing_caller_transaction_can_rollback_mutation_and_attributed_audit_together(string $writer): void
    {
        $fixture = $this->fixture($writer);
        $fixture['actor']->forceFill(['is_admin' => false, 'email_verified_at' => null]);
        $before = $this->evidence();
        $audits = DB::table('audit_events')->count();
        DB::beginTransaction();
        try {
            $this->write($writer, $fixture, $fixture['actor']);
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame($audits + 1, DB::table('audit_events')->count());
            $this->assertNotSame($before, $this->evidence());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function oldSnapshotActors(): array
    {
        $cases = [];
        foreach (self::writers() as [$writer]) {
            foreach (['role', 'email'] as $field) {
                $cases[$writer.' / '.$field] = [$writer, $field];
            }
        }

        return $cases;
    }

    #[DataProvider('oldSnapshotActors')]
    public function test_old_repeatable_read_authority_is_not_used_by_media_mutation(string $writer, string $field): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent media authority withdrawal from an old caller snapshot requires MySQL.');
        }
        $fixture = $this->fixture($writer);
        $before = $this->evidence();
        config(['database.connections.media_authority_mutator' => DB::connection()->getConfig()]);
        $mutator = DB::connection('media_authority_mutator');
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
            (int) $mutator->selectOne('SELECT CONNECTION_ID() AS id')->id);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $retained = User::findOrFail($fixture['actor']->id);
            $mutator->table('users')->where('id', $retained->id)->update($field === 'role'
                ? ['is_admin' => false] : ['email_verified_at' => null]);
            $oldView = User::findOrFail($retained->id);
            $this->assertTrue($oldView->is_admin);
            $this->assertNotNull($oldView->email_verified_at);
            try {
                $this->write($writer, $fixture, $retained);
                $this->fail('Withdrawn current authority wrote media through an old caller snapshot.');
            } catch (AuthorizationException) {
            }
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame($before, $this->evidence());
        } finally {
            DB::rollBack();
            DB::purge('media_authority_mutator');
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function withdrawnRequester(): array
    {
        return ['role' => ['role'], 'email' => ['email']];
    }

    #[DataProvider('withdrawnRequester')]
    public function test_queued_worker_requester_attribution_survives_later_interactive_authority_withdrawal(string $field): void
    {
        $fixture = $this->fixture('queue');
        $run = app(QueueMediaProcessing::class)->handle($fixture['source'], $fixture['actor']);
        DB::table('users')->where('id', $fixture['actor']->id)->update($field === 'role'
            ? ['is_admin' => false] : ['email_verified_at' => null]);
        $before = DB::table('audit_events')->count();
        $completed = app(MediaProcessor::class)->handle($run->id);
        $this->assertSame('completed', $completed->status);
        $this->assertSame($fixture['actor']->id, $completed->requested_by);
        $this->assertSame($fixture['actor']->id, $completed->outputs()->sole()->verified_by);
        $this->assertSame($before + 1, DB::table('audit_events')->count());
        $this->assertSame($fixture['actor']->id, DB::table('audit_events')->orderByDesc('id')->value('actor_id'));
        $this->assertSame($completed->id, app(MediaProcessor::class)->handle($completed->id)->id);
        $this->assertSame($before + 1, DB::table('audit_events')->count());
    }
}
