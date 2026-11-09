<?php

namespace App\Domain\Media\Lifecycle;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Media\Models\MediaUploadSession;
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
 * anywhere holds Laravel ciphertext (outside the reviewed {@see self::OPAQUE_ALLOWLIST}), a hash-only asset manifest, a value
 * of a type the checks cannot read, or more matches than the bounded content check reads. Otherwise a family with no
 * reference found is "unknown", never "unattached". Any table or foreign key added later is included automatically.
 *
 * Checks (each counts referencing rows; their names form the per-family proof):
 *  - fk:<table>.<column>: every foreign key in the schema into media_assets, media_processing_runs or
 *    media_upload_sessions, and every integer column named *asset_id without one;
 *  - json:<table>.<column>: asset ids inside the reviewed plaintext JSON id columns (every text column named *asset_ids,
 *    and offer revision snapshots), in any key naming assets, a preview or files;
 *  - poly:<table>.<prefix>: polymorphic `<prefix>_type`/`<prefix>_id` pairs naming a member (audit_events excepted, below);
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

    /** Reviewed plaintext JSON whose asset ids are read besides every text column named *asset_ids. */
    public const JSON_COLUMNS = ['offer_revisions.snapshot'];

    private const TARGETS = ['media_assets' => MediaAsset::class, 'media_processing_runs' => MediaProcessingRun::class,
        'media_upload_sessions' => MediaUploadSession::class];

    private const TEXT_TYPES = ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'json', 'jsonb', 'enum', 'set',
        'nvarchar', 'nchar', 'clob', 'string', 'uuid'];

    private const PLAIN_TYPES = ['integer', 'int', 'bigint', 'smallint', 'mediumint', 'tinyint', 'boolean', 'bool', 'decimal',
        'numeric', 'double', 'float', 'real', 'datetime', 'timestamp', 'date', 'time', 'year', 'bit'];

    private const TERMS_PER_QUERY = 200;

    private ?array $schema = null;

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
            $family['checks']['publication:track'] = 0;
        }
        unset($family);

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
        foreach ($schema['json'] as $name => [$table, $column]) {
            $runFamily = $table === 'media_processing_runs';
            $hasId = $this->hasColumn($table, 'id');
            DB::table($table)->whereNotNull($column)->orderBy($hasId ? 'id' : $column)
                ->select(array_values(array_unique([...($hasId ? ['id'] : []), $column])))
                ->chunk(500, function ($rows) use (&$families, $owners, $name, $column, $runFamily): void {
                    foreach ($rows as $row) {
                        $ids = $this->jsonAssetIds($row->{$column}, str_ends_with($column, 'asset_ids'));
                        $seen = [];
                        foreach ($ids as $id) {
                            $root = $owners['media_assets'][$id] ?? null;
                            if ($root === null || isset($seen[$root]) || ($runFamily && isset($row->id) && ($owners['media_processing_runs'][(int) $row->id] ?? null) === $root)) {
                                continue;
                            }
                            $seen[$root] = true;
                            $families[$root]['checks'][$name]++;
                        }
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
        $blockers = $this->blockers();
        if ($blockers === [] && $families !== []) {
            $blockers = $this->content($families);
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
        $blockers = [];
        foreach ($this->schema()['tables'] as $table => $columns) {
            $opaque = [];
            foreach ($columns as $column => $type) {
                $qualified = $table.'.'.$column;
                if (preg_match('/asset_manifest_(?:hash|sha256)\z/', $column) === 1) {
                    if (DB::table($table)->whereNotNull($column)->exists()) {
                        $blockers[] = 'hash_only_manifest:'.$qualified;
                    }
                } elseif (in_array($type, self::TEXT_TYPES, true)) {
                    if (! in_array($qualified, self::OPAQUE_ALLOWLIST, true)) {
                        $opaque[] = $column;
                    }
                } elseif (! in_array($type, self::PLAIN_TYPES, true) && DB::table($table)->whereNotNull($column)->exists()) {
                    $blockers[] = 'uninspected_type:'.$qualified;
                }
            }
            if ($opaque !== [] && DB::table($table)->where(function ($query) use ($opaque): void {
                foreach ($opaque as $column) {
                    $query->orWhere($column, 'like', '%'.self::CIPHERTEXT_MARK.'%');
                }
            })->exists()) {
                $blockers[] = 'ciphertext:'.$table;
            }
        }
        sort($blockers);

        return $blockers;
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

    /**
     * Asset ids in plaintext JSON: scalars in a list held by an asset key, `id` of an object held by one, and any value under
     * a key ending in asset_id(s). Asset keys are assets, asset, preview, files and *asset_ids. Nested objects inside an asset
     * entry (a recording binding, say) contribute only their *asset_id keys, so their own ids are not mistaken for assets.
     *
     * @return list<int>
     */
    private function jsonAssetIds(mixed $value, bool $idList): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        $ids = [];
        $add = function (mixed $scalar) use (&$ids): void {
            if (is_int($scalar) && $scalar > 0) {
                $ids[] = $scalar;
            } elseif (is_string($scalar) && preg_match('/\A[1-9][0-9]{0,17}\z/D', $scalar) === 1) {
                $ids[] = (int) $scalar;
            }
        };
        $walk = function (mixed $node, bool $assets) use (&$walk, $add): void {
            if (! is_array($node)) {
                return;
            }
            if (array_is_list($node)) {
                foreach ($node as $child) {
                    is_array($child) ? $walk($child, $assets) : ($assets ? $add($child) : null);
                }

                return;
            }
            foreach ($node as $key => $child) {
                $name = strtolower((string) $key);
                if (preg_match('/asset_ids?\z/', $name) === 1) {
                    is_array($child) ? $walk($child, true) : $add($child);
                } elseif (preg_match('/\A(?:assets?|preview|files)\z/', $name) === 1) {
                    $walk($child, true);
                } elseif ($assets && $name === 'id') {
                    $add($child);
                } else {
                    $walk($child, false);
                }
            }
        };
        $walk($decoded, false);
        if ($idList && is_array($decoded)) {
            $walk($decoded, true);
        }

        return array_values(array_unique($ids));
    }

    private function hasColumn(string $table, string $column): bool
    {
        return array_key_exists($column, $this->schema()['tables'][$table] ?? []);
    }

    /** @return array{tables:array<string,array<string,string>>, references:array<string,array{0:string,1:string,2:string}>, json:array<string,array{0:string,1:string}>, poly:array<string,array{0:string,1:string}>} */
    private function schema(): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }
        $tables = $references = $json = $poly = [];
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
        foreach ($tables as $name => $columns) {
            foreach (Schema::getForeignKeys($name) as $key) {
                if (isset(self::TARGETS[$key['foreign_table']]) && count($key['columns']) === 1) {
                    $references['fk:'.$name.'.'.$key['columns'][0]] = [$name, $key['columns'][0], $key['foreign_table']];
                }
            }
            foreach ($columns as $column => $type) {
                $qualified = $name.'.'.$column;
                if (in_array($type, self::PLAIN_TYPES, true) && preg_match('/(?:\A|_)asset_id\z/', $column) === 1) {
                    $references['fk:'.$qualified] ??= [$name, $column, 'media_assets'];
                }
                if (in_array($type, self::TEXT_TYPES, true) && (str_ends_with($column, 'asset_ids') || in_array($qualified, self::JSON_COLUMNS, true))) {
                    $json['json:'.$qualified] = [$name, $column];
                }
                if ($name !== 'audit_events' && str_ends_with($column, '_type') && isset($columns[substr($column, 0, -5).'_id'])) {
                    $poly['poly:'.$name.'.'.substr($column, 0, -5)] = [$name, substr($column, 0, -5)];
                }
            }
        }
        ksort($references);
        ksort($json);
        ksort($poly);

        return $this->schema = ['tables' => $tables, 'references' => $references, 'json' => $json, 'poly' => $poly];
    }
}
