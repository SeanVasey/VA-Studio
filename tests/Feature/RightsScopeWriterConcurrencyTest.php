<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\RightsScopeOffer;
use App\Domain\Rights\SaveRightsDeclaration;
use App\Domain\Rights\VerifyRightsDeclaration;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class RightsScopeWriterConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent same-actor rights-scope and reviewed-rights waits require MySQL; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public static function scopeOrdering(): array
    {
        return ['link first' => ['link', 0], 'rights before link' => ['link', 1],
            'block first' => ['block', 0], 'rights before block' => ['block', 1]];
    }

    #[DataProvider('scopeOrdering')]
    public function test_scope_control_and_reviewed_rights_writers_serialize_on_the_exact_actor_row(string $writer, int $first): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = $fixture['track'];
        $scope = app(ManageRightsScope::class)->register('synthetic-scope-race', 'SYNTHETIC-SCOPE', $actor);
        $save = app(SaveRightsDeclaration::class);
        $pending = $save->create(['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC-OLDER-PENDING',
            'sample_disclosure' => 'Synthetic older pending disclosure'], $actor);
        $latest = $save->create(['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC-CURRENT-VERIFIED',
            'sample_disclosure' => 'Synthetic cleared evidence'], $actor);
        app(VerifyRightsDeclaration::class)->handle($latest, $actor);
        $revision = app(PublishOffer::class)->handle($fixture['offer'], $actor);
        $latestBefore = $latest->fresh()->getAttributes();
        $revisionsBefore = DB::table('offer_revisions')->orderBy('id')->get()->toJson();
        $tracksBefore = DB::table('tracks')->orderBy('id')->get()->toJson();
        $audits = AuditEvent::count();
        $data = $pending->only(['track_id', 'provenance_reference', 'sample_disclosure']);
        $data['sample_disclosure'] = 'Synthetic concurrent pending correction';
        $common = ['actor_id' => $actor->id, 'media_root' => Storage::disk('local')->path(''), 'require_mfa' => false];
        $inputs = [$common + ['operation' => 'scope', 'writer' => $writer, 'scope_id' => $scope->id, 'revision_id' => $revision->id],
            $common + ['operation' => 'rights', 'declaration_id' => $pending->id, 'review' => $save->review($pending, $actor), 'data' => $data]];
        // Scope first pauses after its resource fence: the competing rights writer
        // must still wait on the actor, proving the late audit FK cannot invert it.
        $inputs[$first] += $first === 0
            ? ['pause_table' => $writer === 'link' ? 'tracks' : 'rights_scopes', 'pause_id' => $writer === 'link' ? $track->id : $scope->id]
            : ['pause_table' => 'users', 'pause_id' => $actor->id];
        $this->race($inputs, function ($directory, $processes, $connections) use ($writer, $first, $actor, $scope, $revision, $pending, $data): void {
            $second = 1 - $first;
            touch($directory.'/start-'.$first);
            $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/start-'.$second);
            $this->observeWait($connections[$second], $connections[$first], 'users', $actor->id, $processes);
            touch($directory.'/release-'.$first);
            $results = $this->results($processes, $connections);
            $this->assertSame('scope-saved', $results[0]['result']);
            $this->assertSame('rights-saved', $results[1]['result']);
            foreach ($results as $result) {
                $this->assertSame('users', $result['locks'][0]['table']);
                $this->assertSame([$actor->id], $result['locks'][0]['ids']);
            }
            $saved = $writer === 'link' ? RightsScopeOffer::where('offer_revision_id', $revision->id)->sole() : RightsScope::findOrFail($scope->id);
            $this->assertSame($results[0]['row'], $saved->getAttributes());
            $this->assertSame($results[1]['row'], $pending->fresh()->getAttributes());
            $this->assertSame($data['sample_disclosure'], $pending->fresh()->sample_disclosure);
            $this->assertSame($writer === 'block', $scope->fresh()->blocked);
            $this->assertSame($writer === 'block' ? 1 : 0, $scope->fresh()->control_version);
            $this->assertSame($writer === 'link' ? ['users', 'users', 'tracks', 'offers', 'rights_scopes'] : ['users', 'users', 'rights_scopes'],
                array_column(array_slice($results[0]['locks'], 0, $writer === 'link' ? 5 : 3), 'table'));
        });
        $this->assertSame($latestBefore, $latest->fresh()->getAttributes());
        $this->assertSame($revisionsBefore, DB::table('offer_revisions')->orderBy('id')->get()->toJson());
        $this->assertSame($tracksBefore, DB::table('tracks')->orderBy('id')->get()->toJson());
        $this->assertSame($audits + 2, AuditEvent::count());
        $this->assertSame(1, AuditEvent::where('action', 'rights.declaration.updated')->count());
        $this->assertSame(1, AuditEvent::where('action', $writer === 'link' ? 'commerce.inventory.offer_linked' : 'commerce.inventory.control_changed')->count());
    }

    public static function oldSnapshotAuthority(): array
    {
        $cases = [];
        foreach (['register', 'link', 'block'] as $writer) {
            foreach (['role', 'email'] as $field) {
                $cases[$writer.' / '.$field] = [$writer, $field];
            }
        }

        return $cases;
    }

    #[DataProvider('oldSnapshotAuthority')]
    public function test_nested_scope_writes_deny_authority_withdrawn_after_an_old_repeatable_read_snapshot(string $writer, string $field): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $scope = app(ManageRightsScope::class)->register('synthetic-old-snapshot', 'SYNTHETIC-SCOPE', $actor);
        $tables = ['rights_scopes', 'rights_scope_offers', 'audit_events'];
        $evidence = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
        $before = $evidence();
        config(['database.connections.authority_mutator' => DB::connection()->getConfig()]);
        $mutator = DB::connection('authority_mutator');
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
            (int) $mutator->selectOne('SELECT CONNECTION_ID() AS id')->id);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $retained = User::findOrFail($actor->id);
            $this->assertTrue($retained->is_admin);
            $this->assertNotNull($retained->email_verified_at);
            $mutator->table('users')->where('id', $actor->id)->update($field === 'role'
                ? ['is_admin' => false] : ['email_verified_at' => null]);
            // Prove the caller still observes the old consistent-read authority,
            // while the domain's locking actor read must use the committed row.
            $oldView = User::findOrFail($actor->id);
            $this->assertTrue($oldView->is_admin);
            $this->assertNotNull($oldView->email_verified_at);
            try {
                match ($writer) {
                    'register' => app(ManageRightsScope::class)->register('synthetic-withdrawn', 'SYNTHETIC-SCOPE', $retained),
                    'link' => app(ManageRightsScope::class)->link($scope->id, $fixture['revision']->id, 'SYNTHETIC-LINK', $retained),
                    'block' => app(ManageRightsScope::class)->block($scope->id, true, 0, 'SYNTHETIC-BLOCK', $retained),
                };
                $this->fail('An old caller snapshot authorized a scope mutation after committed authority withdrawal.');
            } catch (AuthorizationException) {
            }
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame($before, $evidence());
        } finally {
            DB::rollBack();
            DB::purge('authority_mutator');
        }
        $this->assertSame($before, $evidence());
        $this->assertSame(0, DB::transactionLevel());
        $current = User::findOrFail($actor->id);
        if ($field === 'role') {
            $this->assertFalse($current->is_admin);
        } else {
            $this->assertNull($current->email_verified_at);
        }
    }

    private function race(array $inputs, callable $coordinate): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/rights-scope-writer-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/rights-scope-writer-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_RIGHTS_SCOPE_WRITER_DIRECTORY' => $directory, 'VASEY_RIGHTS_SCOPE_WRITER_WORKER' => (string) $worker,
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
