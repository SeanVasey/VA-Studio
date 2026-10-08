<?php

namespace Tests\Feature;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Grants\Paid\PaidGrantDeadline;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantDownloads;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantPolicy;
use App\Domain\Grants\Paid\PaidGrantPrepareStream;
use App\Domain\Grants\Paid\PaidGrantProjectionRead;
use App\Domain\Grants\Paid\PaidGrantReads;
use App\Domain\Grants\Paid\PaidGrantRecords;
use App\Domain\Grants\Paid\PaidGrants;
use App\Domain\Grants\Paid\PaidGrantTransfer;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionProperty;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;
use Throwable;

/**
 * Independent review addendum 1: the size-derived transfer deadline and valid-to-start lifetime (cae5eeae) and the
 * retained delivery policy (f643e944).
 * Synthetic rehearsal fixtures only; no live payment or legal facts are certified.
 */
final class PaidGrantReviewAddendum1Test extends TestCase
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

    private function refusedStatus(callable $call): int|string
    {
        try {
            $call();
        } catch (PaidGrantException $error) {
            return $error->status;
        } catch (Throwable $error) {
            return $error::class;
        }
        $this->fail('Expected a refusal.');
    }

    private function snapshotHook(Closure $after): void
    {
        app()->instance(PaidGrantPrepareStream::class, new class($after) extends PaidGrantPrepareStream
        {
            public function __construct(private readonly Closure $after) {}

            protected function copyTarget(array $target, $destination, int $deadline): void
            {
                parent::copyTarget($target, $destination, $deadline);
                ($this->after)();
            }
        });
    }

    public function test_the_observation_budget_still_bounds_the_first_byte_when_the_transfer_deadline_is_far_away(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        $transfer = (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertDatabaseCount('paid_redemptions', 1);
        $transferDeadline = (new ReflectionProperty(PaidGrantTransfer::class, 'deadline'))->getValue($transfer);
        $this->assertGreaterThan(hrtime(true) + 25_000_000_000, $transferDeadline, 'The size-derived transfer deadline is far away.');
        // Lapse only the original 60 s observation budget held by the closed read (no clock injection exists for it).
        $read = (new ReflectionProperty(PaidGrantTransfer::class, 'closedRead'))->getValue($transfer);
        $budget = (new ReflectionProperty(PaidGrantProjectionRead::class, 'deadline'))->getValue($read);
        (new ReflectionProperty(PaidGrantDeadline::class, 'deadline'))->setValue($budget, hrtime(true) - 1);
        $bytes = '';
        $status = $this->refusedStatus(fn () => $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        }));
        $this->assertSame(410, $status);
        $this->assertSame('', $bytes, 'No byte may leave once the pre-byte observation budget has lapsed.');
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_the_transfer_deadline_is_derived_only_from_the_server_snapshot_size_and_policy(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        $before = hrtime(true);
        $transfer = (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
        $after = hrtime(true);
        $expected = file_get_contents(Storage::disk('local')->path($f['catalog']['media']['master_wav']->storage_path));
        $this->assertSame(strlen($expected), $transfer->sizeBytes);
        $seconds = app(PaidGrantPolicy::class)->transferSeconds($transfer->sizeBytes);
        $deadline = (new ReflectionProperty(PaidGrantTransfer::class, 'deadline'))->getValue($transfer);
        $this->assertGreaterThanOrEqual($before + $seconds * 1_000_000_000, $deadline);
        $this->assertLessThanOrEqual($after + $seconds * 1_000_000_000, $deadline);
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });
        $this->assertSame($expected, $bytes);
    }

    public function test_a_failed_redemption_admitted_before_expiry_cannot_be_restarted_after_expiry_and_records_nothing(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        $expires = CarbonImmutable::parse($auth['expiresAt'], 'UTC');
        // Admitted inside the lifetime; the snapshot then crosses the expiry and fails.
        $this->snapshotHook(function () use ($expires): void {
            $this->travelTo($expires->addSeconds(2));
            throw new \RuntimeException('SYNTHETIC SNAPSHOT FAILURE');
        });
        $this->assertNotSame(200, $this->refusedStatus(fn () => (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user'])));
        $this->assertDatabaseCount('paid_redemptions', 0);
        app()->forgetInstance(PaidGrantPrepareStream::class);
        // A new redemption call takes its own admitted instant, which is now past the expiry.
        $this->assertSame(410, $this->refusedStatus(fn () => (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user'])));
        $this->assertDatabaseCount('paid_redemptions', 0);
    }

    public function test_a_transfer_policy_that_becomes_invalid_during_the_snapshot_refuses_before_the_attempt_is_recorded(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        $this->snapshotHook(function (): void {
            config(['paid-grants.transfer_min_bytes_per_second' => 1]);
        });
        $this->assertSame(503, $this->refusedStatus(fn () => (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user'])));
        $this->assertDatabaseCount('paid_redemptions', 0);
        // Restoring a valid policy leaves the unspent authorization usable inside its lifetime.
        config(['paid-grants.transfer_min_bytes_per_second' => 262144]);
        app()->forgetInstance(PaidGrantPrepareStream::class);
        $transfer = (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });
        $this->assertSame(file_get_contents(Storage::disk('local')->path($f['catalog']['media']['master_wav']->storage_path)), $bytes);
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_a_downward_policy_revision_keeps_the_retained_limit_lifetime_and_idempotent_replay_and_refinalize_does_not_reseal(): void
    {
        $f = $this->complete();
        $v1 = config('paid-grants.delivery_policy');
        $input = ['requestKey' => (string) Str::uuid(), 'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => 'master_wav', 'nonce' => bin2hex(random_bytes(32))];
        $first = $this->authorize($f, $input);
        // The operator lowers the limit and the lifetime for future orders.
        config(['paid-grants.delivery_policy' => ['version' => 'explicit-synthetic-delivery-v2', 'max_downloads' => 1, 'authorization_seconds' => 30] + $v1]);
        // An authorize replayed across the revision is the same row: same id, token and expiry (retained 60 s, not 30 s).
        $this->assertSame($first, $this->authorize($f, $input));
        $this->assertDatabaseCount('paid_authorizations', 1);
        $row = DB::table('paid_authorizations')->where('public_id', $first['id'])->first();
        // Retained 60 s, counted from the end of the authorize budget (Codex 4223825193).
        $this->assertSame(60 + PaidGrantDownloads::AUTHORIZE_BUDGET_SECONDS, (int) CarbonImmutable::parse($row->created_at, 'UTC')->diffInSeconds(CarbonImmutable::parse($row->expires_at, 'UTC')));
        $master = file_get_contents(Storage::disk('local')->path($f['catalog']['media']['master_wav']->storage_path));
        $this->assertSame($master, $this->stream($f, $first));
        // The retained limit (3) still governs, not the revised one (1); the fourth issuance is refused by the retained limit.
        $this->assertSame($master, $this->stream($f, $this->authorize($f)));
        $this->assertSame($master, $this->stream($f, $this->authorize($f)));
        $this->assertSame(409, $this->refusedStatus(fn () => $this->authorize($f)));
        $status = (new PaidGrantDownloads)->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertSame([3, 3], [$status['lines'][0]['attemptCount'], $status['lines'][0]['maxDownloads']]);
        $this->assertDatabaseCount('paid_redemptions', 3);
        // Re-finalizing the same order under the revised policy returns the sealed batch; nothing is re-sealed.
        $again = (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame($f['batch']['id'], $again['id']);
        $this->assertSame(CanonicalJson::encode($v1), CanonicalJson::encode($this->retainedPolicy($f)));
        $this->assertDatabaseCount('paid_order_origins', 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_a_current_policy_of_the_other_provenance_refuses_every_retained_path_without_recording_and_restoring_it_recovers(): void
    {
        $f = $this->complete();
        $v1 = config('paid-grants.delivery_policy');
        $auth = $this->authorize($f);
        // A production-provenance policy authored while rehearsal orders exist (the testing environment keeps rehearsal enablement).
        config(['paid-grants.delivery_policy' => ['version' => 'verified-production-delivery-v1', 'provenance' => 'verified_production'] + $v1]);
        $p = $f['buyer']['principal'];
        $u = $f['buyer']['user'];
        $refusals = [
            'index' => $this->refusedStatus(fn () => (new PaidGrantReads)->index($p, $u)),
            'show' => $this->refusedStatus(fn () => (new PaidGrantReads)->show($f['batch']['id'], $p, $u)),
            'status' => $this->refusedStatus(fn () => (new PaidGrantDownloads)->status($f['batch']['id'], $p, $u)),
            'authorize' => $this->refusedStatus(fn () => $this->authorize($f)),
            'redeem' => $this->refusedStatus(fn () => (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $p, $u)),
            'refinalize' => $this->refusedStatus(fn () => (new PaidGrants)->finalize($p, $u, $f['order']['orderId'])),
        ];
        foreach ($refusals as $path => $status) {
            $this->assertContains($status, [403, 409], $path.' must refuse a rehearsal order under a production policy');
        }
        $this->assertDatabaseCount('paid_authorizations', 1);
        $this->assertDatabaseCount('paid_redemptions', 0);
        $this->assertDatabaseCount('paid_order_origins', 1);
        $this->assertSame(CanonicalJson::encode($v1), CanonicalJson::encode($this->retainedPolicy($f)));
        // Restoring the rehearsal policy: the untouched authorization still streams once.
        config(['paid-grants.delivery_policy' => $v1]);
        $this->assertSame(file_get_contents(Storage::disk('local')->path($f['catalog']['media']['master_wav']->storage_path)), $this->stream($f, $auth));
        $this->assertDatabaseCount('paid_redemptions', 1);
    }
}
