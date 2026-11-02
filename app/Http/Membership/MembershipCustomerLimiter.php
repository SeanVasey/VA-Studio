<?php

namespace App\Http\Membership;

use App\Domain\Memberships\Production\MembershipHttpBinding;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Short atomic admission only; the lock is always released before membership domain work. */
final class MembershipCustomerLimiter
{
    public function admit(MembershipHttpBinding $identity, string $tier): void
    {
        $identity->proveCurrent();
        if (! in_array($tier, ['reads', 'writes'], true)) {
            throw new HttpException(503);
        }
        $principal = $identity->principal();
        $key = 'production-memberships-customer:'.hash('sha256', json_encode(
            [$principal->provenance, $principal->accountId, $tier], JSON_THROW_ON_ERROR));
        // Match Laravel's RateLimiter binding, including its explicit limiter-store override.
        $cache = Cache::store(config('cache.limiter'));
        $store = $cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new HttpException(503);
        }
        $lock = $store->lock($key.':admission', 5);
        // Start before acquisition: integer-expiry stores can grant slightly over four actual seconds.
        $started = hrtime(true);
        $wall = $this->wallMicros();
        if (! is_int($started) || $started < 0) {
            throw new HttpException(503);
        }
        $lease = ['started' => $started, 'lastMono' => $started, 'lastWall' => $wall,
            'deadline' => (intdiv($wall, 1_000_000) + 5) * 1_000_000];
        if (! $lock->get()) {
            throw new HttpException(503);
        }
        try {
            $this->ownedSample($lock, $lease);
            $limiter = new RateLimiter($cache);
            [$timer, $count, $now] = $this->snapshot($cache, $limiter, $key, $lock, $lease);
            if ($count === 10) {
                // Preserve bounded refusal for an overlong full bucket; it is never an admissible epoch.
                throw new HttpException(429, headers: ['Retry-After' => (string) max(1, min(60, $timer - $now))]);
            }
            if ($timer !== null && $timer > $now + 60) {
                throw new HttpException(503);
            }
            $before = $this->ownedSample($lock, $lease);
            if ($timer !== null && ($timer < $before || $timer > $before + 60)) {
                throw new HttpException(503);
            }
            $hits = $limiter->hit($key, 60);
            $after = $this->leaseSample($lease);
            [$currentTimer, $currentCount, $currentNow] = $this->snapshot($cache, $limiter, $key, $lock, $lease);
            // Zero seconds is legitimate only while the complete, unchanged epoch remains cache-present.
            if ($hits < 1 || $hits > 10 || $currentCount !== $hits || $hits !== $count + 1
                || $currentTimer === null || $currentTimer < $currentNow || $currentTimer > $currentNow + 60
                || ($timer !== null && $currentTimer !== $timer)
                || ($timer === null && ($currentTimer < $before + 60 || $currentTimer > $after + 60))) {
                throw new HttpException(503);
            }
        } finally {
            $released = false;
            $stillOwned = true;
            try {
                $released = $lock->release();
            } finally {
                try {
                    $stillOwned = $lock->isOwnedByCurrentProcess();
                } finally {
                    // ArrayLock can retain an expired owner; successful release alone is not a live-lease proof.
                    $this->leaseSample($lease);
                }
            }
            if (! $released || $stillOwned) {
                throw new HttpException(503);
            }
        }
        // Store resolution, lock and cache operations may invoke extensible callbacks.
        $identity->proveCurrent();
    }

    /**
     * @param  array{started: int, lastMono: int, lastWall: int, deadline: int}  $lease
     * @return array{?int, int, int}
     */
    private function snapshot(Repository $cache, RateLimiter $limiter, string $key, Lock $lock, array &$lease): array
    {
        $first = $cache->get($key.':timer');
        // Retain Laravel's PhpRedis serialization/compression bypass for the counter read.
        $count = $this->integer($limiter->attempts($key));
        $absent = $count === 0 && ! $cache->has($key);
        $second = $cache->get($key.':timer');
        $now = $this->ownedSample($lock, $lease);
        if ($first === null && $second === null && $absent) {
            return [null, 0, $now];
        }
        $timer = $this->integer($first);
        if ($timer === null || $timer < 1 || $this->integer($second) !== $timer || $timer < $now
            || $count === null || $count < 1 || $count > 10) {
            throw new HttpException(503);
        }

        return [$timer, $count, $now];
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
            && (string) (int) $value === $value) {
            return (int) $value;
        }

        return null;
    }

    private function wallMicros(): int
    {
        $now = Carbon::now();
        $seconds = $now->getTimestamp();
        $micros = $now->format('u');
        if ($seconds < 0 || $seconds > intdiv(PHP_INT_MAX - 5_999_999, 1_000_000)
            || preg_match('/\A[0-9]{6}\z/D', $micros) !== 1) {
            throw new HttpException(503);
        }

        return $seconds * 1_000_000 + (int) $micros;
    }

    /** @param array{started: int, lastMono: int, lastWall: int, deadline: int} $lease */
    private function ownedSample(Lock $lock, array &$lease): int
    {
        $owned = $lock->isOwnedByCurrentProcess();
        $now = $this->leaseSample($lease);
        if (! $owned) {
            throw new HttpException(503);
        }

        return $now;
    }

    /** @param array{started: int, lastMono: int, lastWall: int, deadline: int} $lease */
    private function leaseSample(array &$lease): int
    {
        $wall = $this->wallMicros();
        $mono = hrtime(true);
        if (! is_int($mono) || $mono < $lease['lastMono'] || $mono < $lease['started']
            || $mono - $lease['started'] >= 4_000_000_000 || $wall < $lease['lastWall'] || $wall >= $lease['deadline']) {
            throw new HttpException(503);
        }
        $lease['lastWall'] = $wall;
        $lease['lastMono'] = $mono;

        return intdiv($wall, 1_000_000);
    }
}
