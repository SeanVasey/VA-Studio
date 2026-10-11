<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;

/**
 * Disposable-fixture reduction to an isolated 236 capability installation before its down() is exercised.
 *
 * Later additive migrations legitimately reference the capability tables (238 preparation packets,
 * 239 buyer assent, 246 checkout authority/basis/review and their children), and 239/246 deliberately
 * refuse operational teardown. The dependents are derived from the live catalog on the current
 * connection, so a later addition neither re-breaks these fixtures nor silently satisfies a refusal
 * case. Each is asserted empty and dropped leaves first with foreign-key enforcement unchanged; the
 * 238 packets keep their own ownership-checked down().
 */
final class CapabilityRollbackFixture
{
    public const OWNED = [CapabilityHistory::CANDIDATES, CapabilityHistory::APPROVALS, CapabilityHistory::CLOSURES];

    public static function isolateCapabilityTables(): void
    {
        $packets = [PacketEvidence::LINES, PacketEvidence::PACKETS];
        $dependents = self::dependents(self::OWNED);
        Assert::assertSame([], array_values(array_diff($packets, $dependents)), 'Preparation packets must reference the capability tables.');
        self::dropEmptyLeavesFirst(array_values(array_diff($dependents, $packets)));
        foreach ($packets as $table) {
            Assert::assertSame(0, DB::table($table)->count(), "Disposable fixture table {$table} must be empty.");
        }
        (require database_path('migrations/2026_10_06_238000_production_track_preparation_packets.php'))->down();
        Assert::assertSame([], self::dependents(self::OWNED), 'Capability tables must have no remaining foreign-key dependents.');
    }

    /** @return list<string> every other table whose foreign keys reach $roots, directly or transitively */
    public static function dependents(array $roots): array
    {
        $children = self::foreignKeyChildren();
        $roots = array_map('strtolower', $roots);
        $found = [];
        $queue = $roots;
        while ($queue !== []) {
            foreach ($children[array_shift($queue)] ?? [] as $child) {
                if (! in_array(strtolower($child), $roots, true) && ! in_array($child, $found, true)) {
                    $found[] = $child;
                    $queue[] = strtolower($child);
                }
            }
        }

        return $found;
    }

    /** @return array<string, list<string>> referenced table (lowercase) => referencing tables */
    private static function foreignKeyChildren(): array
    {
        $children = [];
        foreach (Schema::getTableListing(Schema::getCurrentSchemaName(), false) as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                $children[strtolower($key['foreign_table'])][] = $table;
            }
        }

        return array_map(fn (array $tables): array => array_values(array_unique($tables)), $children);
    }

    /**
     * Asserts each table empty and drops it once nothing else references it, with foreign-key enforcement
     * unchanged. Every table that references a listed table must itself be listed, as dependents() returns.
     *
     * @param  list<string>  $tables
     */
    public static function dropEmptyLeavesFirst(array $tables): void
    {
        while ($tables !== []) {
            $children = self::foreignKeyChildren();
            $leaves = array_values(array_filter($tables,
                fn (string $table): bool => array_diff($children[strtolower($table)] ?? [], [$table]) === []));
            Assert::assertNotSame([], $leaves, 'Dependent fixture tables must form an acyclic foreign-key graph.');
            foreach ($leaves as $table) {
                Assert::assertSame(0, DB::table($table)->count(), "Disposable fixture table {$table} must be empty.");
                Schema::drop($table);
            }
            $tables = array_values(array_diff($tables, $leaves));
        }
    }
}
