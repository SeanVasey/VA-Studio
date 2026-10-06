<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\ProductDraft;
use App\Domain\Catalog\Models\ProductDraftVersion;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\ProductDrafts;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class ProductDraftConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent product draft serialization requires MySQL; SQLite is not concurrency evidence.');
        }
    }

    public static function competingOperations(): array
    {
        return ['edit vs edit' => ['save', 'save'], 'edit vs history' => ['save', 'select'], 'history vs edit' => ['select', 'save']];
    }

    public static function mfaOperations(): array
    {
        return ['create' => ['create'], 'edit' => ['save'], 'history selection' => ['select'], 'inspect' => ['snapshot'], 'choices' => ['tracks']];
    }

    #[DataProvider('competingOperations')]
    public function test_native_product_wait_serializes_competing_draft_changes(string $first, string $second): void
    {
        $author = LicenseFixtures::admin();
        $editor = LicenseFixtures::admin();
        $track = Track::create(['title' => 'Synthetic racing track', 'slug' => 'racing-track']);
        $payload = ['kind' => 'album', 'title' => 'Initial composition', 'track_ids' => [$track->id]];
        $service = app(ProductDrafts::class);
        $draft = $service->save(null, $payload, $author);
        $retained = ProductDraftVersion::sole();
        $service->save($draft, [...$payload, 'title' => 'Current composition', 'version' => 1], $author);
        $directory = $this->directory();
        $processes = [];
        try {
            foreach (['first' => [$first, $author], 'second' => [$second, $editor]] as $name => [$operation, $actor]) {
                $processes[$name] = $this->worker($directory, ['name' => $name, 'pause' => $name === 'first',
                    'operation' => $operation, 'actor_id' => $actor->id, 'draft_id' => $draft->id, 'retained_id' => $retained->id,
                    'version' => 2, 'payload' => [...$payload, 'version' => 2, 'title' => 'Concurrent '.$name]]);
            }
            $ready = $this->ready($directory, $processes);
            $this->assertCount(3, array_unique([getmypid(), $ready['first']['pid'], $ready['second']['pid']]));
            touch($directory.'/start-first');
            $this->await(fn () => is_file($directory.'/locked-first'), $processes);
            touch($directory.'/start-second');
            $this->await(fn () => $this->recordWait($ready['second']['connection_id'], $ready['first']['connection_id'], 'product_drafts', $draft->id), $processes);
            touch($directory.'/release-first');
            $results = $this->results($processes);
            $this->assertSame('saved', $results['first']['result']);
            $this->assertSame('rejected', $results['second']['result']);
            $this->assertSame(3, $draft->fresh()->version);
            $this->assertSame(3, ProductDraftVersion::count());
            $this->assertSame(3, AuditEvent::where('action', 'like', 'catalog.product_draft.%')->count());
            $latest = ProductDraftVersion::where('number', 3)->sole();
            if ($first === 'select') {
                $this->assertSame($retained->manifest_sha256, $latest->manifest_sha256);
                $this->assertSame($retained->id, $latest->source_version_id);
            } else {
                $this->assertSame('Concurrent first', $latest->manifest['title']);
                $this->assertNull($latest->source_version_id);
            }
        } finally {
            $this->cleanup($directory, $processes);
        }
    }

    #[DataProvider('mfaOperations')]
    public function test_required_mfa_withdrawn_during_actor_wait_refuses_product_operation(string $operation): void
    {
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret($panel->getMultiFactorAuthenticationProviders()['app']->generateSecret());
        $track = Track::create(['title' => 'Synthetic MFA track', 'slug' => 'mfa-track']);
        $payload = ['kind' => 'collection', 'title' => 'Retained draft', 'track_ids' => [$track->id]];
        $draft = app(ProductDrafts::class)->save(null, $payload, $actor);
        $before = ProductDraft::sole()->getAttributes();
        $directory = $this->directory();
        $processes = [];
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            $blocker = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $processes['withdrawal'] = $this->worker($directory, ['name' => 'withdrawal', 'require_mfa' => true,
                'operation' => $operation, 'actor_id' => $actor->id, 'draft_id' => $draft->id, 'version' => 1,
                'retained_id' => ProductDraftVersion::sole()->id,
                'payload' => $operation === 'create' ? $payload : [...$payload, 'version' => 1, 'title' => 'Forbidden edit']]);
            $ready = $this->ready($directory, $processes);
            touch($directory.'/start-withdrawal');
            $this->await(fn () => $this->recordWait($ready['withdrawal']['connection_id'], $blocker, 'users', $actor->id), $processes);
            DB::table('users')->where('id', $actor->id)->update(['app_authentication_secret' => null]);
            DB::commit();
            $result = $this->results($processes)['withdrawal'];
            $this->assertTrue($result['mfa_precheck']);
            $this->assertSame('unauthorized', $result['result']);
            $this->assertSame($before, ProductDraft::sole()->getAttributes());
            $this->assertDatabaseCount('product_draft_versions', 1);
            $this->assertDatabaseCount('product_draft_members', 1);
            $this->assertSame(1, AuditEvent::where('action', 'like', 'catalog.product_draft.%')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanup($directory, $processes);
        }
    }

    public function test_source_lock_captures_metadata_committed_while_creator_waits(): void
    {
        $actor = LicenseFixtures::admin();
        $track = Track::create(['title' => 'Synthetic old title', 'slug' => 'source-race']);
        $directory = $this->directory();
        $processes = [];
        try {
            DB::beginTransaction();
            DB::table('tracks')->where('id', $track->id)->lockForUpdate()->first();
            $blocker = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $processes['source'] = $this->worker($directory, ['name' => 'source', 'operation' => 'create', 'actor_id' => $actor->id,
                'payload' => ['kind' => 'album', 'title' => 'Current source', 'track_ids' => [$track->id]]]);
            $ready = $this->ready($directory, $processes);
            touch($directory.'/start-source');
            $this->await(fn () => $this->recordWait($ready['source']['connection_id'], $blocker, 'tracks', $track->id), $processes);
            DB::table('tracks')->where('id', $track->id)->update(['title' => 'Synthetic committed title', 'metadata_version' => 1]);
            DB::commit();
            $this->assertSame('saved', $this->results($processes)['source']['result']);
            $member = ProductDraftVersion::sole()->manifest['members'][0];
            $this->assertSame('Synthetic committed title', $member['title']);
            $this->assertSame(1, $member['metadata_version']);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanup($directory, $processes);
        }
    }

    private function directory(): string
    {
        $directory = storage_path('framework/testing/product-draft-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);

        return $directory;
    }

    private function worker(string $directory, array $input): Process
    {
        $db = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/product-draft-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'DB_CHARSET' => (string) $db['charset'], 'DB_COLLATION' => (string) $db['collation'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ], json_encode($input + ['directory' => $directory], JSON_THROW_ON_ERROR), 40);
        $process->start();

        return $process;
    }

    private function ready(string $directory, array $processes): array
    {
        $ready = [];
        foreach ($processes as $name => $process) {
            $this->await(fn () => is_file($directory.'/ready-'.$name), $processes);
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
        $this->fail('Product workers did not reach the exact native record wait.');
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
