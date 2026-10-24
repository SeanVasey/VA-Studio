<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingAwardFence;
use App\Domain\Memberships\Production\MembershipCustomerIndex;
use App\Domain\Memberships\Production\MembershipCustomerRead;
use App\Domain\Memberships\Production\MembershipException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;

/**
 * InnoDB lock evidence for the customer index, probed with NOWAIT from an independent session. This is lock-holding
 * and lock-order evidence on one native server, not an independent-process race or a throughput claim. Since C4e the
 * index takes the owner lock and the owner's membership rows, never the global billing award fence (review F3).
 */
class MembershipNativeCustomerIndexTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberActivationFixtures;

    private ?PDO $independent = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('InnoDB row locks are observed with NOWAIT from an independent native MySQL session.');
        }
        $this->memberActivationSetup();
        DB::connection()->getPdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }

    protected function tearDown(): void
    {
        if ($this->independent?->inTransaction()) {
            $this->independent->rollBack();
        }
        $this->independent = null;
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_native_index_holds_owner_and_period_locks_but_not_the_global_fence_then_releases_them(): void
    {
        $fixture = $this->memberActivationFixture();
        $probes = $this->probes($fixture);
        $observed = null;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$observed, $probes): void {
            if ($observed === null) {
                // Inside the index transaction, after every read and its current proof: each owner and membership
                // probe must find a held lock, and the global award fence must be free (C4e, review F3 option a).
                $observed = $this->nowait($probes);
            }
        });
        $index = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertCount(1, $index['periods']);
        $this->assertSame(['owner_account' => 3572, 'owner_user' => 3572, 'fence' => 0, 'period' => 3572, 'redemption' => 3572], $observed);
        // After the commit and the read-only committed proof nothing is retained.
        $this->assertSame(array_fill_keys(array_keys($probes), 0), $this->nowait($probes));
    }

    public function test_native_index_waits_at_a_writer_held_owner_lock_and_retains_no_lock_after_refusal(): void
    {
        $fixture = $this->memberActivationFixture();
        $probes = $this->probes($fixture);
        $writer = $this->independent();
        // An in-flight award, reserve, release, expire or activation of this owner holds the owner's T23 rows first.
        $writer->beginTransaction();
        $statement = $writer->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $statement->execute([$fixture['buyer']['principal']->userId]);
        $statement->fetchAll();
        DB::connection()->getPdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user']);
            $this->fail('The index must wait at the held owner lock.');
        } catch (MembershipException $error) {
            $this->assertContains($error->reason, ['index_refused', 'owner_refused']);
            $this->assertSame(1205, $this->driverCode($error));
        } finally {
            DB::connection()->getPdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
        // Only the owner's user row was held, so the 1205 wait was at the owner lock. After the refusal the writer,
        // still holding it, can take every other owner and membership row the index reads: nothing was retained.
        unset($probes['owner_user']);
        $this->assertSame(array_fill_keys(array_keys($probes), 0), $this->nowait($probes, $writer, false));
        $writer->rollBack();
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_native_index_completes_while_another_customers_writer_holds_the_global_fence(): void
    {
        $fixture = $this->memberActivationFixture();
        $writer = $this->independent();
        // Any customer's in-flight award or reserve holds the fence after its own owner lock. The index of this
        // owner no longer serializes behind it (review F3): with a one-second wait bound it must still complete.
        $writer->beginTransaction();
        $writer->query('SELECT id FROM '.(new BillingAwardFence)->table().' WHERE id = 1 FOR UPDATE')->fetchAll();
        DB::connection()->getPdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $read = MembershipCustomerRead::begin();
            $index = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read);
            $read->proveBeforeBytes();
        } finally {
            DB::connection()->getPdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
        $this->assertCount(1, $index['periods']);
        // The writer held the fence throughout: the test's own session still cannot take it.
        $this->assertSame(['fence' => 3572], $this->nowait(['fence' => ['SELECT id FROM '.(new BillingAwardFence)->table().' WHERE id = ?', 1]],
            DB::connection()->getPdo()));
        $writer->rollBack();
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_native_index_and_reserve_interleave_sequentially_and_pre_bytes_sees_the_change(): void
    {
        $fixture = $this->memberActivationFixture();
        $read = MembershipCustomerRead::begin();
        $first = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read);
        $this->assertCount(1, $first['periods'][0]['reservations']);
        $this->reserveFixture($fixture, 'native-second-reservation');
        try {
            $read->proveBeforeBytes();
            $this->fail('A stale prebuilt body must not be released.');
        } catch (MembershipException $error) {
            $this->assertSame('changed_before_bytes', $error->reason);
        }
        $second = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertCount(2, $second['periods'][0]['reservations']);
        $this->assertSame(['available' => 1, 'reserved' => 2, 'consumed' => 0, 'expired' => 0], $second['periods'][0]['balances']);
    }

    private function probes(array $fixture): array
    {
        return [
            'owner_account' => ['SELECT id FROM customer_accounts WHERE id = ?', $fixture['buyer']['principal']->accountId],
            'owner_user' => ['SELECT id FROM users WHERE id = ?', $fixture['buyer']['principal']->userId],
            'fence' => ['SELECT id FROM '.(new BillingAwardFence)->table().' WHERE id = ?', 1],
            'period' => ['SELECT id FROM production_membership_paid_periods WHERE id = ?', $fixture['reservation']['period_id']],
            'redemption' => ['SELECT id FROM production_membership_redemptions WHERE id = ?', $fixture['reservation']['redemption_id']],
        ];
    }

    /** 3572 = ER_LOCK_NOWAIT (a held lock); 0 = acquired. A probe that acquires is rolled back unless retained. */
    private function nowait(array $probes, ?PDO $session = null, bool $own = true): array
    {
        $session ??= $this->independent();
        $result = [];
        foreach ($probes as $name => [$sql, $id]) {
            if ($own) {
                $session->beginTransaction();
            }
            try {
                $statement = $session->prepare($sql.' FOR UPDATE NOWAIT');
                $statement->execute([$id]);
                $this->assertCount(1, $statement->fetchAll());
                $result[$name] = 0;
            } catch (PDOException $error) {
                $result[$name] = (int) ($error->errorInfo[1] ?? -1);
            } finally {
                if ($own) {
                    $session->rollBack();
                }
            }
        }

        return $result;
    }

    private function driverCode(\Throwable $error): ?int
    {
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException) {
                return (int) ($cause->errorInfo[1] ?? -1);
            }
        }

        return null;
    }

    private function independent(): PDO
    {
        if ($this->independent === null) {
            $config = DB::connection()->getConfig();
            $this->independent = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'],
                $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->independent->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $this->independent;
    }
}
