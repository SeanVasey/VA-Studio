<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Inquiries\InquiryConversation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InquiryConversationFixtures as Fixture;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class InquiryConversationConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent conversation record waits require native MySQL.');
        }
    }

    public static function races(): array
    {
        return ['duplicate owner' => ['duplicate', 'owner'], 'duplicate staff' => ['duplicate', 'reply'],
            'archive owner' => ['archive', 'owner'], 'archive staff' => ['archive', 'reply'],
            'role reply' => ['role', 'reply'], 'role read' => ['role', 'read'],
            'MFA reply' => ['mfa', 'reply'], 'MFA read' => ['mfa', 'read']];
    }

    #[DataProvider('races')]
    public function test_committed_inquiry_and_actor_fences_decide_waiting_conversation_operations(string $change, string $operation): void
    {
        $this->fakePrivateMediaStorage();
        ['actor' => $actor, 'inquiry' => $inquiry] = Fixture::create();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $archiver = LicenseFixtures::admin();
        $archiver->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $body = Fixture::message();
        $before = $inquiry->getAttributes();
        $directory = storage_path('framework/testing/inquiry-conversation-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $process = null;
        $firstId = null;
        try {
            $this->assertSame(0, DB::transactionLevel());
            DB::beginTransaction();
            if ($change === 'duplicate') {
                $service = app(InquiryConversation::class);
                $first = $operation === 'owner' ? $service->followUp($inquiry->public_id, Fixture::OWNER, $body) : $service->reply($inquiry->id, $body, $actor);
                $firstId = $first['messageId'];
            } elseif ($change === 'archive') {
                app(InquiryAdministration::class)->transition($inquiry->id, 'archived', 0, $archiver);
            } else {
                $current = User::lockForUpdate()->findOrFail($actor->id);
                if ($change === 'mfa') {
                    $current->saveAppAuthenticationSecret(null);
                } else {
                    $current->forceFill(['is_admin' => false])->save();
                }
            }
            $database = DB::connection()->getConfig();
            $process = new Process([PHP_BINARY, base_path('tests/Support/inquiry-conversation-race-worker.php')], base_path(), [
                'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
                'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            ], json_encode(['operation' => $operation, 'inquiry_id' => $inquiry->id, 'actor_id' => $actor->id,
                'owner' => Fixture::OWNER, 'body' => $body, 'ready' => $directory.'/ready'], JSON_THROW_ON_ERROR), 40);
            $process->start();
            $this->until($process, fn (): bool => is_file($directory.'/ready'));
            $child = (int) file_get_contents($directory.'/ready');
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertNotSame($parent, $child);
            $table = in_array($change, ['role', 'mfa'], true) || ($change === 'duplicate' && $operation === 'reply') ? 'users' : 'customer_inquiries';
            $wait = null;
            $this->until($process, function () use ($child, $parent, $database, $table, &$wait): bool {
                $wait = DB::selectOne("SELECT l.OBJECT_NAME AS table_name, l.INDEX_NAME AS index_name, l.LOCK_DATA AS row_data, l.LOCK_STATUS AS state
                    FROM performance_schema.data_lock_waits w
                    JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID
                    JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID
                    JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE = w.ENGINE
                    WHERE w.ENGINE = 'INNODB' AND r.PROCESSLIST_ID = ? AND b.PROCESSLIST_ID = ?
                    AND l.OBJECT_SCHEMA = ? AND l.OBJECT_NAME = ? AND l.LOCK_TYPE = 'RECORD' AND l.LOCK_STATUS = 'WAITING' LIMIT 1",
                    [$child, $parent, $database['database'], $table]);

                return $wait !== null;
            });
            $this->assertSame('WAITING', $wait->state);
            if ($table === 'users' || $wait->index_name === 'PRIMARY') {
                $this->assertSame('PRIMARY', $wait->index_name);
                $this->assertSame((string) ($table === 'users' ? $actor->id : $inquiry->id), $wait->row_data);
            } else {
                $this->assertSame('customer_inquiries_public_id_unique', $wait->index_name);
                $this->assertSame("'".$inquiry->public_id."', ".$inquiry->id, $wait->row_data);
            }
            DB::commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            $this->assertNotSame(getmypid(), $result['pid']);
            $this->assertSame($child, $result['connection']);
            $this->assertSame('new', $result['old_state']);
            $this->assertTrue($result['old_admin']);
            $this->assertSame(0, $result['transaction_level']);
            $this->assertSame($change === 'duplicate' ? 200 : ($change === 'archive' ? 409 : 403), $result['status']);
            if ($change === 'duplicate') {
                $this->assertTrue($result['replayed']);
                $this->assertSame($firstId, $result['messageId']);
            }
            $this->assertDatabaseCount('inquiry_messages', $change === 'duplicate' ? 1 : 0);
            if ($change !== 'archive') {
                $this->assertSame($before, $inquiry->fresh()->getAttributes());
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            (new Filesystem)->deleteDirectory($directory);
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    private function until(Process $process, callable $ready): void
    {
        $deadline = microtime(true) + 20;
        do {
            clearstatcache();
            if ($ready()) {
                return;
            }
            $this->assertTrue($process->isRunning(), $process->getOutput().$process->getErrorOutput());
            $process->checkTimeout();
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Native conversation barrier was not observed.');
    }
}
