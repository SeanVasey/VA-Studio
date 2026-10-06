<?php

namespace Tests\Feature;

use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\OrderInquiry;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\SiteContent;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InquiryConversationFixtures;
use Tests\Support\OrderInquiryFixtures as Fixture;
use Tests\Support\PaymentFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

class OrderInquiryConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Order inquiry contention requires independent native MySQL processes.');
        }
    }

    public static function races(): array
    {
        $cases = ['exact duplicate' => ['duplicate', 0]];
        foreach (['publication', 'role', 'mfa', 'credential', 'account'] as $kind) {
            $cases[$kind.' admission first'] = [$kind, 0];
            $cases[$kind.' withdrawal first'] = [$kind, 1];
        }

        return $cases;
    }

    #[DataProvider('races')]
    public function test_current_publication_operator_and_customer_fences_serialize_both_commit_orders(string $kind, int $first): void
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $fixture = Fixture::configure();
        $fixture['actor']->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $account = in_array($kind, ['credential', 'account'], true) ? CustomerFixtures::account() : null;
        $order = $account ? CustomerFixtures::prepared($account['user']) : Fixture::guest();
        $body = Fixture::body();
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $principal = $account ? [$account['principal']->accountId, $account['principal']->userId, $account['principal']->ownerKey,
            $account['principal']->accessVersion, $account['principal']->credentialStamp] : null;
        $table = in_array($kind, ['duplicate', 'publication'], true) ? 'site_publications' : 'users';
        $row = $table === 'site_publications' ? 1 : ($account ? $account['user']->id : $fixture['actor']->id);
        $job = ['operation' => 'submit', 'order' => $order->public_id, 'owner' => $order->owner_key,
            'inquiry_owner' => InquiryConversationFixtures::OWNER, 'body' => $body, 'operator' => $fixture['actor']->id,
            'table' => $table, 'row' => $row, 'at' => now()->toISOString(), 'principal' => $principal,
            'config' => array_combine(array_map(fn ($key) => 'inquiries.'.$key, array_keys(config('inquiries'))), array_values(config('inquiries')))];
        $second = array_replace($job, ['operation' => $kind === 'duplicate' ? 'submit' : $kind]);
        if ($kind === 'publication') {
            $content = SiteEditorialFixtures::content('WITHDRAWN');
            $content['contact'] = null;
            $content['navigation'] = array_values(array_filter($content['navigation'], fn ($item) => $item['href'] !== '/contact'));
            $second['release'] = app(SiteContent::class)->create($content, 'Synthetic contact withdrawal', $fixture['actor'])->id;
            $second['revision'] = SitePublication::findOrFail(1)->revision;
        }
        try {
            $results = $this->race([$job, $second], $first, $table, $row);
            $saved = $kind === 'duplicate' || $first === 0;
            $this->assertSame($saved ? 200 : 404, $results[0]['status']);
            $this->assertSame(200, $results[1]['status']);
            $this->assertDatabaseCount('customer_inquiries', $saved ? 2 : 1);
            $this->assertDatabaseCount('inquiry_order_contexts', $saved ? 1 : 0);
            $this->assertSame($saved ? 1 : 0, AuditEvent::where('action', 'inquiry.test_order_linked')->count());
            $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
            if ($kind === 'duplicate') {
                $this->assertFalse($results[0]['replayed']);
                $this->assertTrue($results[1]['replayed']);
                $this->assertSame($results[0]['receipt'], $results[1]['receipt']);
            }
            foreach ($results as $result) {
                $this->assertTrue($result['old']['operator_admin']);
                if ($account) {
                    $this->assertTrue($result['old']['customer_active']);
                }
            }
            if ($saved) {
                $inquiry = CustomerInquiry::where('request_key', $body['requestKey'])->sole();
                $this->assertSame($order->public_id, app(OrderInquiry::class)->ownerContext($inquiry->public_id, InquiryConversationFixtures::OWNER)['order']['id']);
                $locks = $results[0]['locks'];
                $this->assertLessThan(array_search('users', $locks, true), array_search('site_publications', $locks, true));
                $this->assertLessThan(array_search('orders', $locks, true), array_search('users', $locks, true));
                if ($account) {
                    $this->assertLessThan(array_search('orders', $locks, true), array_search('customer_accounts', $locks, true));
                }
            }
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    private function race(array $jobs, int $first, string $table, int $row): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/order-inquiry-'.Str::uuid());
        $files = new Filesystem;
        $files->makeDirectory($directory, 0700, true);
        $database = DB::connection()->getConfig();
        $processes = [];
        try {
            foreach ($jobs as $worker => $job) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/order-inquiry-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'],
                    'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                ], json_encode($job + compact('worker', 'directory'), JSON_THROW_ON_ERROR), 50);
                $process->start();
                $processes[] = $process;
            }
            $this->until($processes, fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), 'complete readiness');
            $ready = array_map(fn ($worker) => json_decode(file_get_contents($directory.'/ready-'.$worker), true, 8, JSON_THROW_ON_ERROR), [0, 1]);
            $ids = array_column($ready, 'connection');
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertCount(3, array_unique([...$ids, $parent]));
            touch($directory.'/start-'.$first);
            $this->until($processes, fn () => is_file($directory.'/locked-'.$first), 'first row lock');
            touch($directory.'/start-'.(1 - $first));
            $wait = null;
            $this->until($processes, function () use ($ids, $first, $database, $table, $row, &$wait): bool {
                $wait = DB::selectOne("SELECT l.OBJECT_NAME AS table_name, l.INDEX_NAME AS index_name, l.LOCK_DATA AS row_data,
                    l.LOCK_TYPE AS type, l.LOCK_STATUS AS state, r.PROCESSLIST_ID AS requester, b.PROCESSLIST_ID AS blocker
                    FROM performance_schema.data_lock_waits w
                    JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID
                    JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID
                    JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE = w.ENGINE
                    WHERE w.ENGINE = 'INNODB' AND r.PROCESSLIST_ID = ? AND b.PROCESSLIST_ID = ? AND l.OBJECT_SCHEMA = ?
                    AND l.OBJECT_NAME = ? AND l.INDEX_NAME = 'PRIMARY' AND l.LOCK_DATA = ? AND l.LOCK_TYPE = 'RECORD' AND l.LOCK_STATUS = 'WAITING' LIMIT 1",
                    [$ids[1 - $first], $ids[$first], $database['database'], $table, (string) $row]);

                return $wait !== null;
            }, 'exact primary record wait');
            $this->assertSame([$table, 'PRIMARY', (string) $row, 'RECORD', 'WAITING', $ids[1 - $first], $ids[$first]],
                [$wait->table_name, $wait->index_name, $wait->row_data, $wait->type, $wait->state, (int) $wait->requester, (int) $wait->blocker]);
            touch($directory.'/commit');
            $results = [];
            foreach ($processes as $worker => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, 24, JSON_THROW_ON_ERROR);
                $this->assertSame($ready[$worker]['pid'], $result['pid']);
                $this->assertSame($ids[$worker], $result['connection']);
                $this->assertSame(0, $result['transaction_level']);
                $this->assertSame('REPEATABLE-READ', $result['isolation']);
                $results[] = $result;
            }
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $files->deleteDirectory($directory);
        }
    }

    private function until(array $processes, callable $ready, string $stage): void
    {
        $deadline = microtime(true) + 20;
        do {
            clearstatcache();
            if ($ready()) {
                return;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), $stage.': '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Native order inquiry race missed '.$stage.'.');
    }
}
