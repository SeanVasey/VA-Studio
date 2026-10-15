<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutCommandFrame;
use App\Domain\Commerce\ProductionCheckout\CheckoutCommitAdmission;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderConsumerCommitAdmissionV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderLocatorV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\TestCase;

/** Real retained synthetic paid Tax graph, ordinary Laravel transactions and genuine listener/callback seams. */
class ProductionTaxCommittedReadReceiptTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;
    use ProductionTaxCheckoutFixtures {
        ProductionTaxCheckoutFixtures::enrollThroughLocalSmtp as private baseReceiptEnrollment;
    }

    private int $enrollments = 0;

    protected function enrollThroughLocalSmtp(string $email = 'buyer@example.test', string $password = 'MailboxPassword123'): array
    {
        $this->enrollments++;

        return $this->baseReceiptEnrollment($this->enrollments === 1 ? $email : 'receipt-'.$this->enrollments.'@example.test', $password);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => IdentityHistoricalCommittedReceipt::VERSION,
            'production_checkout.committed_read_receipts_enabled' => false]);
        Queue::fake();
    }

    private function paid(): array
    {
        $f = $this->taxOrder();
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->paid = true;
        $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        config(['production-tax-checkout.committed_read_receipts_enabled' => true]);
        DB::getPdo()->exec('CREATE TABLE tax_receipt_probe (id INTEGER PRIMARY KEY, marker INTEGER NOT NULL)');

        return [$f, ProductionTaxPaidOrderLocatorV2::locate($f['order']['orderId'])];
    }

    private function source(array $f, ProductionTaxPaidOrderLocatorV2 $locator, CurrentRows $reader): ProductionTaxPaidOrderSourceV2
    {
        $identity = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $reader);

        return ProductionTaxPaidOrderSourceV2::lockedRead($locator, $reader, $identity);
    }

    private function reader(): CurrentRows
    {
        return new CurrentRows(DB::getPdo(), DB::getDriverName());
    }

    private function refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('The operation unexpectedly admitted the original receipt.');
        } catch (CheckoutException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    public function test_two_one_use_siblings_preserve_line_bytes_and_all_successful_callbacks(): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $delegate = $connection->getEventDispatcher();
        $seen = [];
        $deadline = hrtime(true) + 60_000_000_000;
        [$source, $reader, $line, $first, $second] = $connection->transaction(function () use ($f, $locator, $pdo, $delegate, &$seen, $deadline): array {
            $reader = $this->reader();
            $source = $this->source($f, $locator, $reader);
            $line = $source->line(1);
            $first = $source->committedReadReceipt($reader, $deadline);
            $second = $source->committedReadReceipt($reader, $deadline);
            $delegate->listen(TransactionCommitting::class, function () use ($pdo, &$seen): void {
                $seen[] = 'committing';
                $pdo->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');
            });
            $connection = DB::connection();
            $connection->afterCommit(function () use (&$seen): void {
                $seen[] = 'afterCommit';
            });
            $delegate->listen(TransactionCommitted::class, function () use (&$seen): void {
                $seen[] = 'committed';
            });
            $source->proveRetainedCurrent($reader);
            $source->proveRetainedCurrent($reader);

            return [$source, $reader, $line, $first, $second];
        });
        $this->assertSame(['committing', 'afterCommit', 'committed'], $seen);
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, $connection->transactionLevel());
        $first->proveClosed();
        $this->refuses(fn () => $first->proveClosed(), 'committed_read_used');
        $second->proveClosed();
        $this->assertSame($delegate, $connection->getEventDispatcher());
        $this->assertSame($line, $source->line(1));
        $this->assertSame(9123, (int) $pdo->query('SELECT marker FROM tax_receipt_probe')->fetchColumn());
        $this->refuses(fn () => $source->proveRetainedCurrent($reader), 'committed_read_frame');
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_caught_third_wrong_reader_deadline_and_capsule_do_not_disarm_valid_siblings(): void
    {
        [$f, $locator] = $this->paid();
        $deadline = hrtime(true) + 60_000_000_000;
        $capsule = new TaxReceiptAdmissionFixture;
        [$first, $second] = DB::transaction(function () use ($f, $locator, $deadline, $capsule): array {
            $reader = $this->reader();
            $source = $this->source($f, $locator, $reader);
            $first = $source->committedReadReceipt($reader, $deadline, $capsule);
            $observer = DB::connection()->getEventDispatcher();
            $this->refuses(fn () => $source->committedReadReceipt($this->reader(), $deadline, $capsule), 'committed_read_frame');
            $this->refuses(fn () => $source->committedReadReceipt($reader, $deadline + 1, $capsule), 'committed_read_frame');
            $this->refuses(fn () => $source->committedReadReceipt($reader, $deadline, new TaxReceiptAdmissionFixture), 'committed_read_frame');
            $second = $source->committedReadReceipt($reader, $deadline, $capsule);
            $this->refuses(fn () => $source->committedReadReceipt($reader, $deadline, $capsule), 'committed_read_frame');
            $this->assertSame($observer, DB::connection()->getEventDispatcher());
            DB::getPdo()->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');

            return [$first, $second];
        });
        $this->assertSame(2, $capsule->calls);
        $first->proveClosed();
        $second->proveClosed();
        $this->assertDatabaseCount('tax_receipt_probe', 1);
    }

    public function test_caught_early_proof_consumes_and_poisoned_fence_refuses_physical_commit(): void
    {
        [$f, $locator] = $this->paid();
        $source = null;
        $receipt = null;
        $delegate = DB::connection()->getEventDispatcher();
        $this->refuses(function () use ($f, $locator, &$source, &$receipt): void {
            DB::transaction(function () use ($f, $locator, &$source, &$receipt): void {
                $reader = $this->reader();
                $source = $this->source($f, $locator, $reader);
                $receipt = $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
                DB::getPdo()->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');
                $observer = DB::connection()->getEventDispatcher();
                $this->refuses(fn () => $receipt->proveClosed(), 'committed_read_frame');
                $this->assertSame($observer, DB::connection()->getEventDispatcher());
            });
        }, 'committed_read_frame');
        $this->assertTrue(DB::getPdo()->inTransaction());
        $source->abortOwnedFrame();
        $receipt->abortOwnedFrame();
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertDatabaseCount('tax_receipt_probe', 0);
        $this->refuses(fn () => $receipt->proveClosed(), 'committed_read_used');
    }

    public static function committingRefusals(): array
    {
        return ['tax-withdrawal' => ['tax'], 'identity-withdrawal' => ['identity'], 'original-budget' => ['expiry'], 'throwing-delegate' => ['throw']];
    }

    #[DataProvider('committingRefusals')]
    public function test_refused_original_commit_cleans_probe_and_deferred_callbacks_then_allows_fresh_retry(string $kind): void
    {
        [$f, $locator] = $this->paid();
        $source = null;
        $callbacks = 0;
        $pdo = DB::getPdo();
        $delegate = DB::connection()->getEventDispatcher();
        $deadline = hrtime(true) + ($kind === 'expiry' ? 500_000_000 : 60_000_000_000);
        try {
            DB::transaction(function () use ($f, $locator, $pdo, $delegate, $deadline, $kind, &$source, &$callbacks): void {
                $reader = $this->reader();
                $source = $this->source($f, $locator, $reader);
                $source->committedReadReceipt($reader, $deadline);
                DB::connection()->afterCommit(function () use (&$callbacks): void {
                    $callbacks++;
                });
                DB::connection()->afterRollBack(function () use (&$callbacks): void {
                    $callbacks += 100;
                });
                $pdo->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');
                $source->proveRetainedCurrent($reader);
                $source->proveRetainedCurrent($reader);
                $delegate->listen(TransactionCommitting::class, function () use ($kind, $deadline): void {
                    if ($kind === 'tax') {
                        config(['production-tax-checkout.enabled' => false]);
                    }
                    if ($kind === 'identity') {
                        config(['production-customer-identity.historical_receipts_enabled' => false]);
                    }
                    if ($kind === 'expiry') {
                        while (hrtime(true) <= $deadline) {
                            usleep(1000);
                        }
                    }
                    if ($kind === 'throw') {
                        throw new RuntimeException('Synthetic committing listener');
                    }
                });
            });
            $this->fail('A withdrawn/expired original frame physically committed.');
        } catch (\Throwable $error) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $error);
            $this->assertNotNull($source);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue($pdo->inTransaction());
            $source->abortOwnedFrame();
        }
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertDatabaseCount('tax_receipt_probe', 0);
        $this->assertSame(0, $callbacks);
        $delegate->forget(TransactionCommitting::class);
        config(['production-tax-checkout.enabled' => true, 'production-customer-identity.historical_receipts_enabled' => true]);
        DB::transaction(fn () => $pdo->exec('INSERT INTO tax_receipt_probe VALUES (2, 4567)'));
        $this->assertSame(0, $callbacks);
        $receipt = DB::transaction(function () use ($f, $locator) {
            $reader = $this->reader();

            return $this->source($f, $locator, $reader)->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
        });
        $receipt->proveClosed();
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['reviewed'], 1);
    }

    public static function postPhysicalErrors(): array
    {
        return ['managed-afterCommit' => ['managed'], 'committed-delegate' => ['delegate']];
    }

    #[DataProvider('postPhysicalErrors')]
    public function test_throwing_postphysical_callback_preserves_durable_effects_without_positive_receipt(string $kind): void
    {
        [$f, $locator] = $this->paid();
        $receipt = null;
        $delegate = DB::connection()->getEventDispatcher();
        try {
            DB::transaction(function () use ($f, $locator, $kind, &$receipt, $delegate): void {
                $reader = $this->reader();
                $receipt = $this->source($f, $locator, $reader)->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
                DB::getPdo()->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');
                $throw = fn () => throw new RuntimeException('Synthetic postphysical callback');
                if ($kind === 'managed') {
                    DB::connection()->afterCommit($throw);
                } else {
                    $delegate->listen(TransactionCommitted::class, $throw);
                }
            });
            $this->fail('The synthetic postphysical callback did not run.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic postphysical callback', $error->getMessage());
        }
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
        $receipt->abortOwnedFrame();
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertDatabaseCount('tax_receipt_probe', 1);
        $this->refuses(fn () => $receipt->proveClosed(), 'committed_read_frame');
    }

    public function test_false_birth_cannot_enable_receipts_during_actual_source_decryption(): void
    {
        [$f, $locator] = $this->paid();
        config(['production-tax-checkout.committed_read_receipts_enabled' => false]);
        $encrypter = Crypt::getFacadeRoot();
        $called = false;
        DB::transaction(function () use ($f, $locator, $encrypter, &$called): void {
            $reader = $this->reader();
            $identity = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $reader);
            Crypt::shouldReceive('decryptString')->andReturnUsing(function (string $cipher) use ($encrypter, &$called): string {
                $called = true;
                config(['production-tax-checkout.committed_read_receipts_enabled' => true]);

                return $encrypter->decryptString($cipher);
            });
            try {
                $source = ProductionTaxPaidOrderSourceV2::lockedRead($locator, $reader, $identity);
                $this->assertTrue($called);
                $this->refuses(fn () => $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000), 'unsupported');
                $source->proveRetainedCurrent($reader);
            } finally {
                Crypt::swap($encrypter);
            }
        });
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_typed_admission_runs_after_late_delegate_and_refuses_new_consumer_write(): void
    {
        [$f, $locator] = $this->paid();
        $capsule = new TaxReceiptAdmissionFixture;
        $source = null;
        $delegate = DB::connection()->getEventDispatcher();
        $this->refuses(function () use ($f, $locator, $capsule, &$source, $delegate): void {
            DB::transaction(function () use ($f, $locator, $capsule, &$source, $delegate): void {
                $reader = $this->reader();
                $source = $this->source($f, $locator, $reader);
                $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000, $capsule);
                DB::getPdo()->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');
                $delegate->listen(TransactionCommitting::class, function () use ($capsule): void {
                    $capsule->admit = false;
                });
            });
        }, 'consumer_admission');
        $this->assertSame(1, $capsule->calls);
        $source->abortOwnedFrame();
        $this->assertDatabaseCount('tax_receipt_probe', 0);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
    }

    public function test_current_password_change_does_not_revoke_original_historical_paid_proof(): void
    {
        [$f, $locator] = $this->paid();
        $userId = (int) $f['buyer']['user']->id;
        $receipt = DB::transaction(function () use ($f, $locator, $userId) {
            $reader = $this->reader();
            $source = $this->source($f, $locator, $reader);
            $receipt = $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
            DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class,
                fn () => DB::getPdo()->exec("UPDATE users SET password='new-historical-password' WHERE id=".$userId));

            return $receipt;
        });
        $receipt->proveClosed();
        $this->assertSame('new-historical-password', DB::table('users')->where('id', $userId)->value('password'));
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['reviewed'], 1);
    }

    public static function directClosure(): array
    {
        return ['direct-commit-reopen' => ['commit'], 'direct-rollback-reopen' => ['rollBack']];
    }

    #[DataProvider('directClosure')]
    public function test_abort_preserves_unrelated_reopened_physical_and_framework_records(string $operation): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $delegate = $connection->getEventDispatcher();
        $connection->beginTransaction();
        $reader = $this->reader();
        $source = $this->source($f, $locator, $reader);
        $receipt = $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
        $pdo->{$operation}();
        // Close old framework bookkeeping normally before beginning genuinely separate root ownership.
        $manager = (new \ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        $manager->rollback($connection->getName(), 0);
        (new \ReflectionProperty(Connection::class, 'transactions'))->setValue($connection, 0);
        $replacement = new Dispatcher($this->app);
        $connection->setEventDispatcher($replacement);
        $connection->beginTransaction();
        $callbacks = 0;
        $connection->afterCommit(function () use (&$callbacks): void {
            $callbacks++;
        });
        $pdo->exec('INSERT INTO tax_receipt_probe VALUES (2, 4567)');
        $source->abortOwnedFrame();
        $receipt->abortOwnedFrame();
        $this->assertTrue($pdo->inTransaction());
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertSame($replacement, $connection->getEventDispatcher());
        $connection->commit();
        $this->assertSame(1, $callbacks);
        $this->assertDatabaseCount('tax_receipt_probe', 1);
        $this->refuses(fn () => $receipt->proveClosed(), 'committed_read_frame');
        $connection->setEventDispatcher($delegate);
    }

    public function test_nested_begin_poisons_the_original_observer_until_owned_abort(): void
    {
        [$f, $locator] = $this->paid();
        $source = null;
        $this->refuses(function () use ($f, $locator, &$source): void {
            DB::transaction(function () use ($f, $locator, &$source): void {
                $reader = $this->reader();
                $source = $this->source($f, $locator, $reader);
                $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
                DB::getPdo()->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');
                DB::transaction(fn () => null);
            });
        }, 'committed_read_frame');
        $source->abortOwnedFrame();
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('tax_receipt_probe', 0);
    }

    public function test_caught_clone_serialization_and_json_attempts_cannot_duplicate_receipt_authority(): void
    {
        [$f, $locator] = $this->paid();
        $receipt = DB::transaction(function () use ($f, $locator) {
            $reader = $this->reader();
            $source = $this->source($f, $locator, $reader);
            $receipt = $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
            foreach ([$source, $receipt, DB::connection()->getEventDispatcher()] as $capability) {
                foreach ([fn () => clone $capability, fn () => serialize($capability), fn () => json_encode($capability, JSON_THROW_ON_ERROR)] as $attempt) {
                    $refused = false;
                    try {
                        $attempt();
                    } catch (\LogicException|\Error $error) {
                        $this->assertNotInstanceOf(AssertionFailedError::class, $error);
                        $refused = true;
                    }
                    $this->assertTrue($refused, 'Internal receipt capability was copied/exported.');
                }
            }

            return $receipt;
        });
        $receipt->proveClosed();
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_native_closed_source_reads_ignore_peer_row_lock_but_held_reads_wait_and_restore_budget(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL row locking distinguishes closed plain reads from held current reads.');
        }
        [$f, $locator] = $this->paid();
        $pdo = DB::getPdo();
        $receipt = DB::transaction(function () use ($f, $locator) {
            $reader = $this->reader();

            return $this->source($f, $locator, $reader)->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
        });
        $peer = app('db.factory')->make(DB::connection()->getConfig())->getPdo();
        $peer->beginTransaction();
        try {
            $peer->query('SELECT * FROM '.TaxCheckoutSchema::TABLES['reviewed'].' FOR UPDATE')->fetchAll();
            $start = hrtime(true);
            $receipt->proveClosed();
            $this->assertLessThan(2_000_000_000, hrtime(true) - $start, 'Closed original reads acquired an explicit row lock.');
            $this->assertFalse($pdo->inTransaction());
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 11');
            $start = hrtime(true);
            try {
                DB::transaction(function () use ($f, $locator, $pdo): void {
                    $pdo->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');
                    $this->source($f, $locator, $this->reader());
                });
                $this->fail('Held paid graph did not take its current row lock.');
            } catch (CheckoutException $error) {
                $this->assertSame(['contention', 503], [$error->reason, $error->status]);
            }
            $this->assertGreaterThan(4_000_000_000, hrtime(true) - $start);
            $this->assertLessThan(8_000_000_000, hrtime(true) - $start);
            $this->assertSame(11, (int) $pdo->query('SELECT @@session.innodb_lock_wait_timeout')->fetchColumn());
            $this->assertFalse($pdo->inTransaction());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseCount('tax_receipt_probe', 0);
        } finally {
            $peer->rollBack();
        }
    }

    public function test_native_autocommit_withdrawal_refuses_before_any_closed_table_read_transaction(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL autocommit0 can start an implicit InnoDB table-read transaction.');
        }
        [$f, $locator] = $this->paid();
        $pdo = DB::getPdo();
        $receipt = null;
        $called = false;
        try {
            $this->refuses(function () use ($f, $locator, $pdo, &$receipt, &$called): void {
                DB::transaction(function () use ($f, $locator, $pdo, &$receipt, &$called): void {
                    $reader = $this->reader();
                    $receipt = $this->source($f, $locator, $reader)->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
                    $pdo->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');
                    DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use ($pdo, &$called): void {
                        $called = true;
                        $pdo->exec('SET SESSION autocommit = 0');
                    });
                });
            }, 'committed_read_frame');
            $this->assertTrue($called);
            $this->assertFalse($pdo->inTransaction(), 'A closed table SELECT opened an implicit transaction.');
            $this->assertSame(0, (int) $pdo->query('SELECT @@session.autocommit')->fetchColumn());
            $receipt->abortOwnedFrame();
            $this->assertFalse($pdo->inTransaction());
            $this->refuses(fn () => $receipt->proveClosed(), 'committed_read_frame');
            $this->assertSame(0, (int) $pdo->query('SELECT @@session.autocommit')->fetchColumn());
        } finally {
            $pdo->exec('SET SESSION autocommit = 1');
        }
        $this->assertDatabaseCount('tax_receipt_probe', 1);
    }

    public static function observerOrders(): array
    {
        return ['Tax-to-NEW' => ['new', true], 'NEW-to-Tax' => ['new', false], 'Tax-to-V1' => ['v1', true], 'V1-to-Tax' => ['v1', false]];
    }

    #[DataProvider('observerOrders')]
    public function test_observer_install_orders_cannot_create_a_stacked_tax_positive_receipt(string $kind, bool $taxFirst): void
    {
        [$f, $locator] = $this->paid();
        $legacy = null;
        $legacyLocator = null;
        if ($kind === 'v1') {
            config(['production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
                'production_checkout.committed_read_receipts_enabled' => true]);
            $legacy = $this->payable();
            $legacy['hosted']->initiate($legacy['buyer']['principal'], $legacy['buyer']['user'], $legacy['order']['orderId']);
            $legacy['gateway']->paid = true;
            $legacy['hosted']->reconcile($legacy['buyer']['principal'], $legacy['buyer']['user'], $legacy['order']['orderId']);
            $legacyLocator = ProductionPaidOrderLocatorV1::locate($legacy['order']['orderId']);
        }
        $connection = DB::connection();
        $delegate = $connection->getEventDispatcher();
        $source = null;
        $taxReceipt = null;
        $replacement = null;
        $frame = null;
        $legacyReceipt = null;
        try {
            $connection->transaction(function () use ($f, $locator, $legacy, $legacyLocator, $kind, $taxFirst, $connection,
                &$source, &$taxReceipt, &$replacement, &$frame, &$legacyReceipt): void {
                $reader = $this->reader();
                $deadline = hrtime(true) + 60_000_000_000;
                $installOther = function () use ($kind, $legacy, $legacyLocator, $reader, $deadline, $connection, &$frame, &$legacyReceipt): void {
                    if ($kind === 'new') {
                        $frame = CheckoutCommandFrame::capture($connection, $connection->getPdo(), DB::getDriverName());
                        $frame->register(new TaxOtherObserverAdmissionFixture($frame));
                    } else {
                        $identity = $legacy['access']->verifyHistoricalBinding($legacyLocator->historicalBuyerBinding(), $reader);
                        $v1 = ProductionPaidOrderSourceV1::lockedRead($legacyLocator, $reader, $identity);
                        $legacyReceipt = $v1->committedReadReceipt($reader, $deadline);
                    }
                };
                if ($taxFirst) {
                    $source = $this->source($f, $locator, $reader);
                    $taxReceipt = $source->committedReadReceipt($reader, $deadline);
                    $installOther();
                    $replacement = $connection->getEventDispatcher();
                } else {
                    $installOther();
                    $replacement = $connection->getEventDispatcher();
                    $source = $this->source($f, $locator, $reader);
                    $this->refuses(fn () => $source->committedReadReceipt($reader, $deadline), 'committed_read_frame');
                    $this->assertSame($replacement, $connection->getEventDispatcher());
                }
                $connection->getPdo()->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');
            });
            $this->assertFalse($taxFirst, 'Reverse wrapper bypassed the still-delegated Tax commit guard.');
            $legacyReceipt?->proveClosed();
        } catch (CheckoutException $error) {
            $this->assertTrue($taxFirst);
            $this->assertSame('committed_read_frame', $error->reason);
            $this->assertTrue($connection->getPdo()->inTransaction());
            $source->abortOwnedFrame();
            $this->assertSame($replacement, $connection->getEventDispatcher(), 'Tax cleanup replaced a different observer owner.');
        } finally {
            $frame?->restore();
            $connection->setEventDispatcher($delegate);
            $source?->abortOwnedFrame();
        }
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertDatabaseCount('tax_receipt_probe', $taxFirst ? 0 : 1);
        if ($taxReceipt !== null) {
            $this->refuses(fn () => $taxReceipt->proveClosed(), 'committed_read_frame');
        }
    }

    public function test_sqlite_final_raw_order_fence_detects_late_decryption_callback_drift_and_restores_guard(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Synthetic transactional guard removal is SQLite-only; MySQL DDL would implicitly commit.');
        }
        [$f, $locator] = $this->paid();
        $pdo = DB::getPdo();
        $table = TaxCheckoutSchema::TABLES['order'];
        $before = $pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
        $guard = $pdo->query("SELECT sql FROM sqlite_schema WHERE name='ptx_o_update'")->fetchColumn();
        $encrypter = Crypt::getFacadeRoot();
        $source = null;
        $hits = 0;
        try {
            $this->refuses(function () use ($f, $locator, $pdo, $table, $encrypter, &$source, &$hits): void {
                DB::transaction(function () use ($f, $locator, $pdo, $table, $encrypter, &$source, &$hits): void {
                    $reader = $this->reader();
                    $source = $this->source($f, $locator, $reader);
                    $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
                    $pdo->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');
                    DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($pdo, $table, $encrypter, &$hits): void {
                        Crypt::shouldReceive('decryptString')->andReturnUsing(function (string $cipher) use ($pdo, $table, $encrypter, &$hits): string {
                            if ($hits === 0) {
                                $hits++;
                                $pdo->exec('DROP TRIGGER ptx_o_update');
                                $pdo->exec('UPDATE '.$table." SET payload_hash='".hash('sha256', 'NONBINDING LATE RAW ORDER DRIFT')."'");
                            }

                            return $encrypter->decryptString($cipher);
                        });
                    });
                });
            }, 'committed_read_frame');
        } finally {
            Crypt::swap($encrypter);
            $source?->abortOwnedFrame();
        }
        $this->assertSame(1, $hits);
        $this->assertSame($before, $pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame($guard, $pdo->query("SELECT sql FROM sqlite_schema WHERE name='ptx_o_update'")->fetchColumn());
        $this->assertDatabaseCount('tax_receipt_probe', 0);
    }

    public function test_reentrant_committing_delegate_cannot_physically_commit_the_original_probe(): void
    {
        [$f, $locator] = $this->paid();
        $source = null;
        $hits = 0;
        $connection = DB::connection();
        $this->refuses(function () use ($f, $locator, $connection, &$source, &$hits): void {
            $connection->transaction(function () use ($f, $locator, $connection, &$source, &$hits): void {
                $reader = $this->reader();
                $source = $this->source($f, $locator, $reader);
                $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
                $connection->getPdo()->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');
                $connection->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($connection, &$hits): void {
                    if ($hits === 0) {
                        $hits++;
                        $connection->commit();
                    }
                });
            });
        }, 'committed_read_frame');
        $this->assertSame(1, $hits);
        $this->assertTrue($connection->getPdo()->inTransaction(), 'Reentrant callback already committed the original probe.');
        $source->abortOwnedFrame();
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertDatabaseCount('tax_receipt_probe', 0);
    }

    public function test_consumer_withdrawal_in_later_actual_decryption_refuses_before_physical_commit(): void
    {
        [$f, $locator] = $this->paid();
        $pdo = DB::getPdo();
        $userId = (int) $f['buyer']['user']->id;
        $original = $pdo->query('SELECT password FROM users WHERE id='.$userId)->fetchColumn();
        $capsule = new TaxPasswordAdmissionFixture($pdo, $userId, $original);
        $encrypter = Crypt::getFacadeRoot();
        $source = null;
        $hits = 0;
        try {
            $this->refuses(function () use ($f, $locator, $pdo, $userId, $capsule, $encrypter, &$source, &$hits): void {
                DB::transaction(function () use ($f, $locator, $pdo, $userId, $capsule, $encrypter, &$source, &$hits): void {
                    $reader = $this->reader();
                    $source = $this->source($f, $locator, $reader);
                    $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000, $capsule);
                    $pdo->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');
                    DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($pdo, $userId, $encrypter, &$hits): void {
                        Crypt::shouldReceive('decryptString')->andReturnUsing(function (string $cipher) use ($pdo, $userId, $encrypter, &$hits): string {
                            if ($hits === 0) {
                                $hits++;
                                $pdo->exec("UPDATE users SET password='withdrawn-after-initial-consumer-proof' WHERE id=".$userId);
                            }

                            return $encrypter->decryptString($cipher);
                        });
                    });
                });
            }, 'consumer_admission');
        } finally {
            Crypt::swap($encrypter);
            $source?->abortOwnedFrame();
        }
        $this->assertSame(1, $hits);
        $this->assertSame($original, $pdo->query('SELECT password FROM users WHERE id='.$userId)->fetchColumn());
        $this->assertDatabaseCount('tax_receipt_probe', 0);
    }

    public function test_abort_preserves_reopened_root_callbacks_even_with_stale_original_framework_parent(): void
    {
        [$f, $locator] = $this->paid();
        $source = null;
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $delegate = $connection->getEventDispatcher();
        $oldCallbacks = 0;
        try {
            $connection->transaction(function () use ($f, $locator, $connection, &$source, &$oldCallbacks): void {
                $reader = $this->reader();
                $source = $this->source($f, $locator, $reader);
                $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
                $connection->afterCommit(function () use (&$oldCallbacks): void {
                    $oldCallbacks++;
                });
                $connection->getEventDispatcher()->listen(TransactionCommitting::class,
                    fn () => throw new RuntimeException('Synthetic root callback failure'));
            });
            $this->fail('The original committing error did not run.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic root callback failure', $error->getMessage());
        }
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertTrue($pdo->inTransaction());
        $pdo->rollBack(); // Foreign owner closes old physical frame, leaving original framework record deliberately pending.
        $replacement = new Dispatcher($this->app);
        $connection->setEventDispatcher($replacement);
        $connection->beginTransaction();
        $newCallbacks = 0;
        $connection->afterCommit(function () use (&$newCallbacks): void {
            $newCallbacks++;
        });
        $pdo->exec('INSERT INTO tax_receipt_probe VALUES (2, 4567)');
        $source->abortOwnedFrame();
        $this->assertTrue($pdo->inTransaction());
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertSame($replacement, $connection->getEventDispatcher());
        $connection->commit();
        $this->assertSame(0, $oldCallbacks);
        $this->assertSame(1, $newCallbacks, 'Cleanup discarded a separate reopened root callback.');
        $this->assertDatabaseCount('tax_receipt_probe', 1);
        $connection->setEventDispatcher($delegate);
    }

    public function test_owner_terminal_source_fence_refuses_a_nondelegating_dispatcher_replacement(): void
    {
        [$f, $locator] = $this->paid();
        $connection = DB::connection();
        $source = null;
        $replacement = new Dispatcher($this->app);
        $this->refuses(function () use ($f, $locator, $connection, &$source, $replacement): void {
            $connection->transaction(function () use ($f, $locator, $connection, &$source, $replacement): void {
                $reader = $this->reader();
                $source = $this->source($f, $locator, $reader);
                $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
                $connection->getPdo()->exec('INSERT INTO tax_receipt_probe VALUES (1, 9123)');
                $connection->setEventDispatcher($replacement);
                $source->proveRetainedCurrent($reader); // Explicit terminal owner fence before physical commit.
            });
        }, 'committed_read_frame');
        $source->abortOwnedFrame();
        $this->assertSame($replacement, $connection->getEventDispatcher());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertDatabaseCount('tax_receipt_probe', 0);
    }
}

