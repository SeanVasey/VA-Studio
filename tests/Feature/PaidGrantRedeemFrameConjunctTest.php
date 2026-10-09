<?php

namespace Tests\Feature;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Http\Middleware\PaidGrantPrivacy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantCommitFrameProbe;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * Bounded instrumentation of the native HTTP master redemption 409 (`committed_read_frame`).
 *
 * The probe is test-only and read-only. It evaluates each conjunct of the producer's
 * OriginalCommitDispatcher::requireCurrent separately while the redemption's second command mints and
 * closes its two sibling receipts, and records the redemption's position inside the original
 * authorization lifetime. No deadline, authorization or policy is changed.
 */
final class PaidGrantRedeemFrameConjunctTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidGrantDependencyFixtures;
    use ProductionCheckoutJourneyFixture;

    private const GUARDED_FRAMES = ['historicalReceipt', 'requireCurrent', 'register', 'rows', 'requireClosed'];

    protected function beforeRefreshingDatabase(): void
    {
        $this->preparePaidDependencies();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->app->make(Kernel::class)->prependMiddleware(PaidGrantPrivacy::class);
        $this->app->make(ExceptionHandler::class)->respondUsing(static function ($response, $error, $request) {
            return PaidGrantPrivacy::matches($request) ? PaidGrantPrivacy::error($response->getStatusCode()) : $response;
        });
        Route::middleware('web')->group(base_path('routes/paid-grants.php'));
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

    public function test_master_redemption_second_command_keeps_each_commit_observer_conjunct_within_the_original_authorization(): void
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $this->actingAs($f['buyer']['user'], 'customer')->withSession(['_token' => str_repeat('c', 40),
            '_production_customer_identity' => ['binding_digest' => $f['buyer']['principal']->sessionBindingDigest()]]);
        $probe = new PaidGrantCommitFrameProbe;
        $status = null;
        $auth = null;
        $bytes = '';
        $probe->start();
        try {
            $probe->mark('before_finalize');
            $origin = $this->call('POST', '/paid-grants/orders/'.$f['order']['orderId'].'/finalize', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk()->json('origin');
            $probe->mark('after_finalize');
            $this->call('POST', '/paid-grants/origins/'.$origin['id'].'/document', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk()->assertJsonPath('origin.fulfilled', true);
            $probe->mark('after_document');
            $auth = $this->postJson('/paid-grants/origins/'.$origin['id'].'/lines/'.$origin['lines'][0]['id'].'/authorize',
                ['requestKey' => (string) Str::uuid(), 'originHash' => $origin['lines'][0]['originHash'], 'kind' => 'master_wav', 'nonce' => bin2hex(random_bytes(32))])
                ->assertOk()->json('authorization');
            $probe->mark('authorization_returned');
            $raw = http_build_query(['token' => $auth['token'], '_token' => str_repeat('c', 40)], '', '&', PHP_QUERY_RFC3986);
            parse_str($raw, $form);
            $response = $this->call('POST', '/paid-grants/authorizations/'.$auth['id'].'/redeem', $form, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $raw);
            $probe->mark('redeem_returned');
            $status = $response->getStatusCode();
            $bytes = $status === 200 ? $response->streamedContent() : '';
            $probe->mark('bytes_written');
        } finally {
            $probe->stop();
            if (is_string(getenv('VA_PAID_FRAME_TRACE')) && getenv('VA_PAID_FRAME_TRACE') !== '') {
                file_put_contents(getenv('VA_PAID_FRAME_TRACE'), json_encode(['status' => $status, 'expires_at' => $auth['expiresAt'] ?? null,
                    'now_utc' => gmdate('Y-m-d H:i:s'), 'marks' => $probe->marks, 'events' => $probe->events,
                    'samples' => array_slice($probe->observerSamples(), -400)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
        }
        $expires = CarbonImmutable::parse($auth['expiresAt'], 'UTC');
        $remainingAfterRedeem = CarbonImmutable::now('UTC')->floatDiffInSeconds($expires, false);
        $guarded = array_values(array_filter($probe->observerSamples(), fn (array $s): bool => in_array($s['frame'], self::GUARDED_FRAMES, true)));
        // Each conjunct is asserted on its own so a native refusal names the failing one.
        foreach ($guarded as $sample) {
            $this->assertTrue($sample['phase_ok'], 'phase conjunct: observer#'.$sample['observer'].' was invalid in '.$sample['frame']);
        }
        foreach ($guarded as $sample) {
            $this->assertTrue($sample['dispatcher_ok'], 'dispatcher conjunct: '.$sample['current_dispatcher'].' replaced observer#'.$sample['observer'].' in '.$sample['frame']);
        }
        foreach ($guarded as $sample) {
            $this->assertTrue($sample['deadline_ok'], 'deadline conjunct: observer#'.$sample['observer'].' was '.round(-$sample['remaining_ns'] / 1e9, 3).'s past its original deadline in '.$sample['frame']);
        }
        $this->assertSame(200, $status, 'redemption refused '.round($remainingAfterRedeem, 3).'s relative to the original authorization expiry');
        $this->assertSame(file_get_contents(Storage::disk('local')->path($f['catalog']['media']['master_wav']->storage_path)), $bytes);
        $this->assertGreaterThan(0, $remainingAfterRedeem, 'redemption must complete inside the original authorization lifetime');
    }
}
