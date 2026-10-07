<?php

namespace Tests\Feature\ProductionIdentityAdapters;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalPlainRows;
use App\Domain\Customers\ProductionIdentity\IdentityOriginalCommitWitness;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IdentityHistoricalCommitFixture;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class IdentityHistoricalCommittedReceiptTest extends TestCase
{
    use ProductionIdentityFixture;

    private ?IdentityHistoricalCommitFixture $frame = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identitySetup();
        config(['production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => IdentityHistoricalCommittedReceipt::VERSION]);
    }

    protected function tearDown(): void
    {
        $this->frame?->restore();
        parent::tearDown();
    }

    public function test_actual_smtp_original_commit_has_two_one_use_plain_prefix_closures_without_new_transactions(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $queries = $beginnings = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->app['events']->listen(TransactionBeginning::class, function () use (&$beginnings): void {
            $beginnings++;
        });
        $frame = $this->capture($owner['binding']);
        $before = [$queries, $beginnings];
        $frame->receipt(0)->proveClosed();
        $frame->receipt(1)->proveClosed();
        $this->assertSame($before, [$queries, $beginnings]);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame(['original_historical_receipt' => true], $frame->receipt(0)->__debugInfo());
        try {
            $frame->receipt(0)->proveClosed();
            $this->fail('A consumed original receipt cannot be renewed.');
        } catch (IdentityException) {
            $this->addToAssertionCount(1);
        }
        foreach ([$frame->receipt(1), $frame->witness()] as $private) {
            try {
                serialize($private);
                $this->fail('Private original commit capabilities cannot be serialized.');
            } catch (IdentityException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_original_prefix_survives_legitimate_recovery_before_capture_without_current_authority_renewal(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $challenge = $this->requestIdentity('recover');
        $this->completeIdentity($challenge, 'RecoveredHistoricalPassword456');
        $frame = $this->capture($owner['binding']);
        $frame->receipt(0)->proveClosed();
        $frame->receipt(1)->proveClosed();
        $this->assertSame(2, DB::table('production_identity_verifications')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public static function terminalWithdrawals(): array
    {
        return [['flag'], ['lazy_secondary'], ['statement_class'], ['database_name'], ['temporary_users']];
    }

    #[DataProvider('terminalWithdrawals')]
    public function test_current_original_closed_prefix_refuses_changed_policy_primary_or_owner_without_callback(string $mode): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $frame = $this->capture($owner['binding']);
        $pdo = DB::connection()->getPdo();
        $databaseName = DB::connection()->getDatabaseName();
        $name = 'identity_historical_secondary';
        $calls = 0;
        try {
            if ($mode === 'flag') {
                config(['production-customer-identity.historical_receipts_enabled' => false]);
            } elseif ($mode === 'lazy_secondary') {
                config(['database.connections.'.$name => config('database.connections.'.DB::getDefaultConnection())]);
                DB::connection($name)->setPdo(function () use ($pdo, &$calls) {
                    $calls++;

                    return $pdo;
                });
            } elseif ($mode === 'statement_class') {
                HistoricalStatementCallback::$calls = 0;
                HistoricalStatementCallback::$primary = $pdo;
                $pdo->exec('CREATE TABLE identity_original_closure_fixture (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
                $pdo->exec('INSERT INTO identity_original_closure_fixture (id,value) VALUES (1,9123)');
                $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [HistoricalStatementCallback::class, []]);
            } elseif ($mode === 'database_name') {
                DB::connection()->setDatabaseName('foreign_identity');
            } else {
                $pdo->exec(DB::getDriverName() === 'sqlite' ? 'CREATE TEMP TABLE users AS SELECT * FROM main.users'
                    : 'CREATE TEMPORARY TABLE users AS SELECT * FROM users');
            }
            try {
                $frame->receipt(0)->proveClosed();
                $this->fail('The original receipt cannot close changed authority or a callback-capable primary.');
            } catch (IdentityException) {
                $this->assertSame(0, $calls);
                if ($mode === 'statement_class') {
                    $this->assertSame(0, HistoricalStatementCallback::$calls);
                }
            }
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
            DB::connection()->setDatabaseName($databaseName);
            DB::purge($name);
            if ($mode === 'statement_class') {
                $this->assertSame(9123, (int) $pdo->query('SELECT value FROM identity_original_closure_fixture WHERE id=1')->fetchColumn());
                $pdo->exec('DROP TABLE identity_original_closure_fixture');
                HistoricalStatementCallback::$primary = null;
            }
            if ($mode === 'temporary_users') {
                $pdo->exec(DB::getDriverName() === 'sqlite' ? 'DROP TABLE temp.users' : 'DROP TEMPORARY TABLE users');
            }
        }
    }

    public function test_default_off_or_changed_deadline_and_third_sibling_cannot_mint_receipts(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        foreach (['off', 'deadline', 'third'] as $mode) {
            config(['production-customer-identity.historical_receipts_enabled' => $mode !== 'off']);
            try {
                DB::transaction(function () use ($owner, $mode): void {
                    $reader = new CurrentRows(DB::connection()->getPdo(), DB::getDriverName());
                    $expected = (new ProductionCustomerAccess)->verifyHistoricalBinding($owner['binding'], $reader);
                    $deadline = hrtime(true) + 30_000_000_000;
                    $witness = IdentityOriginalCommitWitness::capture($reader, $deadline);
                    if ($mode === 'deadline') {
                        IdentityHistoricalCommittedReceipt::capture($owner['binding'], $reader, $expected, $deadline + 1, $witness);
                    } else {
                        for ($i = 0; $i < 3; $i++) {
                            IdentityHistoricalCommittedReceipt::capture($owner['binding'], $reader, $expected, $deadline, $witness);
                        }
                    }
                });
                $this->fail('Disabled, renewed or unbounded sibling captures must refuse.');
            } catch (IdentityException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_postcommit_withdrawal_is_unknown_while_original_writes_remain_committed(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $this->app['events']->listen(TransactionCommitted::class, function (): void {
            config(['production-customer-identity.historical_receipts_enabled' => false]);
        });
        try {
            $this->capture($owner['binding'], fn () => DB::table('users')->where('id', $owner['user']->id)->update(['name' => 'Durably declared name']));
            $this->fail('Postcommit withdrawal must refuse the response without retrying the original write.');
        } catch (IdentityException) {
            $this->assertSame('Durably declared name', DB::table('users')->where('id', $owner['user']->id)->value('name'));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        }
    }

    public function test_direct_commit_reopen_loses_producer_anchor_and_refuses_original_commit(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $pdo = DB::connection()->getPdo();
        $this->app['events']->listen(TransactionCommitting::class, function () use ($pdo): void {
            $pdo->commit();
            $pdo->beginTransaction();
        });
        try {
            $this->capture($owner['binding']);
            $this->fail('A different physical frame cannot inherit the original commit.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf(\PDOException::class, $error);
        }
    }

    public function test_later_framework_transaction_invalidates_both_original_siblings(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $frame = $this->capture($owner['binding']);
        DB::beginTransaction();
        DB::rollBack();
        foreach ([0, 1] as $index) {
            try {
                $frame->receipt($index)->proveClosed();
                $this->fail('A later transaction cannot become the original commit.');
            } catch (IdentityException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public static function interruptedOriginalCommits(): array
    {
        return [['rollback'], ['nested']];
    }

    #[DataProvider('interruptedOriginalCommits')]
    public function test_rollback_or_nested_event_cannot_be_observed_as_original_positive_commit(string $mode): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $calls = 0;
        $this->app['events']->listen(TransactionCommitting::class, function () use ($mode, &$calls): void {
            if (++$calls === 1) {
                if ($mode === 'rollback') {
                    DB::rollBack();
                } else {
                    DB::beginTransaction();
                    DB::commit();
                }
            }
        });
        try {
            $this->capture($owner['binding']);
            $this->fail('An interrupted original commit cannot return identity closure authority.');
        } catch (IdentityException) {
            $this->addToAssertionCount(1);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertNotNull($this->frame);
        foreach ([0, 1] as $index) {
            try {
                $this->frame->receipt($index)->proveClosed();
                $this->fail('An interrupted sibling cannot be renewed after cleanup.');
            } catch (IdentityException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_foreign_sibling_seal_is_refused_after_real_original_commit(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        try {
            $this->capture($owner['binding'], null, true);
            $this->fail('An opaque seal is bound to its own original receipt, not its sibling.');
        } catch (IdentityException) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        }
    }

    public function test_application_pdo_override_is_refused_before_any_statement_callback(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $foreign = new HistoricalApplicationPdo('sqlite::memory:');
        HistoricalApplicationPdo::$calls = 0;
        try {
            DB::transaction(fn () => IdentityOriginalCommitWitness::capture(new CurrentRows($foreign, 'sqlite'), hrtime(true) + 30_000_000_000));
            $this->fail('Application PDO overrides are not fixed raw historical reads.');
        } catch (IdentityException) {
            $this->assertSame(0, HistoricalApplicationPdo::$calls);
            $this->assertSame(1, DB::table('production_identity_origins')->where('public_id', $owner['binding']['origin_id'])->count());
        }
    }

    public function test_native_plain_closed_prefix_does_not_wait_for_or_acquire_mutable_identity_row_locks(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Plain original identity closure without locking requires native MySQL.');
        }
        $owner = $this->enrollThroughLocalSmtp();
        $frame = $this->capture($owner['binding']);
        $primary = DB::connection()->getPdo();
        $settings = DB::connection()->getConfig();
        $external = new PDO('mysql:host='.$settings['host'].';port='.$settings['port'].';dbname='.$settings['database'].';charset=utf8mb4',
            $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $previousTimeout = (int) $primary->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
        try {
            $primary->exec('SET SESSION innodb_lock_wait_timeout=1');
            $external->beginTransaction();
            $statement = $external->prepare('UPDATE users SET name=? WHERE id=?');
            $statement->execute(['Uncommitted synthetic declared name', $owner['user']->id]);
            // A FOR UPDATE identity read would now wait and fail. Plain permanent SELECT must succeed.
            $frame->receipt(0)->proveClosed();
            $this->assertTrue($external->inTransaction());
            $this->assertFalse($primary->inTransaction());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            if ($external->inTransaction()) {
                $external->rollBack();
            }
            $primary->exec('SET SESSION innodb_lock_wait_timeout='.$previousTimeout);
        }
    }

    public function test_plain_reader_has_no_write_api_and_refuses_unbounded_or_foreign_sql(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $frame = $this->capture($owner['binding']);
        $plain = IdentityHistoricalPlainRows::fromWitness($frame->witness());
        $this->assertFalse(method_exists($plain, 'execute'));
        $this->assertFalse(method_exists($plain, 'insert'));
        foreach ([['orders', 'id = ?', [1], 1], ['users', '1 = 1', [1], 1], ['users', 'id = ?', [1], null]] as $query) {
            try {
                $plain->rows(...$query);
                $this->fail('A receipt only admits its fixed bounded identity SELECTs.');
            } catch (IdentityException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function capture(array $binding, ?\Closure $mutation = null, bool $swapSiblingSeals = false): IdentityHistoricalCommitFixture
    {
        return DB::transaction(function () use ($binding, $mutation, $swapSiblingSeals): IdentityHistoricalCommitFixture {
            $reader = new CurrentRows(DB::connection()->getPdo(), DB::getDriverName());
            $expected = (new ProductionCustomerAccess)->verifyHistoricalBinding($binding, $reader);
            $mutation?->__invoke();
            $this->frame = IdentityHistoricalCommitFixture::capture($binding, $reader, $expected, hrtime(true) + 300_000_000_000, 2, $swapSiblingSeals);

            return $this->frame;
        });
    }
}

final class HistoricalApplicationPdo extends PDO
{
    public static int $calls = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        self::$calls++;

        return parent::prepare($query, $options);
    }
}

final class HistoricalStatementCallback extends PDOStatement
{
    public static int $calls = 0;

    public static ?PDO $primary = null;

    protected function __construct()
    {
        self::$calls++;
        self::$primary?->exec('UPDATE identity_original_closure_fixture SET value=value+10 WHERE id=1');
    }
}
