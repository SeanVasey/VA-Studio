<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use ArrayObject;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use PDOStatement;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;
use Throwable;

/** Actual original framework commit listeners, actual SMTP identity and persisted checkout rows. */
class ProductionCheckoutWriteCommitTest extends TestCase
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

    public function test_committing_persisted_password_withdrawal_rolls_back_order_and_original_password(): void
    {
        $f = $this->payable(false);
        $original = DB::table('users')->where('id', $f['buyer']['user']->id)->value('password');
        app('events')->listen(TransactionCommitting::class, function () use ($f): void {
            DB::table('users')->where('id', $f['buyer']['user']->id)->update(['password' => 'withdrawn-password']);
        });
        $this->refuseAccept($f);
        $this->assertSame($original, DB::table('users')->where('id', $f['buyer']['user']->id)->value('password'));
    }

    public function test_committing_identity_module_withdrawal_rolls_back_order(): void
    {
        $f = $this->payable(false);
        app('events')->listen(TransactionCommitting::class, function (): void {
            config(['production-customer-identity.enabled' => false]);
        });
        $this->refuseAccept($f);
        $this->assertFalse(config('production-customer-identity.enabled'));
    }

    public function test_committing_catalog_withdrawal_rolls_back_order_and_track(): void
    {
        $f = $this->payable(false);
        $trackId = $f['catalog']['items'][0]['trackId'];
        $original = DB::table('tracks')->where('id', $trackId)->value('status');
        app('events')->listen(TransactionCommitting::class, function () use ($trackId): void {
            DB::table('tracks')->where('id', $trackId)->update(['status' => 'draft']);
        });
        $this->refuseAccept($f);
        $this->assertSame($original, DB::table('tracks')->where('id', $trackId)->value('status'));
    }

    public function test_committing_fresh_withdrawal_refuses_new_review_and_retains_original(): void
    {
        $f = $this->payable(false);
        $original = (array) DB::table(CheckoutSchema::TABLES['review'])->first();
        app('events')->listen(TransactionCommitting::class, function (): void {
            config(['production_checkout.fresh_checkout_enabled' => false]);
        });
        $refused = false;
        try {
            $f['checkout']->review($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id,
                $f['catalog']['items'], $f['basis']['public_id'], ['legalName' => 'Declared synthetic buyer'], 'synthetic-physical-new-review');
        } catch (CheckoutException) {
            $refused = true;
        }
        $this->assertTrue($refused);
        $this->assertSame($original, (array) DB::table(CheckoutSchema::TABLES['review'])->first());
        $this->assertDatabaseCount(CheckoutSchema::TABLES['review'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['order'], 0);
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_ordinary_committing_caller_write_survives_positive_order_commit_and_observer_restores(): void
    {
        $f = $this->payable(false);
        $primary = DB::connection()->getRawPdo();
        $primary->exec('CREATE TABLE checkout_command_marker (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
        $delegate = DB::connection()->getEventDispatcher();
        $callbacks = 0;
        app('events')->listen(TransactionCommitting::class, function () use ($primary, &$callbacks): void {
            if (++$callbacks === 1) {
                $primary->exec('INSERT INTO checkout_command_marker (id, value) VALUES (1, 9133)');
            }
        });
        $order = $f['checkout']->accept($f['buyer']['principal'], $f['buyer']['user'], $f['review']['reviewId'], $f['review']['reviewHash'], true, 'synthetic-positive-physical-commit');
        $this->assertTrue($order['assentAccepted']);
        $this->assertSame(9133, (int) $primary->query('SELECT value FROM checkout_command_marker WHERE id = 1')->fetchColumn());
        $this->assertDatabaseCount(CheckoutSchema::TABLES['order'], 1);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertFalse($primary->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_committing_arrayobject_app_parent_is_refused_without_offset_callbacks(): void
    {
        $f = $this->payable(false);
        $parent = new CheckoutCommitArrayParent(config('app'));
        app('events')->listen(TransactionCommitting::class, function () use ($parent): void {
            config(['app' => $parent]);
        });
        $this->refuseAccept($f);
        $this->assertSame(0, $parent->callbacks);
    }

    public function test_committing_statement_class_is_refused_before_statement_callbacks_and_original_claim_is_rolled_back(): void
    {
        $f = $this->payable(false);
        $primary = DB::connection()->getRawPdo();
        CheckoutCommitStatement::$callbacks = 0;
        app('events')->listen(TransactionCommitting::class, function () use ($primary): void {
            $primary->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CheckoutCommitStatement::class]);
        });
        try {
            $refused = false;
            try {
                $this->accept($f);
            } catch (CheckoutException) {
                $refused = true;
            }
            $this->assertTrue($refused);
            $this->assertSame(0, CheckoutCommitStatement::$callbacks);
            $this->assertFalse($primary->inTransaction());
        } finally {
            $primary->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }
        $this->assertNoOrderRows();
    }

    public function test_committing_direct_physical_commit_reopen_refuses_and_preserves_replacement_frame(): void
    {
        $f = $this->payable(false);
        $primary = DB::connection()->getRawPdo();
        $primary->exec('CREATE TABLE checkout_command_marker (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
        app('events')->listen(TransactionCommitting::class, function () use ($primary): void {
            $primary->commit();
            $primary->beginTransaction();
            $primary->exec('INSERT INTO checkout_command_marker (id, value) VALUES (1, 9144)');
        });
        try {
            $refused = false;
            try {
                $this->accept($f);
            } catch (CheckoutException) {
                $refused = true;
            }
            $this->assertTrue($refused);
            $this->assertTrue($primary->inTransaction());
            $this->assertSame(9144, (int) $primary->query('SELECT value FROM checkout_command_marker WHERE id = 1')->fetchColumn());
            // The privileged listener already committed the original rows; detection cannot undo its commit.
            $this->assertDatabaseCount(CheckoutSchema::TABLES['order'], 1);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            if ($primary->inTransaction()) {
                $primary->rollBack();
            }
        }
        $this->assertSame(0, (int) $primary->query('SELECT COUNT(*) FROM checkout_command_marker')->fetchColumn());
    }

    public function test_committing_dispatcher_replacement_refuses_without_replacing_delegate(): void
    {
        $f = $this->payable(false);
        $replacement = new Dispatcher(app());
        app('events')->listen(TransactionCommitting::class, function () use ($replacement): void {
            DB::connection()->setEventDispatcher($replacement);
        });
        $this->refuseAccept($f);
        $this->assertSame($replacement, DB::connection()->getEventDispatcher());
    }

    public function test_committing_trusted_actor_mutation_refuses_order(): void
    {
        $f = $this->payable(false);
        app('events')->listen(TransactionCommitting::class, function () use ($f): void {
            $f['buyer']['user']->id = 999999;
        });
        $this->refuseAccept($f);
    }

    private function accept(array $f): array
    {
        return $f['checkout']->accept($f['buyer']['principal'], $f['buyer']['user'], $f['review']['reviewId'], $f['review']['reviewHash'], true, 'synthetic-commit-admission');
    }

    private function refuseAccept(array $f): void
    {
        $refused = false;
        try {
            $this->accept($f);
        } catch (Throwable) {
            $refused = true;
        }
        $this->assertTrue($refused);
        $this->assertNoOrderRows();
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function assertNoOrderRows(): void
    {
        foreach (['order', 'line', 'attempt'] as $kind) {
            $this->assertDatabaseCount(CheckoutSchema::TABLES[$kind], 0);
        }
    }
}

final class CheckoutCommitArrayParent extends ArrayObject
{
    public int $callbacks = 0;

    public function offsetExists(mixed $key): bool
    {
        $this->callbacks++;

        return parent::offsetExists($key);
    }

    public function offsetGet(mixed $key): mixed
    {
        $this->callbacks++;

        return parent::offsetGet($key);
    }
}

final class CheckoutCommitStatement extends PDOStatement
{
    public static int $callbacks = 0;

    protected function __construct()
    {
        self::$callbacks++;
    }
}
