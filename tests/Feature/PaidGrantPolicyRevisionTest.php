<?php

namespace Tests\Feature;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantDownloads;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantPolicy;
use App\Domain\Grants\Paid\PaidGrantReads;
use App\Domain\Grants\Paid\PaidGrants;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * Codex P2 (PR #56): revising the configured delivery policy must not lock earlier buyers out. An order keeps the
 * policy it was finalized under; a well-formed retained policy of the current provenance is accepted, and its own
 * download limit and authorization lifetime still apply. Synthetic rehearsal fixtures only.
 */
final class PaidGrantPolicyRevisionTest extends TestCase
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

    private function input(array $f, string $kind): array
    {
        return ['requestKey' => (string) Str::uuid(), 'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => $kind, 'nonce' => bin2hex(random_bytes(32))];
    }

    private function authorize(array $f, string $kind = 'master_wav', ?array $input = null): array
    {
        return (new PaidGrantDownloads)->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'], $input ?? $this->input($f, $kind), $f['buyer']['principal'], $f['buyer']['user']);
    }

    private function refused(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('Expected a '.$status.' paid grant refusal.');
        } catch (PaidGrantException $error) {
            $this->assertSame($status, $error->status);
        }
    }

    private function masterBytes(array $f): string
    {
        return file_get_contents(Storage::disk('local')->path($f['catalog']['media']['master_wav']->storage_path));
    }

    private function stream(array $f, array $auth, ?array $buyer = null): string
    {
        $buyer ??= $f['buyer'];
        $transfer = (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $buyer['principal'], $buyer['user']);
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });

        return $bytes;
    }

    public function test_an_order_finalized_under_an_earlier_policy_stays_readable_and_downloadable_under_its_own_terms(): void
    {
        $f = $this->complete();
        $retained = config('paid-grants.delivery_policy');
        config(['paid-grants.delivery_policy' => ['version' => 'explicit-synthetic-delivery-v2', 'max_downloads' => 5, 'authorization_seconds' => 300] + $retained]);

        $shown = (new PaidGrantReads)->show($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertSame($f['batch']['id'], $shown['id']);
        $status = (new PaidGrantDownloads)->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertSame(3, $status['lines'][0]['maxDownloads']);
        $auth = $this->authorize($f);
        $row = DB::table('paid_authorizations')->where('public_id', $auth['id'])->first();
        $this->assertSame(60, (int) CarbonImmutable::parse($row->created_at, 'UTC')->diffInSeconds(CarbonImmutable::parse($row->expires_at, 'UTC')));
        $this->assertSame($this->masterBytes($f), $this->stream($f, $auth));
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_a_retained_policy_of_another_provenance_or_shape_is_refused(): void
    {
        $current = config('paid-grants.delivery_policy');
        PaidGrantPolicy::retained(['version' => 'explicit-synthetic-delivery-v0', 'authorization_seconds' => 30] + $current, $current);
        foreach ([['provenance' => 'verified_production'] + $current, ['authorization_seconds' => 601] + $current, ['max_downloads' => 0] + $current,
            ['purpose' => 'other'] + $current, ['version' => 'Bad Version'] + $current, ['extra' => true] + $current, array_values($current), 'policy'] as $retained) {
            try {
                PaidGrantPolicy::retained($retained, $current);
                $this->fail('A retained policy outside the current provenance or the policy shape must be refused.');
            } catch (PaidGrantException $error) {
                $this->assertContains($error->status, [409, 422]);
            }
        }
    }
}
