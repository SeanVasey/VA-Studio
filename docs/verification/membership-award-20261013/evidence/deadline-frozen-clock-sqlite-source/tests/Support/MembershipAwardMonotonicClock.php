<?php

namespace Tests\Support {
    /**
     * Synthetic monotonic time for membership production tests only. Load before any production
     * membership call site executes: PHP caches namespaced function resolution. No production
     * registration or clock hook exists. Outside an explicitly active synthetic test, the namespace
     * returns the real clock. Inside one, real execution time cannot consume the selected virtual
     * budget. The anchor is deliberately one hour ahead: unchanged clocks in other namespaces
     * (including IdentityCommittedFrame) are lenient, so only membership admission checks are under
     * test. These tests prove original-deadline semantics, never physical execution timing.
     */
    final class MembershipAwardMonotonicClock
    {
        private static int $offsetNs = 0;

        private static int|float|null $anchorNs = null;

        /** Enable only after fixture setup; a future anchor isolates unchanged external frame clocks. */
        public static function freeze(): int|float
        {
            self::$offsetNs = 0;

            return self::$anchorNs = \hrtime(true) + 3_600_000_000_000;
        }

        public static function now(): int|float|false
        {
            return self::$anchorNs === null ? \hrtime(true) : self::$anchorNs + self::$offsetNs;
        }

        public static function advance(int $seconds): void
        {
            if (self::$anchorNs === null || $seconds < 0) {
                throw new \LogicException('A forward synthetic membership clock must be explicitly active.');
            }
            self::$offsetNs += $seconds * 1_000_000_000;
        }

        public static function reset(): void
        {
            self::$offsetNs = 0;
            self::$anchorNs = null;
        }
    }
}

namespace App\Domain\Memberships\Production {
    use Tests\Support\MembershipAwardMonotonicClock;

    if (! function_exists(__NAMESPACE__.'\hrtime')) {
        function hrtime(bool $as_number = false): array|int|float|false
        {
            return $as_number ? MembershipAwardMonotonicClock::now() : \hrtime(false);
        }
    }
}
