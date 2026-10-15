<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderConsumerCommitAdmissionV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderLocatorV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\TestCase;

/** Real retained synthetic paid Tax graph, ordinary Laravel transactions and genuine listener/callback seams. */
class ProductionTaxCommittedReadReceiptTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutFixtures;

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
        $this->assertSame(1, $capsule->calls);
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
}

final class TaxReceiptAdmissionFixture implements ProductionTaxPaidOrderConsumerCommitAdmissionV2
{
    public int $calls = 0;

    public function proveCurrent(PDO $capturedPrimary): void
    {
        CheckoutException::require($capturedPrimary->inTransaction(), 'consumer_admission');
        $this->calls++;
    }
}
