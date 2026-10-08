<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;
use Throwable;

/**
 * Laravel's commit-exception path lowers the framework depth without telling the
 * transactions manager, so a refused NEW-write frame would otherwise leave its
 * pending record (and any after-commit work registered inside it) to run on the
 * next unrelated commit. Probe contributed by the independent d20d4394 review.
 */
class ProductionCheckoutRefusedFrameCallbackTest extends TestCase
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
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true]);
        Queue::fake();
    }

    public function test_refused_new_order_frame_does_not_leak_after_commit_callbacks_into_next_commit(): void
    {
        $f = $this->payable(false);
        $pdo = DB::connection()->getRawPdo();
        $pdo->exec('CREATE TABLE pco_refused_frame_probe (id INTEGER PRIMARY KEY, marker INTEGER NOT NULL)');
        $registered = false;
        $fired = 0;
        app('events')->listen(TransactionCommitting::class, function () use (&$registered, &$fired): void {
            if (! $registered) {
                $registered = true;
                // A framework after-commit side effect belonging to the original NEW-order frame.
                DB::afterCommit(function () use (&$fired): void {
                    $fired++;
                    DB::connection()->getRawPdo()->exec('INSERT INTO pco_refused_frame_probe VALUES (1, 8001)');
                });
                config(['production_checkout.fresh_checkout_enabled' => false]);
            }
        });
        $refused = false;
        try {
            $f['checkout']->accept($f['buyer']['principal'], $f['buyer']['user'], $f['review']['reviewId'], $f['review']['reviewHash'], true, 'review-after-commit-leak');
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
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pco_refused_frame_probe')->fetchColumn());
    }
}
