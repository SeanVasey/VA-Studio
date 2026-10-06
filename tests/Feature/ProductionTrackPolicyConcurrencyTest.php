<?php

namespace Tests\Feature;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyVersion;
use App\Domain\Commerce\Policy\PrepareProductionTrackPolicy;
use App\Domain\Commerce\Policy\ReviewProductionTrackPolicy;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\ProductionTrackPolicyFixtures;
use Tests\TestCase;

class ProductionTrackPolicyConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Production policy source fences require independent MySQL sessions and exact record waits; SQLite is not concurrency evidence.');
        }
    }

    public static function races(): array
    {
        $cases = [];
        foreach (['save', 'acknowledge', 'two acknowledgments', 'withdraw', 'pre-fence snapshot noop'] as $operation) {
            foreach ([0, 1] as $first) {
                $cases[$operation.' / worker '.$first.' first'] = [$operation, $first];
            }
        }

        return $cases;
    }

    #[DataProvider('races')]
    public function test_exact_source_review_and_authority_serialize_both_commit_orders(string $operation, int $first): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $author = LicenseFixtures::admin();
        $editor = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $draft = ProductionTrackPolicyFixtures::create($author);
        $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->sole();
        $original = $version->getAttributes();
        $reference = ['reference' => 'NONBINDING SYNTHETIC REVIEW', 'source_sha256' => hash('sha256', 'synthetic'), 'authored_source_acknowledged' => true];
        $inputs = [
            ['operation' => 'save', 'actor_id' => $editor->id, 'review' => app(PrepareProductionTrackPolicy::class)->review($draft, ProductionTrackPolicyFixtures::authored(true), $editor)],
            ['operation' => 'save', 'actor_id' => $reviewer->id, 'review' => app(PrepareProductionTrackPolicy::class)->review($draft, $this->changed(), $reviewer)],
        ];
        if ($operation === 'acknowledge') {
            $inputs[1] = ['operation' => 'acknowledge', 'actor_id' => $reviewer->id, 'review' => app(ReviewProductionTrackPolicy::class)->review($version, $reviewer), 'reference' => $reference];
        } elseif ($operation === 'two acknowledgments') {
            $inputs = [
                ['operation' => 'acknowledge', 'actor_id' => $editor->id, 'review' => app(ReviewProductionTrackPolicy::class)->review($version, $editor), 'reference' => $reference],
                ['operation' => 'acknowledge', 'actor_id' => $reviewer->id, 'review' => app(ReviewProductionTrackPolicy::class)->review($version, $reviewer), 'reference' => $reference],
            ];
        } elseif ($operation === 'withdraw') {
            $inputs[1] = ['operation' => 'withdraw', 'actor_id' => $editor->id];
        } elseif ($operation === 'pre-fence snapshot noop') {
            $actors = [$editor, $reviewer];
            $inputs[$first] = ['operation' => 'save', 'actor_id' => $actors[$first]->id,
                'review' => app(PrepareProductionTrackPolicy::class)->review($draft, $this->changed(), $actors[$first])];
            $inputs[1 - $first] = ['operation' => 'save', 'actor_id' => $actors[1 - $first]->id,
                'review' => app(PrepareProductionTrackPolicy::class)->review($draft, ProductionTrackPolicyFixtures::authored(), $actors[1 - $first]),
                'pre_fence_read_policy_id' => $draft->id];
        }
        $table = $operation === 'withdraw' ? 'users' : 'production_track_policy_drafts';
        $id = $operation === 'withdraw' ? $editor->id : $draft->id;
        $inputs[$first]['pause_table'] = $table;
        $beforeAudits = DB::table('audit_events')->count();
        $this->race($inputs, $first, $table, $id, function (array $results) use ($operation, $first, $draft, $version, $original, $editor, $beforeAudits): void {
            if ($operation === 'withdraw') {
                $this->assertSame('withdrawn', $results[1]['status']);
                $this->assertSame($first === 0 ? 'saved' : 'denied', $results[0]['status']);
                $saved = $first === 0;
                $acknowledged = false;
                $this->assertFalse($editor->fresh()->is_admin);
            } else {
                $this->assertSame($operation === 'two acknowledgments' || ($operation === 'acknowledge' && $first === 1) ? 'acknowledged' : 'saved', $results[$first]['status']);
                $this->assertSame('blocked', $results[1 - $first]['status']);
                if ($operation === 'pre-fence snapshot noop') {
                    $this->assertSame(1, $results[1 - $first]['pre_fence_revision']);
                    $this->assertNull($results[$first]['pre_fence_revision']);
                }
                $saved = $results[$first]['status'] === 'saved';
                $acknowledged = ! $saved;
            }
            $this->assertSame($original, $version->fresh()->getAttributes());
            $this->assertSame($saved ? 2 : 1, $draft->fresh()->revision);
            $this->assertDatabaseCount('production_track_policy_versions', $saved ? 2 : 1);
            $this->assertDatabaseCount('production_track_policy_source_reviews', $acknowledged ? 1 : 0);
            $this->assertSame($beforeAudits + ($saved || $acknowledged ? 1 : 0), DB::table('audit_events')->count());
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('license_grants', 0);
        });
    }

    private function changed(): array
    {
        $source = ProductionTrackPolicyFixtures::authored();
        $source['version'] = 'synthetic-changed-v1';

        return $source;
    }

    private function race(array $inputs, int $first, string $table, int $id, callable $assertResults): void
    {
        $directory = storage_path('framework/testing/production-policy-'.Str::uuid());
        $files = new Filesystem;
        $files->makeDirectory($directory, 0700, true);
        $database = DB::connection()->getConfig();
        $processes = [];
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/production-track-policy-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'],
                    'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => '',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_PRODUCTION_POLICY_RACE_DIRECTORY' => $directory, 'VASEY_PRODUCTION_POLICY_RACE_WORKER' => (string) $worker,
                ], json_encode($input, JSON_THROW_ON_ERROR), 40);
                $process->start();
                $processes[] = $process;
            }
            $this->await(fn (): bool => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), $processes);
            $ready = array_map(fn (int $worker): array => json_decode(file_get_contents($directory.'/ready-'.$worker), true, 16, JSON_THROW_ON_ERROR), [0, 1]);
            $connections = array_column($ready, 'connection_id');
            $this->assertCount(3, array_unique([...$connections, (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]));
            $this->assertCount(3, array_unique([...array_column($ready, 'pid'), getmypid()]));
            touch($directory.'/start-'.$first);
            $this->await(fn (): bool => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/start-'.(1 - $first));
            $wait = null;
            $this->await(function () use ($connections, $first, $table, $id, &$wait): bool {
                $wait = $this->recordWait($connections[1 - $first], $connections[$first], $table, $id);

                return $wait !== null;
            }, $processes);
            $this->assertSame('WAITING', $wait->lock_status);
            echo json_encode(['production_policy_record_wait' => (array) $wait, 'first' => $first], JSON_THROW_ON_ERROR)."\n";
            touch($directory.'/release-'.$first);
            $results = [];
            foreach ($processes as $worker => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
                $this->assertSame($connections[$worker], $result['connection_id']);
                $this->assertSame(0, $result['transaction_level']);
                $this->assertSame('users', $result['locks'][0]['table']);
                $results[] = $result;
            }
            $this->assertTrue($results[$first]['paused']);
            $assertResults($results);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $files->deleteDirectory($directory);
        }
    }

    private function recordWait(int $requester, int $blocker, string $table, int $id): ?object
    {
        $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS lock_status, requested.LOCK_TYPE AS lock_type, requested.INDEX_NAME AS index_name,
       requested.OBJECT_NAME AS object_name, requested.LOCK_DATA AS lock_data
FROM performance_schema.data_lock_waits waits
JOIN performance_schema.threads requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = ? AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ?
LIMIT 1
SQL;

        return DB::selectOne($sql, [$requester, $blocker, DB::connection()->getConfig('database'), $table, (string) $id]);
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
                $this->assertTrue($process->isRunning(), $process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Production policy workers did not reach the required exact row barrier.');
    }
}
