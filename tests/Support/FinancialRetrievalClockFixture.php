<?php

namespace Tests\Support {
    use App\Domain\Commerce\ProductionFinancialRetrieval\ReadOwnership;
    use Closure;

    /** Test-only fault probe; every clock result is the unchanged real global clock. */
    final class FinancialRetrievalClockFixture
    {
        public static ?Closure $onFirstAttach = null;

        public static function now(bool $asNumber): array|int|float|false
        {
            if (self::$onFirstAttach !== null) {
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
                    if (($frame['class'] ?? null) === ReadOwnership::class && ($frame['function'] ?? null) === 'attach') {
                        $probe = self::$onFirstAttach;
                        self::$onFirstAttach = null;
                        $probe();
                        break;
                    }
                }
            }

            return \hrtime($asNumber);
        }
    }
}

namespace App\Domain\Commerce\ProductionFinancialRetrieval {
    use Tests\Support\FinancialRetrievalClockFixture;

    function hrtime(bool $asNumber = false): array|int|float|false
    {
        return FinancialRetrievalClockFixture::now($asNumber);
    }
}
