<?php

namespace Tests\Feature;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\Paid\PaidGrantCommands;
use App\Domain\Grants\Paid\PaidGrantDeadline;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantDownloads;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantPolicy;
use App\Domain\Grants\Paid\PaidGrantPrepareStream;
use App\Domain\Grants\Paid\PaidGrants;
use App\Domain\Grants\Paid\PaidGrantTransfer;
use Closure;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\PaidGrantMonotonicClock;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;
use Throwable;

// Loaded with this file, before any paid code runs in the process (see PaidGrantMonotonicClock).
class_exists(PaidGrantMonotonicClock::class);

/**
 * Codex P2 (comment 4222562053): snapshot preparation has its own deadline. Redeeming runs three budgets: the 60 s
 * observation budget for locating and the first frame, `paid-grants.snapshot_seconds` (default 300, 30 to 1800) for the
 * private snapshot, and a fresh 60 s budget for the commit frame and the before-first-byte proof. Time is spent on a
 * virtual paid-namespace monotonic clock (Tests\Support\PaidGrantMonotonicClock); no test sleeps. Time is spent when an
 * owned paid frame (`PaidGrantCommands::run`) commits: 1 the first frame, 2 the commit frame. Synthetic rehearsal fixtures
 * only; no live payment or legal facts are certified.
 */
final class PaidGrantSnapshotDeadlineTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidGrantDependencyFixtures;
    use ProductionCheckoutJourneyFixture;

    private const FIRST_FRAME = 1;

    private const COMMIT_FRAME = 2;

    /** @var list<int> */
    private array $commits = [];

    protected function beforeRefreshingDatabase(): void
    {
        $this->preparePaidDependencies();
    }

    protected function setUp(): void
    {
        parent::setUp();
        PaidGrantMonotonicClock::reset();
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
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60],
            // A long transfer allowance, so a 410 before the first byte can only come from the observation budget.
            'paid-grants.transfer_base_seconds' => 600,
            'paid-grants.snapshot_seconds' => 300]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        PaidGrantMonotonicClock::reset();
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

    public function test_a_snapshot_started_late_in_the_observation_budget_gets_its_own_bound(): void
    {
        $this->proveClockSeam();
        $f = $this->complete();
        $auth = $this->authorize($f);
        // The first frame leaves 5 s of the 60 s observation budget; the snapshot then takes 120 s, past both the
        // observation budget and the stream's former fixed 60 s cap, inside the 300 s snapshot bound.
        $this->spendAtCommit([self::FIRST_FRAME => 55]);
        $snapshot = $this->slowSnapshot(120);

        $transfer = $this->redeem($f, $auth);

        $this->assertSame([1, 2], $this->commits, 'The first frame and the commit frame each committed once.');
        $this->assertSame(1, $snapshot->calls);
        $this->assertSame($this->masterBytes($f), $this->drain($transfer));
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_a_snapshot_exceeding_snapshot_seconds_is_refused_with_nothing_recorded_and_the_authorization_stays_usable(): void
    {
        $f = $this->complete();
        $recorded = 0;
        foreach ([[30, 31], [300, 301]] as [$bound, $taken]) {
            config(['paid-grants.snapshot_seconds' => $bound]);
            $auth = $this->authorize($f);
            $this->slowSnapshot($taken);
            $error = $this->refusal(fn () => $this->redeem($f, $auth));
            $this->assertInstanceOf(DeliveryException::class, $error, $bound.' s snapshot bound');
            $this->assertSame('target_unavailable', $error->reason);
            $this->assertDatabaseCount('paid_redemptions', $recorded);

            // Nothing was recorded, so the same authorization still redeems inside its lifetime.
            app()->forgetInstance(PaidGrantPrepareStream::class);
            $this->assertSame($this->masterBytes($f), $this->drain($this->redeem($f, $auth)));
            $this->assertDatabaseCount('paid_redemptions', ++$recorded);
        }
        // A snapshot that fits the minimum bound is admitted.
        config(['paid-grants.snapshot_seconds' => 30]);
        $auth = $this->authorize($f);
        $this->slowSnapshot(29);
        $this->assertSame($this->masterBytes($f), $this->drain($this->redeem($f, $auth)));
        $this->assertDatabaseCount('paid_redemptions', 3);
    }

    public function test_the_commit_frame_and_first_byte_get_a_fresh_budget_after_a_long_first_frame_and_snapshot(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        // 40 s first frame + 40 s snapshot = 80 s, past the 60 s observation budget. The commit frame then takes 50 s and
        // the first byte follows 5 s later: 55 s of the fresh 60 s budget.
        $this->spendAtCommit([self::FIRST_FRAME => 40, self::COMMIT_FRAME => 50]);
        $this->slowSnapshot(40);

        $transfer = $this->redeem($f, $auth);
        $this->assertSame([1, 2], $this->commits);
        $this->assertDatabaseCount('paid_redemptions', 1);
        PaidGrantMonotonicClock::advance(5);
        $this->assertSame($this->masterBytes($f), $this->drain($transfer));
        $this->assertDatabaseCount('paid_redemptions', 1);
        // The attempt is consumed: the same authorization cannot redeem again.
        app()->forgetInstance(PaidGrantPrepareStream::class);
        $this->assertSame(409, $this->refusedStatus(fn () => $this->redeem($f, $auth)));
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_the_fresh_commit_budget_is_still_bounded_before_the_first_byte(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        $this->spendAtCommit([self::FIRST_FRAME => 40]);
        $this->slowSnapshot(40);
        $transfer = $this->redeem($f, $auth);
        // The transfer allowance (600 s + size) is far away; only the fresh 60 s commit-frame budget can refuse here.
        PaidGrantMonotonicClock::advance(61);
        $bytes = '';
        $this->assertSame(410, $this->refusedStatus(fn () => $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        })));
        $this->assertSame('', $bytes, 'No byte leaves once the commit-frame budget has lapsed.');
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_a_commit_frame_overrunning_its_own_budget_still_fails_the_unchanged_post_commit_proof(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        // Unchanged post-commit proof: a commit frame that itself takes longer than its 60 s budget is refused after commit.
        $this->spendAtCommit([self::COMMIT_FRAME => 61]);
        $this->assertSame(410, $this->refusedStatus(fn () => $this->redeem($f, $auth)));
        $this->assertSame([1, 2], $this->commits);
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_out_of_range_snapshot_seconds_refuses_with_503_before_anything_is_recorded(): void
    {
        $shipped = require base_path('config/paid-grants.php');
        $this->assertSame(300, $shipped['snapshot_seconds']);
        $this->assertNull($shipped['delivery_policy']);
        $policy = app(PaidGrantPolicy::class);
        foreach ([30, 300, 1800] as $value) {
            config(['paid-grants.snapshot_seconds' => $value]);
            // The retained delivery policy is unchanged: snapshot_seconds is top-level configuration only.
            $this->assertSame(config('paid-grants.delivery_policy'), $policy->capture());
            $this->assertArrayNotHasKey('snapshot_seconds', $policy->capture());
            $this->assertSame($value, $policy->snapshotSeconds());
        }
        $f = $this->complete();
        config(['paid-grants.snapshot_seconds' => 300]);
        $auth = $this->authorize($f);
        foreach ([29, 1801, '300', null] as $value) {
            config(['paid-grants.snapshot_seconds' => $value]);
            $this->assertSame(503, $this->refusedStatus(fn () => $policy->capture()), var_export($value, true));
            $this->assertSame(503, $this->refusedStatus(fn () => $policy->snapshotSeconds()), var_export($value, true));
            $this->assertSame(503, $this->refusedStatus(fn () => $this->authorize($f)), var_export($value, true));
            $this->assertSame(503, $this->refusedStatus(fn () => $this->redeem($f, $auth)), var_export($value, true));
            $this->assertDatabaseCount('paid_authorizations', 1);
            $this->assertDatabaseCount('paid_redemptions', 0);
        }
        // Restoring a valid bound leaves the unspent authorization usable.
        config(['paid-grants.snapshot_seconds' => 300]);
        $this->assertSame($this->masterBytes($f), $this->drain($this->redeem($f, $auth)));
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    /** The virtual clock must reach paid code, or every scenario above would pass for the wrong reason. */
    private function proveClockSeam(): void
    {
        PaidGrantMonotonicClock::advance(1000);
        try {
            $this->assertGreaterThan(\hrtime(true) + 900_000_000_000, PaidGrantDeadline::start(1)->value(), 'The paid-namespace clock seam is not live.');
        } finally {
            PaidGrantMonotonicClock::reset();
        }
    }

    /**
     * @param  array<int, int>  $seconds  owned paid frame ordinal (counted from now) => seconds spent before its commit
     *                                    returns. Only outermost commits made inside `PaidGrantCommands::run` count; the
     *                                    customer-access and locate transactions around them do not.
     */
    private function spendAtCommit(array $seconds): void
    {
        $this->commits = [];
        Event::listen(TransactionCommitted::class, function () use ($seconds): void {
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $owned = array_filter($frames, fn (array $frame): bool => ($frame['class'] ?? null) === PaidGrantCommands::class && $frame['function'] === 'run');
            $access = array_filter($frames, fn (array $frame): bool => ($frame['class'] ?? null) === ProductionCustomerAccess::class);
            if (DB::transactionLevel() !== 0 || $owned === [] || $access !== []) {
                return;
            }
            $ordinal = count($this->commits) + 1;
            $this->commits[] = $ordinal;
            if (isset($seconds[$ordinal])) {
                PaidGrantMonotonicClock::advance($seconds[$ordinal]);
            }
        });
    }

    /** The private snapshot copy takes this many virtual seconds, checked by the stream's own deadline right after. */
    private function slowSnapshot(int $seconds): object
    {
        $stream = new class($seconds) extends PaidGrantPrepareStream
        {
            public int $calls = 0;

            public function __construct(private readonly int $seconds) {}

            protected function copyTarget(array $target, $destination, int $deadline): void
            {
                $this->calls++;
                parent::copyTarget($target, $destination, $deadline);
                PaidGrantMonotonicClock::advance($this->seconds);
            }
        };
        app()->instance(PaidGrantPrepareStream::class, $stream);

        return $stream;
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

    private function authorize(array $f): array
    {
        return (new PaidGrantDownloads)->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'],
            ['requestKey' => (string) Str::uuid(), 'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => 'master_wav', 'nonce' => bin2hex(random_bytes(32))],
            $f['buyer']['principal'], $f['buyer']['user']);
    }

    private function redeem(array $f, array $auth): PaidGrantTransfer
    {
        return (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
    }

    private function drain(PaidGrantTransfer $transfer): string
    {
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });

        return $bytes;
    }

    private function masterBytes(array $f): string
    {
        return file_get_contents(Storage::disk('local')->path($f['catalog']['media']['master_wav']->storage_path));
    }

    private function refusal(Closure $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $error) {
            return $error;
        }
        $this->fail('Expected a refusal.');
    }

    private function refusedStatus(Closure $call): int|string
    {
        $error = $this->refusal($call);

        return $error instanceof PaidGrantException ? $error->status : $error::class;
    }
}
