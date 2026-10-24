# Expired database-cache pruning (lane O4) — October 24, 2026

Closes review finding R3 of the Filament 5.10.2 update ([`../filament-510-20261018/`](../filament-510-20261018/README.md)): an undelivered staff
flash notification is plain text in the `cache` table for `session.lifetime` minutes, and nothing deleted it afterwards.

| | |
| --- | --- |
| **Tested source** | `4cbcf81d9ab15b5792022926b84c2af6e93195f4` on `harness/cache-prune-20261024` (`origin/main` at `e3e2a180` plus that commit). The commit after it changes only documentation (this directory, `CHANGELOG.md`, the two ops READMEs and the Filament packet's R3 status). |
| **Toolchain** | PHP 8.4.26, Composer 2.8.12, PHPUnit 12.5.34, Laravel 13.37.0, Filament 5.10.2, native MySQL 8.4.11 (`/opt/mysql-8.4.11`, private datadir, port 3381). |
| **Not done** | Nothing was pushed, no PR opened or commented on, no workflow dispatched, `composer.lock` untouched. |

## Design

- **Laravel ships nothing to reuse.** `vendor/laravel/framework/src/Illuminate/Cache/Console/` holds `ClearCommand`, `ForgetCommand`,
  `CacheTableCommand` and `PruneStaleTagsCommand` (`cache:prune-stale-tags`, Redis-only). `DatabaseStore` removes an expired row only when
  that key is read (`forgetManyIfExpired`, line 427); `DatabaseLock` prunes expired locks by a 2-in-100 lottery on each acquire. So a small
  command was written: `app/Console/Commands/PruneExpiredCacheRows.php`, `vasey:cache-prune-expired [--batch=1000]`.
- **Only expired rows.** `expiration <= now` against `cache` and `cache_locks`. Never `cache:clear` or `flush()`: those delete unexpired
  throttle counters, the TOTP replay records (`filament:auth:app_authentication:code:*`) and held locks.
- **Boundary `<=`, not `<`.** The store itself treats a row as expired when `expiration > now` is false (`DatabaseStore::get`, line 153;
  `forgetManyIfExpired`, line 437), and a lock as free when `expiration <= now` (`DatabaseLock` lines 88 and 181). A row at exactly `now`
  is already unreadable, so deleting it removes nothing a reader can see; `<` would leave dead rows for one more run and disagree with the
  store. `test_the_boundary_is_the_one_the_store_itself_uses` reads one such row through `Cache::get` (null) and pins both sides.
- **Configuration, not constants.** The default store's own entry in `config/cache.php` supplies `table`, `connection`, `lock_table` (default
  `cache_locks`) and `lock_connection` (default: `connection`), as `CacheManager::createDatabaseDriver` does. Any default store whose
  driver is not `database` (or that does not exist) is a no-op that prints `Nothing to prune: the default cache store uses the "<driver>"
  driver, not "database".` and exits 0. Filament reads `config('filament.cache_store')`, which this repository leaves null (the default), so
  the same store.
- **Bounded batches.** Each round selects at most `--batch` keys (default 1,000, maximum 5,000) ordered by the indexed `expiration`, then
  deletes `WHERE key IN (...) AND expiration <= cutoff`. Portable query-builder SQL, no `DELETE ... LIMIT` (unordered on MySQL, unsafe for
  statement-based replication). The cutoff is read once before the first statement, so the set only shrinks and the loop terminates, and the
  delete re-checks it, so a key refreshed between the select and the delete survives. It stops on a short batch or an empty select.
- **Counts only.** Success prints `Pruned N expired cache row(s) and M expired cache lock(s); batch size B.` On a database error it prints the
  exception class and code, never the message (a `QueryException` quotes its bindings, which are keys), keeps going with the other table and
  exits 1. It does not call `report()`, for the same reason.
- **Schedule:** `routes/console.php`, `Schedule::command('vasey:cache-prune-expired')->hourlyAt(23)->withoutOverlapping(10)`. Hourly bounds the
  retention of an expired row to the TTL plus one hour, and the work is a short indexed range delete, so a shorter period buys nothing and
  costs a query every few minutes on a table the request path also uses. `:23` keeps it off the minute-zero pile-up and the 03:17 backup. The
  overlap lock follows the existing line's reasoning (hygiene; a 10-minute expiry so a crashed run cannot stall it for the 1,440-minute default).

## The forever-key proof

