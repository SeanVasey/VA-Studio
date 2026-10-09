<?php

namespace Tests\Support {
    /**
     * Test-only virtual monotonic clock for the paid grant namespace, so a test can spend observation, snapshot and
     * commit-frame time without sleeping. Paid code calls `hrtime(true)` unqualified inside `App\Domain\Grants\Paid`,
     * which PHP resolves to the namespaced function below before the global one. The offset only moves that namespace's
     * clock forward; code in other namespaces keeps the real clock, so their checks against a paid deadline are only ever
     * more lenient, never a false refusal. At offset 0 the namespaced function returns exactly the global value.
     *
     * PHP caches a call site's resolution the first time it runs, so this file must be loaded before any paid code runs in
     * the process: the test file that uses it loads it at file scope, which PHPUnit does while building the suite. Tests
     * prove the seam is live (`proveLive()`) before relying on it, and reset the offset in tearDown.
     */
    final class PaidGrantMonotonicClock
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

namespace App\Domain\Grants\Paid {
    use Tests\Support\PaidGrantMonotonicClock;

    if (! function_exists(__NAMESPACE__.'\hrtime')) {
        /** Paid-namespace monotonic clock under test: the global value plus the test offset; the array form is untouched. */
        function hrtime(bool $as_number = false): array|int|float|false
        {
            return $as_number ? \hrtime(true) + PaidGrantMonotonicClock::$offsetNs : \hrtime(false);
        }
    }
}
