<?php

namespace Tests\Feature;

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

class ProductMemberRefreshConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent product draft serialization requires MySQL; SQLite is not concurrency evidence.');
        }
    }

    private function fixture(): array
    {
        $actor = LicenseFixtures::admin();
        $track = Track::create(['title' => 'Synthetic original member', 'slug' => 'native-member']);
        $payload = ['kind' => 'album', 'title' => 'Retained album', 'description' => 'Retained notes', 'track_ids' => [$track->id]];
        $draft = app(ProductDrafts::class)->save(null, $payload, $actor);
        DB::table('tracks')->where('id', $track->id)->update(['title' => 'Synthetic reviewed member', 'metadata_version' => 1]);

        return compact('actor', 'track', 'draft', 'payload');
    }

    public static function competingOperations(): array
    {
        return ['duplicate refresh' => ['refresh', 'refresh'], 'edit before refresh' => ['save', 'refresh'], 'refresh before edit' => ['refresh', 'save']];
    }

    #[DataProvider('competingOperations')]
    public function test_native_draft_wait_allows_one_refresh_or_preserves_the_winning_edit(string $first, string $second): void
    {
        ['actor' => $actor, 'track' => $track, 'draft' => $draft, 'payload' => $payload] = $this->fixture();
        $other = LicenseFixtures::admin();
        $service = app(ProductDrafts::class);
        $retained = ProductDraftVersion::sole()->getAttributes();
        $directory = $this->directory();
        $processes = [];
        try {
            foreach (['first' => [$first, $actor], 'second' => [$second, $other]] as $name => [$operation, $editor]) {
                $review = $service->reviewMembers($draft->id, $editor);
                $processes[$name] = $this->worker($directory, ['name' => $name, 'pause' => $name === 'first',
                    'operation' => $operation, 'actor_id' => $editor->id, 'draft_id' => $draft->id, 'version' => 1,
                    'review_hash' => $review['review_hash'], 'payload' => [...$payload, 'version' => 1, 'title' => 'Winning edit']]);
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
            $this->assertSame(2, $draft->fresh()->version);
            $this->assertDatabaseCount('product_draft_versions', 2);
            $this->assertDatabaseCount('product_draft_members', 2);
            $this->assertSame($retained, ProductDraftVersion::where('number', 1)->sole()->getAttributes());
            $latest = ProductDraftVersion::where('number', 2)->sole();
            $this->assertSame($first === 'refresh' ? 'Retained album' : 'Winning edit', $latest->manifest['title']);
            $this->assertSame('Synthetic reviewed member', $latest->manifest['members'][0]['title']);
            $this->assertSame($first === 'refresh' ? 1 : 0, AuditEvent::where('action', 'catalog.product_draft.members_refreshed')->count());
            $this->assertSame(2, AuditEvent::where('action', 'like', 'catalog.product_draft.%')->count());
            $this->assertSame('Synthetic reviewed member', $track->fresh()->title);
        } finally {
            $this->cleanup($directory, $processes);
        }
    }

    public static function snapshots(): array
    {
        return ['root transaction' => [false], 'old repeatable-read snapshot' => [true]];
    }

    #[DataProvider('snapshots')]
    public function test_native_source_wait_rejects_a_review_changed_by_the_committing_source_writer(bool $oldSnapshot): void
    {
        ['actor' => $actor, 'track' => $track, 'draft' => $draft] = $this->fixture();
        $review = app(ProductDrafts::class)->reviewMembers($draft->id, $actor);
        $before = ProductDraftVersion::sole()->getAttributes();
        $directory = $this->directory();
        $processes = [];
        try {
            DB::beginTransaction();
            DB::table('tracks')->where('id', $track->id)->lockForUpdate()->first();
            $blocker = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $processes['refresh'] = $this->worker($directory, ['name' => 'refresh', 'operation' => 'refresh',
                'actor_id' => $actor->id, 'draft_id' => $draft->id, 'version' => 1, 'review_hash' => $review['review_hash'],
                'old_snapshot' => $oldSnapshot]);
            $ready = $this->ready($directory, $processes);
            touch($directory.'/start-refresh');
            $this->await(fn () => $this->recordWait($ready['refresh']['connection_id'], $blocker, 'tracks', $track->id), $processes);
            DB::table('tracks')->where('id', $track->id)->update(['title' => 'Synthetic changed after review', 'metadata_version' => 2]);
            DB::commit();
            $result = $this->results($processes)['refresh'];
            $this->assertSame('rejected', $result['result']);
            $this->assertSame($oldSnapshot, $result['old_snapshot']);
            $this->assertSame($before, ProductDraftVersion::sole()->getAttributes());
            $this->assertSame(1, $draft->fresh()->version);
            $this->assertDatabaseCount('product_draft_members', 1);
            $this->assertSame(0, AuditEvent::where('action', 'catalog.product_draft.members_refreshed')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanup($directory, $processes);
        }
    }

    public static function authorityChanges(): array
    {
        return ['review / role' => ['review', 'role'], 'refresh / role' => ['refresh', 'role'],
            'review / MFA' => ['review', 'mfa'], 'refresh / MFA' => ['refresh', 'mfa']];
    }

    #[DataProvider('authorityChanges')]
    public function test_native_actor_wait_observes_authority_withdrawal_despite_an_older_snapshot(string $operation, string $state): void
    {
        ['actor' => $actor, 'draft' => $draft] = $this->fixture();
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $actor->saveAppAuthenticationSecret($panel->getMultiFactorAuthenticationProviders()['app']->generateSecret());
        $review = app(ProductDrafts::class)->reviewMembers($draft->id, $actor);
        $before = ProductDraftVersion::sole()->getAttributes();
        $directory = $this->directory();
        $processes = [];
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            $blocker = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $processes['withdrawal'] = $this->worker($directory, ['name' => 'withdrawal', 'operation' => $operation,
                'actor_id' => $actor->id, 'draft_id' => $draft->id, 'version' => 1, 'review_hash' => $review['review_hash'],
                'old_snapshot' => true, 'require_mfa' => true]);
            $ready = $this->ready($directory, $processes);
            touch($directory.'/start-withdrawal');
            $this->await(fn () => $this->recordWait($ready['withdrawal']['connection_id'], $blocker, 'users', $actor->id), $processes);
            DB::table('users')->where('id', $actor->id)->update($state === 'role' ? ['is_admin' => false] : ['app_authentication_secret' => null]);
            DB::commit();
            $result = $this->results($processes)['withdrawal'];
            $this->assertSame('unauthorized', $result['result']);
            $this->assertTrue($result['mfa_precheck']);
            $this->assertTrue($result['old_snapshot']);
            $this->assertSame($before, ProductDraftVersion::sole()->getAttributes());
            $this->assertSame(1, $draft->fresh()->version);
            $this->assertSame(0, AuditEvent::where('action', 'catalog.product_draft.members_refreshed')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanup($directory, $processes);
        }
    }

    private function directory(): string
    {
        $directory = storage_path('framework/testing/product-member-refresh-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);

        return $directory;
    }

    private function worker(string $directory, array $input): Process
    {
        $db = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/product-member-refresh-worker.php')], base_path(), [
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
