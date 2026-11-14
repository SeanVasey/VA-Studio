<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deletes expired rows, and only expired rows, from the database cache store's `cache` and `cache_locks` tables.
 *
 * Laravel removes an expired `cache` row only when that exact key is read again, and ships no command that does it in bulk
 * (`cache:prune-stale-tags` is Redis-only). Filament 5.10 keeps undelivered staff flash notifications in this store as plain
 * text for `session.lifetime` minutes; a notification that is never rendered would stay in the table, and in every backup,
 * indefinitely (independent review R3 of the Filament 5.10.2 update). Rate-limiter counters accumulate the same way.
 *
 * Every distinct database store in use is pruned: the default store, `filament.cache_store`, `cache.limiter` and the database
 * stores of a `failover` store, each connection and table pair once. A table of another store type (file, Redis, array) is left
 * to that store. On a store whose `connection` or `lock_connection` differs from the default, the rows of that connection are
 * the ones pruned, never the default connection's.
 *
 * Never use `cache:clear` or `Cache::flush()` for this. They delete unexpired rows as well: the throttle counters, the
 * "forever" TOTP replay records (`filament:auth:app_authentication:code:*`) and the held locks that live in the same tables.
 *
 * The boundary is `expiration <= now`, the same one DatabaseStore::get() uses (it serves a row only while `expiration > now`) and
 * DatabaseLock uses (a lock is free once `expiration <= now`). A row that is exactly at the boundary is already unreadable, so
 * removing it changes nothing a reader can observe. `forever()` is `put(..., 315360000)`: ten years ahead, not a sentinel, so an
 * "expiration <= now" predicate keeps those rows until ten years have passed. The cutoff is read once, before the first
 * statement, so a row that is written or refreshed while the command runs is never selected on a later batch.
 *
 * Each batch selects at most --batch keys through the `expiration` index and then deletes those keys while re-checking the
 * cutoff, so a row refreshed between the two statements survives and no statement holds a long lock. The command prints counts
 * only: never a key, a value or an exception message (a query exception quotes its bindings, which are keys).
 */
final class PruneExpiredCacheRows extends Command
{
    public const DEFAULT_BATCH = 1000;

    public const MAX_BATCH = 5000;

    protected $signature = 'vasey:cache-prune-expired {--batch=1000 : Rows deleted per statement, 1 to 5000}';

    protected $description = 'Delete expired rows (only) from the database cache and cache-lock tables; never clears unexpired entries.';

    public function handle(): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if ($batch === false || $batch < 1 || $batch > self::MAX_BATCH) {
            $this->error('Invalid --batch.');

            return self::INVALID;
        }

        $rowTargets = $lockTargets = [];
        if (! $this->targets($rowTargets, $lockTargets)) {
            $this->error('The database cache store configuration is incomplete; nothing was pruned.');

            return self::FAILURE;
        }
        if ($rowTargets === []) {
            $default = config('cache.default');
            $store = is_string($default) ? config('cache.stores.'.$default) : null;
            $this->info(sprintf('Nothing to prune: the default cache store uses the "%s" driver, not "database".',
                is_array($store) ? (is_string($store['driver'] ?? null) ? $store['driver'] : 'unknown') : 'unknown'));

            return self::SUCCESS;
        }

        $cutoff = CarbonImmutable::now()->getTimestamp();
        $failed = false;
        $rows = 0;
        foreach ($rowTargets as [$connection, $table]) {
            $rows += $this->prune('cache rows', DB::connection($connection), $table, $cutoff, $batch, $failed);
        }
        $locks = 0;
        foreach ($lockTargets as [$connection, $table]) {
            $locks += $this->prune('cache locks', DB::connection($connection), $table, $cutoff, $batch, $failed);
        }
        if ($failed) {
            return self::FAILURE;
        }

        $this->info(sprintf('Pruned %d expired cache row(s) and %d expired cache lock(s); batch size %d.', $rows, $locks, $batch));

        return self::SUCCESS;
    }

    /**
     * The tables to prune: those of every distinct database store the application reads and writes through. That is the default
     * store, the one Filament keeps flash notifications in (`filament.cache_store`, the default when null), the rate limiter's
     * (`cache.limiter`, the default when null) and any database store a `failover` store lists. Moving one of them to another
     * database store therefore cannot reopen the backlog unnoticed (independent review P3-3 of the pruning command). Each
     * connection and table pair is listed once, whatever number of stores share it.
     *
     * @param  list<array{0: ?string, 1: string}>  $rowTargets
     * @param  list<array{0: ?string, 1: string}>  $lockTargets
     * @return bool false when a database store's configuration is incomplete
     */
    private function targets(array &$rowTargets, array &$lockTargets): bool
    {
        $default = config('cache.default');
        $names = [];
        foreach ([$default, config('filament.cache_store') ?? $default, config('cache.limiter') ?? $default] as $name) {
            $this->expand($name, $names);
        }
        foreach (array_keys($names) as $name) {
            $store = config('cache.stores.'.$name);
            if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
                continue;
            }
            $table = $store['table'] ?? null;
            $connection = $store['connection'] ?? null;
            $lockTable = $store['lock_table'] ?? 'cache_locks';
            $lockConnection = $store['lock_connection'] ?? $connection;
            if (! is_string($table) || $table === '' || ! is_string($lockTable) || $lockTable === ''
                || ! (is_string($connection) || $connection === null) || ! (is_string($lockConnection) || $lockConnection === null)) {
                return false;
            }
            $rowTargets[(string) $connection."\0".$table] = [$connection, $table];
            $lockTargets[(string) $lockConnection."\0".$lockTable] = [$lockConnection, $lockTable];
        }
        $rowTargets = array_values($rowTargets);
        $lockTargets = array_values($lockTargets);

        return true;
    }

    /** Adds a store name and, for a `failover` store, the stores it lists (each name once, so a cycle ends). */
    private function expand(mixed $name, array &$names): void
    {
        if (! is_string($name) || isset($names[$name])) {
            return;
        }
        $names[$name] = true;
        $store = config('cache.stores.'.$name);
        if (is_array($store) && ($store['driver'] ?? null) === 'failover' && is_array($store['stores'] ?? null)) {
            foreach ($store['stores'] as $member) {
                $this->expand($member, $names);
            }
        }
    }

    private function prune(string $what, ConnectionInterface $connection, string $table, int $cutoff, int $batch, bool &$failed): int
    {
        $deleted = 0;
        try {
            do {
                $keys = $connection->table($table)->where('expiration', '<=', $cutoff)->orderBy('expiration')->limit($batch)->pluck('key')->all();
                if ($keys !== []) {
                    $deleted += $connection->table($table)->whereIn('key', $keys)->where('expiration', '<=', $cutoff)->delete();
                }
            } while (count($keys) === $batch);
        } catch (Throwable $error) {
            // Class and SQLSTATE only: the message quotes the statement and its bindings, which are cache keys.
            $failed = true;
            $this->error(sprintf('Pruning %s failed after %d deletion(s) (%s, code %s).', $what, $deleted, $error::class, (string) $error->getCode()));
        }

        return $deleted;
    }
}
