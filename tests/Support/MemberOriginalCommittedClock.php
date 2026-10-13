<?php

namespace Tests\Support {
    use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;

    /** Synthetic terminal-cap consumption only; ordinary calls preserve the real native clock. */
    final class MemberOriginalCommittedClock
    {
        public static ?\Closure $onFinish = null;

        public static function now(bool $asNumber): array|int|float|false
        {
            if (self::$onFinish !== null) {
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20) as $frame) {
                    if (($frame['class'] ?? null) === IdentityCommittedFrame::class
                        && ($frame['function'] ?? null) === 'finish') {
                        $work = self::$onFinish;
                        self::$onFinish = null;
                        $work();
                        break;
                    }
                }
            }

            return \hrtime($asNumber);
        }
    }
}

namespace App\Domain\Customers\ProductionIdentity {
    use Tests\Support\MemberOriginalCommittedClock;

    function hrtime(bool $as_number = false): array|int|float|false
    {
        return MemberOriginalCommittedClock::now($as_number);
    }
}
