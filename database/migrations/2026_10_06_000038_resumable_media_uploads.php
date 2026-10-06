<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new LogicException('Resumable uploads require MySQL or SQLite.');
        }
        $this->refuseTemporaryShadow();
        // Never treat a foreign or interrupted schema as ours. A surviving unjournaled
        // table is retained for inspection; this migration does not drop or repurpose it.
        if (Schema::hasTable('media_upload_sessions')) {
            throw new LogicException('An unjournaled media upload session table exists. Inspect it before retrying.');
        }
        Schema::create('media_upload_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('track_id')->constrained('tracks')->restrictOnDelete();
            $table->string('role', 32);
            $table->string('original_name', 240);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->json('parts');
            $table->string('status', 16);
            $table->foreignId('asset_id')->nullable()->unique()->constrained('media_assets')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('cleaned_at')->nullable();
            $table->timestamps();
            $table->index(['actor_id', 'status', 'expires_at'], 'media_upload_sessions_actor_status_expiry');
        });
    }

    public function down(): void
    {
        $this->refuseTemporaryShadow();
        if (! Schema::hasTable('media_upload_sessions')) {
            return;
        }
        // Empty development rollback can remove this child before its parents.
        // Populated transport/asset identities and their audits are never erased.
        if (DB::table('media_upload_sessions')->exists()) {
            throw new LogicException('Retained upload sessions prevent rollback. Preserve their evidence.');
        }
        $columns = array_column(Schema::getColumns('media_upload_sessions'), 'name');
        if ($columns !== ['id', 'public_id', 'actor_id', 'track_id', 'role', 'original_name', 'size_bytes', 'sha256',
            'received_bytes', 'parts', 'status', 'asset_id', 'expires_at', 'cleaned_at', 'created_at', 'updated_at']) {
            throw new LogicException('Unexpected upload session schema; rollback refused.');
        }
        $foreign = Schema::getForeignKeys('media_upload_sessions');
        $expected = ['actor_id' => 'users', 'track_id' => 'tracks', 'asset_id' => 'media_assets'];
        foreach ($foreign as $key) {
            $column = $key['columns'][0] ?? '';
            if (count($key['columns']) !== 1 || ($expected[$column] ?? null) !== $key['foreign_table']
                || $key['foreign_columns'] !== ['id'] || ! in_array(strtolower($key['on_delete']), ['restrict', 'no action'], true)) {
                throw new LogicException('Unexpected upload session relationship; rollback refused.');
            }
            unset($expected[$column]);
        }
        if ($expected !== []) {
            throw new LogicException('Incomplete upload session relationships; rollback refused.');
        }
        $indexes = ['media_upload_sessions_actor_status_expiry' => [['actor_id', 'status', 'expires_at'], false, false],
            'media_upload_sessions_asset_id_unique' => [['asset_id'], true, false],
            'media_upload_sessions_public_id_unique' => [['public_id'], true, false],
            'sqlite_autoindex_media_upload_sessions_1' => [['id'], true, true], 'primary' => [['id'], true, true],
            'media_upload_sessions_actor_id_foreign' => [['actor_id'], false, false],
            'media_upload_sessions_track_id_foreign' => [['track_id'], false, false]];
        foreach (Schema::getIndexes('media_upload_sessions') as $index) {
            if (($indexes[$index['name']] ?? null) !== [$index['columns'], $index['unique'], $index['primary']]) {
                throw new LogicException('Unexpected upload session index; rollback refused.');
            }
        }
        $triggers = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('tbl_name', 'media_upload_sessions')->where('type', 'trigger')->exists()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->where('EVENT_OBJECT_TABLE', 'media_upload_sessions')->exists();
        if ($triggers) {
            throw new LogicException('Unexpected upload session trigger; rollback refused.');
        }
        Schema::drop('media_upload_sessions');
    }

    private function refuseTemporaryShadow(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            if (DB::table('sqlite_temp_master')->whereRaw('name COLLATE NOCASE = ?', ['media_upload_sessions'])->exists()) {
                throw new LogicException('Temporary upload session shadow refused.');
            }

            return;
        }
        try {
            $definition = DB::selectOne('SHOW CREATE TABLE media_upload_sessions');
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? null) === '42S02' && ($error->errorInfo[1] ?? null) === 1146) {
                return;
            }
            throw $error;
        }
        if (str_starts_with($definition->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
            throw new LogicException('Temporary upload session shadow refused.');
        }
    }
};
