<?php

namespace App\Domain\Media\Lifecycle;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Media\Models\MediaUploadSession;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Reference checks for media asset families (FP-034, tier 3). Read-only: it never deletes, locks or decides deletion.
 *
 * A family is one uploaded source asset (no parent) with the derived assets whose parent it is, the processing runs whose
 * source it is and the upload session that created it. Rows of the family itself (its lineage and its own upload and
 * processing audit events) are not references; every other row that names a family member is, and so is publication of
 * its track. "Unattached" is returned only when every check found zero references AND coverage is complete: no row
 * anywhere holds Laravel ciphertext (outside the reviewed {@see self::OPAQUE_ALLOWLIST}), a registered opaque payload, a
 * hash-only manifest, an unresolved legacy track id, JSON the proof cannot parse, a value of a type the checks cannot
 * read, or more rows than a bounded check reads. Otherwise a family with no reference found is "unknown", never
 * "unattached".
 *
 * Which columns can hold a reference is decided by {@see MediaReferenceRegistry}, a schema-reviewed list, not by column
 * names. A candidate column of the live schema that the registry does not classify makes coverage incomplete.
 *
 * Checks (each counts referencing rows; their names form the per-family proof):
 *  - fk:<table>.<column>: registered integer columns holding the id of an asset, a processing run or an upload session;
 *  - track:<table>.<column>: registered columns holding the family's track id on a sold or committed row (an import
 *    mapping, a product draft member, an order or preparation line, a published offer revision, a free definition);
 *  - json:<table>.<column>: ids inside registered plaintext JSON. The small id lists and offer revision snapshots are read
 *    by the structural pass; snapshots, audit context, site releases, the fulfillment outbox and queued jobs only by the
 *    full proof, which treats a row it cannot parse, or more rows than {@see self::MAX_JSON_ROWS}, as incomplete coverage;
 *  - poly:<table>.<prefix>: registered polymorphic `<prefix>_type`/`<prefix>_id` pairs naming a member;
 *  - publication:track: the family's track is published, scheduled or was ever published;
 *  - content:<table>: any text value containing a member's SHA-256 or private storage path (case-insensitive), except in
 *    the family's own rows and in audit events whose subject is a family row.
 */
final class MediaReferenceProof
{
    /** Families per full proof: the content check compares every text column with every member's hash and path. */
    public const MAX_PROOF_FAMILIES = 10;

    /** Rows read per table by the content check; more makes coverage incomplete instead of reading further. */
    public const MAX_CONTENT_ROWS = 1000;

    /** Laravel Crypt output: base64 of `{"iv":`. */
    public const CIPHERTEXT_MARK = 'eyJpdiI6';

    /** Reviewed ciphertext columns that never name media: Filament TOTP secrets, encrypted sessions and cache values. */
    public const OPAQUE_ALLOWLIST = ['users.app_authentication_secret', 'users.app_authentication_recovery_codes',
        'sessions.payload', 'cache.value'];

    /** Candidate rows read per column by a full-proof JSON check; more makes coverage incomplete. */
    public const MAX_JSON_ROWS = 20000;

    /** Substrings one of which every key of {@see MediaReferenceJson}'s vocabulary contains, per parser. */
    private const JSON_KEY_HINTS = ['assets' => ['asset', 'preview', 'files', 'run_id'],
        'ids' => ['asset', 'preview', 'files', 'run_id', 'track', 'product']];

    private const TARGETS = ['media_assets' => MediaAsset::class, 'media_processing_runs' => MediaProcessingRun::class,
        'media_upload_sessions' => MediaUploadSession::class];

