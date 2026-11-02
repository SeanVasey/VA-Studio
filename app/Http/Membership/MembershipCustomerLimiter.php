<?php

namespace App\Http\Membership;

use App\Domain\Memberships\Production\MembershipHttpBinding;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\LockProvider;
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
        if (! $lock->get()) {
            throw new HttpException(503);
        }
        try {
            $limiter = new RateLimiter($cache);
            if ($limiter->tooManyAttempts($key, 10)) {
                throw new HttpException(429, headers: ['Retry-After' => (string) max(1, min(60, $limiter->availableIn($key)))]);
            }
            $hits = $limiter->hit($key, 60);
            $remaining = $limiter->availableIn($key);
            if ($hits < 1 || $hits > 10 || $limiter->attempts($key) !== $hits || $remaining < 1 || $remaining > 60
                || ! $lock->isOwnedByCurrentProcess()) {
                throw new HttpException(503);
            }
        } finally {
            if (! $lock->release() || $lock->isOwnedByCurrentProcess()) {
                throw new HttpException(503);
            }
        }
        // Store resolution, lock and cache operations may invoke extensible callbacks.
        $identity->proveCurrent();
    }
}
