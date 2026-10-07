<?php

namespace Tests\Feature;

use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\Preferences\Suppression\Models\SuppressionAttempt;
use App\Domain\Customers\Preferences\Suppression\Models\SuppressionConfirmation;
use App\Domain\Customers\Preferences\Suppression\SuppressionDelivery;
use App\Domain\Customers\Preferences\Suppression\SuppressionRequest;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\SuppressionFixtures;
use Tests\TestCase;

class CustomerSuppressionSourceBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    #[DataProvider('receiptPaths')]
    public function test_lazy_primary_is_not_resolved_and_lost_receipt_requires_inspection(bool $inspect, bool $positive): void
    {
        $customer = $this->withdraw();
        $adapter = new SuppressionFixtures;
        $service = new SuppressionDelivery($adapter);
        if ($inspect) {
            $adapter->onSuppress = fn () => null;
            $this->assertSame(['status' => 'unknown'], $service->process($customer['principal'], $customer['user'], 1));
        }
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $after = false;
        $installed = false;
        $callbacks = 0;
        $receipt = function (SuppressionRequest $request) use (&$after, $positive) {
            $after = true;

            return $positive ? SuppressionFixtures::positive($request) : null;
        };
        if ($inspect) {
            $adapter->onInspect = $receipt;
        } else {
            $adapter->onSuppress = $receipt;
        }
        $adapter->onBinding = function () use (&$after, &$installed, &$callbacks, $connection, $primary) {
            if ($after && ! $installed) {
                $installed = true;
                $connection->setPdo(function () use (&$callbacks, $primary) {
                    $callbacks++;
                    config(['customer-suppression.enabled' => false]);

                    return $primary;
                });
            }
        };
        try {
            $this->refuses(fn () => $inspect
                ? $service->reconcile($customer['principal'], $customer['user'], 1)
                : $service->process($customer['principal'], $customer['user'], 1));
            $this->assertTrue($installed);
            $this->assertSame(0, $callbacks);
            $this->assertTrue(config('customer-suppression.enabled'));
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertFalse($primary->inTransaction(), 'Only the anchored original receipt transaction is rolled back.');
            $connection->setPdo($primary);
            $this->assertSame(1, SuppressionAttempt::count());
            $this->assertSame(0, SuppressionConfirmation::count());
            $attempt = SuppressionAttempt::sole()->getAttributes();
            $adapter->onBinding = null;
            $this->assertSame(['status' => 'unknown'], $service->process($customer['principal'], $customer['user'], 1));
            $this->assertSame(1, $adapter->sent, 'A lost positive acknowledgement must never cause another send.');
            $this->assertSame($attempt, SuppressionAttempt::sole()->getAttributes());
            $adapter->onInspect = null;
            $this->assertSame(['status' => 'confirmed'], $service->reconcile($customer['principal'], $customer['user'], 1));
            $this->assertSame(1, $adapter->sent);
            $this->assertSame($inspect ? 2 : 1, $adapter->inspected);
        } finally {
            if ($primary->inTransaction()) {
                $primary->rollBack();
            }
            $connection->setPdo($primary);
        }
    }

    public static function receiptPaths(): array
    {
        return ['send positive' => [false, true], 'send ambiguous' => [false, false], 'inspect positive' => [true, true], 'inspect ambiguous' => [true, false]];
    }

    public function test_same_primary_commit_reopen_refuses_without_rolling_back_the_foreign_transaction(): void
    {
        $customer = $this->withdraw();
        $adapter = new SuppressionFixtures;
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $this->sentinel($primary);
        $after = false;
        $installed = false;
        $adapter->onSuppress = function (SuppressionRequest $request) use (&$after) {
            $after = true;

            return SuppressionFixtures::positive($request);
        };
        $adapter->onBinding = function () use (&$after, &$installed, $primary) {
            if ($after && ! $installed) {
                $installed = true;
                $primary->commit();
                $primary->beginTransaction();
                $primary->exec('INSERT INTO suppression_foreign_boundary (marker) VALUES (1)');
            }
        };
        try {
            $this->refuses(fn () => (new SuppressionDelivery($adapter))->process($customer['principal'], $customer['user'], 1));
            $this->assertTrue($installed);
            $this->assertTrue($primary->inTransaction());
            $this->assertSame(1, (int) $primary->query('SELECT COUNT(*) FROM suppression_foreign_boundary')->fetchColumn());
            $this->assertSame(0, $connection->transactionLevel());
            $primary->rollBack();
            $this->assertSame(0, (int) $primary->query('SELECT COUNT(*) FROM suppression_foreign_boundary')->fetchColumn());
            // The callback committed the real positive receipt. Failure reports no result and
            // does not erase that durable history or pretend the operation was rolled back.
            $this->assertSame(1, SuppressionAttempt::count());
            $this->assertSame(1, SuppressionConfirmation::count());
            $adapter->onBinding = null;
            $this->assertSame(['status' => 'confirmed'], (new SuppressionDelivery($adapter))->reconcile($customer['principal'], $customer['user'], 1));
            $this->assertSame(1, $adapter->sent);
            $this->assertSame(0, $adapter->inspected);
        } finally {
            if ($primary->inTransaction()) {
                $primary->rollBack();
            }
            $connection->setPdo($primary);
            $primary->exec('DROP TABLE suppression_foreign_boundary');
        }
    }

    public function test_replacement_primary_transaction_remains_owned_by_its_caller(): void
    {
        $customer = $this->withdraw();
        $adapter = new SuppressionFixtures;
        $connection = DB::connection();
        $primary = $connection->getPdo();
        config(['database.connections.suppression_foreign' => config('database.connections.'.DB::getDefaultConnection())]);
        $foreign = DB::connection('suppression_foreign')->getPdo();
        $this->sentinel($foreign);
        $foreign->beginTransaction();
        $foreign->exec('INSERT INTO suppression_foreign_boundary (marker) VALUES (1)');
        $after = false;
        $adapter->onSuppress = function (SuppressionRequest $request) use (&$after) {
            $after = true;

            return SuppressionFixtures::positive($request);
        };
        $adapter->onBinding = function () use (&$after, $connection, $foreign) {
            if ($after) {
                $connection->setPdo($foreign);
            }
        };
        try {
            $this->refuses(fn () => (new SuppressionDelivery($adapter))->process($customer['principal'], $customer['user'], 1));
            $this->assertSame($foreign, $connection->getRawPdo());
            $this->assertTrue($foreign->inTransaction());
            $this->assertSame(1, (int) $foreign->query('SELECT COUNT(*) FROM suppression_foreign_boundary')->fetchColumn());
            $this->assertFalse($primary->inTransaction());
            $foreign->rollBack();
            $this->assertSame(0, (int) $foreign->query('SELECT COUNT(*) FROM suppression_foreign_boundary')->fetchColumn());
            $connection->setPdo($primary);
            $this->assertSame(1, SuppressionAttempt::count());
            $this->assertSame(0, SuppressionConfirmation::count());
            $this->assertSame(1, $adapter->sent);
        } finally {
            if ($foreign->inTransaction()) {
                $foreign->rollBack();
            }
            if ($primary->inTransaction()) {
                $primary->rollBack();
            }
            $connection->setPdo($primary);
            $foreign->exec('DROP TABLE suppression_foreign_boundary');
            DB::purge('suppression_foreign');
        }
    }

    public function test_raw_transaction_without_a_framework_owner_is_refused_before_any_attempt(): void
    {
        $customer = $this->withdraw();
        $adapter = new SuppressionFixtures;
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $primary->beginTransaction();
        try {
            $this->refuses(fn () => (new SuppressionDelivery($adapter))->process($customer['principal'], $customer['user'], 1));
            $this->assertTrue($primary->inTransaction());
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertSame(0, SuppressionAttempt::count());
            $this->assertSame(0, $adapter->sent);
        } finally {
            $primary->rollBack();
        }
    }

    private function withdraw(): array
    {
        $customer = CustomerFixtures::account();
        (new CustomerConsentPreferences)->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw());

        return $customer;
    }

    private function sentinel(PDO $pdo): void
    {
        $pdo->exec('CREATE '.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'TEMPORARY ' : '').'TABLE suppression_foreign_boundary (marker INTEGER PRIMARY KEY)');
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Interrupted suppression evidence must not release an outcome.');
        } catch (ConsentException $exception) {
            $this->assertSame(503, $exception->status);
        }
    }
}
