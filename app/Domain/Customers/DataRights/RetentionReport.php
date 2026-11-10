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
     * @return array{policy_reference: string, as_of: string, classes: list<array{class: string, unit: string, period: int, cutoff: string,
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
            $unit = str_ends_with($key, '_days') ? 'days' : 'years';
            $period = $retention[$key];
            $cutoff = $unit === 'days' ? $at->subDays($period) : $at->subYears($period);
            $due = fn (CarbonImmutable $from): CarbonImmutable => $unit === 'days' ? $from->addDays($period) : $from->addYears($period);
            $tables = [];
            foreach (RetentionSchedule::forClass($class) as $entry) {
                $tables[] = ['table' => $entry['table']] + $this->measure($entry, $cutoff, $due);
            }
            $classes[] = ['class' => $class, 'unit' => $unit, 'period' => $period, 'cutoff' => $cutoff->toDateString(), 'tables' => $tables];
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

    /** @param  \Closure(CarbonImmutable): CarbonImmutable  $due  the date a row dated at its argument falls due */
    private function measure(array $entry, CarbonImmutable $cutoff, \Closure $due): array
    {
        $clock = $entry['clock'];
        if ($clock === null) {
            return ['held' => 'clock_not_modeled'];
        }
        $table = $entry['table'];
        $dated = isset($clock['columns']) ? $this->dateExpression($clock) : null;
        $max = DB::table($table)->max('id');
        $count = 0;
        $oldest = $newest = null;
        for ($from = 0; $max !== null && $from < (int) $max; $from += self::CHUNK) {
            $query = DB::table($table.' as r')->where('r.id', '>', $from)->where('r.id', '<=', $from + self::CHUNK);
            $this->past($query, $clock, $cutoff);
            $row = $query->selectRaw('count(*) as n'.($dated === null ? '' : ", min($dated) as lo, max($dated) as hi"))->first();
            $count += (int) $row->n;
            if ($dated !== null && $row->n > 0) {
                $oldest = $oldest === null || $this->moment($clock, $row->lo)->lessThan($oldest) ? $this->moment($clock, $row->lo) : $oldest;
                $newest = $newest === null || $this->moment($clock, $row->hi)->greaterThan($newest) ? $this->moment($clock, $row->hi) : $newest;
            }
        }
        if (isset($clock['via'])) {
            return ['past_period' => $count, 'follows' => $clock['via'][1]];
        }

        return ['past_period' => $count, 'oldest_due' => $oldest === null ? null : $due($oldest)->toDateString(),
            'newest_due' => $newest === null ? null : $due($newest)->toDateString(), 'clock_index' => $this->indexed($table, $clock['columns'][0])];
    }

    /**
     * Rows whose clock has run out: every clock column set and at or before the cutoff, and nothing that holds them. A `via` row
     * follows its parent, joined under a fresh alias per level so a parent that itself follows a parent stays unambiguous.
     * `when` limits either kind of clock to rows whose column holds one value; any other row is never past.
     */
    private function past(Builder $query, array $clock, CarbonImmutable $cutoff, string $alias = 'r'): void
    {
        if (isset($clock['when'])) {
            $query->where($alias.'.'.$clock['when'][0], '=', $clock['when'][1]);
        }
        if (isset($clock['via'])) {
            [$foreign, $parent] = $clock['via'];
            $key = $clock['via'][2] ?? 'id';
            $parentClock = RetentionSchedule::byTable()[$parent]['clock'];
            $query->whereExists(function (Builder $sub) use ($parent, $foreign, $key, $parentClock, $cutoff, $alias): void {
                $sub->selectRaw('1')->from($parent.' as '.$alias.'p')->whereColumn($alias.'p.'.$key, $alias.'.'.$foreign);
                $this->past($sub, $parentClock, $cutoff, $alias.'p');
            });

            return;
        }
        $bound = $this->bound($clock, $cutoff);
        foreach ($clock['columns'] as $column) {
            $query->whereNotNull($alias.'.'.$column)->where($alias.'.'.$column, '<=', $bound);
        }
        if (isset($clock['unless_later'])) {
            [$child, $foreign, $column] = $clock['unless_later'];
            $query->whereNotExists(fn (Builder $sub) => $sub->selectRaw('1')->from($child.' as '.$alias.'l')
                ->whereColumn($alias.'l.'.$foreign, $alias.'.id')->where($alias.'l.'.$column, '>', $bound));
        }
        foreach ($clock['unless_referenced'] ?? [] as $hold) {
            [$holder, $foreign] = $hold;
            $own = $hold[2] ?? 'id';
            $query->whereNotExists(fn (Builder $sub) => $sub->selectRaw('1')->from($holder.' as '.$alias.'h')
                ->whereColumn($alias.'h.'.$foreign, $alias.'.'.$own));
        }
    }

    /** The cutoff in the clock columns' own storage format, so the comparison is exact on SQLite and MySQL alike. */
    private function bound(array $clock, CarbonImmutable $cutoff): int|string
    {
        return match ($clock['format'] ?? 'datetime') {
            'epoch' => $cutoff->getTimestamp(),
            'iso8601' => $cutoff->format('Y-m-d\TH:i:s\Z'),
            default => $cutoff->format('Y-m-d H:i:s'),
        };
    }

    /** A stored clock value as a UTC moment. */
    private function moment(array $clock, int|string $value): CarbonImmutable
    {
        return ($clock['format'] ?? 'datetime') === 'epoch' ? CarbonImmutable::createFromTimestampUTC((int) $value) : CarbonImmutable::parse($value, 'UTC');
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
            $columns = array_merge($clock['columns'] ?? [], isset($clock['via']) ? [$clock['via'][0]] : [],
                isset($clock['when']) ? [$clock['when'][0]] : [], array_map(fn (array $hold): string => $hold[2] ?? 'id', $clock['unless_referenced'] ?? []),
                $clock !== [] ? ['id'] : []);
            if (! Schema::hasTable($entry['table']) || ($columns !== [] && ! Schema::hasColumns($entry['table'], $columns))) {
                throw new DataRightsException(503, 'retention_schedule_table_missing');
            }
        }
    }
}
