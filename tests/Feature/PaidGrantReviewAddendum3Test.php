<?php

namespace Tests\Feature;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantDownloads;
use App\Domain\Grants\Paid\PaidGrantRecords;
use App\Domain\Grants\Paid\PaidGrants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * Independent review addendum 3 (9c19dabb): one held paid spool slot per buyer, exercised through redeem().
 * Synthetic rehearsal fixtures only; no live payment or legal facts are certified.
 */
final class PaidGrantReviewAddendum3Test extends TestCase
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

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
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

    private function complete(): array
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $f['batch'] = (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['batch'] = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($f['batch']['fulfilled']);

        return $f;
    }

    private function authorize(array $f, ?array $input = null): array
    {
        return (new PaidGrantDownloads)->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'],
            $input ?? ['requestKey' => (string) Str::uuid(), 'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => 'master_wav', 'nonce' => bin2hex(random_bytes(32))],
            $f['buyer']['principal'], $f['buyer']['user']);
    }

    private function stream(array $f, array $auth): string
    {
        $transfer = (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });

        return $bytes;
    }

    private function retainedPolicy(array $f): array
    {
        $rows = DB::table('paid_order_origins')->get()->map(fn ($row): array => (array) $row)->all();
        $this->assertCount(1, $rows);
        $this->assertSame($f['batch']['id'], $rows[0]['public_id']);

        return PaidGrantRecords::decode($rows[0])['delivery_policy'];
    }

    public function test_a_second_concurrent_redeem_by_the_same_buyer_is_refused_before_any_attempt_and_succeeds_after_the_first_closes(): void
    {
        $f = $this->complete();
        $kinds = ['master_wav', 'contract'];
        $auths = [];
        foreach ($kinds as $kind) {
            $auths[$kind] = $this->authorize($f, ['requestKey' => (string) Str::uuid(), 'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => $kind, 'nonce' => bin2hex(random_bytes(32))]);
        }
        $p = $f['buyer']['principal'];
        $u = $f['buyer']['user'];
        $first = (new PaidGrantDownloads)->redeem($auths['master_wav']['id'], $auths['master_wav']['token'], $p, $u);
        $this->assertDatabaseCount('paid_redemptions', 1);
        $holder = hash('sha256', 'paid-spool-holder-v1:'.DB::table('paid_order_origins')->value('account_id'));
        $spool = app(DeliveryAssetFiles::class)->privateRoot().'/delivery/paid-spool';
        $this->assertSame($holder, file_get_contents($spool.'/slot-0.holder'));
        // While the first stream is held (not yet written), the same buyer's second redeem is refused before its attempt.
        $refused = null;
        try {
            (new PaidGrantDownloads)->redeem($auths['contract']['id'], $auths['contract']['token'], $p, $u);
        } catch (DeliveryException $error) {
            $refused = $error->reason;
        }
        $this->assertSame('target_unavailable', $refused);
        $this->assertDatabaseCount('paid_redemptions', 1);
        $this->assertSame([], glob($spool.'/slot-*.snapshot'));
        // Refused before any sidecar of its candidate slot was created: nothing is reserved or recorded for it.
        $this->assertFileDoesNotExist($spool.'/slot-1.reserve');
        $this->assertFileDoesNotExist($spool.'/slot-1.holder');
        $status = (new PaidGrantDownloads)->status($f['batch']['id'], $p, $u);
        $this->assertSame(1, $status['lines'][0]['attemptCount']);
        $this->assertSame(['contract' => 'unused', 'master_wav' => 'attempted'], array_column($status['lines'][0]['history'], 'status', 'kind'));
        // In production the second request runs in another process. Here it committed frames in this process, which by design
        // invalidates the held transfer's pre-byte proof, so the first transfer is released by dropping it (its stream and
        // lease close in the destructor) instead of streaming it. Its consumed attempt stays recorded.
        unset($first);
        gc_collect_cycles();
        $this->assertTrue(flock($probe = fopen($spool.'/slot-0.lock', 'rb'), LOCK_EX | LOCK_NB), 'slot 0 is released');
        flock($probe, LOCK_UN);
        fclose($probe);
        $second = (new PaidGrantDownloads)->redeem($auths['contract']['id'], $auths['contract']['token'], $p, $u);
        $pdf = '';
        $second->writeTo(function (string $chunk) use (&$pdf): void {
            $pdf .= $chunk;
        });
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertDatabaseCount('paid_redemptions', 2);
        $this->assertDatabaseCount('license_grants', 0);
    }
}
