<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderConsumerCommitAdmissionV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/** Genuine mailbox/paid source with original commit callbacks and frozen closed-read admission. */
class ProductionCheckoutCommittedReceiptTest extends TestCase
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
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => IdentityHistoricalCommittedReceipt::VERSION,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'production_checkout.committed_read_receipts_enabled' => true]);
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

    public function test_two_one_use_siblings_close_same_original_commit_and_preserve_callback_consumer_writes(): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $dispatcher = $connection->getEventDispatcher();
        $pdo->exec('CREATE TABLE pco_receipt_consumer_probe (id INTEGER PRIMARY KEY, marker INTEGER NOT NULL)');
        $deadline = hrtime(true) + 60_000_000_000;
        [$source, $reader, $first, $second] = $connection->transaction(function () use ($f, $locator, $pdo, $dispatcher, $deadline): array {
            $reader = new CurrentRows($pdo, DB::getDriverName());
            $source = $this->source($f, $locator, $reader);
            $first = $source->committedReadReceipt($reader, $deadline);
            $second = $source->committedReadReceipt($reader, $deadline);
            $written = false;
            // Registered after mint: the decorator must still delegate this before its final source proof.
            $dispatcher->listen(TransactionCommitting::class, function () use ($pdo, &$written): void {
                if (! $written) {
                    $written = true;
                    $pdo->exec('INSERT INTO pco_receipt_consumer_probe VALUES (1, 9123)');
                }
            });
            $source->proveRetainedCurrent($reader);

            return [$source, $reader, $first, $second];
        });
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, $connection->transactionLevel());
        $first->proveClosed();
        try {
            $first->proveClosed();
            $this->fail('Consumed receipt renewed a closed proof.');
        } catch (CheckoutException $error) {
            $this->assertSame('committed_read_used', $error->reason);
        }
        $second->proveClosed();
        $this->assertSame($dispatcher, $connection->getEventDispatcher());
        $this->assertSame(9123, (int) $pdo->query('SELECT marker FROM pco_receipt_consumer_probe')->fetchColumn());
        try {
            $source->proveRetainedCurrent($reader);
            $this->fail('Closed receipt renewed the expired held source.');
        } catch (CheckoutException $error) {
            $this->assertSame('held_transaction', $error->reason);
        }
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_disabled_receipt_capability_refuses_before_identity_receipt_mint(): void
    {
        [$f, $locator] = $this->paid();
        config(['production_checkout.committed_read_receipts_enabled' => false]);
        $connection = DB::connection();
        $connection->transaction(function () use ($f, $locator, $connection): void {
            $reader = new CurrentRows($connection->getPdo(), DB::getDriverName());
            $source = $this->source($f, $locator, $reader);
            try {
                $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
                $this->fail('Disabled producer receipt capability minted a receipt.');
            } catch (CheckoutException $error) {
                $this->assertSame('unsupported', $error->reason);
            }
            $source->proveRetainedCurrent($reader);
        });
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
    }

    public function test_typed_consumer_admission_runs_after_late_delegate_and_refuses_physical_commit_on_buyer_withdrawal(): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $pdo->exec('CREATE TABLE pco_receipt_admission_probe (id INTEGER PRIMARY KEY, marker INTEGER NOT NULL)');
        $userId = (int) $f['buyer']['user']->id;
        $originalPassword = $pdo->query('SELECT password FROM users WHERE id='.$userId)->fetchColumn();
        $called = false;
        try {
            $connection->transaction(function () use ($f, $locator, $connection, $pdo, $userId, &$called): void {
                $reader = new CurrentRows($pdo, DB::getDriverName());
                $source = $this->source($f, $locator, $reader);
                $deadline = hrtime(true) + 60_000_000_000;
                $admission = CheckoutConsumerAdmissionFixture::capture($pdo, $userId, $deadline);
                $source->committedReadReceipt($reader, $deadline, $admission);
                $pdo->exec('INSERT INTO pco_receipt_admission_probe VALUES (1, 9123)');
                $connection->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($pdo, $userId, &$called): void {
                    if (! $called) {
                        $called = true;
                        $pdo->exec("UPDATE users SET password='withdrawn' WHERE id=".$userId);
                    }
                });
                $source->proveRetainedCurrent($reader);
            });
            $this->fail('Withdrawn consumer buyer committed NEW writes.');
        } catch (CheckoutException $error) {
            $this->assertSame('consumer_admission', $error->reason);
            $this->assertTrue($called);
            // Laravel decrements its depth after a committing-listener exception without a PDO rollback.
            // The original transaction owner explicitly aborts its still-open original physical frame.
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertTrue($pdo->inTransaction());
            $pdo->rollBack();
        }
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pco_receipt_admission_probe')->fetchColumn());
        $this->assertSame($originalPassword, $pdo->query('SELECT password FROM users WHERE id='.$userId)->fetchColumn());
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
    }

    public function test_same_typed_admission_accepts_two_siblings_and_preserves_new_consumer_writes(): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $pdo->exec('CREATE TABLE pco_receipt_admission_probe (id INTEGER PRIMARY KEY, marker INTEGER NOT NULL)');
        [$first, $second, $admission] = $connection->transaction(function () use ($f, $locator, $pdo): array {
            $reader = new CurrentRows($pdo, DB::getDriverName());
            $source = $this->source($f, $locator, $reader);
            $deadline = hrtime(true) + 60_000_000_000;
            $pdo->exec('INSERT INTO pco_receipt_admission_probe VALUES (1, 9123)');
            $admission = CheckoutConsumerAdmissionFixture::capture($pdo, (int) $f['buyer']['user']->id, $deadline);
            $first = $source->committedReadReceipt($reader, $deadline, $admission);
            $second = $source->committedReadReceipt($reader, $deadline, $admission);
            $source->proveRetainedCurrent($reader);

            return [$first, $second, $admission];
        });
        $this->assertSame(1, $admission->proofCount());
        $first->proveClosed();
        $second->proveClosed();
        $this->assertSame(9123, (int) $pdo->query('SELECT marker FROM pco_receipt_admission_probe')->fetchColumn());
        $this->assertFalse($pdo->inTransaction());
    }

    public function test_sibling_cannot_remove_original_typed_consumer_admission(): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $connection->transaction(function () use ($f, $locator, $connection): void {
            $pdo = $connection->getPdo();
            $reader = new CurrentRows($pdo, DB::getDriverName());
            $source = $this->source($f, $locator, $reader);
            $deadline = hrtime(true) + 60_000_000_000;
            $admission = CheckoutConsumerAdmissionFixture::capture($pdo, (int) $f['buyer']['user']->id, $deadline);
            $first = $source->committedReadReceipt($reader, $deadline, $admission);
            try {
                $source->committedReadReceipt($reader, $deadline);
                $this->fail('Sibling removed original consumer write admission.');
            } catch (CheckoutException $error) {
                $this->assertSame('committed_read_frame', $error->reason);
            }
            $source->proveRetainedCurrent($reader);
        });
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
    }

    public function test_config_withdrawal_during_actual_source_decryption_cannot_refresh_receipt_context(): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $reader = new CurrentRows($pdo, DB::getDriverName());
        $encrypter = Crypt::getFacadeRoot();
        $account = config('production_checkout.account_id');
        $called = false;
        $deadline = hrtime(true) + 60_000_000_000;
        $connection->beginTransaction();
        try {
            $identity = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $reader);
            Crypt::shouldReceive('decryptString')->andReturnUsing(function (string $cipher) use ($encrypter, &$called): string {
                if (! $called) {
                    $called = true;
                    config(['production_checkout.account_id' => 'acct_WITHDRAWN_DURING_SOURCE_DECRYPTION']);
                }

                return $encrypter->decryptString($cipher);
            });
            $source = ProductionPaidOrderSourceV1::lockedRead($locator, $reader, $identity);
            try {
                $source->committedReadReceipt($reader, $deadline);
                $this->fail('Receipt context refreshed after original source configuration withdrawal.');
            } catch (CheckoutException $error) {
                $this->assertTrue($called);
                $this->assertSame('committed_read_frame', $error->reason);
            }
        } finally {
            Crypt::swap($encrypter);
            config(['production_checkout.account_id' => $account]);
            $connection->rollBack();
        }
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
    }

    public function test_postcommit_lazy_primary_callback_never_runs_and_prepared_result_is_suppressed(): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $called = false;
        $installing = true;
        $deadline = hrtime(true) + 60_000_000_000;
        $connection->getEventDispatcher()->listen(TransactionCommitted::class, function () use ($connection, $pdo, $f, &$called, &$installing): void {
            if (! $installing) {
                return;
            }
            $connection->setPdo(function () use ($pdo, $f, &$called): PDO {
                $called = true;
                $statement = $pdo->prepare('UPDATE users SET password=? WHERE id=?');
                $statement->execute(['WITHDRAWN_BY_LAZY_RECEIPT_CALLBACK', $f['buyer']['user']->id]);

                return $pdo;
            });
        });
        try {
            $connection->transaction(function () use ($f, $locator, $pdo, $deadline): string {
                $reader = new CurrentRows($pdo, DB::getDriverName());
                $source = $this->source($f, $locator, $reader);
                $source->committedReadReceipt($reader, $deadline);
                $source->proveRetainedCurrent($reader);

                return 'PREPARED_RESULT_MUST_NOT_ESCAPE';
            });
            $this->fail('Postcommit unresolved primary returned the prepared result.');
        } catch (CheckoutException $error) {
            $this->assertSame('committed_read_frame', $error->reason);
            $this->assertFalse($called);
        } finally {
            $installing = false;
            $connection->setPdo($pdo);
        }
        $f['access']->current($f['buyer']['principal'], $f['buyer']['user']);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
    }

    public function test_late_committing_direct_commit_reopen_cannot_rebind_original_receipt(): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $called = false;
        $deadline = hrtime(true) + 60_000_000_000;
        $connection->beginTransaction();
        try {
            $reader = new CurrentRows($pdo, DB::getDriverName());
            $source = $this->source($f, $locator, $reader);
            $receipt = $source->committedReadReceipt($reader, $deadline);
            $connection->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($pdo, &$called): void {
                if ($called) {
                    return;
                }
                $called = true;
                $pdo->commit();
                $pdo->beginTransaction();
            });
            try {
                $connection->commit();
                $this->fail('Late listener committed a replacement source frame.');
            } catch (CheckoutException $error) {
                $this->assertTrue($called);
                $this->assertSame('held_transaction', $error->reason);
            }
        } finally {
            $connection->rollBack(0);
        }
        try {
            $receipt->proveClosed();
            $this->fail('Replacement transaction blessed a committed-read receipt.');
        } catch (CheckoutException $error) {
            $this->assertSame('committed_read_frame', $error->reason);
        }
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
    }

    public static function invalidOriginalCommits(): array
    {
        return [['rollback'], ['later_framework_commit'], ['postcommit_direct_reopen'], ['postcommit_dispatcher_replacement']];
    }

    #[DataProvider('invalidOriginalCommits')]
    public function test_receipt_refuses_rollback_later_transaction_and_postcommit_replacement(string $interruption): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $dispatcher = $connection->getEventDispatcher();
        $reader = new CurrentRows($pdo, DB::getDriverName());
        $connection->beginTransaction();
        $source = $this->source($f, $locator, $reader);
        $receipt = $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
        if ($interruption === 'rollback') {
            $connection->rollBack();
            $connection->transaction(fn () => null);
        } else {
            $connection->commit();
            match ($interruption) {
                'later_framework_commit' => $connection->transaction(fn () => null),
                'postcommit_direct_reopen' => $pdo->beginTransaction(),
                'postcommit_dispatcher_replacement' => $connection->setEventDispatcher($dispatcher),
            };
        }
        try {
            $receipt->proveClosed();
            $this->fail('Unrelated/replacement frame retained the original receipt.');
        } catch (CheckoutException $error) {
            $this->assertSame('committed_read_frame', $error->reason);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->assertDatabaseCount('license_grants', 0);
    }
}

