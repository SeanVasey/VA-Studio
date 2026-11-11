<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Shares repeated database reads within one public read decision (a catalog page, a track page, an embed, a public file's listing
 * gate), so a page that judges every offer of every candidate track does not re-read the same asset, processing run, license or rights
 * row once per offer, snapshot entry and page section (staging, 2026-11-11: 3,113 queries for one render).
 *
 * The memo is not a cache. It exists only inside scope() and is emptied when the outermost scope ends, so nothing survives the call
 * that opened it, let alone a request or a worker's next job. It is bypassed inside any database transaction, so no locking or
 * transactional read (checkout, publication, activation, delivery, staff review) is ever served from it, and any statement other than a
 * plain read (except writes to the cache tables, which no memoized value is read from) empties it, so a read that follows a write in the
 * same scope always reaches the database. Callers outside a scope get exactly the reads they always did.
 */
final class PublicReadMemo
{
    private static int $depth = 0;

    private static int $generation = 0;

    /** @var array<string, mixed> */
    private static array $values = [];

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function scope(callable $callback): mixed
    {
        self::$depth++;
        try {
            return $callback();
        } finally {
            if (--self::$depth === 0) {
                self::flush();
            }
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0 && DB::transactionLevel() === 0;
    }

    /**
     * The value for this key from earlier in the scope, or $compute's. Outside a scope or inside a transaction, always $compute's.
     *
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(string $key, Closure $compute): mixed
    {
        if (! self::active()) {
            return $compute();
        }
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }
        $generation = self::$generation;
        $value = $compute();
        // A write while computing (or a transaction opened by it) means the value may predate that write: use it once, keep nothing.
        if ($generation === self::$generation && self::active()) {
            self::$values[$key] = $value;
        }

        return $value;
    }

    /** Taken before a batch read whose rows are then primed, so a write in between keeps them out. */
    public static function generation(): int
    {
        return self::$generation;
    }

    /**
     * Records a value that a batch read inside this scope produced for a key, exactly as remember() would have computed it; an entry that
     * is already there is kept. Nothing is recorded outside an active scope or when anything was written since $generation.
     */
    public static function prime(string $key, mixed $value, int $generation): void
    {
        if (self::active() && $generation === self::$generation && ! array_key_exists($key, self::$values)) {
            self::$values[$key] = $value;
        }
    }

    public static function flush(): void
    {
        self::$values = [];
        self::$generation++;
    }

    /** Registered for every executed statement (AppServiceProvider). */
    public static function observe(QueryExecuted $query): void
    {
        if (self::$depth > 0 && self::writes($query->sql, $query->connection->getTablePrefix())) {
            self::flush();
        }
    }

    /** Anything but a plain SELECT counts as a write, except statements on the cache tables. */
    public static function writes(string $sql, string $prefix = ''): bool
    {
        if (preg_match('/\A\s*select\s/i', $sql) === 1) {
            return false;
        }
        if (preg_match('/\A\s*(?:insert\s+(?:or\s+\w+\s+|ignore\s+)?into|replace\s+into|update|delete\s+from)\s+[`"\[]?([A-Za-z0-9_]+)[`"\]]?[\s(]/i', $sql, $match) === 1) {
            $store = config('cache.stores.database', []);

            return ! in_array($match[1], [$prefix.($store['table'] ?? 'cache'), $prefix.($store['lock_table'] ?? 'cache_locks')], true);
        }

        return true;
    }
}
