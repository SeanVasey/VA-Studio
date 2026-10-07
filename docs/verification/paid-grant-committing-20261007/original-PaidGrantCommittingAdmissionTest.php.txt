<?php

namespace Tests\Feature;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrants;
use App\Providers\ProductionCheckoutServiceProvider;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/** Actual SMTP-enrolled buyer + frozen synthetic paid source. No live payment/legal facts are certified. */
final class PaidGrantCommittingAdmissionTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    private const DEPENDENCY = '/workspace/.va-studio-dependencies/paid/f0a1615';

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        if (DB::getDriverName() === 'mysql') {
            $this->assertSame(getenv('DB_DATABASE'), DB::getDatabaseName(), 'The externally selected disposable testing schema is required.');
        }
        $this->assertFileExists(self::DEPENDENCY.'/source-map.json', 'Exact provisional producer/identity fixture snapshot is required.');
        app('migrator')->path(self::DEPENDENCY.'/database/migrations');
        app()->register(ProductionCheckoutServiceProvider::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'paid-grants.rehearsal_enabled' => true,
            'paid-grants.delivery_policy' => ['schema_version' => 1, 'version' => 'explicit-synthetic-delivery-v1', 'purpose' => 'paid-original-delivery',
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
        Queue::fake();
    }

    protected function smtp(string $mode, int $noticeId): array
    {
        $capture = tempnam(sys_get_temp_dir(), 'va-paid-synthetic-smtp-');
        $process = new Process(['python3', self::DEPENDENCY.'/tests/Support/production_identity_smtp_sink.py', $mode, $capture], timeout: 20);
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

    public static function committingWithdrawals(): array
    {
        return [['policy'], ['password']];
    }

    #[DataProvider('committingWithdrawals')]
    public function test_genuine_last_committing_callback_cannot_withdraw_current_consumer_authority_and_commit_new_origin(string $kind): void
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $fired = false;
        Event::listen(TransactionCommitting::class, function () use ($kind, $f, &$fired): void {
            if (! $fired && DB::table('paid_order_origins')->count() === 1) {
                $fired = true;
                if ($kind === 'policy') {
                    config(['paid-grants.rehearsal_enabled' => false]);
                } else {
                    DB::table('users')->where('id', $f['buyer']['user']->id)->update(['password' => Hash::make('Current buyer withdrawn before physical paid-origin commit')]);
                }
            }
        });
        try {
            (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('A committing callback withdrew current write authority but finalized an origin.');
        } catch (PaidGrantException $error) {
            $this->assertTrue($fired);
            $this->assertSame(403, $error->status);
        }
        $this->assertDatabaseCount('paid_order_origins', 0);
        $this->assertDatabaseCount('paid_grant_origins', 0);
        $this->assertDatabaseCount('paid_document_work', 0);
        $this->assertDatabaseCount('paid_fulfillments', 0);
    }
}
