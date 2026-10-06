<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Rights\Models\RightsDeclaration;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class RightsEvidenceGuardConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent verification-versus-stale-writer processes and observed exact rights row waits require MySQL; SQLite is not concurrency evidence.');
        }
    }

    public static function writes(): array
    {
        return ['stale model update' => ['update'], 'stale model delete' => ['delete']];
    }

    #[DataProvider('writes')]
    public function test_verification_winning_the_exact_rights_row_lock_refuses_the_stale_pending_writer(string $operation): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'Independent workers require committed fixtures.');
        $actor = LicenseFixtures::admin();
        $track = Track::create(['title' => 'Synthetic concurrent rights', 'slug' => 'synthetic-concurrent-rights']);
        $pending = $track->rightsDeclarations()->create(['provenance_reference' => 'SYNTHETIC-ORIGINAL-RIGHTS',
            'sample_disclosure' => 'Original synthetic source', 'status' => 'pending']);
        $originalRow = $pending->getAttributes();
        $beforeTracks = DB::table('tracks')->orderBy('id')->get()->toJson();
        $beforeAudits = DB::table('audit_events')->count();
        $directory = storage_path('framework/testing/rights-evidence-guard-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $database = DB::connection()->getConfig();
        $processes = [];
        try {
            foreach (['verify', $operation] as $worker => $action) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/rights-evidence-guard-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_RIGHTS_EVIDENCE_RACE_DIRECTORY' => $directory, 'VASEY_RIGHTS_EVIDENCE_RACE_WORKER' => (string) $worker,
                ], json_encode(['operation' => $action, 'rights_id' => $pending->id, 'actor_id' => $actor->id], JSON_THROW_ON_ERROR), 40);
                $process->start();
                $processes[] = $process;
            }
            $this->await(fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), $processes);
            $ready = array_map(fn ($worker) => json_decode(file_get_contents($directory.'/ready-'.$worker), true, 16, JSON_THROW_ON_ERROR), [0, 1]);
            $connections = array_column($ready, 'connection_id');
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertCount(3, array_unique([...$connections, $parent]));
            $this->assertCount(3, array_unique([...array_column($ready, 'pid'), getmypid()]));
            $this->assertSame(['pending', 'pending'], array_column($ready, 'original_status'));
            $this->assertSame([$pending->id, $pending->id], array_column($ready, 'rights_id'));
            touch($directory.'/start-0');
            $this->await(fn () => is_file($directory.'/verifier-locked'), $processes);
            touch($directory.'/start-1');
            $this->observeWait($connections[1], $connections[0], $pending->id, $processes);
            touch($directory.'/commit-verifier');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), 'Rights worker failed: '.$process->getOutput().$process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            }
            $this->assertSame($connections, array_column($results, 'connection_id'));
            $this->assertSame(array_column($ready, 'pid'), array_column($results, 'pid'));
            $this->assertSame('verified', $results[0]['result']);
            $this->assertSame('guard-refused', $results[1]['result']);
            $this->assertSame(QueryException::class, $results[1]['exception_class']);
            $this->assertTrue($results[1]['guard_message_present']);
            $this->assertSame('pending', $results[1]['retained_original_status']);
            $verified = RightsDeclaration::findOrFail($pending->id);
            $this->assertSame($results[0]['verified_row'], $verified->getAttributes(), 'The stale writer must leave every field committed by the verifier exact.');
            foreach (['id', 'track_id', 'provenance_reference', 'sample_disclosure', 'created_at'] as $field) {
                $this->assertSame($originalRow[$field], $verified->getAttributes()[$field]);
            }
            $this->assertSame('verified', $verified->status);
            $this->assertSame($actor->id, $verified->verified_by);
            $this->assertNotNull($verified->verified_at);
            $audits = DB::table('audit_events')->where('subject_type', RightsDeclaration::class)->where('subject_id', $verified->id)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $this->assertSame($results[0]['verification_audits'], $audits);
            $this->assertCount(1, $audits);
            $this->assertSame('rights.declaration.verified', $audits[0]['action']);
            $this->assertSame($actor->id, $audits[0]['actor_id']);
            $this->assertSame($beforeAudits + 1, DB::table('audit_events')->count());
            $this->assertSame($beforeTracks, DB::table('tracks')->orderBy('id')->get()->toJson());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    private function observeWait(int $requester, int $blocker, int $id, array $processes): void
    {
        $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS lock_status
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = 'rights_declarations' AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ?
LIMIT 1
SQL;
        $this->await(fn () => DB::selectOne($sql, [$requester, $blocker, DB::getDatabaseName(), (string) $id])?->lock_status === 'WAITING', $processes);
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
                $this->assertTrue($process->isRunning(), 'Rights worker exited before the required barrier/wait: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Rights workers did not produce the required exact-row lock observation.');
    }
}
