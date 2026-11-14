# Independent review: `vasey:cache-prune-expired` (lane O4)

- **Reviewed SHA:** `4cbcf81d9ab15b5792022926b84c2af6e93195f4` (`origin/main` `e3e2a180` plus one commit). The worktree HEAD `ceb29a2b` adds docs only: `git diff --quiet 4cbcf81d HEAD -- app routes tests config database` showed no changes. The docs commit was reviewed for false claims.
- **Files:** `app/Console/Commands/PruneExpiredCacheRows.php`, `routes/console.php` (+8), `tests/Feature/PruneExpiredCacheRowsTest.php`.
- **Toolchain:** PHP 8.4.26, PHPUnit 12.5.34, Laravel 13.37.0 (vendor `Application::VERSION` and `composer.lock` agree), Filament 5.10.2, SQLite 3.45.1, and a private native MySQL 8.4.11 on port 34611 with its own datadir (stopped afterwards).
- **Reviewer actions:** nothing was pushed, committed or posted. Mutations were applied in place and reverted with `git checkout --`. `git status --short` is empty at the end.

## Verdict: **APPROVE**

I found no P0, P1 or P2 issue. The command deletes exactly the set of rows that Laravel's own read path already deletes. It mirrors `CacheManager`'s store construction, and it is bounded, quiet and covered by tests that fail under mutation. The P3 notes below are suggestions. None of them blocks the merge.

## Analysis traced through vendor code

### 1. Boundary and clock
- `DatabaseStore::many()` serves a row only while `$cache->expiration > $currentTime` (line 153). For the rest it calls `forgetManyIfExpired()`, which runs `DELETE ... WHERE key IN (...) AND expiration <= getTime()` (line 437). So **Laravel itself already deletes `expiration <= now` rows on read**. The command applies that same predicate to the whole table.
- `DatabaseLock` treats a lock as free when `expiration <= currentTime()` (the `acquire` takeover update). `pruneExpiredLocks()` (the 2-in-100 lottery) runs an unbatched `DELETE WHERE expiration <= currentTime()`. The command's lock-table predicate is identical.
- Clock source: the store uses `InteractsWithTime::currentTime()` = `Carbon::now()->getTimestamp()`. The command uses `CarbonImmutable::now()->getTimestamp()`. Both read PHP wall-clock time. Database time is used nowhere. `app.timezone` is UTC, and timestamps are timezone-agnostic anyway.
- Prefix: the command ignores the prefix and filters only on `expiration`, so it covers every prefix in the table under the same rule. That is correct. The vendor forget path also handles the `illuminate:cache:flexible:created:` companion keys; these share the TTL, so pruning covers them.
- `<=` versus `<`: `<=` is the store's own rule. A row at exactly `now` is already unreadable (the test asserts `Cache::get` returns null).

### 2. Could it delete an unexpired row? No path found.
- **Forever keys:** `DatabaseStore::forever()` is `put($key, $value, 315360000)`, so `expiration = now + 10y`. That is not a sentinel. The Filament TOTP replay record (`AppAuthentication.php:190`, `$cache->forever(...)` via `HasCacheStore::getCacheRepository()` = `Cache::store()`, the default store) survives until ten years have passed.
- **`add` and `increment`:** `add()` calls `get()` first, and `get()` deletes an expired row. `incrementOrDecrement()` changes only `value` and never `expiration`, so a limiter counter's lifetime is fixed by `add()`. Consider the race where `add()` sees a live row, the row then expires, the prune deletes it, and `increment()` returns false so `hits=0`. That interleaving can already happen when any concurrent `RateLimiter::attempts()` reader of the same key runs `forgetManyIfExpired`. The counter had expired in either case, so the prune adds no new behaviour.
- **Refresh between select and delete:** the delete re-checks `expiration <= cutoff`. On InnoDB, DELETE is a current (locking) read, so a committed refresh is seen. A two-session MySQL probe confirmed it: 1,652 keys were refreshed by a concurrent PDO process while the prune ran, and all 1,652 survived. Exactly 150,000 − 1,652 = 148,348 rows were deleted.
- **Its own overlap mutex:** the cutoff is read once at start, and the run's mutex lock row expires at `start + 600 > cutoff`, so the run never deletes its own mutex.
- **Table, connection and lock settings:** `lock_table ?? 'cache_locks'` and `lock_connection ?? connection` match `CacheManager::createDatabaseDriver` exactly (`config/cache.php` sets `lock_table => env('DB_CACHE_LOCK_TABLE')`, which is null by default). An empty-string table or a non-string value fails closed with exit 1.
- **Non-database default store:** array, file, failover or a missing store is a no-op with exit 0.
- **Collation:** `key` is the PRIMARY KEY in `utf8mb4_unicode_ci` (PAD SPACE). Because of that uniqueness, `WHERE key IN (selected keys)` can match only the rows that were selected.
- **Filament notifications:** `NotificationMailbox::mutate()` uses `Cache::store(config('filament.cache_store'))`. The app leaves that null (no published `config/filament.php`; the vendor default is null), so it is the default store. Its `{key}:lock` row has a 10 s lifetime in `cache_locks` and is handled the same way as the lock lottery handles it.

