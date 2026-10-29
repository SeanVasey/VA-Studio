<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Independent review R3 of the Filament 5.10.2 update: undelivered Filament flash notifications (plain text, `session.lifetime`
 * TTL) and stale throttle keys stay in the `cache` table because Laravel deletes an expired row only when that exact key is read.
 * `vasey:cache-prune-expired` deletes expired rows (and expired locks) in bounded batches, and nothing else.
 */
class PruneExpiredCacheRowsTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-24 12:00:00';

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
        $this->now = CarbonImmutable::now()->getTimestamp();
        config(['cache.default' => 'database']);
        Cache::purge('database');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function row(string $key, int $expiration, string $table = 'cache'): void
    {
        $values = $table === 'cache' ? ['value' => 's:6:"MARKER";'] : ['owner' => 'OWNERMARKER'];
        DB::table($table)->insert(['key' => $key, 'expiration' => $expiration] + $values);
    }

    /** @return list<string> */
    private function keys(string $table = 'cache'): array
    {
        return DB::table($table)->orderBy('key')->pluck('key')->all();
    }

    /** @return array{0: int, 1: string} */
    private function prune(array $options = []): array
    {
        $exit = Artisan::call('vasey:cache-prune-expired', $options);

        return [$exit, trim(Artisan::output())];
    }

    public function test_it_deletes_expired_rows_and_keeps_every_unexpired_row(): void
    {
        $this->row('old-1', $this->now - 86400);
        $this->row('old-2', $this->now - 1);
        $this->row('live-1', $this->now + 1);
        $this->row('live-2', $this->now + 3600);

        [$exit, $output] = $this->prune();

        $this->assertSame(0, $exit);
        $this->assertSame(['live-1', 'live-2'], $this->keys());
        $this->assertSame('Pruned 2 expired cache row(s) and 0 expired cache lock(s); batch size 1000.', $output);
    }

    public function test_the_boundary_is_the_one_the_store_itself_uses(): void
    {
        // DatabaseStore::get() serves a row only while expiration > now, so a row at exactly now is already unreadable.
        // Deleting it (<=) removes nothing a reader can observe; a row one second ahead is still served and must stay.
        $this->row('at-boundary', $this->now);
        $this->row('probe-at-boundary', $this->now);
        $this->row('one-second-ahead', $this->now + 1);
        $this->row('probe-ahead', $this->now + 1);
        $this->assertNull(Cache::get('probe-at-boundary'));
        $this->assertNotNull(DB::table('cache')->where('key', 'like', '%probe-ahead')->first());

        $this->prune();

        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%one-second-ahead')->count());
        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%probe-ahead')->count());
        $this->assertSame(0, DB::table('cache')->where('key', 'like', '%at-boundary')->count());
    }

    public function test_forever_keys_the_totp_replay_record_and_live_throttles_are_never_deleted(): void
    {
        // The real writers: Filament's TOTP replay record is cache->forever(); the limiter is a TTL counter.
        $totp = 'filament:auth:app_authentication:code:'.hash('sha256', 'SYNTHETICSECRET');
        Cache::forever($totp, 56789012);
        Cache::forever('forever-other', 'v');
        RateLimiter::hit('login-throttle-synthetic', 900);
        Cache::put('short-lived', 'v', 60);
        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%filament:auth:app_authentication:code:%')->count());

        // Laravel 13.37 encodes forever as put(..., 315360000): ten years from now, not a sentinel or a null.
        $stored = DB::table('cache')->where('key', 'like', '%'.$totp)->first();
        $this->assertSame($this->now + 315360000, (int) $stored->expiration);

        $this->travelTo(CarbonImmutable::now()->addHours(2));
        [$exit] = $this->prune();
        $this->assertSame(0, $exit);
        $remaining = DB::table('cache')->pluck('key')->map(fn (string $key): string => str_contains($key, 'login-throttle') ? 'throttle' : (str_contains($key, 'short-lived') ? 'short' : $key))->all();
        $this->assertContains(Cache::getPrefix().$totp, $remaining);
        $this->assertContains(Cache::getPrefix().'forever-other', $remaining);
        $this->assertNotContains('short', $remaining, 'the short TTL row expired and goes');
        $this->assertNotContains('throttle', $remaining, 'the 900 s limiter counter expired and goes');
        $this->assertSame(56789012, Cache::get($totp), 'replay protection still sees the last accepted timestep');

        // One second short of ten years it is still there; at ten years it is expired by the store's own rule.
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($this->now + 315360000 - 1));
        $this->prune();
        $this->assertSame(2, DB::table('cache')->count());
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($this->now + 315360000));
        $this->prune();
        $this->assertSame(0, DB::table('cache')->count());
    }

    public function test_a_live_throttle_counter_survives_and_keeps_counting(): void
    {
        RateLimiter::hit('login-throttle-live', 900);
        RateLimiter::hit('login-throttle-live', 900);
        $this->row('expired-neighbour', $this->now - 5);

        $this->prune();

        $this->assertSame(2, RateLimiter::attempts('login-throttle-live'));
        $this->assertSame(0, DB::table('cache')->where('key', 'like', '%expired-neighbour')->count());
    }

    public function test_expired_locks_go_and_held_locks_stay_held(): void
    {
        $held = Cache::lock('held-synthetic', 600);
        $this->assertTrue($held->get());
        $this->row('expired-lock-1', $this->now - 1, 'cache_locks');
        $this->row('expired-lock-2', $this->now, 'cache_locks');
        $this->row('live-lock', $this->now + 30, 'cache_locks');

        [$exit, $output] = $this->prune();

        $this->assertSame(0, $exit);
        $this->assertSame(['held-synthetic', 'live-lock'], array_map(fn (string $key): string => str_replace(Cache::getPrefix(), '', $key), $this->keys('cache_locks')));
        $this->assertStringContainsString('and 2 expired cache lock(s)', $output);
        $this->assertFalse(Cache::lock('held-synthetic', 5)->get(), 'another owner still cannot take the held lock');
        $this->assertTrue($held->release());
    }

    public function test_a_lock_that_expired_by_time_is_removed(): void
    {
        $this->assertTrue(Cache::lock('short-held', 30)->get());
        $this->travelTo(CarbonImmutable::now()->addSeconds(31));

        $this->prune();

        $this->assertSame([], $this->keys('cache_locks'));
    }

    public function test_it_deletes_in_bounded_batches_across_more_than_one_batch(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->row(sprintf('expired-%02d', $i), $this->now - 100 + $i - 25);
        }
        for ($i = 0; $i < 7; $i++) {
            $this->row(sprintf('live-%02d', $i), $this->now + 100 + $i);
        }
        $statements = $this->recordDeletes();

        [$exit, $output] = $this->prune(['--batch' => 10]);

        $this->assertSame(0, $exit);
        $this->assertSame(7, DB::table('cache')->count());
        $this->assertStringContainsString('Pruned 25 expired cache row(s)', $output);
        $this->assertStringContainsString('batch size 10', $output);
        $cacheDeletes = array_values(array_filter($statements(), fn (array $s): bool => str_contains($s['sql'], '"cache"') || str_contains($s['sql'], '`cache`')));
        $this->assertCount(3, $cacheDeletes, '10 + 10 + 5');
        foreach ($cacheDeletes as $delete) {
            // At most the batch of keys plus the cutoff bind: no statement deletes the whole backlog.
            $this->assertLessThanOrEqual(11, count($delete['bindings']));
        }
    }

    public function test_an_exact_multiple_of_the_batch_ends_on_an_empty_select(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->row(sprintf('expired-%02d', $i), $this->now - 1 - $i);
        }
        $statements = $this->recordDeletes();

        $this->prune(['--batch' => 10]);

        $this->assertSame(0, DB::table('cache')->count());
        $this->assertCount(2, array_filter($statements(), fn (array $s): bool => str_contains($s['sql'], 'cache"') || str_contains($s['sql'], 'cache`')));
    }

    public function test_the_lock_table_is_batched_too(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->row(sprintf('expired-lock-%02d', $i), $this->now - 1 - $i, 'cache_locks');
        }

        [, $output] = $this->prune(['--batch' => 5]);

        $this->assertSame([], $this->keys('cache_locks'));
        $this->assertStringContainsString('and 12 expired cache lock(s)', $output);
    }

    public function test_a_row_refreshed_after_selection_survives_the_delete(): void
    {
        // The delete re-checks the cutoff, so a key re-put between the select and the delete is not removed.
        $this->row('refreshed', $this->now - 10);
        $this->row('still-expired', $this->now - 10);
        $refreshed = false;
        DB::listen(function (QueryExecuted $query) use (&$refreshed): void {
            if (! $refreshed && str_starts_with(strtolower($query->sql), 'select')) {
                $refreshed = true;
                DB::table('cache')->where('key', 'refreshed')->update(['expiration' => $this->now + 600]);
            }
        });

        $this->prune();

        $this->assertSame(['refreshed'], $this->keys());
    }

    public function test_batch_option_is_validated_and_nothing_is_deleted_on_a_bad_value(): void
    {
        $this->row('old', $this->now - 1);
        foreach (['0', '-1', '5001', 'abc', '1.5', ''] as $bad) {
            [$exit, $output] = $this->prune(['--batch' => $bad]);
            $this->assertSame(2, $exit, 'batch '.var_export($bad, true));
            $this->assertSame('Invalid --batch.', $output);
        }
        $this->assertSame(['old'], $this->keys());
        [$exit] = $this->prune(['--batch' => '5000']);
        $this->assertSame(0, $exit);
        $this->assertSame([], $this->keys());
    }

    public function test_a_non_database_default_store_is_a_no_op_with_a_clear_message(): void
    {
        $this->row('old', $this->now - 1);
        $this->row('old-lock', $this->now - 1, 'cache_locks');
        foreach (['array', 'file'] as $store) {
            config(['cache.default' => $store]);
            [$exit, $output] = $this->prune();
            $this->assertSame(0, $exit, $store);
            $this->assertStringStartsWith('Nothing to prune: the default cache store uses the "', $output);
            $this->assertStringContainsString('driver, not "database".', $output);
            $this->assertSame(['old'], $this->keys(), $store);
            $this->assertSame(['old-lock'], $this->keys('cache_locks'), $store);
        }
        config(['cache.default' => 'missing-store']);
        [$exit, $output] = $this->prune();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('not "database"', $output);
        $this->assertSame(['old'], $this->keys());
    }

    public function test_it_uses_the_configured_tables_not_fixed_names(): void
    {
        // Swap the two tables: the command reads only `key` and `expiration`, which both tables have, so no DDL is needed
        // (DDL would commit the test transaction on MySQL). If it used fixed names the counts below would be reversed.
        $this->row('named-default-expired', $this->now - 1);
        $this->row('named-default-live', $this->now + 9);
        $this->row('named-locks-expired-1', $this->now - 1, 'cache_locks');
        $this->row('named-locks-expired-2', $this->now - 2, 'cache_locks');
        $this->row('named-locks-live', $this->now + 9, 'cache_locks');
        config(['cache.stores.database.table' => 'cache_locks', 'cache.stores.database.lock_table' => 'cache']);

        [, $output] = $this->prune();

        $this->assertStringContainsString('Pruned 2 expired cache row(s) and 1 expired cache lock(s)', $output);
        $this->assertSame(['named-default-live'], $this->keys('cache'));
        $this->assertSame(['named-locks-live'], $this->keys('cache_locks'));
    }

    /**
     * Two separate in-memory SQLite connections stand in for DB_CACHE_CONNECTION and DB_CACHE_LOCK_CONNECTION. They are their own
     * databases, so creating their tables never touches the test transaction on the default connection.
     *
     * @return array{0: string, 1: string}
     */
    private function separateCacheConnections(): array
    {
        $names = ['prune_probe_rows', 'prune_probe_locks'];
        foreach ($names as $name) {
            config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
            DB::purge($name);
            Schema::connection($name)->create('cache', fn ($table) => $this->cacheColumns($table));
            Schema::connection($name)->create('cache_locks', fn ($table) => $this->cacheColumns($table));
        }

        return $names;
    }

    private function cacheColumns($table): void
    {
        $table->string('key')->primary();
        $table->mediumText('value')->nullable();
        $table->string('owner')->nullable();
        $table->integer('expiration')->index();
    }

    private function probeRow(string $connection, string $table, string $key, int $expiration): void
    {
        DB::connection($connection)->table($table)->insert(['key' => $key, 'value' => 'v', 'owner' => 'o', 'expiration' => $expiration]);
    }

    public function test_it_prunes_the_configured_connection_and_lock_connection_not_the_default_one(): void
    {
        [$rows, $locks] = $this->separateCacheConnections();
        // The default connection holds expired rows too: a regression to DB::connection() would prune these instead.
        $this->row('default-expired', $this->now - 1);
        $this->row('default-lock-expired', $this->now - 1, 'cache_locks');
        $this->probeRow($rows, 'cache', 'rows-expired', $this->now - 1);
        $this->probeRow($rows, 'cache', 'rows-live', $this->now + 60);
        $this->probeRow($locks, 'cache_locks', 'locks-expired', $this->now - 1);
        $this->probeRow($locks, 'cache_locks', 'locks-live', $this->now + 60);
        // The rows connection must not be asked for the lock table, or the lock connection for the rows.
        $this->probeRow($rows, 'cache_locks', 'rows-connection-lock-table', $this->now - 1);
        $this->probeRow($locks, 'cache', 'locks-connection-row-table', $this->now - 1);
        config(['cache.stores.database.connection' => $rows, 'cache.stores.database.lock_connection' => $locks]);

        [$exit, $output] = $this->prune();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Pruned 1 expired cache row(s) and 1 expired cache lock(s)', $output);
        $this->assertSame(['rows-live'], DB::connection($rows)->table('cache')->pluck('key')->all());
        $this->assertSame(['locks-live'], DB::connection($locks)->table('cache_locks')->pluck('key')->all());
        $this->assertSame(['rows-connection-lock-table'], DB::connection($rows)->table('cache_locks')->pluck('key')->all());
        $this->assertSame(['locks-connection-row-table'], DB::connection($locks)->table('cache')->pluck('key')->all());
        $this->assertSame(['default-expired'], $this->keys(), 'the default connection was not pruned');
        $this->assertSame(['default-lock-expired'], $this->keys('cache_locks'));
    }

    public function test_the_lock_connection_defaults_to_the_store_connection(): void
    {
        [$rows] = $this->separateCacheConnections();
        $this->probeRow($rows, 'cache', 'rows-expired', $this->now - 1);
        $this->probeRow($rows, 'cache_locks', 'locks-expired', $this->now - 1);
        $this->row('default-expired', $this->now - 1);
        config(['cache.stores.database.connection' => $rows, 'cache.stores.database.lock_connection' => null]);

        [, $output] = $this->prune();

        $this->assertStringContainsString('Pruned 1 expired cache row(s) and 1 expired cache lock(s)', $output);
        $this->assertSame([], DB::connection($rows)->table('cache_locks')->pluck('key')->all());
        $this->assertSame(['default-expired'], $this->keys());
    }

    public function test_a_failover_default_prunes_its_database_member(): void
    {
        // Review P3-3: a failover default over `database` used to print "Nothing to prune" and leave the backlog.
        $this->row('failover-expired', $this->now - 1);
        $this->row('failover-live', $this->now + 60);
        config(['cache.default' => 'failover']);

        [$exit, $output] = $this->prune();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Pruned 1 expired cache row(s)', $output);
        $this->assertSame(['failover-live'], $this->keys());
    }

    public function test_a_failover_cycle_ends(): void
    {
        config(['cache.stores.loop-a' => ['driver' => 'failover', 'stores' => ['loop-b', 'database']],
            'cache.stores.loop-b' => ['driver' => 'failover', 'stores' => ['loop-a']], 'cache.default' => 'loop-a']);
        $this->row('loop-expired', $this->now - 1);

        [$exit] = $this->prune();

        $this->assertSame(0, $exit);
        $this->assertSame([], $this->keys());
    }

    public function test_a_database_store_used_only_by_the_limiter_or_by_filament_is_pruned_too(): void
    {
        // Review P3-3: the default is `array` here, but the limiter and Filament are pointed at separate database stores. The
        // backlog would otherwise reopen silently while the command reported "Nothing to prune".
        [$limiter, $filament] = $this->separateCacheConnections();
        $this->probeRow($limiter, 'cache', 'limiter-expired', $this->now - 1);
        $this->probeRow($limiter, 'cache', 'limiter-live', $this->now + 60);
        $this->probeRow($filament, 'cache', 'filament-expired', $this->now - 1);
        $this->probeRow($filament, 'cache_locks', 'filament-lock-expired', $this->now - 1);
        $this->row('default-database-expired', $this->now - 1);
        config([
            'cache.default' => 'array',
            'cache.stores.limiter-db' => ['driver' => 'database', 'connection' => $limiter, 'table' => 'cache', 'lock_connection' => $limiter, 'lock_table' => 'cache_locks'],
            'cache.stores.filament-db' => ['driver' => 'database', 'connection' => $filament, 'table' => 'cache', 'lock_connection' => $filament, 'lock_table' => 'cache_locks'],
            'cache.limiter' => 'limiter-db',
            'filament.cache_store' => 'filament-db',
        ]);

        [$exit, $output] = $this->prune();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Pruned 2 expired cache row(s) and 1 expired cache lock(s)', $output);
        $this->assertSame(['limiter-live'], DB::connection($limiter)->table('cache')->pluck('key')->all());
        $this->assertSame([], DB::connection($filament)->table('cache')->pluck('key')->all());
        $this->assertSame([], DB::connection($filament)->table('cache_locks')->pluck('key')->all());
        $this->assertSame(['default-database-expired'], $this->keys(), 'the default store is not a database store here, so its table is not touched');
    }

    public function test_a_table_shared_by_several_stores_is_pruned_once(): void
    {
        $this->row('shared-expired-1', $this->now - 1);
        $this->row('shared-expired-2', $this->now - 2);
        $statements = $this->recordDeletes();
        config(['cache.stores.limiter-db' => config('cache.stores.database'), 'cache.limiter' => 'limiter-db', 'filament.cache_store' => 'database']);

        [, $output] = $this->prune();

        $this->assertStringContainsString('Pruned 2 expired cache row(s) and 0 expired cache lock(s)', $output);
        $this->assertCount(1, array_filter($statements(), fn (array $s): bool => str_contains($s['sql'], 'cache"') || str_contains($s['sql'], 'cache`')));
    }

    public function test_an_incomplete_non_default_database_store_fails_without_pruning_anything(): void
    {
        $this->row('default-expired', $this->now - 1);
        config(['cache.stores.broken' => ['driver' => 'database', 'table' => ''], 'cache.limiter' => 'broken']);

        [$exit, $output] = $this->prune();

        $this->assertSame(1, $exit);
        $this->assertSame('The database cache store configuration is incomplete; nothing was pruned.', $output);
        $this->assertSame(['default-expired'], $this->keys());
    }

    public function test_it_reads_the_default_store_configuration_not_a_store_named_database(): void
    {
        $this->row('database-named-store-expired', $this->now - 1);
        $this->row('second-store-expired', $this->now - 1, 'cache_locks');
        config(['cache.stores.second' => ['driver' => 'database', 'table' => 'cache_locks', 'lock_table' => 'cache_locks'], 'cache.default' => 'second']);

        $this->prune();

        $this->assertSame([], $this->keys('cache_locks'));
        $this->assertSame(['database-named-store-expired'], $this->keys('cache'));
    }

    public function test_the_output_never_contains_a_key_a_value_or_an_owner(): void
    {
        $this->row('KEYMARKER-expired', $this->now - 1);
        $this->row('KEYMARKER-live', $this->now + 100);
        $this->row('KEYMARKER-lock-expired', $this->now - 1, 'cache_locks');
        Cache::forever('filament:auth:app_authentication:code:KEYMARKER-totp', 'VALUEMARKER');

        [, $output] = $this->prune();

        foreach (['KEYMARKER', 'VALUEMARKER', 'MARKER', 'OWNERMARKER', 'filament', Cache::getPrefix(), 'totp'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output);
        }
        $this->assertMatchesRegularExpression('/\APruned \d+ expired cache row\(s\) and \d+ expired cache lock\(s\); batch size \d+\.\z/', $output);
    }

    public function test_a_failure_prints_the_class_and_code_but_never_the_message_and_still_prunes_the_other_table(): void
    {
        $this->row('KEYMARKER-expired', $this->now - 1);
        $this->row('KEYMARKER-lock-expired', $this->now - 1, 'cache_locks');
        // A listener that throws after a delete on `cache` carries the statement's bindings, the way a QueryException message does.
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'delete') && ! str_contains($query->sql, 'cache_locks')) {
                throw new RuntimeException('bindings leaked: '.implode(',', $query->bindings));
            }
        });

        [$exit, $output] = $this->prune();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Pruning cache rows failed after 0 deletion(s) (RuntimeException, code 0).', $output);
        $this->assertStringNotContainsString('KEYMARKER', $output);
        $this->assertStringNotContainsString('leaked', $output);
        $this->assertStringNotContainsString('Pruned ', $output, 'no success line after a failure');
        $this->assertSame([], $this->keys('cache_locks'), 'the lock table was still pruned');
    }

    public function test_incomplete_configuration_fails_without_touching_anything(): void
    {
        $this->row('old', $this->now - 1);
        config(['cache.stores.database.table' => null]);

        [$exit, $output] = $this->prune();

        $this->assertSame(1, $exit);
        $this->assertSame('The database cache store configuration is incomplete; nothing was pruned.', $output);
        $this->assertSame(['old'], $this->keys());
    }

    public function test_an_unread_filament_notification_is_removed_after_its_ttl_and_a_fresh_one_is_kept(): void
    {
        config(['session.driver' => 'array', 'session.lifetime' => 120, 'filament.cache_store' => null]);
        app('session.store')->start();
        Notification::make()->danger()->title('ABANDONED-TITLE')->body('never rendered operator text')->send();
        app(NotificationMailbox::class)->flush();
        $row = DB::table('cache')->where('key', 'like', '%filament:notifications:%')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('never rendered operator text', $row->value, 'plain text, which is the reason for pruning');
        $this->assertSame($this->now + 7200, (int) $row->expiration);

        $this->travelTo(CarbonImmutable::now()->addMinutes(119));
        $this->prune();
        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%filament:notifications:%')->count(), 'inside the TTL it stays');

        $this->travelTo(CarbonImmutable::now()->addMinutes(1));
        [, $output] = $this->prune();
        $this->assertSame(0, DB::table('cache')->where('key', 'like', '%filament:notifications:%')->count());
        $this->assertStringNotContainsString('operator text', $output);
    }

    public function test_it_is_scheduled_hourly_with_a_short_overlap_lock_and_never_as_a_clear(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $this->assertArrayHasKey('vasey:cache-prune-expired', Artisan::all());
        $events = collect($this->app->make(Schedule::class)->events());
        $prune = $events->filter(fn ($event) => str_contains((string) $event->command, 'vasey:cache-prune-expired'));
        $this->assertCount(1, $prune);
        $event = $prune->first();
        $this->assertSame('23 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
        foreach ($events as $scheduled) {
            $this->assertDoesNotMatchRegularExpression('/cache:clear|cache:forget|flush/i', (string) $scheduled->command);
        }
    }

    /** @return callable(): list<array{sql: string, bindings: array}> */
    private function recordDeletes(): callable
    {
        $seen = [];
        DB::listen(function (QueryExecuted $query) use (&$seen): void {
            if (str_starts_with(strtolower($query->sql), 'delete')) {
                $seen[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });

        return function () use (&$seen): array {
            return $seen;
        };
    }
}
