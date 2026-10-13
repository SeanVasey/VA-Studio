<?php

namespace Tests\Support {
    /**
     * Synthetic monotonic time for membership production tests only. Load before any production
     * membership call site executes: PHP caches namespaced function resolution. No production
     * registration or clock hook exists. Other namespaces keep real time, so their comparisons to
     * the captured membership deadline are more lenient; membership's own terminal checks must refuse.
     */
    final class MembershipAwardMonotonicClock
    {
        public static int $offsetNs = 0;

        public static function advance(int $seconds): void
        {
            self::$offsetNs += $seconds * 1_000_000_000;
        }

        public static function reset(): void
        {
            self::$offsetNs = 0;
        }
    }
}

namespace App\Domain\Memberships\Production {
    use Tests\Support\MembershipAwardMonotonicClock;

    if (! function_exists(__NAMESPACE__.'\hrtime')) {
        function hrtime(bool $as_number = false): array|int|float|false
        {
            return $as_number ? \hrtime(true) + MembershipAwardMonotonicClock::$offsetNs : \hrtime(false);
        }
    }
}
