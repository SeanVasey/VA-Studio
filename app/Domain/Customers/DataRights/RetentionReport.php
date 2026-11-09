<?php

namespace App\Domain\Customers\DataRights;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only dry run of the retention schedule (FP-076, U-13). For each class with a configured period it counts, per table,
 * the rows past their period and the earliest and latest date they fell due. It reads with plain selects only (no
 * transaction, no locks), scanning each table in primary-key ranges so no single query is unbounded. Counts and dates only:
 * no row identifiers, names or addresses leave the database. Nothing is deleted or anonymized. Tables the schedule does not
 * classify are listed by name as unclassified; they never fail the report.
 */
final class RetentionReport
{
    /** Primary-key range scanned per query. */
    public const CHUNK = 10000;

    public function __construct(private readonly DataRightsPolicy $policy) {}

    /**
     * @return array{policy_reference: string, as_of: string, classes: list<array{class: string, years: int, cutoff: string,
     *     tables: list<array{table: string, held?: string, past_period?: int, oldest_due?: ?string, newest_due?: ?string,
     *     follows?: string, clock_index?: bool}>}>, indefinite: list<string>, not_applicable: list<string>, unclassified: list<string>}
     *
     * @throws DataRightsException when the retention configuration is unset or invalid, or a scheduled table or column is missing
     */
    public function build(CarbonImmutable $at): array
    {
        $retention = $this->policy->retention();
        $this->assertInstalled();
        $at = $at->utc()->startOfSecond();
        $classes = [];
        foreach (RetentionSchedule::CLASSES as $class => $key) {
            if ($key === null) {
                continue;
            }
            $years = $retention[$key];
            $cutoff = $at->subYears($years);
            $tables = [];
            foreach (RetentionSchedule::forClass($class) as $entry) {
                $tables[] = ['table' => $entry['table']] + $this->measure($entry, $cutoff, $years);
            }
            $classes[] = ['class' => $class, 'years' => $years, 'cutoff' => $cutoff->toDateString(), 'tables' => $tables];
        }

        return ['policy_reference' => $retention['policy_reference'], 'as_of' => $at->toIso8601ZuluString(), 'classes' => $classes,
            'indefinite' => array_column(RetentionSchedule::forClass('indefinite'), 'table'),
            'not_applicable' => array_column(RetentionSchedule::forClass('not_applicable'), 'table'),
            'unclassified' => $this->unclassified()];
    }

    /**
     * Tables of this connection's own schema (SQLite `main`, the MySQL database) that are neither scheduled nor reviewed as
     * not customer data, by unprefixed name. Tables of other schemas, or without this connection's prefix, are not ours.
     *
     * @return list<string>
     */
    private function unclassified(): array
    {
        $prefix = DB::connection()->getTablePrefix();
        $known = RetentionSchedule::byTable() + RetentionSchedule::NOT_CUSTOMER_DATA;
        $tables = [];
        foreach (Schema::getTableListing(Schema::getCurrentSchemaListing(), false) as $name) {
            if (str_starts_with($name, $prefix) && ! array_key_exists($table = substr($name, strlen($prefix)), $known)) {
                $tables[] = $table;
            }
        }
        sort($tables, SORT_STRING);

        return $tables;
    }

    private function measure(array $entry, CarbonImmutable $cutoff, int $years): array
    {
        $clock = $entry['clock'];
        if ($clock === null) {
            return ['held' => 'clock_not_modeled'];
        }
        $table = $entry['table'];
        $bound = $cutoff->format('Y-m-d H:i:s');
        $dated = isset($clock['columns']) ? $this->dateExpression($clock) : null;
        $max = DB::table($table)->max('id');
        $count = 0;
        $oldest = $newest = null;
        for ($from = 0; $max !== null && $from < (int) $max; $from += self::CHUNK) {
            $query = DB::table($table.' as r')->where('r.id', '>', $from)->where('r.id', '<=', $from + self::CHUNK);
            $this->past($query, $clock, $bound);
            $row = $query->selectRaw('count(*) as n'.($dated === null ? '' : ", min($dated) as lo, max($dated) as hi"))->first();
            $count += (int) $row->n;
            if ($dated !== null && $row->n > 0) {
                $oldest = $oldest === null || $row->lo < $oldest ? $row->lo : $oldest;
                $newest = $newest === null || $row->hi > $newest ? $row->hi : $newest;
            }
        }
        if (isset($clock['via'])) {
            return ['past_period' => $count, 'follows' => $clock['via'][1]];
        }
        $due = fn (?string $value): ?string => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->addYears($years)->toDateString();

        return ['past_period' => $count, 'oldest_due' => $due($oldest), 'newest_due' => $due($newest),
            'clock_index' => $this->indexed($table, $clock['columns'][0])];
    }

    /** Rows whose clock has run out: every clock column set and at or before the cutoff, and nothing that holds them. */
    private function past(Builder $query, array $clock, string $bound, string $alias = 'r'): void
    {
        if (isset($clock['via'])) {
            [$foreign, $parent] = $clock['via'];
            $parentClock = RetentionSchedule::byTable()[$parent]['clock'];
            $query->whereExists(function (Builder $sub) use ($parent, $foreign, $parentClock, $bound, $alias): void {
                $sub->selectRaw('1')->from($parent.' as p')->whereColumn('p.id', $alias.'.'.$foreign);
                $this->past($sub, $parentClock, $bound, 'p');
            });

            return;
        }
        foreach ($clock['columns'] as $column) {
            $query->whereNotNull($alias.'.'.$column)->where($alias.'.'.$column, '<=', $bound);
        }
        if (isset($clock['unless_later'])) {
            [$child, $foreign, $column] = $clock['unless_later'];
            $query->whereNotExists(fn (Builder $sub) => $sub->selectRaw('1')->from($child.' as l')
                ->whereColumn('l.'.$foreign, $alias.'.id')->where('l.'.$column, '>', $bound));
        }
        foreach ($clock['unless_referenced'] ?? [] as [$holder, $foreign]) {
            $query->whereNotExists(fn (Builder $sub) => $sub->selectRaw('1')->from($holder.' as h')->whereColumn('h.'.$foreign, $alias.'.id'));
        }
    }

    /** The column that dates a past row: its last clock column, or its latest child row when a later child would hold it. */
    private function dateExpression(array $clock): string
    {
        $grammar = DB::connection()->getQueryGrammar();
        $own = $grammar->wrap('r.'.$clock['columns'][array_key_last($clock['columns'])]);
        if (! isset($clock['unless_later'])) {
            return $own;
        }
        [$child, $foreign, $column] = $clock['unless_later'];
        $latest = 'select max('.$grammar->wrap('l.'.$column).') from '.$grammar->wrapTable($child).' as '.$grammar->wrap('l')
            .' where '.$grammar->wrap('l.'.$foreign).' = '.$grammar->wrap('r.id');

        return "coalesce(($latest), $own)";
    }

    private function indexed(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    /** A scheduled table or clock column that does not exist refuses rather than reporting no rows. */
    private function assertInstalled(): void
    {
        foreach (RetentionSchedule::ENTRIES as $entry) {
            $clock = $entry['clock'] ?? [];
            $columns = array_merge($clock['columns'] ?? [], isset($clock['via']) ? [$clock['via'][0]] : [], $clock !== [] ? ['id'] : []);
            if (! Schema::hasTable($entry['table']) || ($columns !== [] && ! Schema::hasColumns($entry['table'], $columns))) {
                throw new DataRightsException(503, 'retention_schedule_table_missing');
            }
        }
    }
}
