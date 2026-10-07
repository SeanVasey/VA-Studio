<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/** Real enrolled/paid graph; no source authority may outlive its consumer's held transaction. */
class ProductionCheckoutSourceTransactionTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('j', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true]);
        Queue::fake();
    }

    private function paid(): array
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);

        return [$f, ProductionPaidOrderLocatorV1::locate($f['order']['orderId'])];
    }

    private function source(array $f, ProductionPaidOrderLocatorV1 $locator, CurrentRows $reader): ProductionPaidOrderSourceV1
    {
        $identity = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $reader);

        return ProductionPaidOrderSourceV1::lockedRead($locator, $reader, $identity);
    }

    public function test_raw_pdo_transaction_without_framework_transaction_never_mints_source(): void
    {
        [$f, $locator] = $this->paid();
        $pdo = DB::connection()->getPdo();
        $reader = new CurrentRows($pdo, DB::getDriverName());
        $identity = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $reader);
        $pdo->beginTransaction();
        try {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue($pdo->inTransaction());
            ProductionPaidOrderSourceV1::lockedRead($locator, $reader, $identity);
            $this->fail('Raw-only transaction minted source authority.');
        } catch (CheckoutException $error) {
            $this->assertSame('held_transaction', $error->reason);
        } finally {
            $pdo->rollBack();
        }
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_source_proof_after_original_framework_commit_is_refused(): void
    {
        [$f, $locator] = $this->paid();
        $reader = new CurrentRows(DB::connection()->getPdo(), DB::getDriverName());
        $source = DB::transaction(fn () => $this->source($f, $locator, $reader));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        try {
            $source->proveRetainedCurrent($reader);
            $this->fail('Committed source remained held authority.');
        } catch (CheckoutException $error) {
            $this->assertSame('held_transaction', $error->reason);
            $this->assertNull($error->getPrevious());
        }
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
    }

    public function test_expired_source_refusal_does_not_resolve_a_lazy_primary_that_withdraws_buyer_credentials(): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $reader = new CurrentRows($pdo, DB::getDriverName());
        $source = DB::transaction(fn () => $this->source($f, $locator, $reader));
        $f['access']->current($f['buyer']['principal'], $f['buyer']['user']);
        $called = false;
        $connection->setPdo(function () use ($pdo, $f, &$called) {
            $called = true;
            $statement = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $statement->execute(['WITHDRAWN_BY_LATE_PRIMARY_CALLBACK', $f['buyer']['user']->id]);

            return $pdo;
        });
        try {
            try {
                $source->proveRetainedCurrent($reader);
                $this->fail('Expired source renewed authority through a lazy primary.');
            } catch (CheckoutException $error) {
                $this->assertSame('held_transaction', $error->reason);
                $this->assertFalse($called);
            }
        } finally {
            $connection->setPdo($pdo);
        }
        $f['access']->current($f['buyer']['principal'], $f['buyer']['user']);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
    }

    public function test_command_postcommit_refusal_does_not_resolve_a_lazy_primary_or_return_prepared_result(): void
    {
        $f = $this->payable();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $called = false;
        $active = true;
        app('events')->listen(TransactionCommitted::class, function () use ($connection, $pdo, $f, &$called, &$active): void {
            if (! $active) {
                return;
            }
            $active = false;
            $connection->setPdo(function () use ($pdo, $f, &$called) {
                $called = true;
                $statement = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                $statement->execute(['WITHDRAWN_BY_LATE_COMMAND_CALLBACK', $f['buyer']['user']->id]);

                return $pdo;
            });
        });
        try {
            try {
                CommandTransaction::run(static fn (): string => 'PREPARED_RESULT_MUST_NOT_ESCAPE');
                $this->fail('Prepared result escaped through lazy primary.');
            } catch (CheckoutException $error) {
                $this->assertSame('primary_changed', $error->reason);
                $this->assertFalse($called);
            }
        } finally {
            $active = false;
            $connection->setPdo($pdo);
        }
        $f['access']->current($f['buyer']['principal'], $f['buyer']['user']);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['order'], 1);
    }

    public function test_expired_source_refusal_does_not_invoke_an_uncached_connection_resolver(): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $driver = DB::getDriverName();
        $reader = new CurrentRows($pdo, $driver);
        $source = DB::transaction(fn () => $this->source($f, $locator, $reader));
        $called = false;
        $active = true;
        DB::extend($driver, function () use ($connection, $pdo, $f, &$called, &$active) {
            if ($active) {
                $called = true;
                $statement = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                $statement->execute(['WITHDRAWN_BY_CONNECTION_RESOLVER', $f['buyer']['user']->id]);
            }

            return $connection->setPdo($pdo);
        });
        DB::purge();
        try {
            try {
                $source->proveRetainedCurrent($reader);
                $this->fail('Expired source renewed through a connector.');
            } catch (CheckoutException $error) {
                $this->assertSame('held_transaction', $error->reason);
                $this->assertFalse($called);
            }
        } finally {
            $active = false;
            DB::connection();
        }
        $f['access']->current($f['buyer']['principal'], $f['buyer']['user']);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
    }

    public static function interruptedFrames(): array
    {
        return [['direct_commit_reopen'], ['framework_commit_reopen'], ['nested_framework'], ['rollback_across_anchor']];
    }

    #[DataProvider('interruptedFrames')]
    public function test_original_transaction_frame_cannot_be_replaced_or_nested(string $interruption): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $reader = new CurrentRows($pdo, DB::getDriverName());
        $connection->beginTransaction();
        try {
            $pdo->exec('SAVEPOINT consumer_before_source');
            $source = $this->source($f, $locator, $reader);
            $source->proveRetainedCurrent($reader);
            match ($interruption) {
                'direct_commit_reopen' => [$pdo->commit(), $pdo->beginTransaction()],
                'framework_commit_reopen' => [$connection->commit(), $connection->beginTransaction()],
                'nested_framework' => $connection->beginTransaction(),
                'rollback_across_anchor' => $pdo->exec('ROLLBACK TO SAVEPOINT consumer_before_source'),
            };
            // Direct reopen presents the same PDO/driver/Tx1/inTransaction as the original frame.
            $this->assertSame($interruption === 'nested_framework' ? 2 : 1, $connection->transactionLevel());
            $this->assertTrue($pdo->inTransaction());
            try {
                $source->proveRetainedCurrent($reader);
                $this->fail('Interrupted frame retained paid authority.');
            } catch (CheckoutException $error) {
                $this->assertSame('held_transaction', $error->reason);
                $this->assertNull($error->getPrevious());
                $this->assertSame('PRODUCTION_CHECKOUT_UNAVAILABLE', $error->getMessage());
            }
        } finally {
            $connection->rollBack(0);
        }
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_direct_commit_reopen_during_source_decryption_cannot_mint_rebound_source(): void
    {
        [$f, $locator] = $this->paid();
        $pdo = DB::connection()->getPdo();
        $reader = new CurrentRows($pdo, DB::getDriverName());
        $encrypter = Crypt::getFacadeRoot();
        $called = false;
        DB::beginTransaction();
        try {
            $identity = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $reader);
            Crypt::shouldReceive('decryptString')->andReturnUsing(function (string $cipher) use ($encrypter, $pdo, &$called): string {
                if (! $called) {
                    $called = true;
                    $pdo->commit();
                    $pdo->beginTransaction();
                }

                return $encrypter->decryptString($cipher);
            });
            try {
                ProductionPaidOrderSourceV1::lockedRead($locator, $reader, $identity);
                $this->fail('Source mint captured a replacement transaction after decryption.');
            } catch (CheckoutException $error) {
                $this->assertTrue($called);
                $this->assertSame(1, DB::transactionLevel());
                $this->assertTrue($pdo->inTransaction());
                $this->assertSame('held_transaction', $error->reason);
            }
        } finally {
            Crypt::swap($encrypter);
            DB::rollBack();
        }
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_repeated_terminal_proofs_preserve_consumer_writes_after_source_capture(): void
    {
        [$f, $locator] = $this->paid();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE production_paid_consumer_probe (id INTEGER PRIMARY KEY, marker VARCHAR(64) NOT NULL)');
        DB::transaction(function () use ($f, $locator, $pdo): void {
            $reader = new CurrentRows($pdo, DB::getDriverName());
            $source = $this->source($f, $locator, $reader);
            $pdo->exec("INSERT INTO production_paid_consumer_probe (id, marker) VALUES (1, 'retained-consumer-write')");
            $source->proveRetainedCurrent($reader);
            $this->assertSame('retained-consumer-write', $pdo->query('SELECT marker FROM production_paid_consumer_probe WHERE id = 1')->fetchColumn());
            $this->assertSame(4999, $source->line(1)['line_amount_minor']);
            $source->proveRetainedCurrent($reader);
        });
        $this->assertDatabaseHas('production_paid_consumer_probe', ['id' => 1, 'marker' => 'retained-consumer-write']);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->assertDatabaseCount('license_grants', 0);
    }
}
