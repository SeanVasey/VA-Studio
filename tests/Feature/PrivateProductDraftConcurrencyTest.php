<?php

namespace Tests\Feature;

use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PrivateProductDraftFixtures as Fixtures;
use Tests\TestCase;

class PrivateProductDraftConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Private draft independent-process record waits require MySQL; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
    }

    public static function families(): array
    {
        return Fixtures::families();
    }

    public static function withdrawals(): array
    {
        return ['service / role' => ['service', 'role'], 'service / MFA' => ['service', 'mfa'],
            'merch / role' => ['merch', 'role'], 'merch / MFA' => ['merch', 'mfa']];
    }

    #[DataProvider('families')]
    public function test_distinct_actor_competing_reviews_wait_on_the_exact_parent_and_only_first_commits(string $kind): void
    {
        [$class] = Fixtures::classes($kind);
        $author = LicenseFixtures::admin();
        $editor = LicenseFixtures::admin();
        $command = app($class);
        $draft = $command->applyReviewed($command->review(null, Fixtures::payload($kind), $author), $author);
        $first = $command->review($draft, Fixtures::payload($kind, ['description' => 'First committed definition']), $author);
        $second = $command->review($draft, Fixtures::payload($kind, ['description' => 'Second stale definition']), $editor);
        $directory = $this->directory();
        $processes = [];
        try {
            $processes['first'] = $this->worker($directory, ['name' => 'first', 'kind' => $kind, 'actor_id' => $author->id,
                'draft_id' => $draft->id, 'operation' => 'apply', 'review' => $first, 'pause' => true, 'pause_parent' => true]);
            $processes['second'] = $this->worker($directory, ['name' => 'second', 'kind' => $kind, 'actor_id' => $editor->id,
                'draft_id' => $draft->id, 'operation' => 'apply', 'review' => $second, 'snapshot_before_parent' => true]);
            $ready = $this->ready($directory, $processes);
            $this->assertCount(3, array_unique([getmypid(), $ready['first']['pid'], $ready['second']['pid']]));
            touch($directory.'/start-first');
            $this->await(fn (): bool => is_file($directory.'/locked-first'), $processes);
            touch($directory.'/start-second');
            $this->await(fn (): bool => $this->recordWait($ready['second']['connection_id'], $ready['first']['connection_id'], $kind.'_drafts', $draft->id), $processes);
            $this->assertFileExists($directory.'/snapshot-second');
            touch($directory.'/release-first');
            $results = $this->results($processes);
            $this->assertSame('saved', $results['first']['result']);
            $this->assertSame('rejected', $results['second']['result']);
            $this->assertTrue($results['second']['snapshot_established']);
            $snapshot = $command->snapshot($draft->id, $author);
            $this->assertSame(2, $snapshot['version']);
            $this->assertSame('First committed definition', $snapshot['manifest']['description']);
            $this->assertDatabaseCount($kind.'_draft_versions', 2);
        } finally {
            $this->cleanup($directory, $processes);
        }
    }

    #[DataProvider('families')]
    public function test_duplicate_create_capture_waits_on_actor_and_returns_one_definition_without_duplicate_history(string $kind): void
    {
        [$class] = Fixtures::classes($kind);
        $actor = LicenseFixtures::admin();
        $review = app($class)->review(null, Fixtures::payload($kind), $actor);
        $directory = $this->directory();
        $processes = [];
        try {
            foreach (['first', 'second'] as $name) {
                $processes[$name] = $this->worker($directory, ['name' => $name, 'kind' => $kind, 'actor_id' => $actor->id,
                    'operation' => 'apply', 'review' => $review, 'pause' => $name === 'first']);
            }
            $ready = $this->ready($directory, $processes);
            touch($directory.'/start-first');
            $this->await(fn (): bool => is_file($directory.'/locked-first'), $processes);
            touch($directory.'/start-second');
            $this->await(fn (): bool => $this->recordWait($ready['second']['connection_id'], $ready['first']['connection_id'], 'users', $actor->id), $processes);
            touch($directory.'/release-first');
            $results = $this->results($processes);
            $this->assertSame('saved', $results['first']['result']);
            $this->assertSame('saved', $results['second']['result']);
            $this->assertSame($results['first']['draft_id'], $results['second']['draft_id']);
            $this->assertDatabaseCount($kind.'_drafts', 1);
            $this->assertDatabaseCount($kind.'_draft_versions', 1);
        } finally {
            $this->cleanup($directory, $processes);
        }
    }

    #[DataProvider('withdrawals')]
    public function test_committed_role_or_required_mfa_withdrawal_wins_the_exact_actor_fence(string $kind, string $withdrawal): void
    {
        [$class] = Fixtures::classes($kind);
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $command = app($class);
        $review = $command->review(null, Fixtures::payload($kind), $actor);
        $directory = $this->directory();
        $processes = [];
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            $blocker = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $processes['withdrawal'] = $this->worker($directory, ['name' => 'withdrawal', 'kind' => $kind, 'actor_id' => $actor->id,
                'operation' => 'apply', 'review' => $review, 'require_mfa' => true]);
            $ready = $this->ready($directory, $processes);
            touch($directory.'/start-withdrawal');
            $this->await(fn (): bool => $this->recordWait($ready['withdrawal']['connection_id'], $blocker, 'users', $actor->id), $processes);
            DB::table('users')->where('id', $actor->id)->update($withdrawal === 'role' ? ['is_admin' => false] : ['app_authentication_secret' => null]);
            DB::commit();
            $result = $this->results($processes)['withdrawal'];
            $this->assertTrue($result['mfa_precheck']);
            $this->assertSame('unauthorized', $result['result']);
            $this->assertDatabaseCount($kind.'_drafts', 0);
            $this->assertDatabaseCount($kind.'_draft_versions', 0);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanup($directory, $processes);
        }
    }

    #[DataProvider('families')]
    public function test_snapshot_reads_the_after_fence_committed_history_despite_a_callback_created_mvcc_snapshot(string $kind): void
    {
        [$class] = Fixtures::classes($kind);
        $author = LicenseFixtures::admin();
        $reader = LicenseFixtures::admin();
        $command = app($class);
        $draft = $command->applyReviewed($command->review(null, Fixtures::payload($kind), $author), $author);
        $review = $command->review($draft, Fixtures::payload($kind, ['description' => 'New committed snapshot']), $author);
        $directory = $this->directory();
        $processes = [];
        try {
            $processes['first'] = $this->worker($directory, ['name' => 'first', 'kind' => $kind, 'actor_id' => $author->id,
                'draft_id' => $draft->id, 'operation' => 'apply', 'review' => $review, 'pause' => true, 'pause_parent' => true]);
            $processes['second'] = $this->worker($directory, ['name' => 'second', 'kind' => $kind, 'actor_id' => $reader->id,
                'draft_id' => $draft->id, 'operation' => 'snapshot', 'snapshot_before_parent' => true]);
            $ready = $this->ready($directory, $processes);
            touch($directory.'/start-first');
            $this->await(fn (): bool => is_file($directory.'/locked-first'), $processes);
            touch($directory.'/start-second');
            $this->await(fn (): bool => $this->recordWait($ready['second']['connection_id'], $ready['first']['connection_id'], $kind.'_drafts', $draft->id), $processes);
            $this->assertFileExists($directory.'/snapshot-second');
            touch($directory.'/release-first');
            $results = $this->results($processes);
            $this->assertSame('saved', $results['first']['result']);
            $this->assertSame('read', $results['second']['result']);
            $this->assertSame(2, $results['second']['version']);
            $this->assertTrue($results['second']['snapshot_established']);
            $this->assertSame(CanonicalJson::hash($command->snapshot($draft->id, $author)['manifest']), $results['second']['manifest_hash']);
        } finally {
            $this->cleanup($directory, $processes);
        }
    }

    private function directory(): string
    {
        $directory = storage_path('framework/testing/private-product-draft-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);

        return $directory;
    }

    private function worker(string $directory, array $input): Process
    {
        $db = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/private-product-draft-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'DB_CHARSET' => (string) $db['charset'], 'DB_COLLATION' => (string) $db['collation'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        ], json_encode($input + ['directory' => $directory], JSON_THROW_ON_ERROR), 40);
        $process->start();

        return $process;
    }

    private function ready(string $directory, array $processes): array
    {
        $ready = [];
        foreach ($processes as $name => $process) {
            $this->await(fn (): bool => is_file($directory.'/ready-'.$name), $processes);
            $ready[$name] = json_decode(file_get_contents($directory.'/ready-'.$name), true, 16, JSON_THROW_ON_ERROR);
            $this->assertNotSame(getmypid(), $ready[$name]['pid']);
            $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $ready[$name]['connection_id']);
        }

        return $ready;
    }

    private function results(array $processes): array
    {
        $results = [];
        foreach ($processes as $name => $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $results[$name] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame(0, $results[$name]['transaction_level']);
        }

        return $results;
    }

    private function recordWait(int $requester, int $blocker, string $table, int $id): bool
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

        return DB::selectOne($sql, [$requester, $blocker, DB::getDatabaseName(), $table, (string) $id])?->lock_status === 'WAITING';
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
        $this->fail('Private product workers did not reach the exact native record wait.');
    }

    private function cleanup(string $directory, array $processes): void
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        (new Filesystem)->deleteDirectory($directory);
    }
}
