<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\ReviewLicense;
use App\Models\User;
use App\Support\Audit\AuditEvent;
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

class LicenseWriterConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Exact license and current-authority row waits require independent MySQL sessions; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    private function input(string $operation, User $actor, array $input = []): array
    {
        return $input + ['operation' => $operation, 'actor_id' => $actor->id, 'media_root' => Storage::disk('local')->path('')];
    }

    public static function publicationOrdering(): array
    {
        return ['same actor / license first' => [false, 0], 'same actor / offer first' => [false, 1],
            'distinct actors / license first' => [true, 0], 'distinct actors / offer first' => [true, 1]];
    }

    #[DataProvider('publicationOrdering')]
    public function test_license_and_offer_publication_complete_in_both_orders_with_exact_user_or_license_fences(bool $distinct, int $first): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $publisher = $distinct ? LicenseFixtures::admin() : $actor;
        $license = LicenseFixtures::approved($publisher);
        $offer = app(SaveOfferDraft::class)->handle($fixture['offer'], ['license_version_id' => $license->id], $actor);
        $oldRevision = $fixture['revision']->fresh()->getAttributes();
        $oldLicense = LicenseVersion::findOrFail($oldRevision['license_version_id'])->getAttributes();
        $oldReview = $license->reviewEvidence()->firstOrFail()->getAttributes();
        $audits = AuditEvent::count();
        $inputs = [$this->input('publish', $publisher, ['version_id' => $license->id]),
            $this->input('offer', $actor, ['offer_id' => $offer->id])];
        $inputs[$first] += ['pause_table' => 'license_versions', 'pause_id' => $license->id];
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $distinct, $actor, $license, $offer): void {
            $second = 1 - $first;
            touch($directory.'/start-'.$first);
            $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/start-'.$second);
            $this->observeWait($connections[$second], $connections[$first], $distinct ? 'license_versions' : 'users', $distinct ? $license->id : $actor->id, $processes);
            touch($directory.'/release-'.$first);
            $results = $this->results($processes, $connections);
            $this->assertSame('saved', $results[0]['result'], json_encode($results[0], JSON_THROW_ON_ERROR));
            $this->assertSame('published', $license->fresh()->status);
            $this->assertSame($results[0]['row'], $license->fresh()->getAttributes());
            foreach ($results as $result) {
                $this->assertSame('users', $result['locks'][0]['table']);
                $this->assertContains($license->id, array_merge(...array_column(array_filter($result['locks'], fn ($lock) => $lock['table'] === 'license_versions'), 'ids')));
            }
            if ($first === 0) {
                $this->assertSame('offer-published', $results[1]['result'], json_encode($results[1], JSON_THROW_ON_ERROR));
                $revision = OfferRevision::findOrFail($results[1]['revision_id']);
                $this->assertSame($license->id, $revision->license_version_id);
                $this->assertSame(2, $revision->revision);
                $this->assertSame($actor->id, $revision->published_by);
                $this->assertSame($revision->id, $offer->fresh()->current_revision_id);
            } else {
                $this->assertSame('blocked', $results[1]['result']);
                $this->assertArrayHasKey('offer', $results[1]['errors']);
                $this->assertArrayNotHasKey('revision_id', $results[1]);
                $this->assertSame(1, $offer->revisions()->count());
            }
        });
        $this->assertSame($oldRevision, $fixture['revision']->fresh()->getAttributes());
        $this->assertSame($oldLicense, LicenseVersion::findOrFail($oldRevision['license_version_id'])->getAttributes());
        $this->assertSame($oldReview, $license->reviewEvidence()->firstOrFail()->getAttributes());
        $this->assertSame($audits + ($first === 0 ? 2 : 1), AuditEvent::count());
        $this->assertSame(1, AuditEvent::where('action', 'rights.license.published')->where('subject_id', $license->id)->count());
    }

    public static function withdrawalOrdering(): array
    {
        $cases = [];
        foreach (['create', 'update', 'submit', 'approve', 'publish'] as $writer) {
            foreach (['writer first' => 0, 'withdrawal first' => 1] as $order => $first) {
                $cases[$writer.' / '.$order] = [$writer, $first];
            }
        }

        return $cases;
    }

    #[DataProvider('withdrawalOrdering')]
    public function test_all_license_mutations_serialize_current_role_withdrawal_before_resource_and_actor_fk_writes(string $writer, int $first): void
    {
        $actor = LicenseFixtures::admin();
        $version = match ($writer) {
            'approve' => app(ReviewLicense::class)->submit(LicenseFixtures::draft(), LicenseFixtures::admin()),
            'publish' => LicenseFixtures::approved($actor),
            default => LicenseFixtures::draft($actor),
        };
        $before = array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['license_versions', 'license_review_evidence', 'audit_events']);
        $audits = AuditEvent::count();
        $inputs = [$this->input($writer, $actor, ['version_id' => $version->id]), $this->input('withdraw', $actor)];
        $inputs[$first] += ['pause_table' => 'users', 'pause_id' => $actor->id];
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $actor, $version, $writer, $before, $audits): void {
            $second = 1 - $first;
            touch($directory.'/start-'.$first);
            $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/start-'.$second);
            $this->observeWait($connections[$second], $connections[$first], 'users', $actor->id, $processes);
            touch($directory.'/release-'.$first);
            $results = $this->results($processes, $connections);
            $this->assertSame('withdrawn', $results[1]['result']);
            $this->assertFalse($actor->fresh()->is_admin);
            if ($first === 0) {
                $this->assertSame('saved', $results[0]['result'], json_encode($results[0], JSON_THROW_ON_ERROR));
                $this->assertSame('users', $results[0]['locks'][0]['table']);
                $this->assertSame($audits + 1, AuditEvent::count());
                $this->assertSame($results[0]['row'], LicenseVersion::findOrFail($results[0]['row']['id'])->getAttributes());
                if ($writer !== 'create') {
                    $this->assertSame($version->id, $results[0]['row']['id']);
                }
            } else {
                $this->assertSame('denied', $results[0]['result']);
                $this->assertArrayNotHasKey('row', $results[0]);
                $this->assertSame($before, array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['license_versions', 'license_review_evidence', 'audit_events']));
            }
        });
    }

    private function race(array $inputs, callable $coordinate): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/license-writer-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/license-writer-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_LICENSE_WRITER_DIRECTORY' => $directory, 'VASEY_LICENSE_WRITER_WORKER' => (string) $worker,
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
            $this->assertSame(0, $process->getExitCode(), 'License writer process failed: '.$process->getOutput().$process->getErrorOutput());
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
                $this->assertTrue($process->isRunning(), 'A license worker exited before the required barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('License writers did not reach the required exact row wait/barrier.');
    }
}
