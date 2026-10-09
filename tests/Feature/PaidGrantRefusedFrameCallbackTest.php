<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\OriginalCommitDispatcher;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Grants\Paid\PaidGrantDownloads;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrants;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * Probe-B regression for the paid consumer (independent checkout review condition on e86381bf).
 *
 * A commit-time refusal of a paid frame lowers Laravel's depth without telling the transactions manager.
 * Without cleanup, after-commit work registered inside the refused frame runs on the next unrelated commit.
 */
final class PaidGrantRefusedFrameCallbackTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidGrantDependencyFixtures;
    use ProductionCheckoutJourneyFixture;

    protected function beforeRefreshingDatabase(): void
    {
        $this->preparePaidDependencies();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.committed_read_receipts_enabled' => true,
            'production_checkout.committed_read_receipt_version' => 'production-checkout-committed-read-v1',
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => 'identity-historical-committed-receipt-v1',
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'paid-grants.rehearsal_enabled' => true,
            'paid-grants.delivery_policy' => ['schema_version' => 1, 'version' => 'explicit-synthetic-delivery-v1', 'purpose' => 'paid-original-delivery',
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
        Queue::fake();
    }

    protected function smtp(string $mode, int $noticeId): array
    {
        $capture = tempnam(sys_get_temp_dir(), 'va-paid-synthetic-smtp-');
        $process = new Process(['python3', $this->paidDependencyPath('tests/Support/production_identity_smtp_sink.py'), $mode, $capture], timeout: 20);
        $process->start();
        try {
            $process->waitUntil(fn (): bool => preg_match('/\A[0-9]+\n/', $process->getOutput()) === 1);
            app()->instance(IdentityNoticeTransport::class, new LoopbackSmtp((int) trim($process->getOutput())));
            (new WorkIdentityNotice)->process($noticeId);
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            return filesize($capture) > 0 ? json_decode(file_get_contents($capture), true, 8, JSON_THROW_ON_ERROR) : [];
        } finally {
            $process->stop(0);
            unlink($capture);
        }
    }

    public static function refusedFrames(): array
    {
        return [['finalize-transaction'], ['command-run']];
    }

    #[DataProvider('refusedFrames')]
    public function test_refused_paid_frame_does_not_leak_after_commit_callbacks_into_next_unrelated_commit(string $frame): void
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $origin = $frame === 'command-run' ? (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']) : null;
        $pdo = DB::connection()->getRawPdo();
        $pdo->exec('CREATE TABLE zz_paid_refused_frame_probe (id INTEGER PRIMARY KEY, marker INTEGER NOT NULL)');
        $registered = false;
        $observed = false;
        $fired = 0;
        Event::listen(TransactionCommitting::class, function () use (&$registered, &$observed, &$fired): void {
            // Arm only on the paid frame held under the producer's original commit observer.
            $observed = $observed || DB::connection()->getEventDispatcher() instanceof OriginalCommitDispatcher;
            if (! $registered && $observed) {
                $registered = true;
                // Framework after-commit work belonging to the paid frame that is about to be refused.
                DB::afterCommit(function () use (&$fired): void {
                    $fired++;
                    DB::connection()->getRawPdo()->exec('INSERT INTO zz_paid_refused_frame_probe VALUES (1, 8001)');
                });
                config(['paid-grants.rehearsal_enabled' => false]);
            }
        });
        $refused = null;
        try {
            if ($frame === 'command-run') {
                (new PaidGrantDownloads)->status($origin['id'], $f['buyer']['principal'], $f['buyer']['user']);
            } else {
                (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            }
        } catch (PaidGrantException $error) {
            $refused = $error->status;
        }
        $this->assertTrue($registered);
        $this->assertTrue($observed, 'the withdrawal must land inside the producer-observed paid commit');
        $this->assertSame(403, $refused, 'the withdrawn paid policy must refuse the original frame at commit');
        $this->assertDatabaseCount('paid_order_origins', $frame === 'command-run' ? 1 : 0);
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, $fired, 'callback ran during the refused frame');

        // An unrelated, later, ordinary framework commit on the same connection.
        DB::transaction(function (): void {
            DB::table('users')->count();
        });
        $this->assertSame(0, $fired, 'refused paid frame after-commit callback leaked into a later unrelated commit');
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM zz_paid_refused_frame_probe')->fetchColumn());
    }
}