### 3. Termination and load
- The loop runs while `count($keys) === $batch`. Rows already selected either get deleted or have been refreshed beyond the cutoff, so they never come back. New rows at or below the cutoff can appear only from a host whose clock is behind (a finite trickle). The loop terminates. The batch is limited to 1–5000 and validated with `FILTER_VALIDATE_INT`. MySQL allows 65,535 placeholders; SQLite 3.45 allows 32,766.
- MySQL `EXPLAIN` with 50k rows: the select is a `range` scan on `cache_expiration_index` with "Using index" (covering) and `LIMIT`. The delete is a `range` scan on `PRIMARY` over the key list. Each statement autocommits. The select is a non-locking consistent read, and the delete locks at most 5,000 PK records for milliseconds. The app has no multi-row cache writes (`putMany`, `flexible`, `many`) that could lock rows in opposite order, so no deadlock cycle was identified.
- Load probe on native MySQL 8.4.11, unloaded host: 200,000 expired cache rows + 20,000 expired locks pruned in **27 s** at `--batch=5000`. All 50,000 live rows (including forever rows) and all 10,000 live locks survived. Exit 0.
- `withoutOverlapping(10)`: `CacheEventMutex` takes `lock(mutexName, 600)`. For an hourly job, a run longer than 10 minutes loses exclusion only against a run that starts 60 minutes later. Even then two concurrent runs are harmless, because the deletes are idempotent and touch only expired rows. `schedule:work` starts a new `schedule:run` each minute (`ScheduleWorkCommand` `->start()`), so a long prune does not block the every-minute publish job.

### 4. Information leaks
- Success output is counts and batch size only. A failure prints the class and `getCode()` only, the exception is swallowed (no `report()`), and the other table is still pruned. On Unix, scheduled output goes to `/dev/null` by default.
- `DB::connection($name)` sits outside the `try`. A misconfigured connection name throws `Database connection [name] not configured.`, which contains a configuration name, not a key.
- During a staging quiesce, Laravel's console kernel calls `setSignalsToDispatchEvent()` with no arguments, so the child dies on the supervisor group SIGTERM. The prune cannot outlive a quiesce.

### 5. Test quality: non-vacuous
I applied my own mutations, each run against the file on SQLite:

| Mutation | Result |
| --- | --- |
| M1 select `<=` → `<` | killed (4 failures) |
| M2 drop the delete's cutoff re-check | killed (refreshed-row test) |
| M3 hard-code `cache_locks` | killed (configured-tables test) |
| M4 append `$error->getMessage()` to the failure line | killed (failure-output test) |
| M5 cutoff `+1` | killed (3 failures) |
| M6 remove `->limit($batch)` | killed (2 batching tests) |
| M7 single pass (`while (false)`) | killed (3 failures) |
| M8 lock prune uses `$table` | killed (5 failures) |
| **M9 ignore the configured `connection`/`lock_connection` (`DB::connection()`)** | **survives**; see P3-1 |

The real writers are exercised: `Cache::forever` with the Filament TOTP key shape, `RateLimiter::hit`, `Cache::lock`, and Filament `Notification::send()` with `NotificationMailbox::flush()`.

