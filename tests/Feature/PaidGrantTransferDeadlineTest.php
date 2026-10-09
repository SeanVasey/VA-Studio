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
use App\Domain\Grants\Paid\PaidGrantPrepareStream;
use App\Domain\Grants\Paid\PaidGrants;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MediaFixtures;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * Codex P2 finding 1 / review M1: the authorization lifetime is a valid-to-start deadline. An admitted transfer gets its own
 * deadline, base allowance plus the snapshot size at the policy's minimum rate, capped and counted from the redemption commit,
 * so a slow client is not cut off after its one attempt was consumed. The transfer clock is injected; no test sleeps.
 * Synthetic rehearsal fixtures only; no live payment or legal facts are certified.
 */
final class PaidGrantTransferDeadlineTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidGrantDependencyFixtures;
    use ProductionCheckoutJourneyFixture;

    private const MIB = 1048576;

    private const RATE = 16384;

    private int $offset = 0;

    private int $ticks = 0;

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
            // The minimum admitted rate makes the derived deadline of the ~2 MiB master longer than the 60 s authorization.
            'paid-grants.transfer_min_bytes_per_second' => self::RATE]);
        Queue::fake();
        // A 12 s synthetic master (~2 MiB, three 1 MiB stream chunks) replaces the default 1.2 s fixture for this class only,
        // so the per-chunk deadline is observable mid-stream. tearDown removes it from the process-wide fixture cache.
        $this->defaultMaster(MediaFixtures::wav(12.0));
    }

    protected function tearDown(): void
    {
        $this->defaultMaster(null);
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

    public function test_a_stream_that_outlasts_the_authorization_but_fits_the_derived_deadline_completes(): void
    {
        $f = $this->complete();
        $transfer = $this->downloads()->redeem(...$this->redeemArguments($f));
        $derived = $this->derived($transfer->sizeBytes);
        $this->assertGreaterThan(60, $derived, 'The fixture must outlast the 60 s authorization.');
        $bytes = '';
        $chunks = 0;
        $this->ticks = 0;
        $transfer->writeTo(function (string $chunk) use (&$bytes, &$chunks, $derived): void {
            $bytes .= $chunk;
            $chunks++;
            // Past the 60 s authorization lifetime once, still inside the size-derived transfer deadline.
            $this->offset = ($derived - 1) * 1_000_000_000;
        });
        $this->assertSame($this->masterBytes($f), $bytes);
        $this->assertSame(3, $chunks);
        $this->assertSame($chunks + 1, $this->ticks, 'The transfer clock is read before the first byte and before every chunk.');
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_a_stream_that_exceeds_the_derived_deadline_is_refused_mid_stream(): void
    {
        $f = $this->complete();
        $transfer = $this->downloads()->redeem(...$this->redeemArguments($f));
        $derived = $this->derived($transfer->sizeBytes);
        $step = intdiv($derived, 2) + 1;
        $received = 0;
        $this->refused(function () use ($transfer, &$received, $step): void {
            $transfer->writeTo(function (string $chunk) use (&$received, $step): void {
                $received += strlen($chunk);
                $this->offset += $step * 1_000_000_000;
            });
        }, 410);
        $this->assertSame(2 * self::MIB, $received, 'Chunk 2 starts inside the derived deadline; the check before chunk 3 is past it.');
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_the_derived_deadline_is_capped_by_the_configured_maximum(): void
    {
        config(['paid-grants.transfer_max_seconds' => 60]);
        $f = $this->complete();
        $transfer = $this->downloads()->redeem(...$this->redeemArguments($f));
        $this->assertSame(60, $this->derived($transfer->sizeBytes));
        $this->assertGreaterThan(60, 30 + intdiv($transfer->sizeBytes + self::RATE - 1, self::RATE), 'Uncapped, the size would allow longer.');
        $received = 0;
        $this->refused(function () use ($transfer, &$received): void {
            $transfer->writeTo(function (string $chunk) use (&$received): void {
                $received += strlen($chunk);
                $this->offset += 61_000_000_000;
            });
        }, 410);
        $this->assertSame(self::MIB, $received);
    }

    public function test_redeeming_after_the_authorization_expired_is_still_refused_and_records_nothing(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        $this->travelTo(CarbonImmutable::parse($auth['expiresAt'], 'UTC')->addSecond());
        $this->refused(fn () => $this->downloads()->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']), 410);
        $this->assertDatabaseCount('paid_redemptions', 0);
    }

    public function test_a_redemption_admitted_before_expiry_is_recorded_when_its_snapshot_finishes_after_expiry(): void
    {
        $f = $this->complete();
        $auth = $this->authorize($f);
        $expires = CarbonImmutable::parse($auth['expiresAt'], 'UTC');
        $fired = false;
        // The private snapshot finishes one second after the authorization expiry; the redemption was admitted inside it.
        app()->instance(PaidGrantPrepareStream::class, new class(fn () => $this->travelTo($expires->addSecond()), $fired) extends PaidGrantPrepareStream
        {
            public function __construct(private readonly Closure $late, private bool &$fired) {}

            protected function copyTarget(array $target, $destination, int $deadline): void
            {
                parent::copyTarget($target, $destination, $deadline);
                ($this->late)();
                $this->fired = true;
            }
        });

        $transfer = $this->downloads()->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });

        $this->assertTrue($fired);
        $this->assertSame($this->masterBytes($f), $bytes);
        $row = (array) DB::table('paid_redemptions')->sole();
        // The schema's redemption guard compares no time with the expiry, so the committed row keeps its truthful commit time.
        $this->assertTrue(CarbonImmutable::parse($row['created_at'], 'UTC')->greaterThan($expires));
        $this->refused(fn () => $this->downloads()->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']), 410);
        $this->assertDatabaseCount('paid_redemptions', 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_shipped_defaults_policy_bounds_and_derived_formula(): void
    {
        $shipped = require base_path('config/paid-grants.php');
        $this->assertSame(262144, $shipped['transfer_min_bytes_per_second']);
        $this->assertSame(30, $shipped['transfer_base_seconds']);
        $this->assertSame(7200, $shipped['transfer_max_seconds']);
        $policy = app(PaidGrantPolicy::class);
        foreach (['transfer_min_bytes_per_second', 'transfer_base_seconds', 'transfer_max_seconds'] as $key) {
            config(['paid-grants.'.$key => $shipped[$key]]);
        }
        $this->assertSame(31, $policy->transferSeconds(1));
        $this->assertSame(31, $policy->transferSeconds(262144));
        $this->assertSame(32, $policy->transferSeconds(262145));
        // The largest deliverable (1 GiB) needs 4,096 s at 256 KiB/s plus the 30 s allowance, inside the 2 h cap.
        $this->assertSame(4126, $policy->transferSeconds(1073741824));
        $this->assertSame(7200, $policy->transferSeconds(2 * 1073741824));
        $this->refused(fn () => $policy->transferSeconds(0), 503);
        foreach ([['transfer_min_bytes_per_second', 16383], ['transfer_min_bytes_per_second', 1073741825], ['transfer_min_bytes_per_second', '262144'],
            ['transfer_base_seconds', -1], ['transfer_base_seconds', 601], ['transfer_base_seconds', null],
            ['transfer_max_seconds', 59], ['transfer_max_seconds', 14401], ['transfer_max_seconds', '7200']] as [$key, $value]) {
            config(['paid-grants.'.$key => $value]);
            $this->refused(fn () => $policy->capture(), 503);
            $this->refused(fn () => $policy->transferSeconds(1), 503);
            config(['paid-grants.'.$key => $shipped[$key]]);
        }
        foreach ([['transfer_min_bytes_per_second', 16384], ['transfer_min_bytes_per_second', 1073741824], ['transfer_base_seconds', 0],
            ['transfer_base_seconds', 600], ['transfer_max_seconds', 60], ['transfer_max_seconds', 14400]] as [$key, $value]) {
            config(['paid-grants.'.$key => $value]);
            $this->assertSame(config('paid-grants.delivery_policy'), $policy->capture());
            config(['paid-grants.'.$key => $shipped[$key]]);
        }
        // An invalid transfer policy refuses before any authorization row exists.
        $f = $this->complete();
        config(['paid-grants.transfer_max_seconds' => 59]);
        $this->refused(fn () => $this->authorize($f), 503);
        $this->assertDatabaseCount('paid_authorizations', 0);
    }

    private function defaultMaster(?string $bytes): void
    {
        Closure::bind(static function () use ($bytes): void {
            if ($bytes === null) {
                unset(self::$cache['1.2-440']);
            } else {
                self::$cache['1.2-440'] = $bytes;
            }
        }, null, MediaFixtures::class)();
    }

    private function downloads(): PaidGrantDownloads
    {
        return new PaidGrantDownloads(function (): int {
            $this->ticks++;

            return hrtime(true) + $this->offset;
        });
    }

    private function derived(int $bytes): int
    {
        return min(config('paid-grants.transfer_max_seconds'), config('paid-grants.transfer_base_seconds') + intdiv($bytes + self::RATE - 1, self::RATE));
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
        return $this->downloads()->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'],
            ['requestKey' => (string) Str::uuid(), 'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => 'master_wav', 'nonce' => bin2hex(random_bytes(32))],
            $f['buyer']['principal'], $f['buyer']['user']);
    }

    private function redeemArguments(array $f): array
    {
        $auth = $this->authorize($f);

        return [$auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']];
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
