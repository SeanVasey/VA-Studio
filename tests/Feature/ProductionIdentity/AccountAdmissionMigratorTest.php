<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Customers\Preferences\ConsentMigrationAdmission;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\ProductionIdentity\AccountAdmissionGuards;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountMigratorForeground;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

/** Native-only diagnostic preparation. Exact-source review and a serial33089 lease precede execution. */
final class AccountAdmissionMigratorTest extends TestCase
{
    use ProductionIdentityFixture;

    private static bool $invoked = false;

    private array $cleanupFailures = [];

    private ?array $firstFailure = null;

    private string $stage = 'selection';

    private $supervisorChannel = null;

    private bool $registrationUnknown = false;

    private const NAME = '2026_11_01_269000_customer_account_admission_v2';

    private array $children = [];

    private array $events = [];

    private array $reaped = [];

    private array $uncertain = [];

    private array $buffers = [];

    private array $outputBytes = [];

    private ?int $holdDeadline = null;

    private array $ownedThreads = [];

    private array $ownedSessions = [];

    public static function cuts(): array
    {
        return ['up-overlap' => ['up', 'overlap'], 'down-overlap' => ['down', 'overlap'],
            'up-end' => ['up', 'end'], 'down-end' => ['down', 'end']];
    }

    #[DataProvider('cuts')]
    public function test_normal_migrator_process_interruption_and_genuine_ledger_resume(string $direction, string $cut): void
    {
        $this->assertSame($direction.'-'.$cut, $GLOBALS['accountMigratorSelectedCase'] ?? null, 'Use the reviewed one-provider launcher; no direct multi-case invocation.');
        $this->assertFalse(self::$invoked, 'No automatic second fixture after any case outcome.');
        self::$invoked = true;
        $this->openSupervisorChannel();
        $this->journal('selected');
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real Oracle8.4.11 process/DDL/ledger interruption on exclusively owned33089 fixture.');
        }
        $this->assertSame('vaseyaudio_production_identity', DB::getDatabaseName());
        $this->assertSame(false, config('app.debug'));
        $pins = json_decode(file_get_contents(base_path('docs/verification/account-migrator-evidence-20261102/pins.json')), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame($pins['php_binary_sha256'], hash_file('sha256', PHP_BINARY));
        foreach ($pins['sha256'] as $path => $hash) {
            $this->assertSame($hash, hash_file('sha256', base_path($path)), 'Pinned source moved; refresh/review before execution.');
        }
        foreach (['proc_open', 'pcntl_waitpid', 'pcntl_wifstopped', 'pcntl_wstopsig', 'pcntl_wifsignaled', 'pcntl_wtermsig', 'posix_kill'] as $function) {
            $this->assertTrue(function_exists($function) && (new \ReflectionFunction($function))->isInternal(), 'Required native process primitive unavailable.');
        }
        $this->assertSame('8.4.27', PHP_VERSION);
        $this->assertSame('1', ini_get('zend.exception_ignore_args'));
        $this->assertSame(SIG_DFL, \pcntl_signal_get_handler(SIGCHLD));
        $this->assertStringStartsWith('8.4.11', $this->pdo()->query('SELECT VERSION()')->fetchColumn());
        $this->assertStringContainsString('MySQL Community Server', $this->pdo()->query('SELECT @@version_comment')->fetchColumn());
        $this->assertSame(base_path('app/Domain/Customers/ProductionIdentity/AccountAdmissionUpgrade.php'), (new \ReflectionClass(\App\Domain\Customers\ProductionIdentity\AccountAdmissionUpgrade::class))->getFileName());
        $this->assertSame(realpath(base_path('vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php')), (new \ReflectionClass(\Illuminate\Database\Migrations\Migrator::class))->getFileName());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->pdo()->exec('SET SESSION lock_wait_timeout=3,innodb_lock_wait_timeout=3,MAX_EXECUTION_TIME=1000');
        // Parent fixture only. Workers never rebuild/truncate the database or call the coordinator.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
        $this->completeIdentity($this->requestIdentity('enroll', 'migrator-retained@example.test'));
        ConsentFixtures::configure();
        $customer = CustomerFixtures::account(['email' => 'migrator-consent@example.test']);
        app(CustomerConsentPreferences::class)->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
        foreach (['customer_accounts', 'quote_owners', 'audit_events', 'production_identity_verifications', 'customer_consent_events'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), 'Fixture must actually populate '.$table);
        }
        $rows = $this->retained();
        $ledger = $this->otherLedger();
        $this->stable(AccountAdmissionGuards::V2);
        $this->assertCount(1, $this->ledger());
        $this->assertSame(self::NAME, DB::table('migrations')->orderByDesc('batch')->orderByDesc('migration')->value('migration'));
        try {
            if ($direction === 'up') {
                $this->normal('down'); // Real Laravel rollback, never manual ledger DELETE.
                $this->stage = 'post-down-stable-v1';
                $this->stable(AccountAdmissionGuards::V1);
                $this->stage = 'post-down-ledger';
                $this->assertCount(0, $this->ledger());
            }
            $this->stage = 'pre-worker-retained';
            $this->assertSame($rows, $this->retained());
            $this->stage = 'pre-worker-other-ledger';
            $this->assertSame($ledger, $this->otherLedger());
            $this->stage = 'pre-worker-ledger';
            $beforeLedger = $this->ledger();
            $parent = (int) $this->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
            $this->stage = 'pre-worker-clients';
            $this->assertSame([$parent], $this->clientIds(), 'Own fixture must be quiescent except the identified auditor.');
            $this->stage = 'pre-worker-resources';
            $this->assertSame(0, $this->resources($parent, false), 'Unknown non-auditor native resources prevent attribution.');
            $child = $this->start($direction, $cut);
            $ready = $this->message($child, 'ready', 15);
            $this->assertSame($child['pid'], $ready['pid']);
            $this->assertNotSame($parent, $ready['session']);
            $this->assertSame(hash('sha256', $this->pdo()->query('SELECT @@server_uuid')->fetchColumn()), $ready['server']);
            $this->send($child);
            $started = $this->message($child, 'started', 15);
            $this->assertSame($ready['session'], $started['session']);
            if ($cut === 'overlap') {
                // ONE fixed readiness-driven signal schedule. A missed phase ends this case, never retries.
                $this->until(fn (): bool => AccountAdmissionGuards::migrationState($this->pdo(), 'mysql')['version'] === AccountAdmissionGuards::OVERLAP, 10, 'Exact overlap observation missed.');
                $this->assertTrue(\posix_kill($child['pid'], SIGUSR1));
                $status = $this->waitStatus($child, true, 15);
                $this->assertTrue(\pcntl_wifstopped($status));
                $this->assertSame(SIGSTOP, \pcntl_wstopsig($status));
                $marker = $this->message($child, 'signal', 1);
                $this->assertSame(true, $marker['qualified'], 'Missed/truncated/wrong live stack; STOP, no retry.');
                $this->assertSame(1, $marker['seq']);
                $this->assertSame(72, $marker['inspect_line']);
                $this->assertSame($direction === 'up' ? 13 : 18, $marker['migration_line']);
                $this->assertSame($direction, $marker['direction']);
                $this->assertLessThan(96, $marker['frames']);
                $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $marker['trace_sha256']);
                $this->until(fn (): bool => $this->quiescent($parent, $ready['session']), 6, 'Worker/companion native statements did not become quiescent.');
                $this->assertSame(AccountAdmissionGuards::OVERLAP, AccountAdmissionGuards::migrationState($this->pdo(), 'mysql')['version']);
                $this->assertSame($ready['session'], $this->advisoryOwner());
                $this->runtimeRefuses();
            } else {
                $marker = $this->message($child, 'end', 15);
                $this->assertSame($ready['session'], $marker['session']);
                $this->until(fn (): bool => $this->quiescent($parent, $ready['session']), 6, 'END worker is not idle at the real event barrier.');
                $this->stable($direction === 'up' ? AccountAdmissionGuards::V2 : AccountAdmissionGuards::V1);
                $this->assertNull($this->advisoryOwner());
            }
            $this->events[] = ['kind' => 'held-cut', 'direction' => $direction, 'cut' => $cut,
                'version' => array_intersect_key(AccountAdmissionGuards::migrationState($this->pdo(), 'mysql'), array_flip(['version', 'fingerprint'])), 'ledger' => $this->ledger(),
                'retained_hashes' => $this->retained(), 'advisory_owner' => $this->advisoryOwner()];
            $this->assertSame($beforeLedger, $this->ledger());
            $this->assertSame($rows, $this->retained());
            $this->assertSame($ledger, $this->otherLedger());
            $this->holdDeadline = null;
            $this->rememberThreads($parent);
            $this->assertTrue(\posix_kill($child['pid'], SIGKILL));
            $status = $this->waitStatus($child, false, 10);
            $this->assertTrue(\pcntl_wifsignaled($status));
            $this->assertSame(SIGKILL, \pcntl_wtermsig($status));
            $this->close($child);
            $this->released($parent);
            $this->assertSame($beforeLedger, $this->ledger());
            $this->assertSame($rows, $this->retained());
            $this->normal($direction); // NEW uninstrumented artisan process: ordinary log/delete only.
            $this->stable($direction === 'up' ? AccountAdmissionGuards::V2 : AccountAdmissionGuards::V1);
            $this->assertCount($direction === 'up' ? 1 : 0, $this->ledger());
            $this->assertSame($rows, $this->retained());
            $this->assertSame($ledger, $this->otherLedger());
            $this->released($parent);
            $this->events[] = ['direction' => $direction, 'cut' => $cut, 'result' => 'completed',
                'final_version' => array_intersect_key(AccountAdmissionGuards::migrationState($this->pdo(), 'mysql'), array_flip(['version', 'fingerprint'])),
                'final_ledger' => $this->ledger(), 'other_ledger_sha256' => $this->otherLedger(), 'retained_hashes' => $this->retained()];
        } catch (\Throwable $error) {
            $this->firstFailure ??= ['stage' => $this->stage, 'class' => $error::class, 'source_line' => $error->getLine(), 'message_sha256' => hash('sha256', $error->getMessage())];
            $this->firstFailure['feature_trace_site'] ??= $this->featureTraceSite($error);
        } finally {
            $this->holdDeadline = null;
            // No PDO, marker, drain, diagnostic file or assertion may precede the core process attempts.
            try {
                $this->cleanupProcesses();
                if ($this->registrationUnknown) {
                    $this->cleanupFailures[] = ['stage' => 'registration-unknown', 'verified' => false];
                }
            } finally {
                // Diagnostics run only after core process cleanup and never replace the original audit.
                try {
                    $this->events[] = ['kind' => 'post-process-native-counts', 'facts' => $this->nativeAuditFacts()];
                } catch (\Throwable $error) {
                    $this->cleanupFailures[] = ['stage' => 'native-diagnostic', 'class' => $error::class,
                        'feature_trace_site' => $this->featureTraceSite($error), 'verified' => false];
                }
                try {
                    $this->stage = 'final-native-audit';
                    $this->released((int) $this->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
                } catch (\Throwable $error) {
                    $this->cleanupFailures[] = ['stage' => 'native-audit', 'class' => $error::class,
                        'feature_trace_site' => $this->featureTraceSite($error), 'verified' => false];
                } finally {
                    try {
                        $this->journal('final', true);
                        $this->supervisorWrite(['kind' => 'complete']);
                        $this->supervisorRead('complete-ack', null);
                    } catch (\Throwable $error) {
                        $this->cleanupFailures[] = ['stage' => 'final-journal', 'class' => $error::class, 'verified' => false];
                    }
                }
            }
        }
        if ($this->firstFailure !== null || $this->cleanupFailures !== []) {
            $this->fail('Migrator diagnostic STOP; first failure and independent cleanup failures are in private safe journal; unavailable cleanup is UNVERIFIED.');
        }
    }

    private function featureTraceSite(\Throwable $error): ?array
    {
        foreach ($error->getTrace() as $frame) {
            if (($frame['file'] ?? null) === __FILE__ && is_int($frame['line'] ?? null) && is_string($frame['function'] ?? null)) {
                return ['file' => 'tests/Feature/ProductionIdentity/AccountAdmissionMigratorTest.php',
                    'line' => $frame['line'], 'function' => $frame['function']];
            }
        }

        return null; // Explicitly unavailable; never copy exception arguments or messages.
    }

    private function nativeAuditFacts(): array
    {
        $pdo = $this->pdo();
        $parent = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $foreground = $this->foreground();
        $clients = array_map('intval', array_column($foreground['clients'], 'PROCESSLIST_ID'));
        $statement = $pdo->prepare('SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID=?');
        $statement->execute([$parent]);
        $auditorThread = $statement->fetchColumn();
        if ($auditorThread === false) { throw new LogicException('Diagnostic auditor identity unavailable.'); }
        $rawRead = DB::connection()->getRawReadPdo(); // Never resolve a read closure or create a reader.
        $readState = $rawRead === null ? 'null' : ($rawRead instanceof \Closure ? 'closure' : ($rawRead instanceof PDO ? 'pdo' : 'unexpected'));
        if ($readState === 'unexpected') { throw new LogicException('Diagnostic raw reader type unavailable.'); }
        $facts = ['foreground_count' => count($clients) + count($foreground['internal']),
            'recognized_internal_count' => count($foreground['internal']), 'client_count' => count($clients), 'auditor_present' => in_array($parent, $clients, true),
            'non_auditor_count' => count(array_filter($clients, static fn (int $id): bool => $id !== $parent)),
            'advisory_present' => $this->advisoryOwner() !== null, 'raw_read_state' => $readState,
            'raw_read_is_writer' => $rawRead === $pdo,
            'wait_count' => (int) $pdo->query('SELECT COUNT(*) FROM performance_schema.data_lock_waits')->fetchColumn()];
        foreach ([['transactions', 'information_schema.innodb_trx', 'trx_mysql_thread_id', $parent],
            ['data_locks', 'performance_schema.data_locks', 'THREAD_ID', (int) $auditorThread],
            ['metadata_locks', 'performance_schema.metadata_locks', 'OWNER_THREAD_ID', (int) $auditorThread]] as [$key, $table, $column, $id]) {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE '.$column.'<>? OR '.$column.' IS NULL');
            $statement->execute([$id]);
            $facts[$key.'_non_auditor_count'] = (int) $statement->fetchColumn();
            $facts[$key.'_orphan_count'] = (int) $pdo->query('SELECT COUNT(*) FROM '.$table.' WHERE '.$column.' IS NULL')->fetchColumn();
        }

        return $facts;
    }

    private function pdo(): PDO
    {
        return DB::connection()->getPdo();
    }

    private function ledger(): array
    {
        return DB::table('migrations')->where('migration', self::NAME)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function otherLedger(): string
    {
        return hash('sha256', serialize(DB::table('migrations')->where('migration', '<>', self::NAME)->orderBy('id')->get()->all()));
    }

    private function retained(): array
    {
        $snapshot = [];
        foreach (DB::connection()->getSchemaBuilder()->getTableListing(schemaQualified: false) as $table) {
            $this->checkHoldDeadline();
            if ($table === 'migrations') {
                continue;
            }
            $rows = $this->pdo()->query('SELECT * FROM `'.str_replace('`', '``', $table).'`')->fetchAll(PDO::FETCH_ASSOC);
            $bytes = array_map(static function (array $row): string { ksort($row); return serialize($row); }, $rows);
            sort($bytes, SORT_STRING);
            $snapshot[$table] = ['count' => count($bytes), 'sha256' => hash('sha256', serialize($bytes))];
        }
        ksort($snapshot);
        return $snapshot; // No raw rows, credentials or ciphertext in public marker/exception output.
    }

    private function stable(string $version): void
    {
        $this->assertSame($version, AccountAdmissionGuards::stable($this->pdo(), 'mysql')['version']);
        [$present] = (new IdentityMigrationOwnership)->inspect($this->pdo(), 'mysql');
        $this->assertNotContains(false, $present);
        (new ConsentMigrationAdmission($this->pdo(), 'mysql', DB::getDatabaseName()))->dependencies();
    }

    private function runtimeRefuses(): void
    {
        foreach ([fn () => AccountAdmissionGuards::stable($this->pdo(), 'mysql'),
            fn () => (new IdentityMigrationOwnership)->inspect($this->pdo(), 'mysql'),
            fn () => (new ConsentMigrationAdmission($this->pdo(), 'mysql', DB::getDatabaseName()))->dependencies()] as $read) {
            try { $read(); $this->fail('Runtime admitted overlap.'); } catch (LogicException) { $this->assertTrue(true); }
        }
    }

    private function start(string $direction, string $mode): array
    {
        $nonce = bin2hex(random_bytes(16));
        $this->pauseSupervisorChannel(); // No duplicate of the root endpoint enters this child.
        $process = \proc_open([PHP_BINARY, base_path('tests/Support/account-admission-migrator-worker.php'), $direction, $mode, $nonce],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'w'], 4 => ['pipe', 'r'], 7 => ['file', '/dev/null', 'r+']], $pipes, base_path(), null, ['bypass_shell' => true]);
        $child = $this->register($process, $pipes, $nonce);
        $this->nonblocking($child, [0, 1, 2, 3, 4]);
        $this->holdDeadline = \hrtime(true) + 120_000_000_000;
        return $child;
    }

    private function send(array $child): void
    {
        $this->assertSame(1, \fwrite($child['pipes'][4], 'G'));
    }

    private function message(array $child, string $kind, int $seconds): array
    {
        $this->stage = 'protocol-'.$kind;
        $data = $this->buffers[$child['pid']] ?? '';
        $deadline = \hrtime(true) + $seconds * 1_000_000_000;
        do {
            $this->checkHoldDeadline();
            $this->drain($child);
            $part = \fread($child['pipes'][3], 481);
            $this->assertNotFalse($part);
            $data .= $part;
            $this->buffers[$child['pid']] = $data;
            $this->events[] = ['kind' => 'protocol-partial', 'pid' => $child['pid'], 'expected_stage' => $kind, 'bytes' => strlen($data), 'sha256' => hash('sha256', $data)];
            $this->journal('protocol-'.$kind);
            $this->assertLessThanOrEqual(1440, strlen($data), 'Unbounded/duplicated private protocol.');
            $newline = strpos($data, "\n");
            if ($newline !== false) {
                $line = substr($data, 0, $newline + 1);
                $this->assertLessThanOrEqual(480, strlen($line));
                $this->buffers[$child['pid']] = substr($data, $newline + 1);
                $value = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                $this->assertSame($kind, $value['kind']);
                $this->assertSame($child['nonce'], $value['nonce']);
                $this->assertSame($child['pid'], $value['pid']);
                $this->events[] = $value;
                return $value;
            }
            \usleep(10000);
        } while (\hrtime(true) < $deadline);
        $this->fail('Missing/incomplete private marker; no accepted cut.');
    }

    private function waitStatus(array $child, bool $stop, int $seconds): int
    {
        $deadline = \hrtime(true) + $seconds * 1_000_000_000;
        do {
            $this->checkHoldDeadline();
            $this->drain($child); // Operational only; core cleanup never drains.
            $status = 0;
            $pid = \pcntl_waitpid($child['pid'], $status, WNOHANG | ($stop ? WUNTRACED : 0));
            if ($pid === $child['pid']) {
                if (! \pcntl_wifstopped($status)) {
                    $this->reaped[$child['pid']] = true;
                    $this->terminalReceipt($child, $status);
                }
                $this->events[] = ['kind' => 'actual-process-status', 'pid' => $child['pid'], 'raw' => $status, 'stopped' => \pcntl_wifstopped($status)];
                $this->journal('process-status');
                return $status;
            }
            if ($pid === -1) { $this->uncertain[$child['pid']] = true; }
            $this->assertNotSame(-1, $pid, 'Owned waitpid failed; never invent process death.');
            \usleep(10000);
        } while (\hrtime(true) < $deadline);
        $this->fail('Owned child status deadline exceeded.');
    }

    private function drain(array $child): void
    {
        // Discard console text privately; never print arbitrary exception chains/credentials.
        foreach ([1, 2] as $fd) {
            if (! isset($child['pipes'][$fd]) || ! is_resource($child['pipes'][$fd])) { continue; }
            $part = \fread($child['pipes'][$fd], 8192);
            if ($part === false) {
                $this->events[] = ['kind' => 'console-unavailable', 'pid' => $child['pid'], 'fd' => $fd];
                throw new LogicException('Console read unavailable.');
            }
            if ($part !== '') {
                $this->events[] = ['kind' => 'console-part', 'pid' => $child['pid'], 'fd' => $fd, 'bytes' => strlen($part), 'sha256' => hash('sha256', $part)];
            }
            $this->outputBytes[$child['pid']] = ($this->outputBytes[$child['pid']] ?? 0) + strlen($part);
            $this->assertLessThanOrEqual(65536, $this->outputBytes[$child['pid']], 'Unexpected excessive console output; stop.');
        }
    }

    private function close(array $child): void
    {
        if (! isset($this->reaped[$child['pid']])) { throw new LogicException('Unreaped resource cannot be closed with blocking proc_close.'); }
        foreach ($child['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        if (is_resource($child['process'])) { \proc_close($child['process']); }
        foreach ($this->children as &$owned) { if ($owned['pid'] === $child['pid']) { $owned['closed'] = true; } }
    }

    private function register($process, array $pipes, string $nonce): array
    {
        if (! is_resource($process)) { throw new LogicException('Process spawn failed.'); }
        $index = count($this->children);
        $this->children[] = ['process' => $process, 'pipes' => $pipes, 'pid' => 0, 'nonce' => $nonce, 'closed' => false, 'token' => bin2hex(random_bytes(16)), 'supervised' => false, 'terminal_sent' => false];
        $this->registrationUnknown = true;
        $this->openSupervisorChannel(); // Resource already registered before this fallible reopen.
        $status = \proc_get_status($process);
        $pid = $status['pid'];
        $this->children[$index]['pid'] = $pid; // Owned in memory BEFORE all subsequent fallible work/assertions.
        unset($this->reaped[$pid], $this->uncertain[$pid], $this->buffers[$pid], $this->outputBytes[$pid]);
        $this->children[$index]['birth'] = $this->birth($pid);
        if (! $status['running']) { $this->uncertain[$pid] = true; throw new LogicException('Child ended before verifiable ownership registration.'); }
        // No waitpid/proc_close/proc_get_status/reap between RESERVE and final ACK.
        try {
            $token = $this->children[$index]['token'];
            $this->supervisorWrite(['kind' => 'reserve', 'token' => $token, 'pid' => $pid]);
            $challenge = $this->supervisorRead('challenge', $token);
            if (! is_string($challenge['challenge'] ?? null) || ! preg_match('/^[a-f0-9]{32}$/D', $challenge['challenge'])
                || $this->birth($pid) !== $this->children[$index]['birth']) { throw new LogicException('Reserved direct child changed before pidfd commit.'); }
            $this->supervisorWrite(['kind' => 'commit', 'token' => $token, 'challenge' => $challenge['challenge']]);
            $this->supervisorRead('ack', $token);
            $this->children[$index]['supervised'] = true;
            $this->registrationUnknown = false;
        } catch (\Throwable $error) {
            try { $this->supervisorWrite(['kind' => 'unknown']); } catch (\Throwable) {}
            throw $error; // Parent still owns cleanup; outer must retain UNKNOWN/leasefalse.
        }
        $this->journal('registered-child');
        return $this->children[$index];
    }

    private function nonblocking(array $child, array $fds): void
    {
        foreach ($fds as $fd) {
            if (! isset($child['pipes'][$fd]) || ! is_resource($child['pipes'][$fd]) || ! \stream_set_blocking($child['pipes'][$fd], false)) {
                throw new LogicException('Nonblocking pipe setup failed.');
            }
        }
    }

    private function birth(int $pid): array
    {
        $stat = file_get_contents('/proc/'.$pid.'/stat');
        if ($stat === false || ($end = strrpos($stat, ')')) === false) { throw new LogicException('Actual process identity unavailable.'); }
        $fields = preg_split('/\s+/', trim(substr($stat, $end + 1)));
        $exe = readlink('/proc/'.$pid.'/exe');
        if ($exe === false || ! hash_equals(hash_file('sha256', PHP_BINARY), hash_file('sha256', $exe))) {
            throw new LogicException('Registered process is not the actual pinned PHP executable.');
        }
        return ['pid' => $pid, 'start_ticks' => $fields[19] ?? null, 'namespace' => readlink('/proc/'.$pid.'/ns/pid'), 'parent' => (int) ($fields[1] ?? 0)];
    }

    private function cleanupOne(array $child): void
    {
        if ($child['closed']) { return; }
        try {
            $pid = $child['pid'];
            if ($pid < 1 || isset($this->uncertain[$pid])) { throw new LogicException('Uncertain PID/reaper; never signal possible reused process.'); }
            if (! isset($this->reaped[$pid])) {
                $status = 0;
                $wait = \pcntl_waitpid($pid, $status, WNOHANG);
                if ($wait === -1) { $this->uncertain[$pid] = true; throw new LogicException('Owned reap unavailable.'); }
                if ($wait === $pid) { $this->reaped[$pid] = true; $this->terminalReceipt($child, $status); }
                else {
                    if ($this->birth($pid) !== $child['birth']) { $this->uncertain[$pid] = true; throw new LogicException('Process birth changed.'); }
                    $sent = \posix_kill($pid, SIGKILL);
                    $this->events[] = ['kind' => 'cleanup-kill', 'pid' => $pid, 'sent' => $sent];
                    $deadline = \hrtime(true) + 10_000_000_000;
                    do {
                        $wait = \pcntl_waitpid($pid, $status, WNOHANG);
                        if ($wait === $pid) { $this->reaped[$pid] = true; $this->terminalReceipt($child, $status); break; }
                        if ($wait === -1) { $this->uncertain[$pid] = true; break; }
                        \usleep(10000);
                    } while (\hrtime(true) < $deadline);
                }
                $this->events[] = ['kind' => 'cleanup-reap', 'pid' => $pid, 'verified' => isset($this->reaped[$pid]), 'raw_status' => $status];
            }
            if (! isset($this->reaped[$pid])) { throw new LogicException('Reap deadline/unavailable; cleanup UNVERIFIED.'); }
            $this->close($child);
        } catch (\Throwable $error) {
            $this->cleanupFailures[] = ['stage' => 'process-cleanup', 'pid' => $child['pid'], 'class' => $error::class, 'verified' => false];
        }
    }

    private function cleanupProcesses(): void
    {
        foreach ($this->children as $child) {
            try { $this->cleanupOne($child); } catch (\Throwable $error) {
                $this->cleanupFailures[] = ['stage' => 'independent-process-cleanup', 'class' => $error::class, 'verified' => false];
            }
        }
    }

    private function journal(string $stage, bool $final = false): void
    {
        $directory = $GLOBALS['accountMigratorEvidenceDirectory'] ?? '';
        if (! is_string($directory) || ! is_dir($directory) || is_link($directory) || (fileperms($directory) & 0777) !== 0700
            || fileowner($directory) !== posix_geteuid()) { throw new LogicException('Reviewed private launcher directory required.'); }
        $children = [];
        foreach ($this->children as $child) { $children[] = ['pid' => $child['pid'], 'token' => $child['token'], 'supervised' => $child['supervised'], 'terminal_sent' => $child['terminal_sent'], 'birth' => $child['birth'] ?? null, 'closed' => $child['closed'], 'reaped' => isset($this->reaped[$child['pid']]), 'uncertain' => isset($this->uncertain[$child['pid']])]; }
        $value = ['stage' => $stage, 'final' => $final, 'selected_case' => $GLOBALS['accountMigratorSelectedCase'] ?? null,
            'actual_php_pid' => \getmypid(), 'supervisor_nonce' => $GLOBALS['accountMigratorSupervisorNonce'] ?? null, 'children' => $children, 'first_failure' => $this->firstFailure,
            'registration_unknown' => $this->registrationUnknown, 'cleanup_failures' => $this->cleanupFailures, 'events' => $this->events,
            'cleanup_verified' => $final && $this->cleanupFailures === [], 'lease_returnable' => $final && $this->cleanupFailures === []];
        $bytes = json_encode($value, JSON_THROW_ON_ERROR); $tmp = $directory.'/case.json.tmp';
        $file = fopen($tmp, 'wb');
        if ($file === false) { throw new LogicException('Private journal unavailable.'); }
        try {
            if (fwrite($file, $bytes) !== strlen($bytes) || ! fflush($file) || ! fsync($file)) { throw new LogicException('Private journal incomplete.'); }
        } finally { fclose($file); }
        chmod($tmp, 0600);
        if (! rename($tmp, $directory.'/case.json')) { throw new LogicException('Private journal commit failed.'); }
    }

    private function normal(string $direction): void
    {
        $this->stage = 'normal-uninstrumented-'.$direction;
        $argv = [PHP_BINARY, base_path('artisan'), $direction === 'up' ? 'migrate' : 'migrate:rollback', '--path=database/migrations/'.self::NAME.'.php', '--force', '--no-interaction'];
        if ($direction === 'down') { $argv[] = '--step=1'; }
        $this->pauseSupervisorChannel(); // No duplicate of the root endpoint enters this child.
        $process = \proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 7 => ['file', '/dev/null', 'r+']], $pipes, base_path(), null, ['bypass_shell' => true]);
        $child = $this->register($process, $pipes, 'normal');
        try {
            $this->nonblocking($child, [0, 1, 2]);
            $status = $this->waitStatus($child, false, 120);
            $this->assertTrue(\pcntl_wifexited($status));
            $this->assertSame(0, \pcntl_wexitstatus($status), 'Actual uninstrumented artisan command failed.');
            $this->events[] = ['kind' => 'normal-command-exit', 'direction' => $direction, 'exit' => 0,
                'observables' => 'actual process exit plus subsequent whole guard state and ordinary ledger transition; no MigrationEnded event receipt'];
        } finally {
            $this->cleanupOne($child); // No DB, output drain or marker assertion in this path.
        }
        if ($this->cleanupFailures !== []) { throw new LogicException('Normal process cleanup UNVERIFIED; stop before another operation.'); }
    }

    private function openSupervisorChannel(): void
    {
        $this->supervisorChannel = fopen('php://fd/7', 'r+');
        if ($this->supervisorChannel === false || ! stream_set_blocking($this->supervisorChannel, false)) {
            throw new LogicException('Reviewed private supervisor channel unavailable.');
        }
        $metadata = stream_get_meta_data($this->supervisorChannel);
        $stat = fstat($this->supervisorChannel);
        if (($metadata['wrapper_type'] ?? null) !== 'PHP'
            || ($metadata['stream_type'] ?? null) !== 'tcp_socket'
            || ($metadata['uri'] ?? null) !== 'php://fd/7'
            || ($metadata['mode'] ?? null) !== 'r+'
            || ($metadata['seekable'] ?? null) !== false
            || ($metadata['blocked'] ?? null) !== false
            || $stat === false || ($stat['mode'] & 0170000) !== 0140000) {
            throw new LogicException('Reviewed private supervisor channel unavailable.');
        }
        // Pinned PHP's raw socket writes directly; its buffering option may be unsupported.
        if (! in_array(stream_set_write_buffer($this->supervisorChannel, 0), [0, -1], true)) {
            throw new LogicException('Reviewed private supervisor channel unavailable.');
        }
    }

    private function pauseSupervisorChannel(): void
    {
        // Close php://fd's duplicate BEFORE proc_open. Child descriptor7 is explicitly /dev/null.
        // Linux SCM_CREDENTIALS additionally rejects every sender except the unreaped root PID.
        if (! is_resource($this->supervisorChannel) || ! fclose($this->supervisorChannel)) {
            throw new LogicException('Root channel duplicate could not be closed before spawn.');
        }
        $this->supervisorChannel = null;
    }

    private function supervisorWrite(array $value): void
    {
        $value['nonce'] = $GLOBALS['accountMigratorSupervisorNonce'] ?? null;
        $bytes = json_encode($value, JSON_THROW_ON_ERROR);
        if (strlen($bytes) > 2048 || fwrite($this->supervisorChannel, $bytes) !== strlen($bytes)) {
            throw new LogicException('Nonblocking supervisor datagram unavailable.');
        }
    }

    private function supervisorRead(string $kind, ?string $token): array
    {
        $deadline = hrtime(true) + 2_000_000_000;
        do {
            $read = [$this->supervisorChannel]; $write = []; $except = [];
            $ready = stream_select($read, $write, $except, 0, 10000);
            if ($ready === false) { throw new LogicException('Supervisor selector unavailable.'); }
            if ($ready > 0) {
                $bytes = fread($this->supervisorChannel, 2049);
                if ($bytes === false || strlen($bytes) > 2048) { throw new LogicException('Supervisor framing unavailable.'); }
                $value = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
                if (($value['kind'] ?? null) !== $kind || ($value['nonce'] ?? null) !== ($GLOBALS['accountMigratorSupervisorNonce'] ?? null)
                    || ($value['token'] ?? null) !== $token) { throw new LogicException('Supervisor response mismatch.'); }
                return $value;
            }
        } while (hrtime(true) < $deadline);
        throw new LogicException('Supervisor handshake deadline; registration UNKNOWN.');
    }

    private function terminalReceipt(array $child, int $status): void
    {
        foreach ($this->children as &$owned) {
            if ($owned['token'] !== $child['token'] || $owned['terminal_sent'] || ! $owned['supervised']) { continue; }
            $this->supervisorWrite(['kind' => 'terminal', 'token' => $owned['token'], 'status' => $status]);
            $this->supervisorRead('terminal-ack', $owned['token']);
            $owned['terminal_sent'] = true;
        }
    }

    private function clientIds(): array
    {
        return array_map('intval', array_column($this->foreground()['clients'], 'PROCESSLIST_ID'));
    }

    private function foreground(): array
    {
        $rows = $this->pdo()->query("SELECT t.THREAD_ID,t.NAME,t.TYPE,t.PROCESSLIST_ID,t.PROCESSLIST_COMMAND,t.PROCESSLIST_DB,(t.PROCESSLIST_USER IS NULL) AS USER_IS_NULL,t.INSTRUMENTED,e.EVENT_ID,e.END_EVENT_ID FROM performance_schema.threads t LEFT JOIN performance_schema.events_statements_current e ON e.THREAD_ID=t.THREAD_ID WHERE t.TYPE='FOREGROUND' AND t.PROCESSLIST_ID IS NOT NULL ORDER BY t.PROCESSLIST_ID")->fetchAll(PDO::FETCH_ASSOC);
        $classified = AccountMigratorForeground::classify($rows);
        // Keep the recognized nonclient explicit in private evidence; never attribute it to a worker.
        if ($classified['internal'] !== []) {
            $event = ['kind' => 'recognized-server-internal', 'threads' => $classified['internal']];
            if (! in_array($event, $this->events, true)) { $this->events[] = $event; }
        }

        return $classified;
    }

    private function advisoryOwner(): ?int
    {
        $s = $this->pdo()->prepare('SELECT IS_USED_LOCK(?)');
        $s->execute(['account-guard-v2:'.substr(hash('sha256', DB::getDatabaseName()), 0, 40)]);
        $value = $s->fetchColumn();
        return $value === null || $value === false ? null : (int) $value;
    }

    private function quiescent(int $parent, int $primary): bool
    {
        $this->checkHoldDeadline();
        $enabled = $this->pdo()->query("SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME='events_statements_current'")->fetchColumn();
        $this->assertSame('YES', $enabled, 'Native current-statement evidence unavailable.');
        $instruments = $this->pdo()->query("SELECT NAME FROM performance_schema.setup_instruments WHERE NAME IN ('statement/sql/select','statement/sql/create_trigger','statement/sql/drop_trigger') AND ENABLED='YES'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(3, $instruments, 'Required native DDL/SELECT instruments unavailable.');
        $rows = array_values(array_filter($this->foreground()['clients'], static fn (array $row): bool => (int) $row['PROCESSLIST_ID'] !== $parent));
        if ($rows === [] || ! in_array($primary, array_map('intval', array_column($rows, 'PROCESSLIST_ID')), true)) { return false; }
        foreach ($rows as $row) {
            if ($row['PROCESSLIST_COMMAND'] !== 'Sleep' || $row['PROCESSLIST_DB'] !== DB::getDatabaseName() || $row['INSTRUMENTED'] !== 'YES'
                || ($row['EVENT_ID'] !== null && $row['END_EVENT_ID'] === null)) { return false; }
        }
        $resources = $this->resources($parent, true);
        if ($resources !== 0) { return false; }
        $this->ownedThreads = array_values(array_unique([...$this->ownedThreads, ...array_map('intval', array_column($rows, 'THREAD_ID'))]));
        $this->ownedSessions = array_values(array_unique([...$this->ownedSessions, ...array_map('intval', array_column($rows, 'PROCESSLIST_ID'))]));
        $this->events[] = ['kind' => 'native-quiescent', 'expected_primary' => $primary, 'sessions' => $rows, 'pending_or_data_resources' => $resources];
        return true;
    }

    private function resources(int $parent, bool $pendingOnly): int
    {
        // Count ALL wait rows, including NULL/orphan attribution: exclusive-server boundary.
        $count = (int) $this->pdo()->query('SELECT COUNT(*) FROM performance_schema.data_lock_waits')->fetchColumn();
        if (! $pendingOnly) {
            $statement = $this->pdo()->prepare('SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID=?');
            $statement->execute([$parent]); $auditorThread = $statement->fetchColumn();
            $this->assertNotFalse($auditorThread, 'Auditor native identity disappeared.');
            // Whole exclusively-owned server audit catches orphan resources even after worker thread rows vanish.
            foreach ([['information_schema.innodb_trx', 'trx_mysql_thread_id', $parent],
                ['performance_schema.data_locks', 'THREAD_ID', (int) $auditorThread],
                ['performance_schema.metadata_locks', 'OWNER_THREAD_ID', (int) $auditorThread]] as [$table, $column, $id]) {
                $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM '.$table.' WHERE '.$column.'<>? OR '.$column.' IS NULL');
                $statement->execute([$id]); $count += (int) $statement->fetchColumn();
            }
            return $count;
        }
        $queries = ["SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id<>? OR trx_mysql_thread_id IS NULL",
            "SELECT COUNT(*) FROM performance_schema.data_locks d LEFT JOIN performance_schema.threads t ON t.THREAD_ID=d.THREAD_ID WHERE t.PROCESSLIST_ID<>? OR t.PROCESSLIST_ID IS NULL",
            "SELECT COUNT(*) FROM performance_schema.metadata_locks m LEFT JOIN performance_schema.threads t ON t.THREAD_ID=m.OWNER_THREAD_ID WHERE (t.PROCESSLIST_ID<>? OR t.PROCESSLIST_ID IS NULL)".($pendingOnly ? " AND m.LOCK_STATUS='PENDING'" : '')];
        foreach ($queries as $sql) { $statement = $this->pdo()->prepare($sql); $statement->execute([$parent]); $count += (int) $statement->fetchColumn(); }
        return $count;
    }

    private function rememberThreads(int $parent): void
    {
        foreach ($this->foreground()['clients'] as $row) {
            if ((int) $row['PROCESSLIST_ID'] === $parent) { continue; }
            $this->ownedThreads[] = (int) $row['THREAD_ID']; $this->ownedSessions[] = (int) $row['PROCESSLIST_ID'];
        }
        $this->ownedThreads = array_values(array_unique($this->ownedThreads));
        $this->ownedSessions = array_values(array_unique($this->ownedSessions));
    }

    private function checkHoldDeadline(): void
    {
        if ($this->holdDeadline !== null && \hrtime(true) > $this->holdDeadline) {
            $this->fail('Fixed120s owned-worker hold watchdog expired; no accepted cut.');
        }
    }

    private function released(int $parent): void
    {
        $this->until(fn (): bool => $this->clientIds() === [$parent] && $this->advisoryOwner() === null && $this->resources($parent, false) === 0, 10, 'Owned child/companion/advisory/MDL/data release unverified.');
        $this->events[] = ['kind' => 'release-verified', 'remaining_clients' => $this->clientIds(), 'auditor' => $parent, 'advisory_owner' => null, 'resources' => 0];
    }

    private function until(callable $predicate, int $seconds, string $failure): void
    {
        $deadline = \hrtime(true) + $seconds * 1_000_000_000;
        do { $this->checkHoldDeadline(); if ($predicate()) { return; } \usleep(10000); } while (\hrtime(true) < $deadline);
        $this->fail($failure);
    }
}
