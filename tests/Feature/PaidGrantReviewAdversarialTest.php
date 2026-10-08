<?php

namespace Tests\Feature;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantDownloads;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantReads;
use App\Domain\Grants\Paid\PaidGrants;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * Independent-review adversarial cases for the Paid252 composition (authorization and entitlement edges).
 * Synthetic rehearsal fixtures only; no live payment or legal facts are certified.
 */
final class PaidGrantReviewAdversarialTest extends TestCase
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

    public function test_a_token_minted_for_one_authorization_is_refused_against_another_and_the_database_refuses_a_second_redemption_row(): void
    {
        $f = $this->complete();
        $downloads = new PaidGrantDownloads;
        $a = $this->authorize($f, 'master_wav');
        $b = $this->authorize($f, 'contract');
        $this->assertNotSame($a['token'], $b['token']);
        // Cross-authorization token: the right shape, the wrong HMAC input.
        $this->refused(fn () => $downloads->redeem($b['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user']), 403);
        $this->refused(fn () => $downloads->redeem($a['id'], $b['token'], $f['buyer']['principal'], $f['buyer']['user']), 403);
        // Malformed token shape and malformed authorization id refuse before any lookup.
        $this->refused(fn () => $downloads->redeem($a['id'], substr($a['token'], 0, 42), $f['buyer']['principal'], $f['buyer']['user']), 403);
        $this->refused(fn () => $downloads->redeem($a['id'], $a['token'].'=', $f['buyer']['principal'], $f['buyer']['user']), 403);
        $this->refused(fn () => $downloads->redeem(strtoupper($a['id']), $a['token'], $f['buyer']['principal'], $f['buyer']['user']), 422);
        $this->assertDatabaseCount('paid_redemptions', 0);
        // Neither authorization was harmed by the refused attempts.
        $this->assertSame($this->masterBytes($f), $this->stream($f, $a));
        $this->assertDatabaseCount('paid_redemptions', 1);
        $authorizationId = (int) DB::table('paid_authorizations')->where('public_id', $a['id'])->value('id');
        try {
            DB::table('paid_redemptions')->insert(['authorization_id' => $authorizationId, 'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')]);
            $this->fail('The schema must refuse a second redemption row for one authorization.');
        } catch (QueryException|PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('paid_redemptions', 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_another_enrolled_account_cannot_locate_redeem_or_read_a_valid_authorization_and_the_owner_still_can(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f, 'master_wav');
        $other = $this->enrollThroughLocalSmtp('review-other-account@example.test');
        $this->assertNotSame($f['buyer']['principal']->accountId, $other['principal']->accountId);
        $downloads = new PaidGrantDownloads;
        $this->refused(fn () => $downloads->redeem($auth['id'], $auth['token'], $other['principal'], $other['user']), 404);
        $this->refused(fn () => $downloads->status($f['batch']['id'], $other['principal'], $other['user']), 404);
        $this->refused(fn () => (new PaidGrantReads)->show($f['batch']['id'], $other['principal'], $other['user']), 404);
        $this->refused(fn () => $downloads->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'], $this->input($f, 'contract'), $other['principal'], $other['user']), 404);
        $this->assertSame([], (new PaidGrantReads)->index($other['principal'], $other['user'])['origins']);
        $this->assertDatabaseCount('paid_redemptions', 0);
        $this->assertDatabaseCount('paid_authorizations', 1);
        $this->assertSame($this->masterBytes($f), $this->stream($f, $auth));
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_expiry_refuses_without_consuming_and_a_spent_authorization_replayed_after_expiry_reports_expiry_before_consumption(): void
    {
        $f = $this->complete();
        $downloads = new PaidGrantDownloads;
        $expired = $this->authorize($f, 'master_wav');
        $lifetime = config('paid-grants.delivery_policy.authorization_seconds');
        $this->travelTo(CarbonImmutable::now('UTC')->addSeconds($lifetime + 2));
        // Unspent but expired: 410, nothing consumed, and the idempotent authorize replay is also refused.
        $this->refused(fn () => $downloads->redeem($expired['id'], $expired['token'], $f['buyer']['principal'], $f['buyer']['user']), 410);
        $this->assertDatabaseCount('paid_redemptions', 0);
        $this->travelBack();

        $spent = $this->authorize($f, 'master_wav');
        $this->assertSame($this->masterBytes($f), $this->stream($f, $spent));
        $this->assertDatabaseCount('paid_redemptions', 1);
        // Inside the lifetime a spent replay is reported as consumed (409).
        $this->refused(fn () => $downloads->redeem($spent['id'], $spent['token'], $f['buyer']['principal'], $f['buyer']['user']), 409);
        $this->travelTo(CarbonImmutable::parse($spent['expiresAt'], 'UTC')->addSeconds(2));
        // After the lifetime the same spent replay is reported as expired (410): expiry precedes the consumed check.
        $this->refused(fn () => $downloads->redeem($spent['id'], $spent['token'], $f['buyer']['principal'], $f['buyer']['user']), 410);
        $this->travelBack();
        $this->assertDatabaseCount('paid_redemptions', 1);
        $status = $downloads->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertSame(1, $status['lines'][0]['attemptCount']);
        $this->assertSame(['attempted', 'unused'], array_column($status['lines'][0]['history'], 'status'));
    }

    public function test_tampered_authorize_inputs_refuse_and_the_download_budget_is_exhausted_at_max_downloads(): void
    {
        $f = $this->complete();
        $downloads = new PaidGrantDownloads;
        $input = $this->input($f, 'master_wav');
        $tampered = $input;
        $tampered['originHash'] = strrev($input['originHash']);
        $this->refused(fn () => $this->authorize($f, input: $tampered), 409);
        $this->refused(fn () => $this->authorize($f, input: ['kind' => 'master_wav'] + $input + ['extra' => 1]), 422);
        $this->refused(fn () => $this->authorize($f, input: ['kind' => 'preview_mp3'] + $input), 422);
        $first = $this->authorize($f, input: $input);
        // Same requestKey with a different body is not an idempotent replay.
        $this->refused(fn () => $this->authorize($f, input: ['kind' => 'contract'] + $input), 409);
        $this->refused(fn () => $this->authorize($f, input: ['nonce' => bin2hex(random_bytes(32))] + $input), 409);
        $this->assertDatabaseCount('paid_authorizations', 1);
        $this->assertSame($this->masterBytes($f), $this->stream($f, $first));
        for ($attempt = 2; $attempt <= 3; $attempt++) {
            $this->assertSame($this->masterBytes($f), $this->stream($f, $this->authorize($f, 'master_wav')));
        }
        $this->assertDatabaseCount('paid_redemptions', 3);
        // The fourth authorization is refused by the retained delivery policy, not by the client.
        $this->refused(fn () => $this->authorize($f, 'contract'), 409);
        $this->assertDatabaseCount('paid_authorizations', 3);
        $status = $downloads->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertSame(3, $status['lines'][0]['attemptCount']);
        $this->assertSame(3, $status['lines'][0]['maxDownloads']);
        $this->assertDatabaseCount('license_grants', 0);
    }
}
