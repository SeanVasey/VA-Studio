<?php

namespace Tests\Feature;

use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\PaidTax\PaidTaxDocuments;
use App\Domain\Grants\PaidTax\PaidTaxDownloads;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\TestCase;

/** Independent real MySQL buyer/source commands. Availability refusals are not SQL uniqueness evidence. */
final class PaidTaxDownloadNativeTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxGrantFixtures;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePaidTaxFixture();
        config(['paid-tax-grants.downloads_enabled' => true]);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($this->workers as $w) {
            $w['process']->stop(0);
            (new Filesystem)->deleteDirectory($w['directory']);
        }
        parent::tearDown();
    }

    private function payload(array $f): array
    {
        return ['batch_id' => $f['batch']['id'], 'line_id' => $f['batch']['lines'][0]['id'], 'identity' => config('production-customer-identity'),
            'tax' => config('production-tax-checkout'), 'consumer' => config('paid-tax-grants'), 'private_root' => Storage::disk('local')->path('')];
    }

    private function race(array $one, array $two, int $user): array
    {
        $a = $this->start($one);
        $b = $this->start($two);
        $parent = (int) DB::getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->assertNotSame($a['connection_id'], $b['connection_id']);
        DB::beginTransaction();
        DB::table('users')->where('id', $user)->lockForUpdate()->first();
        foreach ([$a, $b] as $worker) {
            touch($worker['directory'].'/start');
            $this->await(fn () => $this->waiting($worker['connection_id'], $parent, $user), $worker['process']);
        }
        DB::commit();
        $results = [$this->finish($a), $this->finish($b)];
        fwrite(STDOUT, 'Native Tax delivery worker outcomes: '.json_encode($results, JSON_THROW_ON_ERROR).PHP_EOL);
        foreach ($results as $i => $result) {
            $this->assertSame(0, $result['transaction_level']);
            $this->assertFalse($result['in_transaction']);
            $this->assertSame([$a, $b][$i]['waits'], $result['waits']);
            $this->assertSame([$a, $b][$i]['connection_id'], $result['connection_id']);
        }

        return $results;
    }

    public function test_native_duplicate_authorizations_join_one_immutable_result_after_current_buyer_serialization(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent native MySQL authorization race.');
        }
        $f = $this->complete();
        $input = $this->input($f['batch']['lines'][0], 'master_wav');
        $payload = $this->payload($f) + ['mode' => 'authorize', 'request' => $input];
        $results = $this->race($payload, $payload, $f['buyer']['user']->id);
        $saved = array_values(array_filter($results, fn ($r) => $r['result'] === 'authorized'));
        $this->assertNotEmpty($saved);
        foreach ($results as $result) {
            if ($result['result'] !== 'authorized') {
                $this->assertSame([PaidTaxException::class, 503], [$result['error_class'], $result['status']]);
            }
        }
        $a = (new PaidTaxDownloads)->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'], $input, $f['buyer']['principal'], $f['buyer']['user']);
        foreach ($saved as $result) {
            $this->assertSame($a['id'], $result['id']);
            $this->assertSame(hash('sha256', $a['token']), $result['token_hash']);
        }
        $this->assertDatabaseCount('paid_tax_authorizations', 1);
        $this->assertDatabaseCount('paid_tax_redemptions', 0);
        $this->assertDatabaseCount('paid_authorizations', 0);
    }

    public static function redemptionModes(): array
    {
        return ['same authorization' => [false], 'last retained attempt across two authorizations' => [true]];
    }

    #[DataProvider('redemptionModes')]
    public function test_native_redemption_duplicate_and_last_attempt_races_keep_one_spent_attempt(bool $last): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent native MySQL redemption race.');
        }
        if ($last) {
            $p = config('paid-tax-grants.delivery_policy');
            $p['max_downloads'] = 1;
            config(['paid-tax-grants.delivery_policy' => $p]);
        }
        $f = $this->complete();
        $a = $this->authorize($f);
        $b = $last ? $this->authorize($f) : $a;
        $payload = $this->payload($f) + ['mode' => 'redeem'];
        $results = $this->race($payload + ['authorization_id' => $a['id'], 'token' => $a['token']], $payload + ['authorization_id' => $b['id'], 'token' => $b['token']], $f['buyer']['user']->id);
        $saved = array_values(array_filter($results, fn ($r) => $r['result'] === 'delivered'));
        $this->assertCount(1, $saved);
        $expected = file_get_contents(Storage::disk('local')->path($f['catalog']['media']['master_wav']->storage_path));
        $this->assertSame([strlen($expected), hash('sha256', $expected)], [$saved[0]['bytes'], $saved[0]['sha256']]);
        foreach ($results as $result) {
            if ($result['result'] === 'delivered') {
                continue;
            }
            $valid = $result['error_class'] === PaidTaxException::class && in_array($result['status'], [409, 503], true)
                || $result['error_class'] === DeliveryException::class && $result['reason'] === 'target_unavailable';
            $this->assertTrue($valid, json_encode($result, JSON_THROW_ON_ERROR));
        }
        $this->assertDatabaseCount('paid_tax_redemptions', 1);
        $d = new PaidTaxDownloads;
        foreach ([$a, $b] as $auth) {
            $this->refused(409, fn () => $d->redeem($f['batch']['id'], $auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']));
        }
        $this->assertSame(1, $d->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'])['lines'][0]['attemptCount']);
        $this->assertDatabaseCount('paid_redemptions', 0);
    }

    private function start(array $input): array
    {
        $directory = storage_path('framework/testing/paid-tax-delivery-race-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $database = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_PAID_TAX_DELIVERY_RACE_ONLY' => '1', 'VA_PAID_TAX_DELIVERY_RACE_DIRECTORY' => $directory];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) $database[$key];
        }
        $process = new Process([PHP_BINARY, base_path('tests/Support/paid-tax-delivery-race-worker.php')], base_path(), $environment, json_encode($input, JSON_THROW_ON_ERROR), 90);
        $process->start();
        $worker = compact('process', 'directory');
        $this->workers[] = $worker;
        $this->await(fn (): bool => is_file($directory.'/ready'), $process);
        $ready = json_decode(file_get_contents($directory.'/ready'), true, 8, JSON_THROW_ON_ERROR);
        $this->assertNotSame(getmypid(), $ready['pid']);

        return $worker + $ready;
    }

    private function finish(array $worker): array
    {
        $worker['process']->wait();
        $this->assertSame(0, $worker['process']->getExitCode(), 'The isolated native worker did not complete.');

        return json_decode($worker['process']->getOutput(), true, 16, JSON_THROW_ON_ERROR);
    }

    private function waiting(int $requester, int $blocker, int $userId): bool
    {
        $row = DB::selectOne(<<<'SQL'
SELECT requested.LOCK_DATA AS row_key FROM performance_schema.data_lock_waits waits
JOIN performance_schema.threads requester ON requester.THREAD_ID=waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads blocker ON blocker.THREAD_ID=waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID=waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE=waits.ENGINE
WHERE waits.ENGINE='INNODB' AND requester.PROCESSLIST_ID=? AND blocker.PROCESSLIST_ID=?
AND requested.OBJECT_SCHEMA=? AND requested.OBJECT_NAME='users' AND requested.INDEX_NAME='PRIMARY'
AND requested.LOCK_TYPE='RECORD' AND requested.LOCK_STATUS='WAITING' LIMIT 1
SQL, [$requester, $blocker, DB::getDatabaseName()]);

        return $row !== null && trim($row->row_key, "'\"") === (string) $userId;
    }

    private function await(callable $condition, Process $process): void
    {
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            if (! $process->isRunning()) {
                $result = json_decode($process->getOutput(), true);
                $public = is_array($result) ? array_intersect_key($result, array_flip(['result', 'error_class', 'status', 'location', 'phase', 'transaction_level', 'in_transaction'])) : ['result' => 'no JSON result'];
                $this->fail('Native worker ended before the expected barrier: '.json_encode($public, JSON_THROW_ON_ERROR));
            }
            $process->checkTimeout();
            usleep(10000);
        } while (hrtime(true) < $deadline);
        $this->fail('Native worker did not reach its exact original-buyer row wait.');
    }

    protected function complete(bool $twoLines = false): array
    {
        $f = $this->paidTaxOrder(twoLines: $twoLines);
        $f['batch'] = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['batch'] = (new PaidTaxDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);

        return $f;
    }

    protected function input(array $line, string $kind): array
    {
        return ['requestKey' => (string) Str::uuid(), 'originHash' => $line['originHash'], 'kind' => $kind, 'nonce' => bin2hex(random_bytes(32))];
    }

    protected function authorize(array $f, string $kind = 'master_wav'): array
    {
        return (new PaidTaxDownloads)->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'], $this->input($f['batch']['lines'][0], $kind),
            $f['buyer']['principal'], $f['buyer']['user']);
    }

    protected function refused(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('The invalid delivery operation was accepted.');
        } catch (PaidTaxException $error) {
            $this->assertSame($status, $error->status);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::getPdo()->inTransaction());
    }
}
