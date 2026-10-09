<?php

namespace Tests\Feature;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantDownloads;
use App\Domain\Grants\Paid\PaidGrantRequestInstant;
use App\Domain\Grants\Paid\PaidGrants;
use App\Http\Middleware\PaidGrantPrivacy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * Codex P2 (comment 4224514947): a redemption is admitted by the instant the server began the request
 * (`REQUEST_TIME_FLOAT`, set by the SAPI), captured before the identity proof, so a slow current-identity proof near expiry
 * cannot refuse a token that was valid on arrival. A missing, non-finite, future or implausibly old value falls back to the
 * current time, which can only make admission stricter. Synthetic rehearsal fixtures only; no live payment is certified.
 */
final class PaidGrantRequestInstantTest extends TestCase
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

    private function login(array $buyer): void
    {
        $this->actingAs($buyer['user'], 'customer')->withSession(['_token' => str_repeat('c', 40),
            '_production_customer_identity' => ['binding_digest' => $buyer['principal']->sessionBindingDigest()]]);
    }

    public function test_a_request_received_before_expiry_is_admitted_although_its_identity_proof_ends_after_it(): void
    {
        [$f, $auth] = $this->authorized();
        $expires = CarbonImmutable::parse($auth['expiresAt'], 'UTC');
        $this->travelTo($expires->subSeconds(2));
        $received = $this->now();
        $this->slowIdentity(5);
        $response = $this->redeem($auth, ['REQUEST_TIME_FLOAT' => $received])->assertOk();
        $this->assertSame($this->masterBytes($f), $response->streamedContent());
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_an_identity_proof_longer_than_the_capture_age_bound_does_not_expire_a_request_received_in_time(): void
    {
        // Codex P2 4224939409: the instant is validated once, when the controller captures it. The identity proof here
        // takes 65 s of wall time, more than ADMISSION_MAX_AGE_SECONDS, and ends 63 s after expiry. The captured instant,
        // 2 s before expiry, still admits the redemption: the exact bytes and one recorded attempt.
        [$f, $auth] = $this->authorized();
        $expires = CarbonImmutable::parse($auth['expiresAt'], 'UTC');
        $this->travelTo($expires->subSeconds(2));
        $received = $this->now();
        $this->slowIdentity(PaidGrantDownloads::ADMISSION_MAX_AGE_SECONDS + 5);
        $response = $this->redeem($auth, ['REQUEST_TIME_FLOAT' => $received])->assertOk();
        $this->assertSame($this->masterBytes($f), $response->streamedContent());
        $this->assertTrue(CarbonImmutable::now('UTC')->greaterThan($expires->addSeconds(60)));
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_a_captured_instant_admits_the_redemption_after_more_than_the_age_bound_has_passed(): void
    {
        // The same rule at the domain boundary: an instant captured while the authorization is live is used as captured,
        // with no second age check, however long passes before redeem() runs.
        [$f, $auth] = $this->authorized();
        $expires = CarbonImmutable::parse($auth['expiresAt'], 'UTC');
        $this->travelTo($expires->subSeconds(2));
        $instant = PaidGrantDownloads::receivedAt($this->now());
        $this->travel(PaidGrantDownloads::ADMISSION_MAX_AGE_SECONDS + 5)->seconds();
        $transfer = (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user'], $instant);
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });
        $this->assertSame($this->masterBytes($f), $bytes);
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_a_request_received_after_expiry_is_still_refused_with_nothing_recorded(): void
    {
        [, $auth] = $this->authorized();
        $this->travelTo(CarbonImmutable::parse($auth['expiresAt'], 'UTC')->addSecond());
        $this->redeem($auth, ['REQUEST_TIME_FLOAT' => $this->now()])->assertStatus(410);
        $this->assertDatabaseCount('paid_redemptions', 0);
    }

    public function test_a_missing_or_implausible_request_time_falls_back_to_now_and_never_widens_admission(): void
    {
        [$f, $auth] = $this->authorized();
        $expires = CarbonImmutable::parse($auth['expiresAt'], 'UTC');
        // The controller runs 3 s after expiry. A valid server request time 8 s earlier (5 s before expiry) admits the
        // redemption; every rejected value falls back to the controller's own now, after expiry, and is refused.
        $this->travelTo($expires->addSeconds(3));
        $statuses = [];
        foreach (['missing' => null, 'string' => 'abc', 'numeric string' => (string) ($this->now() - 8), 'not finite' => NAN, 'infinite' => INF,
            'boolean' => true, 'array' => [1], 'future' => 120.0, 'older than the bound' => -(PaidGrantDownloads::ADMISSION_MAX_AGE_SECONDS + 1.0)] as $case => $value) {
            $raw = is_float($value) && is_finite($value) ? $this->now() + $value : $value;
            $statuses[$case] = $this->redeem($auth, ['REQUEST_TIME_FLOAT' => $raw])->getStatusCode();
            $this->assertDatabaseCount('paid_redemptions', 0);
        }
        $this->assertSame(array_fill_keys(array_keys($statuses), 410), $statuses);
        // Control: the same request with a valid server request time from before expiry is admitted.
        $response = $this->redeem($auth, ['REQUEST_TIME_FLOAT' => $this->now() - 8])->assertOk();
        $this->assertSame($this->masterBytes($f), $response->streamedContent());
        $this->assertDatabaseCount('paid_redemptions', 1);
    }

    public function test_the_admission_instant_rule(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00.250000', 'UTC'));
        $now = CarbonImmutable::now('UTC');
        $at = fn (float $offset): float => (float) $now->format('U.u') + $offset;
        $this->assertEquals($now->subSeconds(5), PaidGrantDownloads::receivedAt($at(-5.0))->at);
        $this->assertEquals($now->subSeconds(PaidGrantDownloads::ADMISSION_MAX_AGE_SECONDS), PaidGrantDownloads::receivedAt($at(-(float) PaidGrantDownloads::ADMISSION_MAX_AGE_SECONDS))->at);
        // A value up to 1 s ahead (clock granularity) is clamped to now; anything else falls back to now.
        $this->assertEquals($now, PaidGrantDownloads::receivedAt($at(0.5))->at);
        foreach ([$at(1.5), $at(-(float) PaidGrantDownloads::ADMISSION_MAX_AGE_SECONDS - 1), NAN, INF, -INF, null, 'abc', '1', true, [1]] as $value) {
            $this->assertEquals($now, PaidGrantDownloads::receivedAt($value)->at);
        }
        $this->assertEquals(PaidGrantRequestInstant::capture($at(-5.0)), PaidGrantDownloads::receivedAt($at(-5.0)));
    }

    public function test_the_admitted_instant_can_only_be_obtained_by_capture_time_validation(): void
    {
        // Codex 4224939409: redeem() trusts the instant without re-checking its age, so the type must not be constructible
        // from an arbitrary time. Its only constructor is private; capture() is its only factory, and the value is fixed.
        $class = new ReflectionClass(PaidGrantRequestInstant::class);
        $this->assertTrue($class->isFinal());
        $this->assertTrue($class->getConstructor()->isPrivate());
        $this->assertTrue($class->getProperty('at')->isReadOnly());
        $factories = array_values(array_map(fn (ReflectionMethod $method): string => $method->getName(),
            array_filter($class->getMethods(ReflectionMethod::IS_PUBLIC), fn (ReflectionMethod $method): bool => $method->isStatic())));
        $this->assertSame(['capture'], $factories);
        $this->assertSame(PaidGrantRequestInstant::class, (string) (new ReflectionMethod(PaidGrantDownloads::class, 'redeem'))->getParameters()[4]->getType()?->getName());
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
        // Long before the clock: falls back to now at capture, so a backdated value cannot be smuggled through the type.
        $this->assertEquals(CarbonImmutable::now('UTC'), PaidGrantRequestInstant::capture(0.0)->at);
    }

    private function now(): float
    {
        return (float) CarbonImmutable::now('UTC')->format('U.u');
    }

    /** The current-identity proof (`ProductionCustomerSessions::principal`) takes `$seconds` of wall time, once. */
    private function slowIdentity(int $seconds): void
    {
        $spent = false;
        Event::listen(TransactionCommitted::class, function () use (&$spent, $seconds): void {
            if ($spent || DB::transactionLevel() !== 0) {
                return;
            }
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            if (array_filter($frames, fn (array $frame): bool => ($frame['class'] ?? null) === ProductionCustomerSessions::class && $frame['function'] === 'principal') === []) {
                return;
            }
            $spent = true;
            $this->travel($seconds)->seconds();
        });
    }

    private function authorized(): array
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $f['batch'] = (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['batch'] = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($f['batch']['fulfilled']);
        $auth = (new PaidGrantDownloads)->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'],
            ['requestKey' => (string) Str::uuid(), 'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => 'master_wav', 'nonce' => bin2hex(random_bytes(32))],
            $f['buyer']['principal'], $f['buyer']['user']);
        $this->login($f['buyer']);

        return [$f, $auth];
    }

    private function redeem(array $auth, array $server)
    {
        $raw = http_build_query(['token' => $auth['token'], '_token' => str_repeat('c', 40)], '', '&', PHP_QUERY_RFC3986);
        parse_str($raw, $form);

        return $this->call('POST', '/paid-grants/authorizations/'.$auth['id'].'/redeem', $form, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'] + $server, $raw);
    }

    private function masterBytes(array $f): string
    {
        return file_get_contents(Storage::disk('local')->path($f['catalog']['media']['master_wav']->storage_path));
    }
}
