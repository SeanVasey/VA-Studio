<?php

namespace Tests\Feature;

use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Scratch diagnostic (not for merge): where one MySQL migrate:fresh spends its time. */
final class ZzMigrationProfileTest extends TestCase
{
    public function test_profile(): void
    {
        $this->requireFixtureDatabase();
        $migrations = [];
        $current = null;
        $started = 0.0;
        $queries = [];
        Event::listen(MigrationStarted::class, function (MigrationStarted $e) use (&$current, &$started): void {
            $current = basename((new \ReflectionClass($e->migration))->getFileName());
            $started = microtime(true);
        });
        Event::listen(MigrationEnded::class, function () use (&$current, &$started, &$migrations): void {
            $migrations[$current] = microtime(true) - $started;
            $current = null;
        });
        DB::listen(function (QueryExecuted $q) use (&$queries, &$current): void {
            $sql = preg_replace('/\s+/', ' ', $q->sql);
            $kind = preg_match('/information_schema|performance_schema|SHOW /i', $sql) ? 'meta' : strtoupper(strtok(ltrim($sql), ' '));
            $queries[] = [$q->time / 1000, $kind, substr($sql, 0, 160), $current ?? '(outside a migration)'];
        });
        $t0 = microtime(true);
        Artisan::call('migrate:fresh');
        $total = microtime(true) - $t0;
        arsort($migrations);
        $byKind = [];
        $byOwner = [];
        foreach ($queries as [$time, $kind, , $owner]) {
            $byKind[$kind] = ($byKind[$kind] ?? 0) + $time;
            $byOwner[$owner] = ($byOwner[$owner] ?? 0) + $time;
        }
        arsort($byKind);
        usort($queries, fn ($a, $b) => $b[0] <=> $a[0]);
        $report = ['total_s' => round($total, 1), 'migrations' => count($migrations), 'migration_s' => round(array_sum($migrations), 1),
            'query_count' => count($queries), 'query_s' => round(array_sum(array_column($queries, 0)), 1),
            'query_s_by_kind' => array_map(fn ($v) => round($v, 1), array_slice($byKind, 0, 12, true)),
            'slowest_migrations' => array_map(fn ($v) => round($v, 2), array_slice($migrations, 0, 25, true)),
            'slowest_queries' => array_map(fn ($q) => [round($q[0], 3), $q[3], $q[2]], array_slice($queries, 0, 25))];
        fwrite(STDERR, "\nMIGRATION-PROFILE ".json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->assertTrue(true);
    }
}
