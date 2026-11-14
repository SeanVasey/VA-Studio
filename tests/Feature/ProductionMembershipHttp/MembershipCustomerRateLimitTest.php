<?php

namespace Tests\Feature\ProductionMembershipHttp;

use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Memberships\Production\MembershipCustomerIndex;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;

/** Real capsules and HTTP routes. Array-store checks prove local admission semantics, not distributed concurrency. */
final class MembershipCustomerRateLimitTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberActivationFixtures;

    private const UNKNOWN = '00000000-0000-4000-8000-000000000000';

    private int $address = 0;

    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(self::class);
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['env'] = 'testing';
            $app['config']->set('production-memberships.http_mounted', true);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->memberActivationSetup();
        config(['cache.stores.member-admission' => ['driver' => 'array'], 'cache.limiter' => 'member-admission']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_read_bucket_survives_ip_session_and_recovery_changes_and_expires(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $calls = $this->domainCalls();
        // Actual first-page domain reads consume admission across IPs and new session ids; no F9 route exists.
        for ($i = 0; $i < 10; $i++) {
            if ($i === 5) {
                $this->app['session.store']->migrate();
                $this->actAs($fixture['buyer']);
            }
            $this->read('/production-memberships/periods')->assertOk();
        }
        $before = $calls();
        $response = $this->privateUnavailable($this->read('/production-memberships/periods'), 429);
        $this->assertMatchesRegularExpression('/\A(?:[1-9]|[1-5][0-9]|60)\z/D', $response->headers->get('Retry-After'));
        $this->assertSame($before, $calls(), 'An exhausted bucket never enters the membership domain.');
        $old = $fixture['buyer']['principal'];
        $this->completeIdentity($this->requestIdentity('recover'));
        $recovered = (new ProductionCustomerSessions)->authenticate('buyer@example.test', 'MailboxPassword123');
        $this->assertNotNull($recovered);
        $this->assertSame($old->accountId, $recovered['principal']->accountId);
        $this->assertNotSame($old->durableBinding()['verification_observation_id'],
            $recovered['principal']->durableBinding()['verification_observation_id']);
        $this->actAs($recovered);
        $this->privateUnavailable($this->read('/production-memberships/periods'), 429);
        $this->assertSame($before, $calls());
        // Recovery appends a verification observation; access-version changes are the separate active-state lifecycle.
        DB::table('customer_accounts')->where('id', $old->accountId)->update(['active' => false,
            'access_version' => DB::raw('access_version + 1'), 'updated_at' => CarbonImmutable::now('UTC')]);
        $this->privateUnavailable($this->read('/production-memberships/periods'), 403);
        DB::table('customer_accounts')->where('id', $old->accountId)->update(['active' => true,
            'access_version' => DB::raw('access_version + 1'), 'updated_at' => CarbonImmutable::now('UTC')]);
        $renewed = (new ProductionCustomerSessions)->authenticate('buyer@example.test', 'MailboxPassword123');
        $this->assertNotNull($renewed);
        $this->assertNotSame($old->accessVersion, $renewed['principal']->accessVersion);
        $this->actAs($renewed);
        $this->privateUnavailable($this->read('/production-memberships/periods'), 429);
        $this->assertSame($before, $calls());
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(61));
        $this->read('/production-memberships/periods')->assertOk();
        $this->assertGreaterThan($before, $calls());
    }

    public function test_refused_first_page_domain_reads_still_consume_admission_before_domain(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $refused = 0;
        $connection = DB::connection();
        $beforeLevel = $connection->transactionLevel();
        $armed = true;
        $connection->beforeStartingTransaction(static function () use (&$refused, &$armed): void {
            if (! $armed) {
                return;
            }
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $frame) {
                if (($frame['class'] ?? null) === MembershipCustomerIndex::class && ($frame['function'] ?? null) === 'index') {
                    $refused++;
                    // Genuine controller/domain entry; Laravel invokes this before creating its transaction.
                    throw new MembershipException('synthetic_index_refused');
                }
            }
        });
        try {
            for ($i = 0; $i < 10; $i++) {
                $this->read('/production-memberships/periods')->assertNotFound()->assertExactJson(['code' => 'MEMBERSHIP_NOT_FOUND'])
                    ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
                    ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff');
                $this->assertSame($i + 1, $refused, 'Each admitted read must actually enter the index domain.');
                $this->assertSame($beforeLevel, $connection->transactionLevel(), 'The before-transaction refusal cannot leave an owned transaction.');
            }
            $this->privateUnavailable($this->read('/production-memberships/periods'), 429);
            $this->assertSame(10, $refused, 'Exhaustion cannot enter even a refusing domain.');
        } finally {
            $armed = false; // Connection belongs to this test application and is retired by normal teardown.
        }
    }

    public function test_customers_and_read_write_tiers_have_distinct_buckets_and_all_write_routes_share_one(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        for ($i = 0; $i < 10; $i++) {
            $this->read('/production-memberships/periods')->assertOk();
        }
        $this->privateUnavailable($this->read('/production-memberships/periods'), 429);
        for ($i = 0; $i < 10; $i++) {
            $this->write($i % 3)->assertNotFound();
        }
        $calls = $this->domainCalls();
        foreach ([0, 1, 2] as $endpoint) {
            $this->privateUnavailable($this->write($endpoint), 429);
        }
        $this->assertSame(0, $calls());
        $other = $this->enrollThroughLocalSmtp('limiter-other@example.test');
        $this->actAs($other);
        $this->read('/production-memberships/periods')->assertOk()->assertJsonPath('index.periods', []);
        $this->write(2)->assertNotFound();
    }

    public function test_configured_store_is_used_and_a_contended_admission_lock_fails_before_domain(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $principal = $fixture['buyer']['principal'];
        $key = 'production-memberships-customer:'.hash('sha256', json_encode([$principal->provenance, $principal->accountId, 'reads'], JSON_THROW_ON_ERROR));
        $lock = Cache::store('member-admission')->getStore()->lock($key.':admission', 60);
        $this->assertTrue($lock->get());
        $calls = $this->domainCalls();
        try {
            $this->privateUnavailable($this->read('/production-memberships/periods'), 503);
            $this->assertSame(0, $calls());
            $this->assertNull(Cache::store('array')->get($key));
        } finally {
            $this->assertTrue($lock->release());
        }
        $this->read('/production-memberships/periods')->assertOk();
        $this->assertSame(1, Cache::store('member-admission')->get($key));
        $this->assertNull(Cache::store('array')->get($key));
        // Domain work begins after admission's lock has been released.
        $released = false;
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use ($key, &$released): void {
            $probe = Cache::store('member-admission')->getStore()->lock($key.':admission', 60);
            $released = $probe->get();
            if ($released) {
                $probe->release();
            }
        });
        $this->read('/production-memberships/periods')->assertOk();
        $this->assertTrue($released);
        // Laravel accepts the literal string store name "0"; only null selects its default store.
        config(['cache.stores.0' => ['driver' => 'array'], 'cache.limiter' => '0']);
        $numeric = Cache::store('0')->getStore()->lock($key.':admission', 60);
        $this->assertTrue($numeric->get());
        try {
            $this->privateUnavailable($this->read('/production-memberships/periods'), 503);
        } finally {
            $this->assertTrue($numeric->release());
        }
        config(['cache.limiter' => 'member-admission']);
        Cache::store('member-admission')->put($key, 10, 3600);
        Cache::store('member-admission')->put($key.':timer', CarbonImmutable::now('UTC')->timestamp + 3600, 3600);
        $before = $calls();
        $this->privateUnavailable($this->read('/production-memberships/periods'), 429)->assertHeader('Retry-After', '60');
        $this->assertSame($before, $calls(), 'A corrupted long cache timer cannot create an unbounded retry header.');
    }

    public function test_cache_exception_is_a_private_pre_operation_failure_for_reads_and_writes(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $store = new class extends ArrayStore
        {
            public function increment($key, $value = 1)
            {
                throw new RuntimeException('synthetic private cache failure');
            }
        };
        Cache::extend('member-failing', fn () => Cache::repository($store));
        config(['cache.stores.member-failing' => ['driver' => 'member-failing'], 'cache.limiter' => 'member-failing']);
        $calls = $this->domainCalls();
        $this->privateUnavailable($this->read('/production-memberships/periods'), 503);
        $this->privateUnavailable($this->write(2), 503);
        $this->assertSame(0, $calls());
        $unsupported = \Mockery::mock(Store::class);
        Cache::extend('member-unlocked', fn () => Cache::repository($unsupported));
        config(['cache.stores.member-unlocked' => ['driver' => 'member-unlocked'], 'cache.limiter' => 'member-unlocked']);
        $this->privateUnavailable($this->read('/production-memberships/periods'), 503);
        $this->privateUnavailable($this->write(2), 503);
        $this->assertSame(0, $calls());
    }

    public function test_cache_callbacks_cannot_change_identity_or_steal_the_admission_lease_before_domain_work(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $store = new class extends ArrayStore
        {
            public function increment($key, $value = 1)
            {
                app('session.store')->forget('_production_customer_identity');

                return parent::increment($key, $value);
            }
        };
        Cache::extend('member-changing', fn () => Cache::repository($store));
        config(['cache.stores.member-changing' => ['driver' => 'member-changing'], 'cache.limiter' => 'member-changing']);
        $calls = $this->domainCalls();
        $this->privateUnavailable($this->read('/production-memberships/periods'), 403);
        $this->assertSame(0, $calls());
        $this->actAs($fixture['buyer']);
        $stolen = new class extends ArrayStore
        {
            public function increment($key, $value = 1)
            {
                $result = parent::increment($key, $value);
                CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(6));
                // Another admission can acquire the expired lease; the original owner must refuse.
                if (! $this->lock($key.':admission', 5)->get()) {
                    throw new RuntimeException('Synthetic lease takeover failed.');
                }

                return $result;
            }
        };
        Cache::extend('member-stolen', fn () => Cache::repository($stolen));
        config(['cache.stores.member-stolen' => ['driver' => 'member-stolen'], 'cache.limiter' => 'member-stolen']);
        $this->privateUnavailable($this->read('/production-memberships/periods'), 503);
        $this->assertSame(0, $calls());
    }

    private function actAs(array $owner): void
    {
        Auth::guard('customer')->setUser($owner['user']);
        $this->withSession(['_token' => str_repeat('c', 40), '_production_customer_identity' => ['binding_digest' => $owner['principal']->sessionBindingDigest()]]);
    }

    private function read(string $path): TestResponse
    {
        return $this->call('GET', $path, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '192.0.2.'.(++$this->address)]);
    }

    private function write(int $endpoint): TestResponse
    {
        $path = '/production-memberships/periods/'.self::UNKNOWN;
        $body = [];
        if ($endpoint === 0) {
            $path .= '/reservations';
            $body = ['offerId' => '1', 'expectedRevision' => 1, 'requestKey' => 'synthetic-rate-limit-0001'];
        } elseif ($endpoint === 1) {
            $path .= '/reservations/'.self::UNKNOWN.'/release';
        } else {
            $path .= '/expire';
        }

        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => session()->token(), 'REMOTE_ADDR' => '192.0.2.'.(++$this->address)], json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    private function privateUnavailable(TestResponse $response, int $status): TestResponse
    {
        return $response->assertStatus($status)->assertExactJson(['code' => 'MEMBERSHIP_UNAVAILABLE'])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    private function domainCalls(): \Closure
    {
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, static function () use (&$calls): void {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $frame) {
                if (in_array($frame['class'] ?? '', [MembershipCustomerIndex::class, MembershipReservationOperations::class], true)) {
                    $calls++;
                    break;
                }
            }
        });

        return static function () use (&$calls): int {
            return $calls;
        };
    }
}
