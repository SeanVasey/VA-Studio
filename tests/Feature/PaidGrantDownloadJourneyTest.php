<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Commerce\ProductionCheckout\TaxExemptions;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\CompleteIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantDownloads;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantPrepareStream;
use App\Domain\Grants\Paid\PaidGrantReads;
use App\Domain\Grants\Paid\PaidGrantRecords;
use App\Domain\Grants\Paid\PaidGrants;
use App\Providers\ProductionCheckoutServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutFixtures;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Actual SMTP-enrolled buyer + frozen synthetic paid source. No live payment/legal facts are certified. */
final class PaidGrantDownloadJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    private const DEPENDENCY = '/workspace/.va-studio-dependencies/paid/2ec1854-e8f7441';

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

    private function retained(bool $twoLines = false): array
    {
        if (! $twoLines) {
            $f = $this->payable();
        } else {
            $catalog = ProductionCheckoutFixtures::catalog();
            $second = QuoteFixtures::selection(3499);
            $items = [...$catalog['items'], ...$second['items']];
            $buyer = $this->enrollThroughLocalSmtp();
            $access = new ProductionCustomerAccess;
            $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, ProductionCheckoutFixtures::exemptionPolicy($catalog), 'synthetic-two-lines-policy', $catalog['actor']);
            $basis = (new TaxExemptions($access))->qualify($buyer['principal'], $buyer['user'], $authority['public_id'], $items,
                ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:two-lines-buyer', 'source_sha256' => hash('sha256', 'NONBINDING TWO-LINE QUALIFICATION'),
                    'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                    'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')], 'synthetic-two-lines-qualification', $catalog['actor']);
            $checkout = new ProductionCheckout($access);
            $review = $checkout->review($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $items, $basis['public_id'], ['legalName' => 'Declared synthetic buyer'], 'synthetic-two-lines-review');
            $order = $checkout->accept($buyer['principal'], $buyer['user'], $review['reviewId'], $review['reviewHash'], true, 'synthetic-two-lines-accept');
            $gateway = new ProductionCheckoutGatewayFixture;
            $hosted = new HostedCheckout($access, $gateway);
            $f = compact('catalog', 'buyer', 'order', 'gateway', 'hosted', 'second');
        }
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $f['batch'] = (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);

        return $f;
    }

    private function complete(): array
    {
        $f = $this->retained();
        $f['batch'] = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);

        return $f;
    }

    private function authorize(array $f, string $kind = 'master_wav'): array
    {
        return (new PaidGrantDownloads)->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'],
            ['requestKey' => (string) Str::uuid(), 'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => $kind, 'nonce' => bin2hex(random_bytes(32))],
            $f['buyer']['principal'], $f['buyer']['user']);
    }

    public function test_exact_paid_master_and_original_pdf_are_one_attempt_each_with_token_free_bounded_status_and_consumed_replay_refusal(): void
    {
        $f = $this->complete();
        $downloads = new PaidGrantDownloads;
        $original = (array) DB::table('paid_originals')->sole();
        foreach (['master_wav', 'contract'] as $kind) {
            $input = ['requestKey' => (string) Str::uuid(), 'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => $kind, 'nonce' => bin2hex(random_bytes(32))];
            $auth = $downloads->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'], $input, $f['buyer']['principal'], $f['buyer']['user']);
            $this->assertSame($auth, $downloads->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'], $input, $f['buyer']['principal'], $f['buyer']['user']));
            $path = $kind === 'master_wav' ? $f['catalog']['media']['master_wav']->storage_path : PaidGrantRecords::decode($original)['artifact']['storage_path'];
            $expected = file_get_contents(Storage::disk('local')->path($path));
            $transfer = $downloads->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
            $bytes = '';
            $transfer->writeTo(function (string $chunk) use (&$bytes): void {
                $bytes .= $chunk;
            });
            $this->assertSame($expected, $bytes);
            $this->assertSame(hash('sha256', $expected), $transfer->sha256);
            $this->assertSame(strlen($expected), $transfer->sizeBytes);
            try {
                $downloads->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
                $this->fail('A spent authorization cannot consume another attempt.');
            } catch (PaidGrantException $error) {
                $this->assertSame(409, $error->status);
            }
            $status = $downloads->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->assertStringNotContainsString($auth['token'], json_encode($status, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString($path, json_encode($status, JSON_THROW_ON_ERROR));
        }
        $this->assertSame(2, $status['lines'][0]['attemptCount']);
        $this->assertCount(2, $status['lines'][0]['history']);
        $this->assertSame(['attempted', 'attempted'], array_column($status['lines'][0]['history'], 'status'));
        $this->assertSame($original, (array) DB::table('paid_originals')->sole());
        $this->assertDatabaseCount('paid_redemptions', 2);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_actual_mailbox_recovery_keeps_original_paid_origin_and_pdf_and_allows_only_fresh_same_account_download(): void
    {
        $f = $this->complete();
        $downloads = new PaidGrantDownloads;
        $authorization = $this->authorize($f);
        $original = (array) DB::table('paid_originals')->sole();
        $batch = (array) DB::table('paid_order_origins')->sole();
        $line = (array) DB::table('paid_grant_origins')->sole();
        $artifact = PaidGrantRecords::decode($original)['artifact'];
        $pdf = file_get_contents(Storage::disk('local')->path($artifact['storage_path']));
        $expected = file_get_contents(Storage::disk('local')->path($f['catalog']['media']['master_wav']->storage_path));
        $replacement = 'RecoveredPaidMailboxPassword123';
        $this->requestIdentity('recover', $f['buyer']['user']->email);
        $id = (int) DB::table('production_identity_notices')->orderByDesc('id')->value('id');
        $received = $this->smtp('accept', $id);
        $this->assertSame(1, preg_match('~http://localhost/customer/access#recover\.([a-f0-9-]{36})\.([a-f0-9]{64})~', $received['data'], $match));
        (new CompleteIdentity)->complete($match[1], $match[2], $replacement, 'Recovered declared buyer', str_repeat('e', 64));
        try {
            $downloads->redeem($authorization['id'], $authorization['token'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->fail('The pre-recovery principal retained paid download authority.');
        } catch (PaidGrantException $error) {
            $this->assertSame(403, $error->status);
            $this->assertDatabaseCount('paid_redemptions', 0);
        }
        $current = (new ProductionCustomerSessions)->authenticate($f['buyer']['user']->email, $replacement);
        $this->assertNotNull($current);
        $this->assertSame($f['buyer']['principal']->accountId, $current['principal']->accountId);
        $this->assertSame($f['batch'], (new PaidGrantReads)->show($f['batch']['id'], $current['principal'], $current['user']));
        $transfer = $downloads->redeem($authorization['id'], $authorization['token'], $current['principal'], $current['user']);
        $bytes = '';
        $transfer->writeTo(static function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });
        $this->assertSame($expected, $bytes);
        $this->assertSame($original, (array) DB::table('paid_originals')->sole());
        $this->assertSame($batch, (array) DB::table('paid_order_origins')->sole());
        $this->assertSame($line, (array) DB::table('paid_grant_origins')->sole());
        $this->assertSame($pdf, file_get_contents(Storage::disk('local')->path($artifact['storage_path'])));
        $this->assertDatabaseCount('paid_redemptions', 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_actual_source_copy_withdrawal_cannot_consume_attempt_or_return_private_bytes(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        $fired = false;
        app()->instance(PaidGrantPrepareStream::class, new class($f['buyer']['user']->id, $fired) extends PaidGrantPrepareStream
        {
            public function __construct(private readonly int $userId, private bool &$fired) {}

            protected function copyTarget(array $target, $destination, int $deadline): void
            {
                parent::copyTarget($target, $destination, $deadline);
                $this->fired = true;
                DB::table('users')->where('id', $this->userId)->update(['password' => Hash::make('Credential withdrawn while exact paid master copied')]);
            }
        });
        try {
            (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->fail('A verified descriptor does not renew withdrawn owner authority.');
        } catch (PaidGrantException $error) {
            $this->assertTrue($fired);
            $this->assertSame(403, $error->status);
        }
        $this->assertDatabaseCount('paid_redemptions', 0);
        $this->assertDatabaseCount('paid_originals', 1);
        $this->assertDatabaseCount('paid_fulfillments', 1);
    }

    public function test_actual_final_commit_policy_withdrawal_refuses_stream_and_retains_truthful_consumed_attempt(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        $fired = false;
        Event::listen(TransactionCommitted::class, function () use (&$fired): void {
            if (! $fired && DB::table('paid_redemptions')->count() === 1) {
                $fired = true;
                config(['paid-grants.rehearsal_enabled' => false]);
            }
        });
        try {
            (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->fail('A final commit callback cannot release a private stream after policy withdrawal.');
        } catch (PaidGrantException $error) {
            $this->assertTrue($fired);
            $this->assertSame(403, $error->status);
        }
        $this->assertDatabaseCount('paid_redemptions', 1);
        $this->assertDatabaseCount('paid_originals', 1);
    }

    public function test_held_transfer_rechecks_original_owner_before_first_byte_without_new_transaction_or_principal(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        $transfer = (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
        DB::table('users')->where('id', $f['buyer']['user']->id)->update(['password' => Hash::make('Credential withdrawn before paid response body')]);
        $bytes = '';
        try {
            $transfer->writeTo(function (string $chunk) use (&$bytes): void {
                $bytes .= $chunk;
            });
            $this->fail('The original response proof cannot renew the changed owner before bytes.');
        } catch (PaidGrantException $error) {
            $this->assertSame(403, $error->status);
        }
        $this->assertSame('', $bytes);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertDatabaseCount('paid_redemptions', 1);
    }
}
