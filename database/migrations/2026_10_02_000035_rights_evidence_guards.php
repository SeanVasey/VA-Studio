<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MESSAGE = 'Verified rights evidence is immutable. Record a new declaration.';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Rights evidence guards require SQLite or MySQL.');
        }
        $guards = $this->guards();
        $this->preflightTables($guards);
        $this->preflightValues();
        $installed = $this->preflightGuards($guards);
        // MySQL independently commits each CREATE TRIGGER. Adopt only our exact surviving
        // creation prefix, after validating every object and retained row before any DDL.
        $missing = false;
        foreach ($installed as $present) {
            if ($present && $missing) {
                $this->unexpected('installation order');
            }
            $missing = $missing || ! $present;
        }
        foreach ($guards as $name => $definition) {
            if (! $installed[$name]) {
                DB::unprepared($definition['statement']);
            }
        }
    }

    private function verified(string $alias): string
    {
        // The application recognizes this exact stored status, not a collation-equivalent label.
        $type = DB::getDriverName() === 'sqlite' ? 'BLOB' : 'BINARY';

        return "CAST({$alias}.status AS {$type}) = CAST('verified' AS {$type})";
    }

    private function guards(): array
    {
        $verified = $this->verified('protected_rights');
        $rightsCollision = "EXISTS (SELECT 1 FROM rights_declarations AS protected_rights WHERE protected_rights.id = NEW.id AND {$verified})";
        $trackCollision = 'protected_rights.track_id IN (SELECT protected_track.id FROM tracks AS protected_track WHERE protected_track.id = NEW.id OR protected_track.slug = NEW.slug)';
        $otherTrackCollision = 'protected_rights.track_id IN (SELECT protected_track.id FROM tracks AS protected_track WHERE protected_track.id <> OLD.id AND (protected_track.id = NEW.id OR protected_track.slug = NEW.slug))';
        $invalidVerifiedIdentity = $this->verified('NEW').' AND (NEW.id <= 0 OR NEW.track_id <= 0)';
        $definitions = [
            'rights_evidence_immutable_update' => ['rights_declarations', 'BEFORE', 'UPDATE', $this->verified('OLD')." OR (NEW.id <> OLD.id AND {$rightsCollision}) OR ({$invalidVerifiedIdentity})"],
            'rights_evidence_immutable_delete' => ['rights_declarations', 'BEFORE', 'DELETE', $this->verified('OLD')],
            'rights_evidence_verified_insert' => ['rights_declarations', 'BEFORE', 'INSERT', $rightsCollision],
            'rights_evidence_verified_identity_insert' => ['rights_declarations', 'AFTER', 'INSERT', $invalidVerifiedIdentity],
            'tracks_rights_evidence_delete' => ['tracks', 'BEFORE', 'DELETE', "EXISTS (SELECT 1 FROM rights_declarations AS protected_rights WHERE protected_rights.track_id = OLD.id AND {$verified})"],
            'tracks_rights_evidence_update' => ['tracks', 'BEFORE', 'UPDATE', "EXISTS (SELECT 1 FROM rights_declarations AS protected_rights WHERE {$verified} AND ((NEW.id <> OLD.id AND protected_rights.track_id = OLD.id) OR {$otherTrackCollision}))"],
            'tracks_rights_evidence_insert' => ['tracks', 'BEFORE', 'INSERT', "EXISTS (SELECT 1 FROM rights_declarations AS protected_rights WHERE {$verified} AND {$trackCollision})"],
        ];
        $guards = [];
        foreach ($definitions as $name => [$table, $timing, $operation, $condition]) {
            if (DB::getDriverName() === 'sqlite') {
                $guards[$name] = ['table' => $table, 'timing' => $timing, 'operation' => $operation,
                    'statement' => "CREATE TRIGGER {$name} {$timing} {$operation} ON {$table} WHEN COALESCE(({$condition}), 0) BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END"];
            } else {
                $body = "BEGIN IF COALESCE(({$condition}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'; END IF; END";
                $guards[$name] = ['table' => $table, 'timing' => $timing, 'operation' => $operation, 'body' => $body,
                    'statement' => "CREATE TRIGGER {$name} {$timing} {$operation} ON {$table} FOR EACH ROW {$body}"];
            }
        }

        return $guards;
    }

    private function preflightTables(array $guards): void
    {
        $names = ['tracks', 'rights_declarations', 'tracks_slug_unique', 'tracks_status_index', ...array_keys($guards)];
        if (DB::getDriverName() === 'sqlite') {
            if (DB::table('sqlite_temp_master')->whereIn(DB::raw('name COLLATE NOCASE'), $names)
                ->orWhereIn(DB::raw('tbl_name COLLATE NOCASE'), ['tracks', 'rights_declarations'])->exists()) {
                $this->unexpected('temporary object shadow');
            }
        }
        foreach (['tracks', 'rights_declarations'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                $objects = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$table])->get();
                $object = $objects->first();
                if ($objects->count() !== 1 || $object->name !== $table || $object->type !== 'table'
                    || $object->sql !== preg_replace('/\Acreate table /', 'CREATE TABLE ', $this->tableStatement($table))) {
                    $this->unexpected('table definition for '.$table);
                }
            } else {
                $definition = DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table));
                if (str_starts_with($definition->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
                    $this->unexpected('temporary table shadow');
                }
                $tables = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())
                    ->whereRaw('LOWER(TABLE_NAME) = ?', [$table])->get();
                $object = $tables->first();
                if ($tables->count() !== 1 || $object->TABLE_NAME !== $table || $object->TABLE_TYPE !== 'BASE TABLE'
                    || $object->ENGINE !== 'InnoDB' || $object->TABLE_COLLATION !== DB::connection()->getConfig('collation')
                    || $object->CREATE_OPTIONS !== '' || $object->TABLE_COMMENT !== '') {
                    $this->unexpected('table storage definition for '.$table);
                }
                $this->preflightMySqlColumns($table);
            }
            $this->preflightIndexes($table);
            $this->preflightForeignKeys($table);
            $this->preflightAdditionalTriggers($table, $guards);
        }
    }

    private function tableStatement(string $name): string
    {
        $table = new Blueprint(DB::connection(), $name);
        $table->create();
        $table->id();
        if ($name === 'rights_declarations') {
            $table->foreignId('track_id')->constrained('tracks')->restrictOnDelete();
            $table->text('provenance_reference');
            $table->text('sample_disclosure');
            $table->string('status')->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        } else {
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('artist')->default('VASEY.AUDIO');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('bpm')->nullable();
            $table->string('musical_key', 24)->nullable();
            $table->string('genre')->nullable();
            $table->string('mood')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('tags')->nullable();
            $table->json('waveform')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->string('published_slug')->nullable();
            $table->unsignedInteger('metadata_version')->default(0);
            $table->integer('publication_version')->default(0);
        }

        return $table->toSql()[0];
    }

    private function preflightMySqlColumns(string $table): void
    {
        $collation = DB::connection()->getConfig('collation');
        $types = $table === 'rights_declarations' ? [
            'id' => ['bigint unsigned', false], 'track_id' => ['bigint unsigned', false],
            'provenance_reference' => ['text', false], 'sample_disclosure' => ['text', false],
            'status' => ['varchar(255)', false], 'verified_by' => ['bigint unsigned', true],
            'verified_at' => ['timestamp', true], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
        ] : [
            'id' => ['bigint unsigned', false], 'title' => ['varchar(255)', false], 'slug' => ['varchar(255)', false],
            'artist' => ['varchar(255)', false], 'description' => ['text', true], 'bpm' => ['smallint unsigned', true],
            'musical_key' => ['varchar(24)', true], 'genre' => ['varchar(255)', true], 'mood' => ['varchar(255)', true],
            'duration_seconds' => ['int unsigned', true], 'tags' => ['json', true], 'waveform' => ['json', true],
            'status' => ['varchar(255)', false], 'published_at' => ['timestamp', true],
            'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
            'published_slug' => ['varchar(255)', true], 'metadata_version' => ['int unsigned', false], 'publication_version' => ['int', false],
        ];
        $columns = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)->orderBy('ORDINAL_POSITION')->get();
        if ($columns->pluck('COLUMN_NAME')->all() !== array_keys($types)) {
            $this->unexpected('column identity for '.$table);
        }
        foreach ($columns as $column) {
            [$type, $nullable] = $types[$column->COLUMN_NAME];
            $default = match ($column->COLUMN_NAME) {
                'status' => $table === 'rights_declarations' ? 'pending' : 'draft',
                'artist' => 'VASEY.AUDIO', 'metadata_version', 'publication_version' => '0', default => null,
            };
            $actualDefault = $column->COLUMN_DEFAULT === null ? null : (string) $column->COLUMN_DEFAULT;
            $text = str_starts_with($type, 'varchar') || $type === 'text';
            if ($column->COLUMN_TYPE !== $type || $column->IS_NULLABLE !== ($nullable ? 'YES' : 'NO')
                || $actualDefault !== $default || $column->COLLATION_NAME !== ($text ? $collation : null)
                || $column->COLUMN_COMMENT !== '' || $column->GENERATION_EXPRESSION !== ''
                || $column->EXTRA !== ($column->COLUMN_NAME === 'id' ? 'auto_increment' : '')) {
                $this->unexpected('column definition for '.$table.'.'.$column->COLUMN_NAME);
            }
        }
        if (DB::table('information_schema.TABLE_CONSTRAINTS')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)->where('CONSTRAINT_TYPE', 'CHECK')->exists()) {
            $this->unexpected('additional check constraint for '.$table);
        }
    }

    private function preflightIndexes(string $table): void
    {
        $expected = ['primary' => [['id'], true]];
        if ($table === 'tracks') {
            $expected += ['tracks_slug_unique' => [['slug'], true], 'tracks_status_index' => [['status'], false]];
        } elseif (DB::getDriverName() === 'mysql') {
            $expected += ['rights_declarations_track_id_foreign' => [['track_id'], false], 'rights_declarations_verified_by_foreign' => [['verified_by'], false]];
        }
        foreach (Schema::getIndexes($table) as $index) {
            if (! isset($expected[$index['name']]) || [$index['columns'], $index['unique']] !== $expected[$index['name']]
                || $index['type'] !== (DB::getDriverName() === 'mysql' ? 'btree' : null)) {
                $this->unexpected('index definition for '.$table);
            }
            unset($expected[$index['name']]);
        }
        if ($expected !== []) {
            $this->unexpected('missing index for '.$table);
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (DB::select('PRAGMA index_list('.DB::connection()->getQueryGrammar()->wrapTable($table).')') as $index) {
                if ($index->partial !== 0) {
                    $this->unexpected('partial index for '.$table);
                }
                foreach (DB::select('PRAGMA index_xinfo('.DB::connection()->getPdo()->quote($index->name).')') as $part) {
                    if ($part->key === 1 && ($part->cid < 0 || $part->coll !== 'BINARY' || $part->desc !== 0)) {
                        $this->unexpected('index collation or expression for '.$table);
                    }
                }
            }
        } else {
            foreach (DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)->get() as $part) {
                if ($part->SUB_PART !== null || $part->EXPRESSION !== null || $part->COLLATION !== 'A'
                    || $part->IS_VISIBLE !== 'YES' || $part->INDEX_COMMENT !== '') {
                    $this->unexpected('index part definition for '.$table);
                }
            }
        }
    }

    private function preflightForeignKeys(string $table): void
    {
        $keys = Schema::getForeignKeys($table);
        if ($table === 'tracks') {
            if ($keys !== []) {
                $this->unexpected('additional foreign key for tracks');
            }

            return;
        }
        $expected = ['track_id' => 'tracks', 'verified_by' => 'users'];
        foreach ($keys as $key) {
            $column = count($key['columns']) === 1 ? $key['columns'][0] : null;
            if (! isset($expected[$column]) || $key['foreign_table'] !== $expected[$column] || $key['foreign_columns'] !== ['id']
                || ! in_array(strtolower($key['on_update']), ['restrict', 'no action'], true)
                || strtolower($key['on_delete']) !== 'restrict'
                || $key['foreign_schema'] !== (DB::getDriverName() === 'sqlite' ? 'main' : DB::getDatabaseName())
                || (DB::getDriverName() === 'mysql' && $key['name'] !== 'rights_declarations_'.$column.'_foreign')) {
                $this->unexpected('foreign key definition for rights_declarations');
            }
            unset($expected[$column]);
        }
        if ($expected !== []) {
            $this->unexpected('missing rights foreign key');
        }
    }

    private function preflightAdditionalTriggers(string $table, array $guards): void
    {
        $allowed = array_keys(array_filter($guards, fn ($guard) => $guard['table'] === $table));
        if ($table === 'tracks') {
            $allowed = [...$allowed, 'tracks_public_url_insert', 'tracks_public_url_update', 'tracks_public_url_delete',
                'tracks_publication_version_insert', 'tracks_publication_version_update'];
        }
        $names = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->whereRaw('tbl_name COLLATE NOCASE = ?', [$table])->pluck('name')->all()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('EVENT_OBJECT_TABLE', $table)->pluck('TRIGGER_NAME')->all();
        if (array_diff($names, $allowed) !== []) {
            $this->unexpected('additional trigger for '.$table);
        }
    }

    private function preflightValues(): void
    {
        // BEFORE INSERT auto IDs have engine-specific sentinel values. The AFTER INSERT guard
        // checks actual generated identities; historical pending identities remain editable.
        if (DB::table('rights_declarations AS retained_rights')->whereRaw($this->verified('retained_rights'))
            ->where(fn ($query) => $query->where('id', '<=', 0)->orWhere('track_id', '<=', 0))->exists()) {
            $this->unexpected('retained nonpositive verified identity');
        }
        if (DB::getDriverName() === 'sqlite') {
            if (DB::table('rights_declarations')->whereRaw("typeof(track_id) <> 'integer' OR (verified_by IS NOT NULL AND typeof(verified_by) <> 'integer')")->exists()
                || DB::select("PRAGMA foreign_key_check('rights_declarations')") !== []) {
                $this->unexpected('retained rights reference');
            }
        } else {
            if (DB::table('rights_declarations AS rights')->leftJoin('tracks', 'tracks.id', '=', 'rights.track_id')
                ->leftJoin('users', 'users.id', '=', 'rights.verified_by')->whereNull('tracks.id')
                ->orWhere(fn ($query) => $query->whereNotNull('rights.verified_by')->whereNull('users.id'))->exists()) {
                $this->unexpected('retained rights reference');
            }
        }
    }

    private function preflightGuards(array $guards): array
    {
        $installed = [];
        foreach ($guards as $name => $definition) {
            if (DB::getDriverName() === 'sqlite') {
                $objects = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$name])->get();
                $object = $objects->first();
                $matches = $objects->count() <= 1 && ($object === null || ($object->type === 'trigger' && $object->name === $name
                    && $object->tbl_name === $definition['table'] && $object->sql === $definition['statement']));
            } else {
                $objects = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                    ->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->get();
                $object = $objects->first();
                $matches = $objects->count() <= 1 && ($object === null || ($object->TRIGGER_NAME === $name
                    && $object->EVENT_OBJECT_TABLE === $definition['table'] && $object->ACTION_TIMING === $definition['timing']
                    && $object->EVENT_MANIPULATION === $definition['operation'] && $object->ACTION_STATEMENT === $definition['body']));
            }
            if (! $matches) {
                $this->unexpected('guard identity or definition for '.$name);
            }
            $installed[$name] = $object !== null;
        }

        return $installed;
    }

    private function unexpected(string $part): never
    {
        throw new LogicException("Unexpected rights evidence {$part}; existing schema and data are unchanged. Investigate before retrying.");
    }

    public function down(): void
    {
        // Interface rollback must not permit rewriting retained verified evidence or erase audits.
        // Explicit disposal of the complete development database remains a separate operation.
    }
};
