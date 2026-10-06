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
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Customer account schema requires a new supported table.');
        }
        $this->refuseTemporaryShadow();
        if ($this->permanentTable() || $this->installedGuards(false) !== []) {
            throw new LogicException('Unexpected customer account objects; migration refused.');
        }
        Schema::create('customer_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->char('owner_key', 64)->unique();
            $table->foreign('owner_key')->references('owner_key')->on('quote_owners')->restrictOnDelete();
            $table->boolean('active');
            $table->unsignedInteger('access_version');
            $table->timestamps();
        });
        foreach ($this->guards() as $guard) {
            DB::unprepared($guard['statement']);
        }
    }

    private function guards(): array
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $changed = $sqlite
            ? 'NEW.id IS NOT OLD.id OR NEW.public_id IS NOT OLD.public_id OR NEW.user_id IS NOT OLD.user_id OR NEW.owner_key IS NOT OLD.owner_key OR NEW.created_at IS NOT OLD.created_at'
            : 'NOT (NEW.id <=> OLD.id) OR NOT (BINARY NEW.public_id <=> BINARY OLD.public_id) OR NOT (NEW.user_id <=> OLD.user_id) OR NOT (BINARY NEW.owner_key <=> BINARY OLD.owner_key) OR NOT (NEW.created_at <=> OLD.created_at)';
        $shape = $sqlite
            ? "length(NEW.owner_key) != 64 OR NEW.owner_key GLOB '*[^0-9a-f]*' OR length(NEW.public_id) != 36 OR NEW.public_id GLOB '*[^0-9a-f-]*'"
            : "NOT REGEXP_LIKE(NEW.owner_key, '^[0-9a-f]{64}$', 'c') OR NOT REGEXP_LIKE(NEW.public_id, '^[0-9a-f-]{36}$', 'c')";
        // SQLite REPLACE can suppress DELETE triggers. Refuse all conflicting identities before replacement.
        $collision = 'EXISTS (SELECT 1 FROM customer_accounts WHERE id=NEW.id OR public_id=NEW.public_id OR user_id=NEW.user_id OR owner_key=NEW.owner_key)';
        $conditions['INSERT'] = ("$shape OR $collision OR NEW.active != 1 OR NEW.access_version != 1 OR NEW.created_at IS NULL OR NEW.updated_at IS NULL OR NOT EXISTS (SELECT 1 FROM users WHERE id=NEW.user_id AND is_admin=0 AND email_verified_at IS NOT NULL)");
        $conditions['UPDATE'] = ("$changed OR NEW.active NOT IN (0,1) OR NEW.active=OLD.active OR OLD.access_version >= 4294967294 OR NEW.access_version != OLD.access_version+1 OR NEW.updated_at IS NULL OR NEW.updated_at < OLD.updated_at");
        $conditions['DELETE'] = ('1=1');
        $guards = [];
        foreach ($conditions as $operation => $when) {
            $name = 'customer_accounts_'.strtolower($operation);
            $body = "BEGIN IF $when THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer identity is retained'; END IF; END";
            $statement = $sqlite
                ? "CREATE TRIGGER $name BEFORE $operation ON customer_accounts WHEN $when BEGIN SELECT RAISE(ABORT, 'Customer identity is retained'); END"
                : "CREATE TRIGGER $name BEFORE $operation ON customer_accounts FOR EACH ROW $body";
            $guards[$name] = compact('operation', 'body', 'statement');
        }

        return $guards;
    }

    public function down(): void
    {
        $this->refuseTemporaryShadow();
        $exists = $this->permanentTable();
        $guards = $this->installedGuards($exists);
        if (! $exists) {
            return;
        }
        if (DB::table('customer_accounts')->exists()) {
            throw new LogicException('Retained customer identities prevent rollback.');
        }
        if (array_column(Schema::getColumns('customer_accounts'), 'name') !== ['id', 'public_id', 'user_id', 'owner_key', 'active', 'access_version', 'created_at', 'updated_at']) {
            throw new LogicException('Unexpected customer account columns; rollback refused.');
        }
        foreach ($guards as $name) {
            DB::unprepared('DROP TRIGGER '.$name);
        }
        Schema::drop('customer_accounts');
    }

    private function refuseTemporaryShadow(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            if (DB::table('sqlite_temp_master')->whereIn(DB::raw('name COLLATE NOCASE'), ['customer_accounts', ...array_keys($this->guards())])
                ->orWhereRaw('tbl_name COLLATE NOCASE = ?', ['customer_accounts'])->exists()) {
                throw new LogicException('Temporary customer account object refused.');
            }

            return;
        }
        if (DB::getDriverName() !== 'mysql') {
            throw new LogicException('Unsupported customer account database.');
        }
        try {
            $table = DB::selectOne('SHOW CREATE TABLE customer_accounts');
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? null) === '42S02' && ($error->errorInfo[1] ?? null) === 1146) {
                return;
            }
            throw $error;
        }
        if (str_starts_with($table->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
            throw new LogicException('Temporary customer account table refused.');
        }
    }

    private function permanentTable(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $objects = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', ['customer_accounts'])->get();
            $object = $objects->first();
            $valid = $objects->count() <= 1 && ($object === null || ($object->name === 'customer_accounts' && $object->type === 'table'));
        } else {
            $objects = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TABLE_NAME) = ?', ['customer_accounts'])->get();
            $object = $objects->first();
            $valid = $objects->count() <= 1 && ($object === null || ($object->TABLE_NAME === 'customer_accounts' && $object->TABLE_TYPE === 'BASE TABLE' && $object->ENGINE === 'InnoDB'));
        }
        if (! $valid) {
            throw new LogicException('Unexpected customer account table identity.');
        }

        return $object !== null;
    }

    /** Exact guard bytes and owning permanent table are checked before any DDL. */
    private function installedGuards(bool $ownedTable): array
    {
        $installed = [];
        foreach ($this->guards() as $name => $definition) {
            if (DB::getDriverName() === 'sqlite') {
                $objects = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$name])->get();
                $guard = $objects->first();
                $valid = $ownedTable && $objects->count() === 1 && $guard->type === 'trigger' && $guard->name === $name
                    && $guard->tbl_name === 'customer_accounts' && $guard->sql === $definition['statement'];
            } else {
                $objects = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->get();
                $guard = $objects->first();
                $valid = $ownedTable && $objects->count() === 1 && $guard->TRIGGER_NAME === $name && $guard->EVENT_OBJECT_TABLE === 'customer_accounts'
                    && $guard->ACTION_TIMING === 'BEFORE' && $guard->EVENT_MANIPULATION === $definition['operation'] && $guard->ACTION_STATEMENT === $definition['body'];
            }
            if (($guard !== null && ! $valid) || ($ownedTable && $guard === null)) {
                throw new LogicException('Unexpected customer account guard ownership.');
            }
            if ($guard !== null) {
                $installed[] = $name;
            }
        }
        if ($ownedTable) {
            $all = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'customer_accounts')->count()
                : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('EVENT_OBJECT_TABLE', 'customer_accounts')->count();
            if ($all !== count($installed)) {
                throw new LogicException('Foreign customer account guards prevent rollback.');
            }
        }

        return $installed;
    }
};