final class TaxReceiptAdmissionFixture implements ProductionTaxPaidOrderConsumerCommitAdmissionV2
{
    public int $calls = 0;

    public bool $admit = true;

    public function proveCurrent(PDO $capturedPrimary): void
    {
        $this->calls++;
        CheckoutException::require($this->admit && $capturedPrimary->inTransaction(), 'consumer_admission');
    }
}

final readonly class TaxOtherObserverAdmissionFixture implements CheckoutCommitAdmission
{
    public function __construct(private CheckoutCommandFrame $frame) {}

    public function belongsTo(CheckoutCommandFrame $frame): bool
    {
        return $frame === $this->frame;
    }

    public function proveCurrent(): void
    {
        $this->frame->prove(1);
    }

    public function proveFresh(): void
    {
        $this->frame->prove(1);
    }
}

final readonly class TaxPasswordAdmissionFixture implements ProductionTaxPaidOrderConsumerCommitAdmissionV2
{
    public function __construct(private PDO $primary, private int $userId, private string $originalPassword) {}

    public function proveCurrent(PDO $capturedPrimary): void
    {
        CheckoutException::require($capturedPrimary === $this->primary && $capturedPrimary->inTransaction(), 'consumer_admission');
        $statement = $capturedPrimary->prepare('SELECT password FROM users WHERE id = ?');
        $statement->execute([$this->userId]);
        CheckoutException::require($statement->fetchColumn() === $this->originalPassword, 'consumer_admission');
    }
}
