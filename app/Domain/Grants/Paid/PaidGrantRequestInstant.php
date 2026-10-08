<?php

namespace App\Domain\Grants\Paid;

use Carbon\CarbonImmutable;

/**
 * The instant the server began a redeem request, validated once against the clock when it is captured (before the
 * identity proof). The constructor is private, so the only way to obtain one is `capture()`: no caller can hand
 * `PaidGrantDownloads::redeem()` an unvalidated time. Redeem uses `$at` as captured, without a second age check, so a
 * slow identity proof cannot turn a request that arrived in time into an expired one (Codex 4224939409).
 */
final class PaidGrantRequestInstant
{
    private function __construct(public readonly CarbonImmutable $at) {}

    /**
     * The SAPI's `REQUEST_TIME_FLOAT` (never a client header), else Laravel's `LARAVEL_START` (set at the top of
     * `public/index.php`, also before any identity work), else now. A candidate must be a finite PHP float or int no more
     * than 1 s ahead of now (clock granularity; clamped to now) and no older than
     * `PaidGrantDownloads::ADMISSION_MAX_AGE_SECONDS`. Anything else falls back to now, so a broken value can only make
     * admission stricter.
     */
    public static function capture(mixed $requestTime): self
    {
        $now = CarbonImmutable::now('UTC');
        foreach ([$requestTime, defined('LARAVEL_START') ? constant('LARAVEL_START') : null] as $candidate) {
            if ((is_float($candidate) || is_int($candidate)) && is_finite((float) $candidate)) {
                $at = self::admissible(CarbonImmutable::createFromTimestamp((float) $candidate, 'UTC'), $now);
                if ($at !== null) {
                    return new self($at);
                }
            }
        }

        return new self($now);
    }

    private static function admissible(CarbonImmutable $at, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($at->greaterThan($now->addSecond()) || $at->lessThan($now->subSeconds(PaidGrantDownloads::ADMISSION_MAX_AGE_SECONDS))) {
            return null;
        }

        return $at->lessThan($now) ? $at : $now;
    }
}