Laravel 13.37 does not encode "forever" as a sentinel. `DatabaseStore::forever()` (line 331) is `put($key, $value, 315360000)`: the row's
`expiration` is `now + 315,360,000` seconds, exactly ten years. `expiration <= now` is false for it until those ten years have passed.
`test_forever_keys_the_totp_replay_record_and_live_throttles_are_never_deleted` uses the real writers (`Cache::forever` with the key shape
Filament writes for TOTP, `RateLimiter::hit`, `Cache::put`), asserts the stored expiration is `now + 315360000`, moves two hours ahead (the
short TTL row and the 900 s limiter counter go; the forever rows and the replay timestep stay readable through `Cache::get`), then moves to
`+315359999` s (both forever rows stay) and `+315360000` s (both go, by the store's own rule). So the command can delete a replay record only
when the store itself would already have refused to serve it. The consequence worth knowing: replay records are retained ten years, not
indefinitely; that is Laravel's and Filament's choice and is not changed here.

## Does it run on staging and production?

Read from the repository; nothing was run on a host.

- **Staging.** `ops/staging/workers/vasey-staging-workers.conf` defines `[program:vasey-staging-scheduler]`, `artisan schedule:work` from
  `current`, `autostart=true`, `autorestart=true`; `provision.sh` installs it with the other supervisor programs. `schedule:work` starts a new
  `schedule:run` process every minute (`ScheduleWorkCommand`), so every entry in `routes/console.php` runs, and a new release's entries apply
  from the first tick after `current` points at it. There is no cron to install and the Forge Scheduler must not also be used (the README says
  so). `forge-deploy.sh` requires `CACHE_STORE=database` (line 116), so the command's no-op branch does not apply there.
  **Verdict: yes, once this commit is deployed there and the scheduler program is running.** The existing liveness check
  (`ops/monitoring/scheduler-liveness-check.sh`) greps for the publish line and is unaffected.
- **Production.** `ops/production/` derives its kit from staging (`build-kit.py` renames the workers file to
  `vasey-production-workers.conf`; `--check` still passes: 13 files), so the same supervisor `schedule:work` program exists in the kit. The
  deploy checklist in `docs/ops/production-activation-packet.md` lists the unit and the `* * * * * ... schedule:run` cron line as the
  alternative "as U-02 decides". **Verdict: the kit will run it, but there is no production host (U-02 is undecided), so nothing runs
  today.** Whoever builds the host must run exactly one scheduler (the supervisor program or the cron line), set `CACHE_STORE=database` and
  keep the cache one shared persistent store (not `array`; not a `file` store that differs between hosts), or Filament notifications that
  cross requests and the TOTP replay records are lost or unshared. That requirement is now written in both READMEs ("Cache housekeeping").
- **Quiesce and backup.** The prune is a child of `schedule:run`, which `vasey-staging-ctl`'s `workers_stopped` pgrep pattern
  (`artisan (...|schedule:run|...)`) already waits for, so it does not run through a quiesced backup. Idempotent deletes need no further care.
- **Not changed (adjacent).** The header comment of the staging workers file and the sentence in `docs/ops/monitoring-and-host-baseline.md`
  still describe the scheduler as running one command; editing the conf comment risks `build-kit.py`'s counted patches, so it was left.

## Tests

`tests/Feature/PruneExpiredCacheRowsTest.php`, 19 tests, portable SQL, no driver branching (so it needs no census entry), no DDL (DDL commits
the test transaction on MySQL; the configured-table tests swap `cache` and `cache_locks`, which share `key` and `expiration`).

| Requirement | Test |
| --- | --- |
| Expired deleted, unexpired kept | `test_it_deletes_expired_rows_and_keeps_every_unexpired_row` |
| Boundary `<=` | `test_the_boundary_is_the_one_the_store_itself_uses` |
| Forever, TOTP replay key, throttles | `test_forever_keys_the_totp_replay_record_and_live_throttles_are_never_deleted`, `test_a_live_throttle_counter_survives_and_keeps_counting` |
| Locks expired deleted, held kept (still held) | `test_expired_locks_go_and_held_locks_stay_held`, `test_a_lock_that_expired_by_time_is_removed` |
| Batching across batches (3 deletes for 25 at 10; 2 for 20 at 10; each at most 11 bindings; locks too) | `test_it_deletes_in_bounded_batches_across_more_than_one_batch`, `test_an_exact_multiple_of_the_batch_ends_on_an_empty_select`, `test_the_lock_table_is_batched_too` |
| Refresh between select and delete | `test_a_row_refreshed_after_selection_survives_the_delete` |
| Bad `--batch` | `test_batch_option_is_validated_and_nothing_is_deleted_on_a_bad_value` |
| Non-database default store no-op | `test_a_non_database_default_store_is_a_no_op_with_a_clear_message` (array, file, failover, a missing store) |
| Configured tables, default store's own config | `test_it_uses_the_configured_tables_not_fixed_names`, `test_it_reads_the_default_store_configuration_not_a_store_named_database`, `test_incomplete_configuration_fails_without_touching_anything` |
| No keys, values or owners in output, also on failure | `test_the_output_never_contains_a_key_a_value_or_an_owner`, `test_a_failure_prints_the_class_and_code_but_never_the_message_and_still_prunes_the_other_table` |
| Real Filament notification | `test_an_unread_filament_notification_is_removed_after_its_ttl_and_a_fresh_one_is_kept` (plain-text row from `NotificationMailbox::flush()`, kept at 119 minutes, gone at 120) |
| Schedule | `test_it_is_scheduled_hourly_with_a_short_overlap_lock_and_never_as_a_clear` (`23 * * * *`, `withoutOverlapping`, expiry 10, and no scheduled command matches `cache:clear`, `cache:forget` or `flush`) |

## Verification

| Command | Result |
| --- | --- |
| `phpunit tests/Feature/PruneExpiredCacheRowsTest.php` (SQLite memory, direct PHPUnit) | OK (19 tests, 112 assertions) |
| the same file on native MySQL 8.4.11 (`vaseyaudio_o4_test`, full migrations) | OK (19 tests, 112 assertions) |
| the new file plus `SiteScheduleRunnerTest` and `BillingSweepHintsCommandTest` (the other tests that read the schedule) at the tested SHA | OK (34 tests, 194 assertions) |
| `scripts/ci/test-phpunit-shards.py` / `test-ci-scope.py` / `test-workflow-cadence.py` / `test-focused-tests.py` | OK: 52 / 27 / 14 / 53 tests |
| `phpunit-shards.py --shards=2 --prefix=phpunit-ci-sqlite ...` with the new file | proved 9,437 expanded tests in 689 files; the file is in the partition |
| `phpunit-shards.py --shards=24 --prefix=phpunit-ci-mysql --mysql-native-selection ...` | proof accepted (generated files deleted afterwards) |
| `python3 ops/production/build-kit.py --check` | ok, 13 files |
| `composer validate --strict` | valid |
| `git diff --check origin/main...HEAD` | clean |
| `composer audit` | one advisory, GHSA-mhf7-xp6r-6qrc (livewire/livewire `>=4.3.0,<4.4.8`, published 2026-10-23). **Pre-existing on main**, not caused by this lane; another lane fixes it. `composer.lock` was not touched. |

No census or selection edit was needed: the test branches on no driver and skips nothing, so `database-sqlite-skips.json`,
`database-mysql-skips.json` and `database-mysql-selection.json` are unchanged (the append-only census and its pinned hash are not involved).

## Mutations

[`mutations.txt`](mutations.txt) holds the raw output. Each was applied to a copy of the command (or `routes/console.php`), the file was run, and the original restored.

| Mutation | Tests that fail |
| --- | --- |
| `<` instead of `<=` in the select | 4 (boundary, forever, locks, notification) |
| `<` instead of `<=` in the delete re-check only | the same 4 |
| delete everything: no expiration filter on the select | 1 (batching; the delete re-check still protects) |
| delete everything: no re-check in the delete | 1 (refreshed row) |
| delete everything: no filter in select or delete (a flush) | 8 |
| cutoff `PHP_INT_MAX`, or `now + 315360000` (treat forever rows as expired) | 8 each |
| no batching (limit 1,000,000) | 2 |
| lock table not pruned | 5 |
| runs on a non-database store | 1 |
| prints the exception message | 1 |
| schedule `everyMinute()` instead of `hourlyAt(23)` | 1 |
| schedule without `withoutOverlapping` | 1 |

## Not tested

- **A real host.** No supervisor, `schedule:work`, Forge, cron or deploy was run; the staging and production answers above are from the
  configuration. No MySQL run on a table with millions of rows (batch timing and lock behaviour under load are unmeasured); the batch size is
  a bound, not a tuned value.
- **Cache connections other than the default.** `connection` and `lock_connection` are passed to `DB::connection()` as `CacheManager` does,
  but both tests use the default connection.
- **Multiple web hosts** sharing one cache database: the deletes are idempotent, but this was not run concurrently with live cache traffic
  on MySQL (the refresh race is covered on one connection by a listener, not by two sessions).
- **The Redis, file and memcached stores.** They expire their own entries; the command is a deliberate no-op there.
- **A failing delete on a real driver.** The failure path is exercised with a throwing query listener, not a real constraint or deadlock error.
- **Pint** is not enforced (open standard gap); no PHP style tool was run.

## Independent review

[`independent-review-4cbcf81d-DECISION.md`](independent-review-4cbcf81d-DECISION.md): **APPROVE** at `4cbcf81d`, no P0 to P2. The reviewer
reran the file on SQLite and on its own MySQL 8.4.11 (19 tests, 112 assertions each), caught eight of nine mutations, pruned 200k expired
rows and 20k expired locks in 27 s on MySQL with every live row kept, and ran a second-session refresh race with no refreshed key lost.
P3-2 (production README wording) is fixed in the docs commit; P3-1 and P3-3 to P3-5 are in the deferred ledger.
