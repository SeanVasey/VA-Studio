<?php

namespace Tests\Feature\ProductionMembershipHttp;

use App\Domain\Memberships\Production\MembershipCustomerIndex;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;

/** Configured-store/request regressions; Array/File results do not establish shared-backend concurrency. */
final class MembershipCustomerLimiterEpochTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberActivationFixtures;

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
        config(['cache.stores.member-epoch' => ['driver' => 'array'], 'cache.limiter' => 'member-epoch']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_real_array_fractional_tail_admits_both_tiers_caps_at_ten_and_starts_fresh_only_after_expiry(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $origin = $this->freezeFractionalTime();
        $cache = Cache::store('member-epoch');
        $readKey = $this->key($fixture, 'reads');
        $writeKey = $this->key($fixture, 'writes');
        $this->read()->assertOk();
        $this->write()->assertNotFound();
        $timer = $origin->timestamp + 60;
        $this->assertSame($timer, $cache->get($readKey.':timer'));
        $this->assertSame($timer, $cache->get($writeKey.':timer'));
        // Integer deadline has arrived; the real ArrayStore's .800 physical expiry has not.
        CarbonImmutable::setTestNow($origin->addSeconds(60)->setMicrosecond(100000));
        $this->assertSame(0, (new RateLimiter($cache))->availableIn($readKey));
        $this->assertSame($timer, $cache->get($readKey.':timer'));
        $calls = $this->domainCalls();
        for ($i = 1; $i < 10; $i++) {
            $this->read()->assertOk();
            $this->write()->assertNotFound();
        }
        $this->assertGreaterThan(0, $calls(), 'The legitimate zero-second tail must reach membership domain work.');
        $before = $calls();
        $this->privateFailure($this->read(), 429)->assertHeader('Retry-After', '1');
        $this->privateFailure($this->write(), 429)->assertHeader('Retry-After', '1');
        $this->assertSame($before, $calls());
        foreach ([$readKey, $writeKey] as $key) {
            $this->assertSame(10, $cache->get($key));
            $this->assertSame($timer, $cache->get($key.':timer'));
            $this->assertNull(Cache::store('array')->get($key));
        }
        CarbonImmutable::setTestNow($origin->addSeconds(60)->setMicrosecond(801000));
        $this->read()->assertOk();
        $this->assertSame(1, $cache->get($readKey));
        $this->assertSame($timer + 60, $cache->get($readKey.':timer'));
        for ($i = 1; $i < 10; $i++) {
            $this->read()->assertOk();
        }
        $this->privateFailure($this->read(), 429)->assertHeader('Retry-After', '60');
        $this->assertSame(10, $cache->get($readKey));
    }

    public function test_real_file_store_expires_at_the_integer_boundary_and_creates_one_new_epoch(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $origin = $this->freezeFractionalTime();
        $directory = storage_path('framework/testing/member-epoch-'.Str::uuid());
        $files = new Filesystem;
        $files->makeDirectory($directory, 0700, true);
        config(['cache.stores.member-epoch-file' => ['driver' => 'file', 'path' => $directory,
            'lock_path' => $directory], 'cache.limiter' => 'member-epoch-file']);
        $cache = Cache::store('member-epoch-file');
        $key = $this->key($fixture);
        try {
            $this->read()->assertOk();
            $timer = $cache->get($key.':timer');
            CarbonImmutable::setTestNow($origin->addSeconds(59));
            $this->read()->assertOk();
            $this->assertSame(2, $cache->get($key));
            $this->assertSame($timer, $cache->get($key.':timer'));
            CarbonImmutable::setTestNow($origin->addSeconds(60)->setMicrosecond(0));
            $this->assertNull($cache->get($key));
            $this->assertNull($cache->get($key.':timer'));
            $this->read()->assertOk();
            $this->assertSame(1, $cache->get($key));
            $this->assertSame($timer + 60, $cache->get($key.':timer'));
        } finally {
            Cache::forgetDriver('member-epoch-file');
            $this->assertTrue($files->deleteDirectory($directory));
        }
    }

    public function test_partial_stale_and_corrupt_states_refuse_without_a_hit_or_reset(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $now = $this->freezeFractionalTime()->timestamp;
        $store = $this->useHooks();
        $cache = Cache::store('member-epoch');
        $key = $this->key($fixture);
        $calls = $this->domainCalls();
        $cases = [
            [null, 1], [$now + 60, null], [null, 0], [$now + 60, 0], [$now + 60, -1],
            [$now + 60, 11], [$now + 60, true], [$now + 60, 1.0], [$now + 60, '01'],
            [$now + 60, '1e0'], [$now + 60, '-1'], [$now + 60, []], [$now + 60, (string) PHP_INT_MAX.'0'],
            [false, 1], [true, 1], [(float) ($now + 60), 1], ['0'.($now + 60), 1],
            [(string) PHP_INT_MAX.'0', 1], [[], 1], [$now - 1, 1], [$now + 61, 1], [0, 1],
        ];
        foreach ($cases as [$timer, $counter]) {
            $store->flush(); // Synthetic case setup, never a production admission repair.
            if ($timer !== null) {
                $cache->put($key.':timer', $timer, 3600);
            }
            if ($counter !== null) {
                $cache->put($key, $counter, 3600);
            }
            $beforeHits = $store->hits;
            $this->privateFailure($this->read(), 503);
            $this->assertSame($beforeHits, $store->hits);
            $this->assertSame($counter, $cache->get($key));
            $this->assertSame($timer, $cache->get($key.':timer'));
            $this->assertSame(0, $calls());
        }
    }

    public function test_canonical_decimal_timer_and_counter_are_accepted_and_full_quota_headers_remain_bounded(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $now = $this->freezeFractionalTime()->timestamp;
        $cache = Cache::store('member-epoch');
        $key = $this->key($fixture);
        $cache->put($key, '9', 60);
        $cache->put($key.':timer', (string) $now, 60);
        $this->read()->assertOk();
        $this->assertSame(10, $cache->get($key));
        $this->assertSame((string) $now, $cache->get($key.':timer'));
        $calls = $this->domainCalls();
        $this->privateFailure($this->read(), 429)->assertHeader('Retry-After', '1');
        $cache->put($key.':timer', (string) ($now + 30), 3600);
        $this->privateFailure($this->read(), 429)->assertHeader('Retry-After', '30');
        $cache->put($key.':timer', (string) ($now + 3600), 3600);
        $this->privateFailure($this->read(), 429)->assertHeader('Retry-After', '60');
        $this->assertSame(10, $cache->get($key));
        $this->assertSame(0, $calls());
    }

    public function test_expiry_during_the_hit_refuses_once_and_the_next_request_keeps_the_new_epochs_consumed_hit(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $origin = $this->freezeFractionalTime();
        $store = $this->useHooks();
        $cache = Cache::store('member-epoch');
        $key = $this->key($fixture);
        $this->read()->assertOk();
        $oldTimer = $cache->get($key.':timer');
        CarbonImmutable::setTestNow($origin->addSeconds(60)->setMicrosecond(500000));
        $reads = 0;
        $store->beforeGet = static function (string $observed) use ($store, $key, $origin, &$reads): void {
            if ($observed === $key.':timer' && ++$reads === 3) {
                $store->beforeGet = null;
                // The third timer read belongs to the real RateLimiter::hit timer add.
                CarbonImmutable::setTestNow($origin->addSeconds(60)->setMicrosecond(900000));
            }
        };
        $calls = $this->domainCalls();
        $this->privateFailure($this->read(), 503);
        $this->assertSame(2, $store->hits, 'Exactly one hit was attempted by the refused request.');
        $this->assertSame(1, $cache->get($key), 'The refused new-epoch hit is retained, never refunded.');
        $this->assertSame($oldTimer + 60, $cache->get($key.':timer'));
        $this->assertSame(0, $calls());
        $this->read()->assertOk();
        $this->assertSame(2, $cache->get($key));
        $this->assertSame($oldTimer + 60, $cache->get($key.':timer'));
    }

    public function test_timer_expiry_between_snapshot_reads_refuses_without_attempting_a_hit(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $origin = $this->freezeFractionalTime();
        $store = $this->useHooks();
        $key = $this->key($fixture);
        $this->read()->assertOk();
        CarbonImmutable::setTestNow($origin->addSeconds(60)->setMicrosecond(500000));
        $reads = 0;
        $store->beforeGet = static function (string $observed) use ($store, $key, $origin, &$reads): void {
            if ($observed === $key.':timer' && ++$reads === 2) {
                $store->beforeGet = null;
                CarbonImmutable::setTestNow($origin->addSeconds(60)->setMicrosecond(900000));
            }
        };
        $calls = $this->domainCalls();
        $this->privateFailure($this->read(), 503);
        $this->assertSame(1, $store->hits);
        $this->assertSame(0, $calls());
        $this->read()->assertOk();
        $this->assertSame(1, Cache::store('member-epoch')->get($key));
    }

    public function test_missing_corrupt_or_replaced_post_hit_timers_and_counter_changes_never_enter_domain(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $now = $this->freezeFractionalTime()->timestamp;
        $store = $this->useHooks();
        $cache = Cache::store('member-epoch');
        $key = $this->key($fixture);
        $calls = $this->domainCalls();
        foreach (['missing_timer', 'corrupt_timer', 'replaced_timer', 'changed_count', 'missing_count'] as $change) {
            $store->flush();
            $cache->put($key, 1, 60);
            $cache->put($key.':timer', $now + 60, 60);
            $store->afterIncrement = static function () use ($store, $cache, $key, $now, $change): void {
                $store->afterIncrement = null;
                match ($change) {
                    'missing_timer' => $cache->forget($key.':timer'),
                    'corrupt_timer' => $cache->put($key.':timer', false, 60),
                    'replaced_timer' => $cache->put($key.':timer', $now + 59, 60),
                    'changed_count' => $cache->put($key, 3, 60),
                    'missing_count' => $cache->forget($key),
                };
            };
            $beforeHits = $store->hits;
            $this->privateFailure($this->read(), 503);
            $this->assertSame($beforeHits + 1, $store->hits);
            $this->assertSame($change === 'missing_count' ? null : ($change === 'changed_count' ? 3 : 2), $cache->get($key));
            $this->assertSame(0, $calls());
        }
    }

    public function test_expired_array_owner_without_successor_refuses_before_hit_and_after_one_consumed_hit(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $origin = $this->freezeFractionalTime();
        $store = $this->useHooks();
        $cache = Cache::store('member-epoch');
        $key = $this->key($fixture);
        $calls = $this->domainCalls();
        foreach (['before', 'during'] as $stage) {
            $store->flush();
            CarbonImmutable::setTestNow($origin);
            $cache->put($key, 1, 60);
            $cache->put($key.':timer', $origin->timestamp + 60, 60);
            $retainedOwner = false;
            $delay = static function () use ($store, $origin, $key, &$retainedOwner): void {
                CarbonImmutable::setTestNow($origin->addSeconds(6));
                $lease = $store->locks[$key.':admission'];
                $retainedOwner = $lease['expiresAt']->isPast()
                    && $store->restoreLock($key.':admission', $lease['owner'])->isOwnedByCurrentProcess();
            };
            if ($stage === 'before') {
                $store->beforeGet = static function (string $observed) use ($store, $key, $delay): void {
                    if ($observed === $key.':timer') {
                        $store->beforeGet = null;
                        $delay();
                    }
                };
            } else {
                $store->afterIncrement = static function () use ($store, $delay): void {
                    $store->afterIncrement = null;
                    $delay();
                };
            }
            $beforeHits = $store->hits;
            $this->privateFailure($this->read(), 503);
            $this->assertTrue($retainedOwner, 'A genuine expired ArrayLock still reports the old owner without a successor.');
            $this->assertSame($beforeHits + ($stage === 'during' ? 1 : 0), $store->hits);
            $this->assertSame($stage === 'during' ? 2 : 1, $cache->get($key));
            $this->assertSame(0, $calls());
            $this->assertArrayNotHasKey($key.':admission', $store->locks, 'Owned cleanup still releases the expired lease.');
        }
    }

    public function test_delays_inside_post_hit_owner_release_and_post_release_callbacks_refuse(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $origin = $this->freezeFractionalTime();
        $store = $this->useHooks();
        $key = $this->key($fixture);
        $calls = $this->domainCalls();
        foreach (['post_hit_owner', 'release', 'post_release_owner'] as $stage) {
            $store->flush();
            CarbonImmutable::setTestNow($origin);
            $ownerReads = 0;
            if ($stage === 'release') {
                $store->afterRelease = static function () use ($store, $origin): void {
                    $store->afterRelease = null;
                    CarbonImmutable::setTestNow($origin->addSeconds(6));
                };
            } else {
                $store->afterOwner = static function (?string $owner) use ($store, $origin, $stage, &$ownerReads): void {
                    $ownerReads++;
                    if (($stage === 'post_hit_owner' && $ownerReads === 4) || ($stage === 'post_release_owner' && $owner === null)) {
                        $store->afterOwner = null;
                        CarbonImmutable::setTestNow($origin->addSeconds(6));
                    }
                };
            }
            $beforeHits = $store->hits;
            $this->privateFailure($this->read(), 503);
            $this->assertSame($beforeHits + 1, $store->hits);
            $this->assertSame(1, Cache::store('member-epoch')->get($key));
            $this->assertSame(0, $calls());
            $this->assertArrayNotHasKey($key.':admission', $store->locks);
        }
    }

    public function test_lease_deadline_equality_and_observed_backward_time_refuse_with_before_deadline_control(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $origin = $this->freezeFractionalTime();
        $store = $this->useHooks();
        $key = $this->key($fixture);
        foreach (['before', 'equal', 'backward'] as $edge) {
            $store->flush();
            CarbonImmutable::setTestNow($origin);
            $calls = $this->domainCalls();
            $store->afterIncrement = static function () use ($store, $origin, $edge): void {
                $store->afterIncrement = null;
                CarbonImmutable::setTestNow(match ($edge) {
                    'before' => $origin->addSeconds(5)->startOfSecond()->subMicrosecond(),
                    'equal' => $origin->addSeconds(5)->startOfSecond(),
                    'backward' => $origin->subMicrosecond(),
                });
            };
            if ($edge === 'before') {
                $this->read()->assertOk();
                $this->assertGreaterThan(0, $calls());
            } else {
                $this->privateFailure($this->read(), 503);
                $this->assertSame(0, $calls());
            }
            $this->assertSame(1, Cache::store('member-epoch')->get($key));
        }
    }

    public function test_monotonic_budget_refuses_a_bounded_real_stall_even_with_frozen_wall_time(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $this->freezeFractionalTime();
        $store = $this->useHooks();
        $key = $this->key($fixture);
        $store->afterIncrement = static function () use ($store): void {
            $store->afterIncrement = null;
            usleep(4_010_000); // One bounded real monotonic test; Carbon remains frozen throughout.
        };
        $calls = $this->domainCalls();
        $this->privateFailure($this->read(), 503);
        $this->assertSame(1, $store->hits);
        $this->assertSame(1, Cache::store('member-epoch')->get($key));
        $this->assertSame(0, $calls());
    }

    public function test_successor_ownership_and_release_failures_never_enter_domain(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $origin = $this->freezeFractionalTime();
        $store = $this->useHooks();
        $key = $this->key($fixture);
        $successor = null;
        $store->afterIncrement = static function () use ($store, $origin, $key, &$successor): void {
            $store->afterIncrement = null;
            CarbonImmutable::setTestNow($origin->addSeconds(6));
            $successor = $store->lock($key.':admission', 60);
            if (! $successor->get()) {
                throw new \RuntimeException('Synthetic successor must acquire the expired lease.');
            }
        };
        $calls = $this->domainCalls();
        try {
            $this->privateFailure($this->read(), 503);
            $this->assertNotNull($successor);
            $this->assertTrue($successor->isOwnedByCurrentProcess(), 'An old owner cannot release its successor.');
            $this->assertSame(0, $calls());
        } finally {
            if ($successor !== null) {
                $this->assertTrue($successor->release());
            }
        }
        foreach (['false', 'still_owned'] as $failure) {
            $store->flush();
            CarbonImmutable::setTestNow($origin);
            $store->releaseFalse = $failure === 'false';
            $store->releaseStillOwned = $failure === 'still_owned';
            $this->privateFailure($this->read(), 503);
            $this->assertSame(0, $calls());
            $store->releaseFalse = $store->releaseStillOwned = false;
            $store->lock($key.':admission')->forceRelease(); // Synthetic failed-release cleanup only.
        }
    }

    public function test_a_contended_real_lock_refuses_without_count_mutation_or_releasing_its_owner(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $this->freezeFractionalTime();
        $store = $this->useHooks();
        $key = $this->key($fixture);
        $held = $store->lock($key.':admission', 60);
        $this->assertTrue($held->get());
        $calls = $this->domainCalls();
        try {
            $this->privateFailure($this->read(), 503);
            $this->assertSame(0, $store->hits);
            $this->assertNull(Cache::store('member-epoch')->get($key));
            $this->assertNull(Cache::store('member-epoch')->get($key.':timer'));
            $this->assertTrue($held->isOwnedByCurrentProcess());
            $this->assertSame(0, $calls());
        } finally {
            $this->assertTrue($held->release());
        }
    }

    public function test_final_identity_withdrawal_refuses_and_successful_domain_entry_sees_the_lock_released(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $this->freezeFractionalTime();
        $store = $this->useHooks();
        $key = $this->key($fixture);
        $store->afterRelease = static function () use ($store): void {
            $store->afterRelease = null;
            app('session.store')->forget('_production_customer_identity');
        };
        $calls = $this->domainCalls();
        $this->privateFailure($this->read(), 403);
        $this->assertSame(0, $calls());
        $this->assertSame(1, Cache::store('member-epoch')->get($key));
        $this->actAs($fixture['buyer']);
        $released = false;
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, static function () use ($store, $key, &$released): void {
            $probe = $store->lock($key.':admission', 5);
            if ($probe->get()) {
                $released = true;
                $probe->release();
            }
        });
        $this->read()->assertOk();
        $this->assertTrue($released);
        $this->assertGreaterThan(0, $calls());
    }

    private function freezeFractionalTime(): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC')->addSecond()->setMicrosecond(800000);
        CarbonImmutable::setTestNow($now);

        return $now;
    }

    private function useHooks(): MembershipEpochArrayStore
    {
        $store = new MembershipEpochArrayStore;
        Cache::extend('member-epoch-hooks', static fn () => Cache::repository($store));
        config(['cache.stores.member-epoch' => ['driver' => 'member-epoch-hooks']]);
        Cache::forgetDriver('member-epoch');

        return $store;
    }

    private function key(array $fixture, string $tier = 'reads'): string
    {
        $principal = $fixture['buyer']['principal'];

        return 'production-memberships-customer:'.hash('sha256', json_encode(
            [$principal->provenance, $principal->accountId, $tier], JSON_THROW_ON_ERROR));
    }

    private function actAs(array $owner): void
    {
        Auth::guard('customer')->setUser($owner['user']);
        $this->withSession(['_token' => str_repeat('e', 40), '_production_customer_identity' => [
            'binding_digest' => $owner['principal']->sessionBindingDigest()]]);
    }

    private function read(): TestResponse
    {
        return $this->call('GET', '/production-memberships/periods', [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '198.51.100.'.(++$this->address)]);
    }

    private function write(): TestResponse
    {
        return $this->call('POST', '/production-memberships/periods/00000000-0000-4000-8000-000000000000/expire', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => session()->token(), 'REMOTE_ADDR' => '198.51.100.'.(++$this->address)], '{}');
    }

    private function privateFailure(TestResponse $response, int $status): TestResponse
    {
        return $response->assertStatus($status)->assertExactJson(['code' => 'MEMBERSHIP_UNAVAILABLE'])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    private function domainCalls(): Closure
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

/** Hooks surround real cache/lock operations; RateLimiter itself is never replaced. */
final class MembershipEpochArrayStore extends ArrayStore
{
    public ?Closure $beforeGet = null;

    public ?Closure $afterIncrement = null;

    public ?Closure $afterOwner = null;

    public ?Closure $afterRelease = null;

    public bool $releaseFalse = false;

    public bool $releaseStillOwned = false;

    public int $hits = 0;

    public function get($key)
    {
        $this->beforeGet?->__invoke($key);

        return parent::get($key);
    }

    public function increment($key, $value = 1)
    {
        $this->hits++;
        $result = parent::increment($key, $value);
        $this->afterIncrement?->__invoke();

        return $result;
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        return new MembershipEpochArrayLock($this, $name, $seconds, $owner);
    }
}

final class MembershipEpochArrayLock extends ArrayLock
{
    protected function getCurrentOwner()
    {
        $owner = parent::getCurrentOwner();
        $this->store->afterOwner?->__invoke($owner);

        return $owner;
    }

    public function release()
    {
        if ($this->store->releaseFalse) {
            return false;
        }
        if ($this->store->releaseStillOwned) {
            return true;
        }
        $result = parent::release();
        $this->store->afterRelease?->__invoke();

        return $result;
    }
}
