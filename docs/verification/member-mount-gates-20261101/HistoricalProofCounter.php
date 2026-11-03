<?php

namespace Tests\MemberMountGatesProbe {
    use App\Domain\Customers\ProductionCustomerAccess;
    use App\Domain\Customers\ProductionIdentity\IdentityPolicy;

    /** Manual-probe instrumentation only. Counts no arguments, identity values, keys or digests. */
    final class HistoricalProofCounter
    {
        public static bool $active = false;

        public static array $counts = ['capture' => 0, 'current' => 0, 'committed' => 0];

        public static function observe(string $data): void
        {
            if (! self::$active || ! str_starts_with($data, IdentityPolicy::VERSION."\0owner\0")) {
                return;
            }
            $matches = $historical = $retained = false;
            $phase = 'capture';
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
                if (($frame['class'] ?? '') === IdentityPolicy::class
                    && ($frame['function'] ?? '') === 'matches') {
                    $matches = true;
                }
                if (($frame['class'] ?? '') === ProductionCustomerAccess::class) {
                    $method = $frame['function'] ?? '';
                    $historical = $historical || $method === 'historical';
                    $retained = $retained || in_array($method, ['verifyHistoricalBinding', 'verifyHistoricalBindingCommitted'], true);
                    if ($method === 'proveHistoricalBindingCurrent') {
                        $phase = 'current';
                    } elseif ($method === 'proveHistoricalBindingCommitted') {
                        $phase = 'committed';
                    }
                }
            }
            // Count retained-binding proofs only; current-principal read() also validates historical evidence.
            // Under the asserted single-key fixture each retained verification has one owner-match MAC.
            if ($matches && $historical && $retained) {
                self::$counts[$phase]++;
            }
        }
    }
}

namespace App\Domain\Customers\ProductionIdentity {
    use Tests\MemberMountGatesProbe\HistoricalProofCounter;

    /** Same primitive and arguments; inspection is enabled only for a separate, unmeasured proof-count read. */
    function hash_hmac(string $algo, string $data, #[\SensitiveParameter] string $key, bool $binary = false): string
    {
        HistoricalProofCounter::observe($data);

        return \hash_hmac($algo, $data, $key, $binary);
    }
}