    private const TEXT_TYPES = ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'json', 'jsonb', 'enum', 'set',
        'nvarchar', 'nchar', 'clob', 'string', 'uuid'];

    private const PLAIN_TYPES = ['integer', 'int', 'bigint', 'smallint', 'mediumint', 'tinyint', 'boolean', 'bool', 'decimal',
        'numeric', 'double', 'float', 'real', 'datetime', 'timestamp', 'date', 'time', 'year', 'bit'];

    private const TERMS_PER_QUERY = 200;

    private ?array $schema = null;

    /** @var array<string, true> structural JSON columns holding a value the parser could not read */
    private array $unparsed = [];

    public function __construct(private readonly MediaReferenceRegistry $registry = new MediaReferenceRegistry) {}

    /**
     * @param  list<int>  $rootIds  source assets (no parent)
     * @return array<int, array{track_id:int, members:list<int>, runs:list<int>, sessions:list<int>, recorded_bytes:?int, checks:array<string,int>}>
     */
    public function structural(array $rootIds): array
    {
        $families = $this->families($rootIds);
        if ($families === []) {
            return [];
        }
        $schema = $this->schema();
        foreach ($families as &$family) {
            $family['checks'] = [];
            foreach ($schema['references'] as $name => $_) {
                $family['checks'][$name] = 0;
            }
            foreach ($schema['json'] as $name => $_) {
                $family['checks'][$name] = 0;
            }
            foreach ($schema['poly'] as $name => $_) {
                $family['checks'][$name] = 0;
            }
            foreach ($schema['tracks'] as $name => $_) {
                $family['checks'][$name] = 0;
            }
            $family['checks']['publication:track'] = 0;
        }
        unset($family);
        $this->unparsed = [];

        $owners = $this->owners($families);
        foreach ($schema['references'] as $name => [$table, $column, $target]) {
            $ids = array_keys($owners[$target]);
            foreach (array_chunk($ids, 500) as $chunk) {
                $select = array_values(array_unique(array_filter([$column, $this->hasColumn($table, 'id') ? 'id' : null,
                    $table === 'media_assets' ? 'parent_asset_id' : null, $table === 'media_processing_runs' ? 'source_asset_id' : null,
                    $table === 'media_upload_sessions' ? 'asset_id' : null])));
                foreach (DB::table($table)->whereIn($column, $chunk)->get($select) as $row) {
                    $root = $owners[$target][(int) $row->{$column}];
                    if ($this->rowFamily($table, $row, $owners) !== $root) {
                        $families[$root]['checks'][$name]++;
                    }
                }
            }
        }
        foreach ($schema['json'] as $name => [$table, $column, $parser]) {
            $hasId = $this->hasColumn($table, 'id');
            DB::table($table)->whereNotNull($column)->orderBy($hasId ? 'id' : $column)
                ->select(array_values(array_unique([...($hasId ? ['id'] : []), $column])))
                ->chunk(500, function ($rows) use (&$families, $owners, $name, $table, $column, $parser): void {
                    foreach ($rows as $row) {
                        $ids = MediaReferenceJson::read($parser, $row->{$column});
                        if ($ids === null) {
                            $this->unparsed[$table.'.'.$column] = true;

                            continue;
                        }
                        $this->count($families, $owners, $name, $table, $row, $ids);
                    }
                });
        }
        foreach ($schema['poly'] as $name => [$table, $prefix]) {
            foreach (self::TARGETS as $target => $class) {
                foreach (array_chunk(array_keys($owners[$target]), 500) as $chunk) {
                    foreach (DB::table($table)->where($prefix.'_type', $class)->whereIn($prefix.'_id', $chunk)->pluck($prefix.'_id') as $id) {
                        $families[$owners[$target][(int) $id]]['checks'][$name]++;
                    }
                }
            }
        }
        $tracks = array_values(array_unique(array_column($families, 'track_id')));
        $byTrack = [];
        foreach ($families as $root => $family) {
            $byTrack[$family['track_id']][] = $root;
        }
        foreach ($schema['tracks'] as $name => [$table, $column]) {
            foreach (array_chunk($tracks, 500) as $chunk) {
                foreach (DB::table($table)->whereIn($column, $chunk)->groupBy($column)->selectRaw(DB::getQueryGrammar()->wrap($column).' AS track_id, COUNT(*) AS aggregate')->get() as $row) {
                    foreach ($byTrack[(int) $row->track_id] ?? [] as $root) {
                        $families[$root]['checks'][$name] += (int) $row->aggregate;
                    }
                }
            }
        }
        $published = [];
        foreach (array_chunk($tracks, 500) as $chunk) {
            foreach (DB::table('tracks')->whereIn('id', $chunk)->where(fn ($query) => $query->where('status', 'published')
                ->orWhereNotNull('published_at')->orWhereNotNull('published_slug'))->pluck('id') as $id) {
                $published[(int) $id] = true;
            }
        }
        foreach ($families as &$family) {
            $family['checks']['publication:track'] = isset($published[$family['track_id']]) ? 1 : 0;
        }
        unset($family);

        return $families;
    }

    /**
     * The full proof for at most {@see self::MAX_PROOF_FAMILIES} families.
     *
     * @param  list<int>  $rootIds
     * @return array{coverage:'complete'|'incomplete', blockers:list<string>, families:array<int, array{state:string,
     *     track_id:int, members:list<int>, recorded_bytes:?int, checks:array<string,int>}>}
     */
    public function prove(array $rootIds): array
    {
        if (count($rootIds) > self::MAX_PROOF_FAMILIES) {
            throw new InvalidArgumentException('Too many families for one proof.');
        }
        $families = $this->structural($rootIds);
        $blockers = array_values(array_unique([...$this->blockers(), ...array_map(fn (string $column): string => 'unparsed:'.$column, array_keys($this->unparsed))]));
        sort($blockers);
        if ($blockers === [] && $families !== []) {
            // The cheap id checks run first; the content search over every text column only when they leave nothing open.
            $blockers = $this->deep($families);
            if ($blockers === []) {
                $blockers = $this->content($families);
            }
        }
        foreach ($families as &$family) {
            $family['state'] = array_sum($family['checks']) > 0 ? 'attached' : ($blockers === [] ? 'unattached' : 'unknown');
            unset($family['runs'], $family['sessions'], $family['needles']);
        }
        unset($family);

        return ['coverage' => $blockers === [] ? 'complete' : 'incomplete', 'blockers' => $blockers, 'families' => $families];
    }

    /**
     * Schema-wide reasons no family can be proven unattached, independent of the families asked about.
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $schema = $this->schema();
        $blockers = array_map(fn (string $column): string => 'unregistered:'.$column, $schema['unregistered']);
        $digests = $this->registry->entries('manifest_digests');
        $opaque = $this->registry->entries('opaque');
        foreach ($this->registry->entries('track_source_ids') as $qualified => $_) {
            [$table, $column] = explode('.', $qualified, 2);
            // No reviewed link from a legacy track id to a store track exists, so no value can be resolved.
            if ($this->hasColumn($table, $column) && $this->nonEmpty($table, $column)->exists()) {
                $blockers[] = 'unresolved_track_source:'.$qualified;
            }
        }
        foreach ($schema['tables'] as $table => $columns) {
            $scanned = [];
            foreach ($columns as $column => $type) {
                $qualified = $table.'.'.$column;
                if (isset($digests[$qualified])) {
                    if (DB::table($table)->whereNotNull($column)->exists()) {
                        $blockers[] = 'hash_only_manifest:'.$qualified;
                    }
                } elseif (isset($opaque[$qualified])) {
                    if ($this->nonEmpty($table, $column)->exists()) {
                        $blockers[] = 'opaque:'.$qualified;
                    }
                } elseif (in_array($type, self::TEXT_TYPES, true)) {
                    if (! in_array($qualified, self::OPAQUE_ALLOWLIST, true)) {
                        $scanned[] = $column;
                    }
                } elseif (! in_array($type, self::PLAIN_TYPES, true) && DB::table($table)->whereNotNull($column)->exists()) {
                    $blockers[] = 'uninspected_type:'.$qualified;
                }
            }
            if ($scanned !== [] && DB::table($table)->where(function ($query) use ($scanned): void {
                foreach ($scanned as $column) {
                    $query->orWhere($column, 'like', '%'.self::CIPHERTEXT_MARK.'%');
                }
            })->exists()) {
                $blockers[] = 'ciphertext:'.$table;
            }
        }
        sort($blockers);

        return $blockers;
    }

    /** Candidate columns of the live schema that {@see MediaReferenceRegistry} does not classify. */
    public function unregistered(): array
    {
        return $this->schema()['unregistered'];
    }

    /**
     * Ids in the registered plaintext JSON that only the full proof reads (P2-3).
     *
     * @return list<string> blockers found while reading; counts are added to each family's checks
     */
    private function deep(array &$families): array
    {
        $owners = $this->owners($families);
        $jobs = $this->registry->entries('jobs');
        $blockers = [];
        foreach ($this->schema()['deep'] as $name => [$table, $column, $parser]) {
            foreach ($families as &$family) {
                $family['checks'][$name] = 0;
            }
            unset($family);
            $wrapped = DB::getQueryGrammar()->wrap($column);
            $identity = array_values(array_intersect(['id', 'parent_asset_id', 'source_asset_id', 'asset_id', 'subject_type', 'subject_id'],
                array_keys($this->schema()['tables'][$table])));
            $query = $this->nonEmpty($table, $column);
            if (isset(self::JSON_KEY_HINTS[$parser])) {
                // Rows without any vocabulary key cannot name an id; rows that are not JSON at all are caught below.
                $query->where(function ($query) use ($wrapped, $parser): void {
                    foreach (self::JSON_KEY_HINTS[$parser] as $hint) {
                        $query->orWhereRaw('LOWER(CAST('.$wrapped.' AS CHAR)) LIKE ?', ['%'.$hint.'%']);
                    }
                    $query->orWhereRaw('CAST('.$wrapped.' AS CHAR) NOT LIKE ?', ['{%']);
                });
            }
            $rows = $query->orderBy(in_array('id', $identity, true) ? 'id' : $column)->limit(self::MAX_JSON_ROWS + 1)
                ->get(array_values(array_unique([...$identity, $column])));
            if ($rows->count() > self::MAX_JSON_ROWS) {
                $blockers[] = 'json_bound:'.$table.'.'.$column;

                continue;
            }
            foreach ($rows as $row) {
                $ids = MediaReferenceJson::read($parser, $row->{$column}, $jobs);
                if ($ids === null) {
                    $blockers[] = 'unparsed:'.$table.'.'.$column;

                    continue 2;
                }
                $this->count($families, $owners, $name, $table, $row, $ids);
            }
        }
        sort($blockers);

        return $blockers;
    }

    /** Adds one to the named check of every family the ids of one row name, except the row's own family. */
    private function count(array &$families, array $owners, string $name, string $table, object $row, array $ids): void
    {
        $roots = [];
        foreach (['media_assets', 'media_processing_runs'] as $target) {
            foreach ($ids[$target] as $id) {
                if (isset($owners[$target][$id])) {
                    $roots[$owners[$target][$id]] = true;
                }
            }
        }
        if ($ids['tracks'] !== []) {
            $tracks = array_flip($ids['tracks']);
            foreach ($families as $root => $family) {
                if (isset($tracks[$family['track_id']])) {
                    $roots[$root] = true;
                }
            }
        }
        unset($roots[$this->rowFamily($table, $row, $owners) ?? 0]);
        foreach (array_keys($roots) as $root) {
            $families[$root]['checks'][$name]++;
        }
    }

    /** @return list<string> blockers found while reading; counts are added to each family's checks */
    private function content(array &$families): array
    {
        $needles = [];
        foreach ($families as $root => $family) {
            foreach ($family['needles'] as $needle) {
                $needles[strtolower($needle)][] = $root;
            }
        }
        $owners = $this->owners($families);
        $blockers = [];
        foreach ($this->schema()['tables'] as $table => $columns) {
            $text = array_keys(array_filter($columns, fn (string $type): bool => in_array($type, self::TEXT_TYPES, true)));
            if ($text === []) {
                continue;
            }
            foreach ($families as &$family) {
                $family['checks']['content:'.$table] = 0;
            }
            unset($family);
            $identity = array_values(array_intersect(['id', 'parent_asset_id', 'source_asset_id', 'asset_id', 'subject_type', 'subject_id'], array_keys($columns)));
            $terms = [];
            foreach ($text as $column) {
                foreach (array_keys($needles) as $needle) {
                    $terms[] = [$column, $needle];
                }
            }
            $rows = [];
            foreach (array_chunk($terms, self::TERMS_PER_QUERY) as $chunk) {
                $found = DB::table($table)->where(function ($query) use ($chunk): void {
                    foreach ($chunk as [$column, $needle]) {
                        // An explicit escape character makes `\`, `%` and `_` literal on SQLite and MySQL alike.
                        $query->orWhereRaw(DB::getQueryGrammar()->wrap($column)." LIKE ? ESCAPE '!'", ['%'.self::likeLiteral($needle).'%']);
                    }
                })->limit(self::MAX_CONTENT_ROWS + 1)->get(array_values(array_unique([...$identity, ...$text])));
                foreach ($found as $row) {
                    $key = isset($row->id) ? (string) $row->id : md5(serialize($row));
                    $rows[$key] = $row;
                }
                if ($found->count() > self::MAX_CONTENT_ROWS || count($rows) > self::MAX_CONTENT_ROWS) {
                    $blockers[] = 'content_bound:'.$table;

                    continue 2;
                }
            }
            foreach ($rows as $row) {
                $hit = [];
                foreach ($text as $column) {
                    $value = $row->{$column};
                    if (! is_string($value) || $value === '') {
                        continue;
                    }
                    foreach ($needles as $needle => $roots) {
                        if (stripos($value, $needle) !== false) {
                            foreach ($roots as $root) {
                                $hit[$root] = true;
                            }
                        }
                    }
                }
                foreach (array_keys($hit) as $root) {
                    if ($this->rowFamily($table, $row, $owners) !== $root) {
                        $families[$root]['checks']['content:'.$table]++;
                    }
                }
            }
        }
        sort($blockers);

        return $blockers;
    }

    private static function likeLiteral(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** The family a row belongs to (its own lineage, or an audit event about a family row), or null. */
    private function rowFamily(string $table, object $row, array $owners): ?int
    {
        return match ($table) {
            'media_assets' => isset($row->id) ? ($owners['media_assets'][(int) $row->id] ?? null) : null,
            'media_processing_runs' => isset($row->id) ? ($owners['media_processing_runs'][(int) $row->id] ?? null) : null,
            'media_upload_sessions' => isset($row->id) ? ($owners['media_upload_sessions'][(int) $row->id] ?? null) : null,
            'audit_events' => isset($row->subject_type, $row->subject_id) && ($target = array_search($row->subject_type, self::TARGETS, true)) !== false
                ? ($owners[$target][(int) $row->subject_id] ?? null) : null,
            default => null,
        };
    }

    /** @return array<string, array<int, int>> target table => row id => family root */
    private function owners(array $families): array
    {
        $owners = ['media_assets' => [], 'media_processing_runs' => [], 'media_upload_sessions' => []];
        foreach ($families as $root => $family) {
            foreach ($family['members'] as $id) {
                $owners['media_assets'][$id] = $root;
            }
            foreach ($family['runs'] as $id) {
                $owners['media_processing_runs'][$id] = $root;
            }
            foreach ($family['sessions'] as $id) {
                $owners['media_upload_sessions'][$id] = $root;
            }
        }

        return $owners;
    }

    private function families(array $rootIds): array
    {
        $rootIds = array_values(array_unique(array_map('intval', $rootIds)));
        $families = [];
        foreach (array_chunk($rootIds, 500) as $chunk) {
            foreach (DB::table('media_assets')->whereIn('id', $chunk)->orWhereIn('parent_asset_id', $chunk)->orderBy('id')
                ->get(['id', 'parent_asset_id', 'track_id', 'sha256', 'storage_path', 'size_bytes']) as $asset) {
                $root = $asset->parent_asset_id === null ? (int) $asset->id : (int) $asset->parent_asset_id;
                if ($asset->parent_asset_id === null && ! in_array((int) $asset->id, $rootIds, true)) {
                    continue;
                }
                $families[$root] ??= ['track_id' => 0, 'members' => [], 'runs' => [], 'sessions' => [], 'recorded_bytes' => 0, 'needles' => []];
                $family = &$families[$root];
                $family['members'][] = (int) $asset->id;
                if ($asset->parent_asset_id === null) {
                    $family['track_id'] = (int) $asset->track_id;
                }
                $bytes = filter_var($asset->size_bytes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $family['recorded_bytes'] = $bytes === false || $family['recorded_bytes'] === null || $bytes > PHP_INT_MAX - $family['recorded_bytes']
                    ? null : $family['recorded_bytes'] + $bytes;
                foreach ([$asset->sha256, $asset->storage_path] as $needle) {
                    if (is_string($needle) && strlen($needle) >= 16) {
                        $family['needles'][] = $needle;
                        // JSON encoders escape slashes by default: `media\/revisions\/...` names the same file.
                        if (str_contains($needle, '/')) {
                            $family['needles'][] = str_replace('/', '\\/', $needle);
                        }
                    }
                }
                unset($family);
            }
        }
        foreach (array_keys($families) as $root) {
            if (! in_array($root, $rootIds, true) || $families[$root]['track_id'] === 0) {
                throw new InvalidArgumentException('Only source assets (without a parent) start a family.');
            }
        }
        foreach (array_chunk(array_keys($families), 500) as $chunk) {
            foreach (DB::table('media_processing_runs')->whereIn('source_asset_id', $chunk)->get(['id', 'source_asset_id']) as $run) {
                $families[(int) $run->source_asset_id]['runs'][] = (int) $run->id;
            }
            foreach (DB::table('media_upload_sessions')->whereIn('asset_id', $chunk)->get(['id', 'asset_id']) as $session) {
                $families[(int) $session->asset_id]['sessions'][] = (int) $session->id;
            }
        }
        foreach ($families as &$family) {
            $family['needles'] = array_values(array_unique($family['needles']));
        }
        unset($family);

        return $families;
    }

    /** Rows holding a value in the column; an empty string is no value for a character column. */
    private function nonEmpty(string $table, string $column): Builder
    {
        $type = $this->schema()['tables'][$table][$column];
        $query = DB::table($table)->whereNotNull($column);

        return in_array($type, self::TEXT_TYPES, true) && ! in_array($type, ['json', 'jsonb'], true) ? $query->where($column, '<>', '') : $query;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return array_key_exists($column, $this->schema()['tables'][$table] ?? []);
    }

    /**
     * The live schema, and the registered columns that exist in it.
     *
     * @return array{tables:array<string,array<string,string>>, references:array<string,array{0:string,1:string,2:string}>,
     *     tracks:array<string,array{0:string,1:string}>, json:array<string,array{0:string,1:string,2:string}>,
     *     deep:array<string,array{0:string,1:string,2:string}>, poly:array<string,array{0:string,1:string}>, unregistered:list<string>}
     */
    private function schema(): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }
        $tables = $foreign = $references = $tracks = $json = $deep = $poly = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (str_starts_with($name, 'sqlite_')) {
                continue;
            }
            foreach (Schema::getColumns($name) as $column) {
                $tables[$name][$column['name']] = strtolower((string) $column['type_name']);
            }
        }
        ksort($tables);
        foreach (array_keys($tables) as $name) {
            foreach (Schema::getForeignKeys($name) as $key) {
                if ($key['foreign_table'] === 'tracks' || in_array($key['foreign_table'], MediaReferenceRegistry::TARGET_TABLES, true)) {
                    $foreign[$name] = [...($foreign[$name] ?? []), ...$key['columns']];
                }
            }
        }
        $present = fn (string $qualified): ?array => isset($tables[explode('.', $qualified, 2)[0]][explode('.', $qualified, 2)[1]])
            ? explode('.', $qualified, 2) : null;
        foreach ($this->registry->entries('media_ids') as $qualified => $target) {
            if ($found = $present($qualified)) {
                $references['fk:'.$qualified] = [...$found, $target];
            }
        }
        foreach ($this->registry->entries('track_ids') as $qualified => $_) {
            if ($found = $present($qualified)) {
                $tracks['track:'.$qualified] = $found;
            }
        }
        foreach ($this->registry->entries('json_structural') as $qualified => $parser) {
            if ($found = $present($qualified)) {
                $json['json:'.$qualified] = [...$found, $parser];
            }
        }
        foreach ($this->registry->entries('json_deep') as $qualified => $parser) {
            if ($found = $present($qualified)) {
                $deep['json:'.$qualified] = [...$found, $parser];
            }
        }
        foreach ($this->registry->entries('polymorphic') as $qualified => $_) {
            [$name, $prefix] = explode('.', $qualified, 2);
            if (isset($tables[$name][$prefix.'_type'], $tables[$name][$prefix.'_id'])) {
                $poly['poly:'.$qualified] = [$name, $prefix];
            }
        }
        ksort($references);
        ksort($tracks);
        ksort($json);
        ksort($deep);
        ksort($poly);

        return $this->schema = ['tables' => $tables, 'references' => $references, 'tracks' => $tracks, 'json' => $json, 'deep' => $deep,
            'poly' => $poly, 'unregistered' => $this->registry->unregistered($tables, $foreign)];
    }
}