/** Typed transport harness only; the real paid-grant capsule separately proves its complete write authority. */
final class CheckoutConsumerAdmissionFixture implements ProductionPaidOrderConsumerCommitAdmissionV1
{
    private int $proofs = 0;

    private function __construct(private readonly PDO $primary, private readonly string $table,
        private readonly int $userId, private readonly string $originalPassword, private readonly int $deadlineNs) {}

    public static function capture(PDO $primary, int $userId, int $deadlineNs): self
    {
        if (! $primary->inTransaction() || $deadlineNs <= hrtime(true)) {
            throw new CheckoutException('consumer_admission');
        }
        $table = $primary->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'main.users'
            : '`'.DB::connection()->getDatabaseName().'`.users';
        $statement = $primary->prepare('SELECT password FROM '.$table.' WHERE id=?');
        $statement->execute([$userId]);

        return new self($primary, $table, $userId, $statement->fetchColumn(), $deadlineNs);
    }

    public function proveCurrent(PDO $capturedPrimary): void
    {
        if ($capturedPrimary !== $this->primary || ! $capturedPrimary->inTransaction() || hrtime(true) >= $this->deadlineNs) {
            throw new CheckoutException('consumer_admission');
        }
        $statement = $capturedPrimary->prepare('SELECT password FROM '.$this->table.' WHERE id=?');
        $statement->execute([$this->userId]);
        if ($statement->fetchColumn() !== $this->originalPassword) {
            throw new CheckoutException('consumer_admission');
        }
        $this->proofs++;
    }

    public function proofCount(): int
    {
        return $this->proofs;
    }
}
