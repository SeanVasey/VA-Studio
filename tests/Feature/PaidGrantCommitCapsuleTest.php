<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Grants\Paid\PaidGrantConsumerCommitAdmission;
use App\Domain\Grants\Paid\PaidGrantDeadline;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantPolicy;
use App\Domain\Grants\Paid\PaidGrantReadReceipt;
use App\Domain\Grants\Paid\PaidGrantRows;
use App\Domain\Grants\Paid\PaidGrants;
use App\Providers\ProductionCheckoutServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/** Actual SMTP-enrolled buyer + frozen synthetic paid source. No live payment/legal facts are certified. */
final class PaidGrantCommitCapsuleTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    private const DEPENDENCY = '/workspace/.va-studio-dependencies/paid/90d09a5-e6c02b9';

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

    public static function capsuleCases(): array
    {
        return [['valid'], ['policy'], ['password'], ['config-subclass'], ['app-parent'], ['database-parent'], ['paid-parent'], ['identity-parent'], ['statement-class'], ['foreign-primary'], ['expired']];
    }

    #[DataProvider('capsuleCases')]
    public function test_opaque_capsule_closes_only_original_prepared_current_owner_policy_and_owned_writes_without_callbacks_or_new_locks(string $kind): void
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $batch = (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $locator = ProductionPaidOrderLocatorV1::locate($f['order']['orderId']);
        $configuration = app('config');
        $originalParents = array_intersect_key($configuration->all(), array_flip(['app', 'database', 'paid-grants', 'production-customer-identity']));
        $pdo = DB::connection()->getPdo();
        $held = null;
        $callbackInvoked = false;
        $budget = PaidGrantDeadline::start($kind === 'expired' ? 1 : 60);
        try {
            DB::transaction(function () use ($kind, $f, $batch, $locator, $configuration, $pdo, $budget, &$held, &$callbackInvoked): void {
                $rows = new PaidGrantRows;
                $held = $rows;
                $access = app(ProductionCustomerAccess::class);
                $authority = $access->lock($f['buyer']['principal'], $f['buyer']['user'], $rows->current());
                $historical = $access->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current());
                $reader = $rows->admitSource();
                $source = ProductionPaidOrderSourceV1::lockedRead($locator, $reader, $historical);
                $grants = new PaidGrants;
                $graph = $grants->graph($batch['id'], $f['buyer']['principal']->accountId, $rows);
                $rows->execute('UPDATE '.$rows->table('paid_document_work')." SET state='claimed', attempts=1, claim_id=?, expires_at=? WHERE id=?",
                    ['dddddddd-dddd-4ddd-8ddd-dddddddddddd', now()->utc()->addSeconds(300)->format('Y-m-d H:i:s'), $graph['lines'][0]['work']['id']]);
                $graph = $grants->graph($batch['id'], $f['buyer']['principal']->accountId, $rows);
                $policy = app(PaidGrantPolicy::class)->capture();
                $receipt = PaidGrantReadReceipt::capture($rows, $f['buyer']['principal'], $f['buyer']['user'], $authority, $policy, $grants->snapshots($graph, $rows));
                $capsule = PaidGrantConsumerCommitAdmission::capture($receipt, $budget);
                foreach ([fn () => serialize($capsule), fn () => json_encode($capsule, JSON_THROW_ON_ERROR)] as $attempt) {
                    try {
                        $attempt();
                        $this->fail('Consumer admission became serialized authority.');
                    } catch (\LogicException) {
                        $this->assertTrue(true);
                    }
                }
                $access->proveCurrent($f['buyer']['principal'], $f['buyer']['user'], $reader, $authority);
                $source->proveRetainedCurrent($reader);
                $rows->finish();
                if ($kind === 'policy') {
                    config(['paid-grants.rehearsal_enabled' => false]);
                } elseif ($kind === 'password') {
                    DB::table('users')->where('id', $f['buyer']['user']->id)->update(['password' => Hash::make('Prepared current write authority withdrawn')]);
                } elseif ($kind === 'config-subclass') {
                    app()->instance('config', new PaidAdmissionCallbackConfiguration($configuration->all(), $callbackInvoked));
                } elseif (in_array($kind, ['app-parent', 'database-parent', 'paid-parent', 'identity-parent'], true)) {
                    $parent = ['app-parent' => 'app', 'database-parent' => 'database', 'paid-parent' => 'paid-grants', 'identity-parent' => 'production-customer-identity'][$kind];
                    $configuration->set($parent, new PaidAdmissionCallbackParent($configuration->get($parent), $callbackInvoked));
                } elseif ($kind === 'statement-class') {
                    $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [PaidAdmissionCallbackStatement::class, [&$callbackInvoked]]);
                } elseif ($kind === 'expired') {
                    usleep(1_100_000); // Expire the original captured budget without renewing the capsule/frame.
                }
                $capsule->proveCurrent($kind === 'foreign-primary' ? new \PDO('sqlite::memory:') : $pdo);
                if ($kind !== 'valid') {
                    $this->fail('Changed current authority/context was admitted.');
                }
                $source->proveRetainedCurrent($reader);
                $this->assertSame('claimed', DB::table('paid_document_work')->sole()->state);
            });
            $held->assertCommitted();
        } catch (PaidGrantException $error) {
            $held?->abort();
            $this->assertNotSame('valid', $kind, 'Original prepared authority must remain usable.');
            $this->assertContains($error->status, [403, 410, 503]);
        } finally {
            foreach ($originalParents as $parent => $values) {
                $configuration->set($parent, $values);
            }
            app()->instance('config', $configuration);
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
        $this->assertFalse($callbackInvoked, 'Raw admission cannot resolve a configuration subclass or custom PDOStatement callback.');
        $this->assertSame($kind === 'valid' ? 'claimed' : 'pending', DB::table('paid_document_work')->sole()->state);
        $this->assertDatabaseCount('paid_order_origins', 1);
        $this->assertDatabaseCount('paid_grant_origins', 1);
        $this->assertDatabaseCount('paid_originals', 0);
        $this->assertDatabaseCount('paid_fulfillments', 0);
    }
}

final class PaidAdmissionCallbackConfiguration extends Repository
{
    public function __construct(array $items, private bool &$invoked)
    {
        parent::__construct($items);
    }

    public function get($key, $default = null)
    {
        $this->invoked = true;

        return parent::get($key, $default);
    }
}

final class PaidAdmissionCallbackStatement extends \PDOStatement
{
    protected function __construct(private bool &$invoked)
    {
        $this->invoked = true;
    }
}

final class PaidAdmissionCallbackParent extends \ArrayObject
{
    public function __construct(array $values, private bool &$invoked)
    {
        parent::__construct($values);
    }

    public function offsetExists(mixed $key): bool
    {
        $this->invoked = true;

        return parent::offsetExists($key);
    }

    public function offsetGet(mixed $key): mixed
    {
        $this->invoked = true;

        return parent::offsetGet($key);
    }
}