### 6. Schedule placement and docs
- `hourlyAt(23)->withoutOverlapping(10)` gives `23 * * * *` in UTC. The 03:17 UTC backup quiesce stops the scheduler, so an overlapping 03:23 tick is skipped and the next hour picks up the work.
- Claims verified true: `forge-deploy.sh:116` enforces `need CACHE_STORE database`; staging `SESSION_LIFETIME=480`; the staging scheduler is `schedule:work` under supervisor with `stopasgroup` and `killasgroup`; the production kit derives `vasey-production-workers.conf` from the staging file (`build-kit.py:220`); `workers_stopped`'s pgrep pattern includes `schedule:run`; the line references in the verification README (153, 437, 331) are correct; the author's mutation table agrees with my independent mutations.
- Minor wording issue: see P3-2.

## Findings

- **P0 / P1 / P2:** none found.
- **P3-1 (test gap): connection routing is untested.** M9 passes all 19 tests. Failure path: a later edit passes `DB::connection()` instead of `$connection`, while a deployment sets `DB_CACHE_CONNECTION` to a separate connection. The command would then prune the default connection's `cache` table (still only expired rows) and report success, while the real cache store keeps its plaintext backlog. Suggest one test that configures a second sqlite `:memory:`-style connection, or asserts the connection name the queries run on through `QueryExecuted::$connectionName`.
- **P3-2 (docs wording):** `ops/production/README.md` says notifications "do not outlive their `SESSION_LIFETIME`". The real bound is TTL + up to 1 hour + run time, as `routes/console.php` and the staging README correctly say. Suggest aligning the wording.
- **P3-3 (scope, fails safe):** only the default store is pruned. If `filament.cache_store` or `cache.limiter` is later pointed at another database store, or the default becomes `failover` over `database`, the command prints "Nothing to prune" (or prunes only the default) and exits 0, so the retention gap silently reopens. Deletion safety is unaffected. Suggest a sentence in the ops "Cache housekeeping" sections.
- **P3-4 (operational): multi-host clock skew.** Expiry is judged by the scheduler host's clock. If that clock runs Δ seconds ahead of the web hosts, rows they would still serve are removed up to Δ seconds early. This affects throttle windows; ten-year forever rows are unaffected. Laravel's read path already has the same exposure across hosts. Suggest stating NTP as a host requirement when multiple hosts share the store (U-02).
- **P3-5 (adjacent, acknowledged by the author):** the `ops/staging/workers/vasey-staging-workers.conf` header comment (lines 96–97) and `docs/ops/monitoring-and-host-baseline.md` still describe a single scheduled command.

## Commands run (from `/home/user/wt/o4`)

| Command | Result |
| --- | --- |
| Direct PHPUnit, SQLite `:memory:`, `tests/Feature/PruneExpiredCacheRowsTest.php` (the prescribed env line) | OK (19 tests, 112 assertions), 5.2 s |
| The same file on native MySQL 8.4.11 (`DB_DATABASE=vaseyaudio_o4review`, `VA_LOCAL_DISPOSABLE_MYSQL=vaseyaudio_o4review`, port 34611) | OK (19 tests, 112 assertions), 6 m 47 s (full migrations) |
| Mutations M1–M9 (above), each run on SQLite then `git checkout --` | M1–M8 killed, M9 survives |
| MySQL `EXPLAIN` of the select and delete (50k rows) | covering range on `cache_expiration_index`; PK range for the delete |
| `php artisan vasey:cache-prune-expired --batch=5000` on MySQL with 200k expired / 50k live cache rows and 20k expired / 10k live locks | `Pruned 200000 ... and 20000 ...`, exit 0, 27 s; live rows intact |
| Concurrent refresher (separate PDO session) during `--batch=500` prune of 150k expired rows | 1,652 refreshed, 0 missing afterwards, 0 expired remaining |
| `mysqladmin shutdown` (my instance only); `git status --short` | stopped; clean |

## Untested conditions
- No real host: supervisor, `schedule:work`, a Forge deploy, a quiesce or a backup were not run.
- A MySQL table with millions of rows under live production traffic: lock waits and replication lag were not measured. The 200k-row probe ran on an idle local server.
- A real deadlock or constraint error on the failure path. My reasoning finds no cycle with the app's current cache writers, but this was not stress-tested with many concurrent sessions.
- A non-default `connection` or `lock_connection` (P3-1). SQLite was not tested beyond the test file.
- Multiple scheduler or web hosts with clock skew (P3-4).
- Backups taken before the prune still hold expired plaintext rows until backup retention rotates them. That is outside this change.
