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

        $storeName = config('cache.default');
        $store = is_string($storeName) ? config('cache.stores.'.$storeName) : null;
        if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
            $this->info(sprintf('Nothing to prune: the default cache store uses the "%s" driver, not "database".',
                is_array($store) ? (is_string($store['driver'] ?? null) ? $store['driver'] : 'unknown') : 'unknown'));

            return self::SUCCESS;
        }

        $table = $store['table'] ?? null;
        $connection = $store['connection'] ?? null;
        $lockTable = $store['lock_table'] ?? 'cache_locks';
        $lockConnection = $store['lock_connection'] ?? $connection;
        if (! is_string($table) || $table === '' || ! is_string($lockTable) || $lockTable === ''
            || ! (is_string($connection) || $connection === null) || ! (is_string($lockConnection) || $lockConnection === null)) {
            $this->error('The database cache store configuration is incomplete; nothing was pruned.');

            return self::FAILURE;
        }

        $cutoff = CarbonImmutable::now()->getTimestamp();
        $failed = false;
        $rows = $this->prune('cache rows', DB::connection($connection), $table, $cutoff, $batch, $failed);
        $locks = $this->prune('cache locks', DB::connection($lockConnection), $lockTable, $cutoff, $batch, $failed);
        if ($failed) {
            return self::FAILURE;
        }

        $this->info(sprintf('Pruned %d expired cache row(s) and %d expired cache lock(s); batch size %d.', $rows, $locks, $batch));

        return self::SUCCESS;
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
