<?php

namespace Tests\Review;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderCommittedReadReceiptV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderConsumerCommitAdmissionV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;
use Throwable;

/**
 * Independent reviewer throwaway probes for d20d4394 (not part of tests/).
 *
 * A: receipt observer as the SOLE observer on a CommandTransaction (no NEW-write observer)
 *    frame; typed consumer admission withdrawn in a committing listener. Expect the command
 *    frame owner (CommandTransaction::abort) to roll back the whole original physical frame
 *    automatically, and the receipt never to close.
 * B: NEW-write order acceptance refused by a committing-time fresh-policy withdrawal, where
 *    the same listener registered a framework after-commit callback. Probe whether the refused
 *    frame's framework callback state leaks into the NEXT unrelated commit and persists rows.
 */
class ReviewAdversarialCompositionTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => IdentityHistoricalCommittedReceipt::VERSION,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'production_checkout.committed_read_receipts_enabled' => true]);
        Queue::fake();
    }

    public function test_a_receipt_on_command_frame_withdrawn_admission_rolls_back_whole_frame_and_never_closes(): void
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $locator = ProductionPaidOrderLocatorV1::locate($f['order']['orderId']);

        $connection = DB::connection();
        $pdo = $connection->getRawPdo();
        $delegate = $connection->getEventDispatcher();
        $pdo->exec('CREATE TABLE review_consumer_probe (id INTEGER PRIMARY KEY, marker INTEGER NOT NULL)');
        $userId = (int) $f['buyer']['user']->id;
        $originalPassword = $pdo->query('SELECT password FROM users WHERE id='.$userId)->fetchColumn();
        $receipt = null;
        $withdrawn = false;
        $refused = null;
        try {
            CommandTransaction::run(function (Records $rows) use ($f, $locator, $pdo, $userId, &$receipt, &$withdrawn): void {
                $identity = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);
                $source = ProductionPaidOrderSourceV1::lockedRead($locator, $rows->current, $identity);
                $deadline = hrtime(true) + 60_000_000_000;
                $admission = ReviewConsumerAdmission::capture($pdo, $userId, $deadline);
                $receipt = $source->committedReadReceipt($rows->current, $deadline, $admission);
                $pdo->exec('INSERT INTO review_consumer_probe VALUES (1, 7001)');
                DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($pdo, $userId, &$withdrawn): void {
                    if (! $withdrawn) {
                        $withdrawn = true;
                        $pdo->exec("UPDATE users SET password='review-withdrawn' WHERE id=".$userId);
                    }
                });
                $source->proveRetainedCurrent($rows->current);
            });
        } catch (CheckoutException $error) {
            $refused = $error->reason;
        }
        $this->assertTrue($withdrawn, 'withdrawal listener must have run');
        $this->assertSame('consumer_admission', $refused, 'withdrawn admission must refuse the original commit');
        // CommandTransaction (frame owner) must have rolled back the whole physical frame itself.
        $this->assertFalse($pdo->inTransaction(), 'command frame owner left the original physical transaction open');
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame($delegate, $connection->getEventDispatcher(), 'observer must restore the prior dispatcher');
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM review_consumer_probe')->fetchColumn(), 'consumer write became durable after withdrawal');
        $this->assertSame($originalPassword, $pdo->query('SELECT password FROM users WHERE id='.$userId)->fetchColumn());
        $this->assertInstanceOf(ProductionPaidOrderCommittedReadReceiptV1::class, $receipt);
        try {
            $receipt->proveClosed();
            $this->fail('A receipt closed after its original commit was refused.');
        } catch (CheckoutException $error) {
            $this->assertSame('committed_read_frame', $error->reason);
        }
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_b_refused_new_order_frame_does_not_leak_after_commit_callbacks_into_next_commit(): void
    {
        $f = $this->payable(false);
        $pdo = DB::connection()->getRawPdo();
        $pdo->exec('CREATE TABLE review_leak_probe (id INTEGER PRIMARY KEY, marker INTEGER NOT NULL)');
        $registered = false;
        $fired = 0;
        app('events')->listen(TransactionCommitting::class, function () use (&$registered, &$fired): void {
            if (! $registered) {
                $registered = true;
                // A framework after-commit side effect belonging to the original NEW-order frame.
                DB::afterCommit(function () use (&$fired): void {
                    $fired++;
                    DB::connection()->getRawPdo()->exec('INSERT INTO review_leak_probe VALUES (1, 8001)');
                });
                config(['production_checkout.fresh_checkout_enabled' => false]);
            }
        });
        $refused = false;
        try {
            $f['checkout']->accept($f['buyer']['principal'], $f['buyer']['user'], $f['review']['reviewId'], $f['review']['reviewHash'], true, 'review-afteccommit-leak');
        } catch (Throwable) {
            $refused = true;
        }
        $this->assertTrue($registered);
        $this->assertTrue($refused, 'withdrawn fresh policy must refuse the NEW order');
        foreach (['order', 'line', 'attempt'] as $kind) {
            $this->assertDatabaseCount(CheckoutSchema::TABLES[$kind], 0);
        }
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, $fired, 'callback ran during the refused frame');

        // An unrelated, later, ordinary framework commit on the same connection.
        DB::transaction(function (): void {
            DB::table('users')->count();
        });
        $this->assertSame(0, $fired, 'refused frame after-commit callback leaked into a later unrelated commit');
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM review_leak_probe')->fetchColumn());
    }
}

final class ReviewConsumerAdmission implements ProductionPaidOrderConsumerCommitAdmissionV1
{
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
    }
}
