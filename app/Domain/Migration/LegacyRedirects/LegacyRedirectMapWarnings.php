<?php

namespace App\Domain\Migration\LegacyRedirects;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Diagnostic throttling only. This state can never authorize a map or redirect. */
final class LegacyRedirectMapWarnings
{
    public const CACHE_KEY = 'legacy-redirects:invalid-map-warning';

    public const BACKOFF_SECONDS = 60;

    /** One local warning episode also bounds unavailable/evicted cache state; never a per-path history. */
    private static ?array $fallback = null;

    public function invalid(string $path): void
    {
        $source = hash('sha256', $path);
        try {
            $warn = Cache::add(self::CACHE_KEY, $source, self::BACKOFF_SECONDS);
            if (! $warn && Cache::get(self::CACHE_KEY) !== $source) {
                Cache::put(self::CACHE_KEY, $source, self::BACKOFF_SECONDS);
                $warn = true;
            }
        } catch (Throwable) {
            $warn = true;
        }
        if ($warn && $this->locallyDue($source)) {
            // Structural problem strings can include source labels/paths/data. Never put them into request logs.
            Log::warning('Legacy redirect map refused; no legacy redirects are served.', ['category' => 'invalid_map']);
        }
    }

    private function locallyDue(string $source): bool
    {
        $now = now()->getTimestamp();
        $previous = self::$fallback;
        if ($previous !== null && $previous['source'] === $source && $now >= $previous['at']
            && $now - $previous['at'] < self::BACKOFF_SECONDS) {
            return false;
        }
        self::$fallback = ['source' => $source, 'at' => $now];

        return true;
    }

    public function repaired(string $path): void
    {
        $source = hash('sha256', $path);
        try {
            if (Cache::get(self::CACHE_KEY) === $source) {
                Cache::forget(self::CACHE_KEY);
            }
        } catch (Throwable) {
            // A diagnostic store failure cannot change the result of a freshly verified valid map.
        }
        if ((self::$fallback['source'] ?? null) === $source) {
            self::$fallback = null;
        }
    }
}
