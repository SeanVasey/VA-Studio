<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\ReadTrackPublicationManifest;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\SaveRightsDeclaration;
use App\Domain\Rights\VerifyRightsDeclaration;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class RightsDeclarationWriterConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent current-authority, affected-track and same-actor legacy FK waits require MySQL; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function pending(string $suffix = 'source'): array
    {
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $track = Track::create(['title' => 'Synthetic rights '.$suffix, 'slug' => 'synthetic-rights-'.$suffix])->refresh();
        $declaration = $track->rightsDeclarations()->create(['provenance_reference' => 'SYNTHETIC-RETAINED-'.$suffix,
            'sample_disclosure' => 'Synthetic original '.$suffix, 'status' => 'pending'])->refresh();

        return compact('actor', 'track', 'declaration');
    }

    private function data(RightsDeclaration $record, array $changes = []): array
    {
        return array_replace($record->only(['track_id', 'provenance_reference', 'sample_disclosure']), $changes);
    }

    private function input(string $operation, User $actor, array $input = []): array
    {
        return $input + ['operation' => $operation, 'actor_id' => $actor->id,
            'media_root' => Storage::disk('local')->path(''), 'require_mfa' => false];
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['tracks', 'rights_declarations', 'audit_events']);
    }

    public static function authorityOrdering(): array
    {
        $cases = [];
        foreach (['staff role' => ['is_admin', false], 'verified email' => ['email_verified_at', null],
            'MFA enrollment' => ['app_authentication_secret', null]] as $label => [$field, $value]) {
            foreach (['writer first' => 0, 'withdrawal first' => 1] as $order => $first) {
                $cases[$label.' '.$order] = [$field, $value, $first];
            }
        }

        return $cases;
    }

    #[DataProvider('authorityOrdering')]
    public function test_current_authority_withdrawal_and_reviewed_verification_serialize_on_the_exact_actor_row(string $field, mixed $value, int $first): void
    {
        ['actor' => $actor, 'declaration' => $declaration] = $this->pending();
        $review = app(VerifyRightsDeclaration::class)->review($declaration, $actor);
        $before = $this->evidence();
        $inputs = [$this->input('verify', $actor, ['declaration_id' => $declaration->id, 'review' => $review, 'require_mfa' => true]),
            $this->input('withdraw', $actor, ['field' => $field, 'value' => $value, 'require_mfa' => true])];
        $inputs[$first] += ['pause_table' => 'users', 'pause_id' => $actor->id];
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $actor, $field, $value, $declaration, $before): void {
            $second = 1 - $first;
            touch($directory.'/start-'.$first);
            $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/start-'.$second);
            $this->observeWait($connections[$second], $connections[$first], 'users', $actor->id, $processes);
            touch($directory.'/release-'.$first);
            $results = $this->results($processes, $connections);
            $this->assertSame('withdrawn', $results[1]['result']);
            $this->assertSame($value, User::findOrFail($actor->id)->{$field});
            if ($first === 0) {
                $this->assertSame('saved', $results[0]['result']);
                $this->assertSame('verified', $declaration->fresh()->status);
                $this->assertSame($actor->id, $declaration->fresh()->verified_by);
                $this->assertSame(1, AuditEvent::where('action', 'rights.declaration.verified')->count());
                $this->assertSame($results[0]['row'], $declaration->fresh()->getAttributes());
            } else {
                $this->assertSame('denied', $results[0]['result']);
                $this->assertArrayNotHasKey('row', $results[0]);
                $this->assertSame($before, $this->evidence());
            }
        });
    }

    public static function manifestFences(): array
    {
        $cases = [];
        foreach (['append' => ['create', 'source'], 'verify older pending' => ['verify', 'source'],
            'retarget source' => ['retarget', 'source'], 'retarget target' => ['retarget', 'target']] as $label => [$operation, $fence]) {
            foreach (['manifest first' => 0, 'writer first' => 1] as $order => $first) {
                $cases[$label.' '.$order] = [$operation, $fence, $first];
            }
        }

        return $cases;
    }

    #[DataProvider('manifestFences')]
    public function test_manifest_capture_and_supported_rights_mutation_share_each_exact_affected_track_fence(string $operation, string $fence, int $first): void
    {
        $fixture = QuoteFixtures::selection();
        $source = $fixture['track'];
        $writer = $fixture['actor'];
        $save = app(SaveRightsDeclaration::class);
        // An older pending declaration remains editable/verifiable without inventing a latest-only policy.
        $pending = $save->create(['track_id' => $source->id, 'provenance_reference' => 'SYNTHETIC-OLDER-PENDING',
            'sample_disclosure' => 'Synthetic older pending disclosure'], $writer);
        $latest = $save->create(['track_id' => $source->id, 'provenance_reference' => 'SYNTHETIC-LATEST-VERIFIED',
            'sample_disclosure' => 'Synthetic latest verified disclosure'], $writer);
        app(VerifyRightsDeclaration::class)->handle($latest, $writer);
        app(PublishOffer::class)->handle($fixture['offer'], $writer);
        $target = $operation === 'retarget' ? QuoteFixtures::selection()['track'] : $source;
        $track = $fence === 'target' ? $target : $source;
        $reader = LicenseFixtures::admin();
        $this->assertNotSame($reader->id, $writer->id, 'The track test must not substitute same-actor serialization for a track fence.');
        $baseline = app(ReadTrackPublicationManifest::class)->handle($track->id, $reader);
        $tracksBefore = DB::table('tracks')->orderBy('id')->get()->toJson();
        $latestBefore = $latest->fresh()->getAttributes();
        $auditCount = AuditEvent::count();
        $writerInput = match ($operation) {
            'create' => ['data' => ['track_id' => $source->id, 'provenance_reference' => 'SYNTHETIC-NEW-PENDING', 'sample_disclosure' => 'Synthetic new pending']],
            'verify' => ['declaration_id' => $pending->id, 'review' => app(VerifyRightsDeclaration::class)->review($pending, $writer)],
            'retarget' => ['declaration_id' => $pending->id, 'review' => $save->review($pending, $writer), 'data' => $this->data($pending, ['track_id' => $target->id])],
        };
        $inputs = [$this->input('capture', $reader, ['track_id' => $track->id]), $this->input($operation, $writer, $writerInput)];
        $inputs[$first] += ['pause_table' => 'tracks', 'pause_id' => $track->id];
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $track, $operation, $baseline, $source, $target, $pending): void {
            $second = 1 - $first;
            touch($directory.'/start-'.$first);
            $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/start-'.$second);
            $this->observeWait($connections[$second], $connections[$first], 'tracks', $track->id, $processes);
            touch($directory.'/release-'.$first);
            $results = $this->results($processes, $connections);
            $this->assertSame('saved', $results[1]['result']);
            if ($operation === 'create' && $first === 1) {
                $this->assertSame('blocked', $results[0]['result']);
                $this->assertArrayNotHasKey('hash', $results[0]);
            } else {
                $this->assertSame('captured', $results[0]['result']);
                $this->assertSame($baseline->hash(), $results[0]['hash']);
            }
            $this->assertSame($results[1]['row'], RightsDeclaration::findOrFail($results[1]['row']['id'])->getAttributes());
            if ($operation === 'retarget') {
                $this->assertSame($target->id, $pending->fresh()->track_id);
                $this->assertSame([$source->id, $target->id], $this->trackLocks($results[1]));
            } elseif ($operation === 'verify') {
                $this->assertSame('verified', $pending->fresh()->status);
            } else {
                $this->assertSame('pending', $source->rightsDeclarations()->latest('id')->firstOrFail()->status);
            }
        });
        $this->assertSame($tracksBefore, DB::table('tracks')->orderBy('id')->get()->toJson());
        $this->assertSame($latestBefore, $latest->fresh()->getAttributes());
        $this->assertSame($auditCount + 1, AuditEvent::count());
    }

    public function test_opposite_retargets_lock_tracks_in_numeric_order_and_preserve_both_current_pending_rows(): void
    {
        $first = $this->pending('first');
        $second = $this->pending('second');
        $save = app(SaveRightsDeclaration::class);
        $beforeTracks = DB::table('tracks')->orderBy('id')->get()->toJson();
        $original = [$first['declaration']->getAttributes(), $second['declaration']->getAttributes()];
        $ids = [$first['track']->id, $second['track']->id];
        sort($ids, SORT_NUMERIC);
        $inputs = [];
        foreach ([$first, $second] as $index => $fixture) {
            $target = $index === 0 ? $second['track'] : $first['track'];
            $inputs[] = $this->input('retarget', $fixture['actor'], ['declaration_id' => $fixture['declaration']->id,
                'review' => $save->review($fixture['declaration'], $fixture['actor']),
                'data' => $this->data($fixture['declaration'], ['track_id' => $target->id]), 'pause_table' => 'tracks', 'pause_id' => $ids[0]]);
        }
        $this->race($inputs, function ($directory, $processes, $connections) use ($ids): void {
            touch($directory.'/start-0');
            touch($directory.'/start-1');
            $this->await(fn () => is_file($directory.'/locked-0') || is_file($directory.'/locked-1'), $processes);
            $winner = is_file($directory.'/locked-0') ? 0 : 1;
            $loser = 1 - $winner;
            $this->observeWait($connections[$loser], $connections[$winner], 'tracks', $ids[0], $processes);
            // The first writer releases only after the exact lower-ID wait was observed. The second then reaches its own barrier.
            touch($directory.'/release-'.$winner);
            $this->await(fn () => is_file($directory.'/locked-'.$loser), [$processes[$loser]]);
            touch($directory.'/release-'.$loser);
            $results = $this->results($processes, $connections);
            foreach ($results as $result) {
                $this->assertSame('saved', $result['result']);
                $this->assertSame($ids, $this->trackLocks($result));
            }
        });
        foreach ([$first, $second] as $index => $fixture) {
            $fresh = $fixture['declaration']->fresh()->getAttributes();
            $expected = $original[$index];
            unset($fresh['track_id'], $fresh['updated_at'], $expected['track_id'], $expected['updated_at']);
            $this->assertSame($expected, $fresh);
            $this->assertSame(($index === 0 ? $second : $first)['track']->id, $fixture['declaration']->fresh()->track_id);
        }
        $this->assertSame($beforeTracks, DB::table('tracks')->orderBy('id')->get()->toJson());
        $this->assertSame(2, AuditEvent::where('action', 'rights.declaration.updated')->count());
    }

    public static function waitingChanges(): array
    {
        return ['changed disclosure' => ['edit'], 'changed source association' => ['retarget']];
    }

    #[DataProvider('waitingChanges')]
    public function test_pending_identity_changed_while_verifier_waits_is_refused_without_acquiring_a_new_track_after_rights(string $operation): void
    {
        ['actor' => $editor, 'track' => $source, 'declaration' => $declaration] = $this->pending();
        $verifier = LicenseFixtures::admin();
        $target = Track::create(['title' => 'Synthetic waiting target', 'slug' => 'synthetic-waiting-target']);
        $save = app(SaveRightsDeclaration::class);
        $inputs = [$this->input($operation, $editor, ['declaration_id' => $declaration->id, 'review' => $save->review($declaration, $editor),
            'data' => $this->data($declaration, $operation === 'retarget' ? ['track_id' => $target->id] : ['sample_disclosure' => 'Synthetic committed changed disclosure']),
            'pause_table' => 'tracks', 'pause_id' => $source->id]),
            $this->input('verify', $verifier, ['declaration_id' => $declaration->id, 'review' => app(VerifyRightsDeclaration::class)->review($declaration, $verifier)])];
        $this->race($inputs, function ($directory, $processes, $connections) use ($source, $declaration): void {
            touch($directory.'/start-0');
            $this->await(fn () => is_file($directory.'/locked-0'), $processes);
            touch($directory.'/start-1');
            $this->observeWait($connections[1], $connections[0], 'tracks', $source->id, $processes);
            touch($directory.'/release-0');
            $results = $this->results($processes, $connections);
            $this->assertSame('saved', $results[0]['result']);
            $this->assertSame('blocked', $results[1]['result']);
            $this->assertArrayHasKey('rights', $results[1]['errors']);
            $this->assertSame([$source->id], $this->trackLocks($results[1]), 'A stale association must refuse rather than taking a newly discovered parent after the rights lock.');
            $this->assertSame($results[0]['row'], $declaration->fresh()->getAttributes());
        });
        $this->assertSame('pending', $declaration->fresh()->status);
        $this->assertNull($declaration->fresh()->verified_by);
        $this->assertNull($declaration->fresh()->verified_at);
        $this->assertSame(1, AuditEvent::where('action', 'rights.declaration.updated')->count());
        $this->assertSame(0, AuditEvent::where('action', 'rights.declaration.verified')->count());
    }

    public function test_same_actor_legacy_offer_revision_and_rights_writer_complete_without_a_user_fk_track_cycle(): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = $fixture['track'];
        $offer = app(SaveOfferDraft::class)->handle($fixture['offer'], ['track_id' => $track->id,
            'license_version_id' => $fixture['offer']->license_version_id, 'price_minor' => $fixture['offer']->price_minor + 1,
            'currency' => 'USD', 'deliverable_asset_ids' => $fixture['offer']->deliverable_asset_ids], $actor);
        $oldRevision = $fixture['revision']->fresh()->getAttributes();
        $oldRights = $track->rightsDeclarations()->latest('id')->firstOrFail()->getAttributes();
        $audits = AuditEvent::count();
        $inputs = [$this->input('legacy-offer', $actor, ['offer_id' => $offer->id, 'pause_table' => 'tracks', 'pause_id' => $track->id]),
            $this->input('create', $actor, ['data' => ['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC-AFTER-LEGACY',
                'sample_disclosure' => 'Synthetic current append after legacy publication']])];
        $this->race($inputs, function ($directory, $processes, $connections) use ($actor, $track): void {
            touch($directory.'/start-0');
            $this->await(fn () => is_file($directory.'/locked-0'), $processes);
            touch($directory.'/start-1');
            $this->observeWait($connections[1], $connections[0], 'users', $actor->id, $processes);
            touch($directory.'/release-0');
            $results = $this->results($processes, $connections);
            $this->assertSame('published', $results[0]['result'], 'Real legacy publication failed: '.json_encode($results[0], JSON_THROW_ON_ERROR));
            $this->assertSame('saved', $results[1]['result'], 'Real supported rights creation failed: '.json_encode($results[1], JSON_THROW_ON_ERROR));
            $this->assertSame('users', $results[0]['locks'][0]['table'], 'The participating publisher must lock current authority before its track.');
            $this->assertSame([$track->id], $this->trackLocks($results[0]));
            $this->assertSame($results[0]['revision_id'], $track->offers()->firstOrFail()->fresh()->current_revision_id);
            $this->assertSame($results[1]['row'], RightsDeclaration::findOrFail($results[1]['row']['id'])->getAttributes());
        });
        $this->assertSame($oldRevision, $fixture['revision']->fresh()->getAttributes());
        $this->assertSame($oldRights, RightsDeclaration::findOrFail($oldRights['id'])->getAttributes());
        $this->assertSame($audits + 2, AuditEvent::count());
        $this->assertSame(1, AuditEvent::where('action', 'rights.declaration.created')->count());
    }

    public static function catalogWriterOrdering(): array
    {
        $cases = [];
        foreach (['metadata', 'draft', 'deactivate'] as $writer) {
            foreach (['catalog first' => 0, 'rights first' => 1] as $order => $first) {
                $cases[$writer.' / '.$order] = [$writer, $first];
            }
        }

        return $cases;
    }

    #[DataProvider('catalogWriterOrdering')]
    public function test_same_actor_catalog_writers_and_rights_creation_complete_in_both_lock_orders(string $writer, int $first): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = $fixture['track'];
        $offer = $fixture['offer']->fresh();
        $oldRevision = $fixture['revision']->fresh()->getAttributes();
        $oldRights = $track->rightsDeclarations()->latest('id')->firstOrFail()->getAttributes();
        $audits = AuditEvent::count();
        $data = $writer === 'metadata'
            ? ['title' => 'Synthetic catalog writer title', 'metadata_version' => $track->metadata_version]
            : ['price_minor' => $offer->price_minor + 1];
        $inputs = [$this->input('catalog-writer', $actor, ['writer' => $writer, 'track_id' => $track->id,
            'offer_id' => $offer->id, 'data' => $data]),
            $this->input('create', $actor, ['data' => ['track_id' => $track->id,
                'provenance_reference' => 'SYNTHETIC-CATALOG-WRITER', 'sample_disclosure' => 'Synthetic concurrent append']])];
        // A pending append blocks published metadata readiness. Use a private track for both orders.
        $track->update(['status' => 'draft']);
        $inputs[$first] += $first === 0
            ? ['pause_table' => 'tracks', 'pause_id' => $track->id]
            : ['pause_table' => 'users', 'pause_id' => $actor->id];
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $actor, $track, $offer, $writer, $data): void {
            $second = 1 - $first;
            touch($directory.'/start-'.$first);
            $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/start-'.$second);
            $this->observeWait($connections[$second], $connections[$first], 'users', $actor->id, $processes);
            touch($directory.'/release-'.$first);
            $results = $this->results($processes, $connections);
            $this->assertSame('catalog-saved', $results[0]['result'], json_encode($results[0], JSON_THROW_ON_ERROR));
            $this->assertSame('saved', $results[1]['result'], json_encode($results[1], JSON_THROW_ON_ERROR));
            foreach ($results as $result) {
                $this->assertSame('users', $result['locks'][0]['table']);
                $this->assertSame([$track->id], $this->trackLocks($result));
            }
            $this->assertSame($results[1]['row'], RightsDeclaration::findOrFail($results[1]['row']['id'])->getAttributes());
            if ($writer === 'metadata') {
                $this->assertSame($data['title'], $track->fresh()->title);
                $this->assertSame($data['metadata_version'] + 1, $track->fresh()->metadata_version);
                $this->assertSame($results[0]['row'], $track->fresh()->getAttributes());
            } else {
                $this->assertSame($results[0]['row'], $offer->fresh()->getAttributes());
                $this->assertSame($writer === 'draft', $offer->fresh()->is_active);
                $this->assertSame($writer === 'draft' ? $data['price_minor'] : $offer->price_minor, $offer->fresh()->price_minor);
            }
        });
        $this->assertSame($oldRevision, $fixture['revision']->fresh()->getAttributes());
        $this->assertSame($oldRights, RightsDeclaration::findOrFail($oldRights['id'])->getAttributes());
        $this->assertSame($audits + 2, AuditEvent::count());
        $this->assertSame(1, AuditEvent::where('action', 'rights.declaration.created')->count());
        $this->assertSame('pending', $track->rightsDeclarations()->latest('id')->firstOrFail()->status);
    }

    private function trackLocks(array $result): array
    {
        return array_values(array_merge(...array_column(array_filter($result['locks'], fn ($lock) => $lock['table'] === 'tracks'), 'ids')));
    }

    private function race(array $inputs, callable $coordinate): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/rights-writer-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/rights-declaration-writer-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_RIGHTS_WRITER_DIRECTORY' => $directory, 'VASEY_RIGHTS_WRITER_WORKER' => (string) $worker,
                ], json_encode($input, JSON_THROW_ON_ERROR), 40);
                $process->start();
                $processes[] = $process;
            }
            $this->await(fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), $processes);
            $ready = array_map(fn ($worker) => json_decode(file_get_contents($directory.'/ready-'.$worker), true, 16, JSON_THROW_ON_ERROR), [0, 1]);
            $connections = array_column($ready, 'connection_id');
            $this->assertCount(3, array_unique([...$connections, (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]));
            $this->assertCount(3, array_unique([...array_column($ready, 'pid'), getmypid()]));
            foreach ($ready as $index => $row) {
                $this->assertTrue($row['retained_admin']);
                $this->assertTrue($row['retained_verified_email']);
                if ($inputs[$index]['require_mfa']) {
                    $this->assertTrue($row['retained_enrollment']);
                }
            }
            $coordinate($directory, $processes, $connections);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    private function results(array $processes, array $connections): array
    {
        $results = [];
        foreach ($processes as $index => $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), 'Rights writer process failed: '.$process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame($connections[$index], $result['connection_id']);
            $this->assertSame(0, $result['transaction_level']);
            $results[] = $result;
        }

        return $results;
    }

    private function observeWait(int $requester, int $blocker, string $table, int $id, array $processes): void
    {
        $this->await(fn () => $this->waiting($requester, $blocker, $table, $id), $processes);
    }

    private function waiting(int $requester, int $blocker, string $table, int $id): bool
    {
        $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS lock_status
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = ? AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ?
LIMIT 1
SQL;

        return DB::selectOne($sql, [$requester, $blocker, DB::connection()->getConfig('database'), $table, (string) $id])?->lock_status === 'WAITING';
    }

    private function await(callable $ready, array $processes): void
    {
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if ($ready()) {
                return;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'A rights worker exited before the required barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Rights writers did not reach the required exact row wait/barrier.');
    }
}
