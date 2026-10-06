<?php

namespace Tests\Feature;

use App\Domain\Catalog\ReviewedOfferDraft;
use App\Domain\Catalog\SaveOfferDraft;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ReviewedOfferDraftConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Reviewed offer fences require independent MySQL sessions and exact record waits; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    public static function races(): array
    {
        $cases = [];
        foreach (['edit', 'legacy', 'publish', 'deactivate', 'role', 'email', 'mfa'] as $operation) {
            foreach ([0, 1] as $first) {
                $cases[$operation.' / worker '.$first.' first'] = [$operation, $first];
            }
        }

        return $cases;
    }

    #[DataProvider('races')]
    public function test_reviewed_edits_legacy_publication_deactivation_and_authority_serialize_both_commit_orders(string $operation, int $first): void
    {
        ['actor' => $editor, 'track' => $track, 'offer' => $offer, 'revision' => $original] = QuoteFixtures::selection();
        $other = in_array($operation, ['role', 'email', 'mfa'], true) ? $editor : LicenseFixtures::admin();
        $originalRow = $original->fresh()->getAttributes();
        $trackRow = $track->fresh()->getAttributes();
        $licenseRow = $offer->fresh()->licenseVersion->getAttributes();
        app(SaveOfferDraft::class)->handle($offer, ['price_minor' => 5999], $editor);
        if ($operation === 'mfa') {
            $panel = Filament::getPanel('admin');
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        }
        $command = app(ReviewedOfferDraft::class);
        $review = $command->review($offer, $editor);
        $data = Arr::only($review['display'], ReviewedOfferDraft::FIELDS);
        $audits = AuditEvent::count();
        $inputs = [$this->input('edit', $editor, ['track_id' => $track->id, 'offer_id' => $offer->id, 'review' => $review,
            'data' => array_replace($data, ['price_minor' => 6999])]),
            $this->input($operation, $other, ['track_id' => $track->id, 'offer_id' => $offer->id])];
        $inputs[1] += match ($operation) {
            'edit' => ['review' => $command->review($offer, $other), 'data' => array_replace($data, ['price_minor' => 7999])],
            'legacy' => ['data' => ['price_minor' => 8999]],
            default => [],
        };
        $authority = in_array($operation, ['role', 'email', 'mfa'], true);
        $table = $authority ? 'users' : 'tracks';
        $id = $authority ? $editor->id : $track->id;
        foreach ($inputs as &$input) {
            $input['require_mfa'] = $operation === 'mfa';
        }
        unset($input);
        $inputs[$first] += ['pause_table' => $table, 'pause_id' => $id];
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $operation, $authority, $table, $id, $offer, $editor, $audits): void {
            $this->releaseAfterExactWait($directory, $processes, $connections, $first, $table, $id);
            $results = $this->results($processes, $connections);
            $this->assertTrue($results[$first]['paused']);
            $this->assertSame('users', $results[0]['locks'][0]['table']);
            $this->assertContains($editor->id, $results[0]['locks'][0]['ids']);
            if ($first === 0 || ! $authority) {
                $tables = array_column($results[0]['locks'], 'table');
                $trackIndex = array_search('tracks', $tables, true);
                $this->assertIsInt($trackIndex);
                $this->assertGreaterThan(0, $trackIndex);
                $this->assertSame('offers', $tables[$trackIndex + 1]);
            }
            $current = $offer->fresh();
            if ($operation === 'edit') {
                $this->assertSame('saved', $results[$first]['result']);
                $this->assertSame('blocked', $results[1 - $first]['result']);
                $this->assertArrayHasKey('offer', $results[1 - $first]['errors']);
                $this->assertSame($results[$first]['row'], $current->getAttributes());
                $this->assertSame($first === 0 ? 6999 : 7999, $current->price_minor);
                $this->assertSame($audits + 1, AuditEvent::count());
            } elseif ($authority) {
                $this->assertSame('withdrawn', $results[1]['result']);
                $this->assertSame($first === 0 ? 'saved' : 'denied', $results[0]['result']);
                $this->assertSame($first === 0 ? 6999 : 5999, $current->price_minor);
                $this->assertSame($audits + ($first === 0 ? 1 : 0), AuditEvent::count());
                $this->assertSame(match ($operation) {
                    'role' => false, default => null
                }, match ($operation) {
                    'role' => $editor->fresh()->is_admin, 'email' => $editor->fresh()->email_verified_at, default => $editor->fresh()->app_authentication_secret
                });
            } else {
                $this->assertSame('saved', $results[1]['result']);
                $this->assertSame($first === 0 ? 'saved' : 'blocked', $results[0]['result']);
                if ($first === 1) {
                    $this->assertArrayHasKey('offer', $results[0]['errors']);
                }
                $this->assertSame($audits + ($first === 0 ? 2 : 1), AuditEvent::count());
                if ($operation === 'legacy') {
                    $this->assertSame(8999, $current->price_minor);
                } else {
                    $this->assertSame($first === 0 ? 6999 : 5999, $current->price_minor);
                    if ($operation === 'publish') {
                        $this->assertSame(2, $current->currentRevision->revision);
                        $this->assertSame($current->price_minor, $current->currentRevision->price_minor);
                        $this->assertTrue($current->is_active);
                    } else {
                        $this->assertFalse($current->is_active);
                        $this->assertSame(1, $current->currentRevision->revision);
                    }
                }
            }
        });
        $this->assertSame($originalRow, $original->fresh()->getAttributes());
        $this->assertSame($trackRow, $track->fresh()->getAttributes());
        $this->assertSame($licenseRow, $offer->fresh()->licenseVersion->getAttributes());
        $this->assertDatabaseCount('license_grants', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    private function input(string $operation, User $actor, array $data): array
    {
        return $data + ['operation' => $operation, 'actor_id' => $actor->id, 'media_root' => Storage::disk('local')->path('')];
    }

    private function releaseAfterExactWait(string $directory, array $processes, array $connections, int $first, string $table, int $id): void
    {
        touch($directory.'/start-'.$first);
        $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
        touch($directory.'/start-'.(1 - $first));
        $this->await(fn () => $this->waiting($connections[1 - $first], $connections[$first], $table, $id), $processes);
        touch($directory.'/release-'.$first);
    }

    private function race(array $inputs, callable $coordinate): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/reviewed-offer-draft-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/reviewed-offer-draft-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_REVIEWED_OFFER_DIRECTORY' => $directory, 'VASEY_REVIEWED_OFFER_WORKER' => (string) $worker,
                ], json_encode($input, JSON_THROW_ON_ERROR), 40);
                $process->start();
                $processes[] = $process;
            }
            $this->await(fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), $processes);
            $ready = array_map(fn ($worker) => json_decode(file_get_contents($directory.'/ready-'.$worker), true, 16, JSON_THROW_ON_ERROR), [0, 1]);
            $connections = array_column($ready, 'connection_id');
            $this->assertCount(3, array_unique([...$connections, (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]));
            $this->assertCount(3, array_unique([...array_column($ready, 'pid'), getmypid()]));
            foreach ($ready as $row) {
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
            $this->assertSame(0, $process->getExitCode(), 'Reviewed offer worker process failed: '.$process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame($connections[$index], $result['connection_id']);
            $this->assertSame(0, $result['transaction_level']);
            $results[] = $result;
        }

        return $results;
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
                $this->assertTrue($process->isRunning(), 'A reviewed offer worker exited before the required barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Reviewed offer workers did not reach the required exact row wait/barrier.');
    }
}
