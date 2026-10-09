<?php

namespace Tests\Feature;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantDownloads;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrants;
use App\Domain\Grants\Paid\PaidGrantTransfer;
use Carbon\CarbonImmutable;
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

// Loaded with this file, before any paid code runs in the process (see PaidGrantMonotonicClock).
class_exists(PaidGrantMonotonicClock::class);

/**
 * Codex P2 (comment 4223825193): an authorization's lifetime counts from the latest moment its token can reach the
 * customer, and redemption is admitted by the instant the redeem request began (the Free256 rule). `expires_at` is
 * `created_at + authorization_seconds + PaidGrantDownloads::AUTHORIZE_BUDGET_SECONDS` (the authorize frame's own budget,
 * which may still run after the row is inserted), and the admitted instant is taken before `locate()`. Time is spent on both
 * clocks without sleeping. Synthetic rehearsal fixtures only; no live payment or legal facts are certified.
 */
final class PaidGrantAuthorizationWindowTest extends TestCase
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
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60],
        ]);
        Queue::fake();
        PaidGrantMonotonicClock::reset();
    }

    protected function tearDown(): void
    {
        PaidGrantMonotonicClock::reset();
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

    public function test_a_redeem_soon_after_a_slow_authorize_is_admitted_for_the_whole_lifetime_from_token_receipt(): void
    {
        $f = $this->complete();
        // The authorize frame commits 55 s after inserting the row (inside its 60 s budget), so the token reaches the
        // customer 55 s after created_at. The customer then redeems 50 s later, inside the 60 s policy lifetime.
        $this->spendAtCommit(PaidGrantDownloads::class, 'authorize', 55);
        $auth = $this->authorize($f);
        $this->spend(50);

        $this->assertSame($this->masterBytes($f), $this->drain($this->redeem($f, $auth)));
        $this->assertDatabaseCount('paid_redemptions', 1);
        $this->assertSame(60 + PaidGrantDownloads::AUTHORIZE_BUDGET_SECONDS, $this->lifetime($this->row($auth)));
    }

    public function test_a_redeem_started_before_expiry_whose_locate_and_first_frame_cross_it_is_admitted(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        $this->travelTo(CarbonImmutable::parse($auth['expiresAt'], 'UTC')->subSeconds(2));
        // locate() commits 5 s later: the first frame then runs 3 s after expiry. The request began 2 s before it.
        $this->spendAtCommit(PaidGrantDownloads::class, 'locate', 5);

        $this->assertSame($this->masterBytes($f), $this->drain($this->redeem($f, $auth)));
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_a_600_s_policy_redeems_with_the_added_authorize_budget_inside_the_maximum(): void
    {
        // Independent review A11-L2: the longest accepted lifetime (600 s) plus the authorize budget must pass the
        // post-frame check after a fresh redeem.
        config(['paid-grants.delivery_policy.authorization_seconds' => 600]);
        $f = $this->complete();
        $auth = $this->authorize($f);
        $this->assertSame(600 + PaidGrantDownloads::AUTHORIZE_BUDGET_SECONDS, $this->lifetime($this->row($auth)));
        $this->assertSame($this->masterBytes($f), $this->drain($this->redeem($f, $auth)));
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_a_redeem_started_after_expiry_is_still_refused_with_nothing_recorded(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        $this->travelTo(CarbonImmutable::parse($auth['expiresAt'], 'UTC')->addSecond());
        $this->refused(fn () => $this->redeem($f, $auth), 410);
        $this->assertDatabaseCount('paid_redemptions', 0);
        // Exactly at expiry is already too late (valid-to-start means strictly before expires_at).
        $this->travelTo(CarbonImmutable::parse($auth['expiresAt'], 'UTC'));
        $this->refused(fn () => $this->redeem($f, $auth), 410);
        $this->assertDatabaseCount('paid_redemptions', 0);
    }

    public function test_the_added_constant_is_the_authorize_budget_and_the_idempotent_replay_keeps_the_same_expiry(): void
    {
        $f = $this->complete();
        $input = ['requestKey' => (string) Str::uuid(), 'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => 'master_wav', 'nonce' => bin2hex(random_bytes(32))];
        $first = $this->authorize($f, $input);
        $this->travel(30)->seconds();
        $this->assertSame($first, $this->authorize($f, $input));
        $this->assertDatabaseCount('paid_authorizations', 1);
        $this->assertSame(60 + 60, $this->lifetime($this->row($first)));
        $this->assertSame(60, PaidGrantDownloads::AUTHORIZE_BUDGET_SECONDS);
    }

    /** Spends time on both clocks when the outermost transaction opened by `$class::$function` commits (not customer access). */
    private function spendAtCommit(string $class, string $function, int $seconds): void
    {
        $spent = false;
        Event::listen(TransactionCommitted::class, function () use (&$spent, $class, $function, $seconds): void {
            if ($spent || DB::transactionLevel() !== 0) {
                return;
            }
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            if (array_filter($frames, fn (array $frame): bool => ($frame['class'] ?? null) === $class && $frame['function'] === $function) === []
                || array_filter($frames, fn (array $frame): bool => ($frame['class'] ?? null) === ProductionCustomerAccess::class) !== []) {
                return;
            }
            $spent = true;
            $this->spend($seconds);
        });
    }

    private function spend(int $seconds): void
    {
        PaidGrantMonotonicClock::advance($seconds);
        $this->travel($seconds)->seconds();
    }

    private function authorize(array $f, ?array $input = null): array
    {
        return (new PaidGrantDownloads)->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'],
            $input ?? ['requestKey' => (string) Str::uuid(), 'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => 'master_wav', 'nonce' => bin2hex(random_bytes(32))],
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

    private function row(array $auth): object
    {
        return DB::table('paid_authorizations')->where('public_id', $auth['id'])->sole();
    }

    private function lifetime(object $row): int
    {
        return (int) CarbonImmutable::parse($row->created_at, 'UTC')->diffInSeconds(CarbonImmutable::parse($row->expires_at, 'UTC'));
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

    private function masterBytes(array $f): string
    {
        return file_get_contents(Storage::disk('local')->path($f['catalog']['media']['master_wav']->storage_path));
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
}
