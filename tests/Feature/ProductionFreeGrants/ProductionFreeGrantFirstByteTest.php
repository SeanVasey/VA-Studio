<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantCommittedRead;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantTransfer;
use ArrayObject;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Component\Process\Process;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Owner doc step 5: after the redemption commits, the transfer's committed read must pass before the first byte, on
 * the original deadline. A withdrawal after that commit means zero bytes, the retained redemption and the fixed
 * `outcome_unknown`; nothing retries and nothing renews the deadline.
 */
final class ProductionFreeGrantFirstByteTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
    }

    public function test_an_unchanged_committed_redemption_delivers_the_exact_bytes_once(): void
    {
        [$owner, $authorization] = $this->authorized('master_wav');
        $transfer = (new ProductionFreeGrantDownloads)->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']);
        $transfer->begin();
        $this->assertSame($this->sources->bytes['synthetic-master_wav'], $this->bytes($transfer));
        $this->assertSame(1, DB::table('production_free_redemptions')->count());
    }

    /** @return array<string, array{0:string}> */
    public static function withdrawals(): array
    {
        return ['staff revocation' => ['revocation'], 'owner account withdrawn' => ['owner'], 'policy withdrawn' => ['policy'],
            'session temporary shadow of the revocations table' => ['shadow'], 'facade cache replaced' => ['facade']];
    }

    #[DataProvider('withdrawals')]
    public function test_a_withdrawal_after_the_redemption_commit_sends_zero_bytes_and_keeps_the_redemption(string $withdrawal): void
    {
        [$owner, $authorization, $origin, $seal] = $this->authorized('stems_zip');
        $transfer = (new ProductionFreeGrantDownloads)->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']);
        $this->assertSame(1, DB::table('production_free_redemptions')->count());
        $facade = new ReflectionProperty(Facade::class, 'resolvedInstance');
        $cache = $facade->getValue();
        $sentinel = null;
        match ($withdrawal) {
            'revocation' => (new ProductionFreeGrants)->revoke($origin['id'], ['originSeal' => $seal, 'reason' => 'Synthetic withdrawal after redemption'], $this->staff()),
            'owner' => DB::table('customer_accounts')->where('id', $owner['principal']->accountId)->update(['active' => false, 'access_version' => 2]),
            'policy' => config(['production-free-grants.enabled' => false]),
            'shadow' => DB::connection()->getPdo()->exec('CREATE TEMPORARY TABLE production_free_revocations (id TEXT)'),
            'facade' => $facade->setValue(null, $sentinel = new FirstByteFacadeSentinel($cache)),
        };
        $calls = 0;
        try {
            $transfer->writeTo(function () use (&$calls): void {
                $calls++;
            });
            $this->fail('A withdrawn redemption must not deliver.');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('outcome_unknown', $error->reason);
        } finally {
            $facade->setValue(null, $cache);
            if ($withdrawal === 'shadow') {
                DB::connection()->getPdo()->exec(DB::getDriverName() === 'mysql' ? 'DROP TEMPORARY TABLE production_free_revocations' : 'DROP TABLE temp.production_free_revocations');
            }
        }
        $this->assertSame(0, $calls, 'Zero bytes after a post-commit withdrawal.');
        $this->assertSame(0, $sentinel?->calls ?? 0, 'The replaced facade cache ran no offset callback.');
        // The committed redemption is retained; the outcome is honestly unknown, never a refusal of the commit.
        $this->assertSame(1, DB::table('production_free_redemptions')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        // Nothing retries: the same authorization cannot be redeemed again.
        config(['production-free-grants.enabled' => true]);
        if ($withdrawal !== 'owner') {
            $this->refuses(fn () => (new ProductionFreeGrantDownloads)->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']),
                $withdrawal === 'revocation' ? 'revoked' : 'already_redeemed', 'already_redeemed');
        }
    }

    public function test_revocation_inside_the_redemption_commit_rolls_back_the_redemption_and_the_revocation(): void
    {
        [$owner, $authorization, $origin] = $this->authorized('master_wav');
        $calls = 0;
        // Only the redemption commit has a redemption row; the revocation lands inside that frame, after the work ran.
        Event::listen(TransactionCommitting::class, function () use (&$calls, $origin): void {
            if ($calls === 0 && DB::table('production_free_redemptions')->count() > 0) {
                $calls++;
                $this->insertRevocation($origin['id']);
            }
        });
        $this->refuses(fn () => (new ProductionFreeGrantDownloads)->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']), 'revoked');
        $this->assertSame(1, $calls);
        $this->assertSame(0, DB::table('production_free_redemptions')->count());
        $this->assertSame(0, DB::table('production_free_revocations')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_the_committed_read_is_one_use_and_refuses_after_its_original_deadline(): void
    {
        [$owner, $authorization] = $this->authorized('download_mp3');
        $transfer = (new ProductionFreeGrantDownloads)->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']);
        $read = (new ReflectionProperty(ProductionFreeGrantTransfer::class, 'committed'))->getValue($transfer);
        $this->assertInstanceOf(ProductionFreeGrantCommittedRead::class, $read);
        $values = [];
        foreach (['identity', 'policy', 'rows'] as $name) {
            $values[$name] = (new ReflectionProperty(ProductionFreeGrantCommittedRead::class, $name))->getValue($read);
        }
        $read->proveBeforeBytes();
        $this->refuses(fn () => $read->proveBeforeBytes(), 'outcome_unknown');
        // The same evidence on a deadline that has passed: the frame refuses; nothing extends it.
        $expired = new ProductionFreeGrantCommittedRead($owner['principal'], $owner['user'], $values['identity'], $values['policy'], $values['rows'], hrtime(true) - 1);
        $this->refuses(fn () => $expired->proveBeforeBytes(), 'outcome_unknown');
        $fresh = new ProductionFreeGrantCommittedRead($owner['principal'], $owner['user'], $values['identity'], $values['policy'], $values['rows'], hrtime(true) + 60_000_000_000);
        $fresh->proveBeforeBytes();
        $transfer->close();
    }

    public function test_a_refused_begin_closes_the_snapshot_and_a_later_write_emits_nothing(): void
    {
        [$owner, $authorization, $origin, $seal] = $this->authorized('master_wav');
        $transfer = (new ProductionFreeGrantDownloads)->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']);
        (new ProductionFreeGrants)->revoke($origin['id'], ['originSeal' => $seal, 'reason' => 'Synthetic withdrawal before the header'], $this->staff());
        $this->refuses(fn () => $transfer->begin(), 'outcome_unknown');
        $calls = 0;
        $this->refuses(fn () => $transfer->writeTo(function () use (&$calls): void {
            $calls++;
        }), 'outcome_unknown');
        $this->assertSame(0, $calls);
    }

    public function test_native_first_byte_frame_is_read_only_read_committed_and_never_waits_for_row_locks(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native InnoDB isolation, read-only access mode and row locks held by an independent process.');
        }
        [$owner, $authorization, $origin] = $this->authorized('master_wav');
        $transfer = (new ProductionFreeGrantDownloads)->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']);
        $pdo = DB::connection()->getPdo();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 2');
        try {
            // An independent process holds exclusive locks on the origin row and the revocation gap for this origin.
            $this->resetBarriers();
            $locks = $this->worker('lock-rows', ['origin_id' => $origin['id']]);
            $this->awaitFile('locked');
            $started = hrtime(true);
            $this->assertSame($this->sources->bytes['synthetic-master_wav'], $this->bytes($transfer));
            $elapsed = (hrtime(true) - $started) / 1e9;
            $this->assertLessThan(2.0, $elapsed, 'A locking read would wait for the peer; the committed frame reads without locks.');
            touch($this->raceDirectory.'/release');
            $this->assertSame(['result' => 'released'], array_intersect_key($this->finished($locks), ['result' => true]));

            // An independent process blocks the frame on a table metadata lock and observes the frame's transaction.
            $transferTwo = $this->secondTransfer($owner, $origin);
            $this->resetBarriers();
            $observer = $this->worker('observe-frame', ['connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()]);
            $this->awaitFile('locked');
            $this->assertSame($this->sources->bytes['synthetic-download_mp3'], $this->bytes($transferTwo));
            $seen = $this->finished($observer);
            $this->assertSame('observed', $seen['result'], json_encode($seen));
            $this->assertSame('READ COMMITTED', $seen['isolation']);
            $this->assertSame('READ ONLY', $seen['access_mode']);
            // The session defaults are unchanged afterwards: the frame applied to its own transaction only.
            $this->assertSame('REPEATABLE-READ', $pdo->query('SELECT @@SESSION.transaction_isolation')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT @@SESSION.transaction_read_only')->fetchColumn());
        } finally {
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
    }

    public function test_native_independent_process_revocation_across_the_first_byte(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native independent-process race of staff revocation against redemption and its first byte.');
        }
        $staff = $this->staff();
        // (i) The revocation commits after the redemption commit and before the first byte: zero bytes, retained redemption.
        [$owner, $authorization, $origin, $seal] = $this->authorized('master_wav');
        $this->resetBarriers();
        $redeemer = $this->worker('redeem', ['authorization_id' => $authorization['id'], 'token' => $authorization['token']]);
        $this->awaitFile('redeemed');
        $revoker = $this->worker('revoke', ['origin_id' => $origin['id'], 'seal' => $seal, 'staff_id' => (int) $staff->getKey(), 'hold' => false]);
        $this->assertSame('revoked', $this->finished($revoker)['result']);
        touch($this->raceDirectory.'/proceed');
        $first = $this->finished($redeemer);
        $this->assertSame(['result' => 'refused', 'reason' => 'outcome_unknown', 'bytes' => 0], array_intersect_key($first, array_flip(['result', 'reason', 'bytes'])), json_encode($first));
        $this->assertSame(1, DB::table('production_free_redemptions')->where('authorization_id', $authorization['id'])->count());

        // (ii) The revocation is in flight, held at its own commit, during the first byte: the frame neither waits for
        // it nor sees it, so the bytes go; the revocation then commits and later delivery refuses.
        [$owner, $authorization, $origin, $seal] = $this->authorized('master_wav', 'second@example.test');
        $this->resetBarriers();
        $redeemer = $this->worker('redeem', ['authorization_id' => $authorization['id'], 'token' => $authorization['token'], 'email' => 'second@example.test']);
        $this->awaitFile('redeemed');
        $revoker = $this->worker('revoke', ['origin_id' => $origin['id'], 'seal' => $seal, 'staff_id' => (int) $staff->getKey(), 'hold' => true]);
        $this->awaitFile('holding');
        touch($this->raceDirectory.'/proceed');
        $second = $this->finished($redeemer);
        $this->assertSame('delivered', $second['result'], json_encode($second));
        $this->assertSame(hash('sha256', $this->sources->bytes['synthetic-master_wav']), $second['sha256']);
        touch($this->raceDirectory.'/commit');
        $this->assertSame('revoked', $this->finished($revoker)['result']);
        $this->assertSame(1, DB::table('production_free_revocations')->where('origin_id', $origin['id'])->count());

        // (iii) The revocation holds its locks while the redemption starts: the redemption waits, then sees the committed
        // revocation and records nothing.
        [$owner, $authorization, $origin, $seal] = $this->authorized('master_wav', 'third@example.test');
        $this->resetBarriers();
        $redeemer = $this->worker('redeem', ['authorization_id' => $authorization['id'], 'token' => $authorization['token'], 'email' => 'third@example.test',
            'proceed' => false, 'gate' => true]);
        $this->awaitFile('ready');
        $revoker = $this->worker('revoke', ['origin_id' => $origin['id'], 'seal' => $seal, 'staff_id' => (int) $staff->getKey(), 'hold' => true]);
        $this->awaitFile('holding');
        touch($this->raceDirectory.'/go');
        $this->awaitLockWait();
        touch($this->raceDirectory.'/commit');
        $this->assertSame('revoked', $this->finished($revoker)['result']);
        $third = $this->finished($redeemer);
        $this->assertSame(['result' => 'refused', 'reason' => 'revoked', 'bytes' => 0], array_intersect_key($third, array_flip(['result', 'reason', 'bytes'])), json_encode($third));
        $this->assertSame(0, DB::table('production_free_redemptions')->where('authorization_id', $authorization['id'])->count());
    }

    private string $raceDirectory = '';

    /** Start a new interleaving with no barrier file left from the previous one. */
    private function resetBarriers(): void
    {
        if ($this->raceDirectory === '') {
            $this->raceDirectory = $this->privateRoot.'/race';
            mkdir($this->raceDirectory, 0700);
        }
        foreach (glob($this->raceDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }
    }

    /** @param  array<string, mixed>  $input */
    private function worker(string $mode, array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/production-free-race-worker.php')], base_path(), [
            'VA_PRODUCTION_FREE_RACE_ONLY' => '1', 'VA_PRODUCTION_FREE_RACE_DIRECTORY' => $this->raceDirectory,
        ], json_encode(['mode' => $mode, ...$input, 'app_key' => config('app.key'), 'private_root' => $this->privateRoot,
            'free' => config('production-free-grants'), 'identity' => config('production-customer-identity'),
            'bytes' => $this->sources->bytes], JSON_THROW_ON_ERROR), 120);
        $process->start();

        return $process;
    }

    private function finished(Process $process): array
    {
        $process->wait();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
    }

    private function awaitFile(string $name): void
    {
        $end = hrtime(true) + 60_000_000_000;
        while (! is_file($this->raceDirectory.'/'.$name)) {
            if (hrtime(true) > $end) {
                $this->fail('Barrier '.$name.' timed out.');
            }
            usleep(10000);
            clearstatcache();
        }
    }

    /** Wait until a transaction is actually blocked on a row lock of the origin the revocation holds. */
    private function awaitLockWait(): void
    {
        $end = hrtime(true) + 30_000_000_000;
        do {
            $waits = (int) DB::connection()->getPdo()->query("SELECT COUNT(*) FROM performance_schema.data_lock_waits w
                JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
                WHERE l.OBJECT_SCHEMA = DATABASE() AND l.OBJECT_NAME = 'production_free_origins'")->fetchColumn();
            if ($waits > 0) {
                return;
            }
            usleep(20000);
        } while (hrtime(true) < $end);
        $pdo = DB::connection()->getPdo();
        $this->fail('The redemption never waited for the held revocation locks: '.json_encode([
            'processes' => $pdo->query("SELECT ID, COMMAND, STATE, LEFT(INFO, 160) info FROM information_schema.PROCESSLIST WHERE DB = DATABASE()")->fetchAll(\PDO::FETCH_ASSOC),
            'transactions' => $pdo->query('SELECT trx_mysql_thread_id, trx_state, LEFT(trx_query, 160) query FROM information_schema.INNODB_TRX')->fetchAll(\PDO::FETCH_ASSOC),
            'barriers' => array_map('basename', glob($this->raceDirectory.'/*') ?: []),
        ]));
    }

    private function secondTransfer(array $owner, array $origin): ProductionFreeGrantTransfer
    {
        $seal = (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user'])['originSeal'];
        $authorization = (new ProductionFreeGrantDownloads)->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'download_mp3'], $owner['principal'], $owner['user']);

        return (new ProductionFreeGrantDownloads)->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']);
    }

    /** @return array{0:array,1:array,2:array,3:string} */
    private function authorized(string $role, string $email = 'free-owner@example.test'): array
    {
        $definition = DB::table('production_free_definitions')->exists()
            ? ['id' => (string) DB::table('production_free_definitions')->value('id')] : $this->openDefinition();
        $owner = $this->customer($email);
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
        $origin = $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $seal = (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user'])['originSeal'];
        $authorization = (new ProductionFreeGrantDownloads)->authorize($origin['id'], ['originSeal' => $seal, 'role' => $role], $owner['principal'], $owner['user']);

        return [$owner, $authorization, $origin, $seal];
    }

    private function bytes(ProductionFreeGrantTransfer $transfer): string
    {
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });

        return $bytes;
    }

    private function refuses(callable $operation, string ...$reasons): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.implode(' or ', $reasons));
        } catch (ProductionFreeGrantException $error) {
            $this->assertContains($error->reason, $reasons);
        }
    }
}

/** A callback-capable facade cache: counts offset reads made while it is installed. */
final class FirstByteFacadeSentinel extends ArrayObject
{
    public int $calls = 0;

    public function offsetGet(mixed $key): mixed
    {
        $this->calls++;

        return parent::offsetGet($key);
    }

    public function offsetExists(mixed $key): bool
    {
        $this->calls++;

        return parent::offsetExists($key);
    }
}
