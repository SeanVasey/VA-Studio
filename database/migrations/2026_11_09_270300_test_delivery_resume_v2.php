<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FP-077 phase 1 (Sean, 2026-11-09): admit test-owner-delivery-v2 authorizations alongside v1, and add the append-only
 * coverage log of v2 transfers. Everything else in the 000023 insert guard (expiry = issue + 60 s, bindings, shapes) is
 * kept byte for byte, and the supplemental 000029/000030 hash and UUID byte guards are separate triggers left untouched.
 *
 * Recovery after a partial MySQL run: MySQL DDL commits statement by statement, so a failure part-way leaves earlier
 * steps applied and a rerun refuses (it never adopts an unexpected state). Inspect `information_schema.TRIGGERS` and the
 * tables, then, with no v2 authorization, admission or coverage row present:
 * - v2 guard created, original still present: `DROP TRIGGER test_delivery_authorizations_valid_insert_v2`, then rerun.
 * - original dropped, v2 guard present: recreate the original from `statements()[0]` (exactly what down() does), drop
 *   any `test_delivery_admission*`/`test_delivery_coverage*` triggers and both new tables, then rerun.
 * Never drop a guard or table that holds v2 evidence; escalate instead. SQLite runs the migration in one transaction.
 */
return new class extends Migration
{
    private const ORIGINAL = 'test_delivery_authorizations_valid_insert';

    private const ADMITTED = 'test_delivery_authorizations_valid_insert_v2';

    private const NEW_TRIGGERS = ['test_delivery_admissions_valid_insert', 'test_delivery_admissions_immutable_update',
        'test_delivery_admissions_immutable_delete', 'test_delivery_coverage_valid_insert', 'test_delivery_coverage_immutable_update',
        'test_delivery_coverage_immutable_delete'];

    public function up(): void
    {
        $this->driver();
        [$originalStatement, $admittedStatement] = $this->statements();
        // Refuse before any DDL unless the installed guard is exactly the 000023 definition; never adopt a drifted one.
        if (! $this->installed(self::ORIGINAL, $originalStatement)) {
            throw new LogicException('Unexpected test delivery authorization guard; nothing was changed. Investigate before retrying.');
        }
        if ($this->exists(self::ADMITTED)) {
            throw new LogicException('A v2 authorization guard already exists; nothing was changed. Investigate before retrying.');
        }
        // Create first, then drop: while both exist a row must pass both (v1 only), so no instant admits an unguarded row.
        DB::unprepared($this->create(self::ADMITTED, 'test_delivery_authorizations', 'insert', $admittedStatement));
        DB::unprepared('DROP TRIGGER '.self::ORIGINAL);

        // One row per admitted v2 response, written under the authorization lock before any byte: the response limit and the
        // incomplete cap count these, so a worker killed mid-stream (which records no coverage) still counts.
        Schema::create('test_delivery_admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_delivery_authorization_id');
            $table->foreign('test_delivery_authorization_id', 'delivery_admission_authorization_fk')->references('id')->on('test_delivery_authorizations')->restrictOnDelete();
            $table->foreignId('test_delivery_redemption_id');
            $table->foreign('test_delivery_redemption_id', 'delivery_admission_redemption_fk')->references('id')->on('test_delivery_redemptions')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->unique(['test_delivery_authorization_id', 'sequence'], 'delivery_admission_sequence_unique');
            $table->unsignedBigInteger('first_byte');
            $table->unsignedBigInteger('last_byte');
            $table->dateTime('admitted_at');
        });
        $this->admissionGuards();

        Schema::create('test_delivery_coverage', function (Blueprint $table) {
            $table->id();
            // Each coverage row records bytes served for exactly one admission, inside its admitted selection.
            $table->foreignId('test_delivery_admission_id');
            $table->foreign('test_delivery_admission_id', 'delivery_coverage_admission_fk')->references('id')->on('test_delivery_admissions')->restrictOnDelete();
            $table->unique('test_delivery_admission_id', 'delivery_coverage_admission_unique');
            $table->foreignId('test_delivery_authorization_id');
            $table->foreign('test_delivery_authorization_id', 'delivery_coverage_authorization_fk')->references('id')->on('test_delivery_authorizations')->restrictOnDelete();
            $table->foreignId('test_delivery_redemption_id');
            $table->foreign('test_delivery_redemption_id', 'delivery_coverage_redemption_fk')->references('id')->on('test_delivery_redemptions')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->unique(['test_delivery_authorization_id', 'sequence'], 'delivery_coverage_sequence_unique');
            $table->unsignedBigInteger('first_byte');
            $table->unsignedBigInteger('last_byte');
            $intervals = $table->text('intervals');
            if (DB::getDriverName() === 'mysql') {
                $intervals->charset('ascii')->collation('ascii_bin');
            }
            $table->unsignedBigInteger('covered_bytes');
            // At most one row per authorization completes coverage: that row is the one counted download.
            $table->foreignId('completed_authorization_id')->nullable();
            $table->foreign('completed_authorization_id', 'delivery_coverage_completed_fk')->references('id')->on('test_delivery_authorizations')->restrictOnDelete();
            $table->unique('completed_authorization_id', 'delivery_coverage_completed_unique');
            $table->dateTime('recorded_at');
        });
        $this->coverageGuards();
    }

    private function admissionGuards(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $same = 'c.test_delivery_authorization_id = NEW.test_delivery_authorization_id';
        $timestamp = $sqlite
            ? "TYPEOF(NEW.admitted_at) = 'text' AND LENGTH(NEW.admitted_at) = 19 AND SUBSTR(NEW.admitted_at, 1, 4) BETWEEN '1000' AND '9999' AND DATETIME(NEW.admitted_at, '+0 seconds') = NEW.admitted_at"
            : 'YEAR(NEW.admitted_at) BETWEEN 1000 AND 9999 AND MICROSECOND(NEW.admitted_at) = 0';
        $policy = $sqlite ? "x.policy_version = 'test-owner-delivery-v2'" : "CAST(x.policy_version AS BINARY) = CAST('test-owner-delivery-v2' AS BINARY)";
        $shape = "NEW.first_byte >= 0 AND NEW.last_byte >= NEW.first_byte AND {$timestamp} AND NEW.sequence BETWEEN 1 AND 128"
            ." AND NEW.sequence = 1 + (SELECT COUNT(*) FROM test_delivery_admissions c WHERE {$same})";
        if ($sqlite) {
            foreach (['sequence', 'first_byte', 'last_byte'] as $column) {
                $shape .= " AND TYPEOF(NEW.{$column}) = 'integer'";
            }
        }
        $binding = 'EXISTS (SELECT 1 FROM test_delivery_redemptions r JOIN test_delivery_authorizations x ON x.id = r.test_delivery_authorization_id'
            .' WHERE r.id = NEW.test_delivery_redemption_id AND x.id = NEW.test_delivery_authorization_id AND '.$policy
            .' AND NEW.last_byte < r.size_bytes AND NEW.admitted_at >= r.redeemed_at)';
        DB::unprepared($this->create('test_delivery_admissions_valid_insert', 'test_delivery_admissions', 'insert', $this->body("{$shape} AND {$binding}")));
        DB::unprepared($this->create('test_delivery_admissions_immutable_update', 'test_delivery_admissions', 'update', $this->body(null)));
        DB::unprepared($this->create('test_delivery_admissions_immutable_delete', 'test_delivery_admissions', 'delete', $this->body(null)));
    }

    private function coverageGuards(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $same = 'c.test_delivery_authorization_id = NEW.test_delivery_authorization_id';
        $timestamp = $sqlite
            ? "TYPEOF(NEW.recorded_at) = 'text' AND LENGTH(NEW.recorded_at) = 19 AND SUBSTR(NEW.recorded_at, 1, 4) BETWEEN '1000' AND '9999' AND DATETIME(NEW.recorded_at, '+0 seconds') = NEW.recorded_at"
            : 'YEAR(NEW.recorded_at) BETWEEN 1000 AND 9999 AND MICROSECOND(NEW.recorded_at) = 0';
        $length = $sqlite ? 'LENGTH(CAST(NEW.intervals AS BLOB))' : 'OCTET_LENGTH(NEW.intervals)';
        $policy = $sqlite ? "x.policy_version = 'test-owner-delivery-v2'" : "CAST(x.policy_version AS BINARY) = CAST('test-owner-delivery-v2' AS BINARY)";
        $shape = 'NEW.first_byte >= 0 AND NEW.last_byte >= NEW.first_byte AND NEW.covered_bytes >= NEW.last_byte - NEW.first_byte + 1'
            ." AND {$length} BETWEEN 7 AND 8192 AND SUBSTR(NEW.intervals, 1, 2) = '[[' AND {$timestamp}"
            .' AND NEW.sequence BETWEEN 1 AND 128'
            ." AND NEW.sequence = 1 + (SELECT COUNT(*) FROM test_delivery_coverage c WHERE {$same})"
            ." AND NEW.covered_bytes >= COALESCE((SELECT MAX(c.covered_bytes) FROM test_delivery_coverage c WHERE {$same}), 0)";
        if ($sqlite) {
            foreach (['sequence', 'first_byte', 'last_byte', 'covered_bytes'] as $column) {
                $shape .= " AND TYPEOF(NEW.{$column}) = 'integer'";
            }
            $shape .= " AND TYPEOF(NEW.intervals) = 'text'";
        }
        // The started v2 transfer this row belongs to; completion is set on exactly the first row that covers the file.
        $binding = 'EXISTS (SELECT 1 FROM test_delivery_redemptions r JOIN test_delivery_authorizations x ON x.id = r.test_delivery_authorization_id'
            .' WHERE r.id = NEW.test_delivery_redemption_id AND x.id = NEW.test_delivery_authorization_id AND '.$policy
            .' AND NEW.last_byte < r.size_bytes AND NEW.covered_bytes <= r.size_bytes AND NEW.recorded_at >= r.redeemed_at'
            .' AND EXISTS (SELECT 1 FROM test_delivery_admissions m WHERE m.id = NEW.test_delivery_admission_id'
            .' AND m.test_delivery_authorization_id = NEW.test_delivery_authorization_id AND m.test_delivery_redemption_id = r.id'
            .' AND NEW.first_byte >= m.first_byte AND NEW.last_byte <= m.last_byte AND NEW.recorded_at >= m.admitted_at'
            .' AND NOT EXISTS (SELECT 1 FROM test_delivery_coverage u WHERE u.test_delivery_admission_id = m.id))'
            .' AND (CASE WHEN NEW.covered_bytes = r.size_bytes AND NOT EXISTS (SELECT 1 FROM test_delivery_coverage c'
            ." WHERE {$same} AND c.covered_bytes = r.size_bytes)"
            .' THEN NEW.completed_authorization_id IS NOT NULL AND NEW.completed_authorization_id = NEW.test_delivery_authorization_id'
            .' ELSE NEW.completed_authorization_id IS NULL END))';
        DB::unprepared($this->create('test_delivery_coverage_valid_insert', 'test_delivery_coverage', 'insert', $this->body("{$shape} AND {$binding}")));
        DB::unprepared($this->create('test_delivery_coverage_immutable_update', 'test_delivery_coverage', 'update', $this->body(null)));
        DB::unprepared($this->create('test_delivery_coverage_immutable_delete', 'test_delivery_coverage', 'delete', $this->body(null)));
    }

    /**
     * [the exact 000023 guard statement, the same statement admitting v1 or v2]. Rebuilt from 000023's own helpers so a
     * byte of drift in either the installed guard or this reconstruction refuses the migration.
     *
     * @return array{0: string, 1: string}
     */
    private function statements(): array
    {
        $original = require database_path('migrations/2026_09_26_000023_test_owner_delivery.php');
        $build = Closure::bind(function (bool $both): string {
            $binding = 'EXISTS (SELECT 1 FROM test_delivery_controls d JOIN test_fulfillment_activations a ON a.id = d.test_fulfillment_activation_id'
                .' JOIN order_finalizations f ON f.id = a.order_finalization_id JOIN orders o ON o.id = a.order_id'
                .' JOIN license_grants g ON g.order_finalization_id = f.id JOIN order_lines l ON l.id = g.order_line_id'
                .' WHERE d.id = NEW.test_delivery_control_id AND d.order_id = NEW.order_id AND a.id = NEW.test_fulfillment_activation_id'
                .' AND o.id = NEW.order_id AND f.order_id = NEW.order_id AND l.order_id = NEW.order_id AND g.id = NEW.license_grant_id'
                ." AND f.mode = 'test' AND f.outcome = 'paid' AND d.blocked = 0 AND d.control_version = NEW.control_version"
                .' AND '.$this->equalBytes('o.owner_key', 'NEW.owner_key')
                .' AND NEW.issued_at >= a.activated_at AND NEW.issued_at >= d.updated_at AND '.$this->target('NEW').')';
            $version = $this->equalBytes('NEW.policy_version', "'test-owner-delivery-v1'");
            if ($both) {
                $version = '('.$version.' OR '.$this->equalBytes('NEW.policy_version', "'test-owner-delivery-v2'").')';
            }
            $shape = $this->uuid('NEW.public_id').' AND '.$this->evidenceShape().' AND '.$this->timestamp('NEW.issued_at')
                .' AND '.$this->timestamp('NEW.expires_at').' AND NEW.expires_at = '.$this->plusSeconds('NEW.issued_at', 60)
                .' AND '.$version.' AND NEW.control_version BETWEEN 1 AND 4294967293';
            foreach (['owner_key', 'token_hash', 'idempotency_key_hash', 'request_hash'] as $name) {
                $shape .= ' AND '.$this->hash('NEW.'.$name);
            }
            if (DB::getDriverName() === 'sqlite') {
                $shape .= " AND TYPEOF(NEW.control_version) = 'integer'";
            }
            $allowed = "{$shape} AND {$binding}";

            return DB::getDriverName() === 'mysql' ? $this->bytewise($allowed) : $allowed;
        }, $original, $original::class);

        return [$this->body($build(false)), $this->body($build(true))];
    }

    /** Same message and form as the 000023 guards. */
    private function body(?string $allowed): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return ($allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)")." BEGIN SELECT RAISE(ABORT, 'Invalid or immutable test delivery evidence'); END";
        }
        $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable test delivery evidence';";

        return 'BEGIN '.($allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;").' END';
    }

    private function create(string $name, string $table, string $operation, string $body): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$body}"
            : "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW {$body}";
    }

    private function installed(string $name, string $body): bool
    {
        $rows = $this->named($name);
        if ($rows->count() !== 1) {
            return false;
        }
        $row = $rows->first();
        if (DB::getDriverName() === 'sqlite') {
            return $row->name === $name && $row->tbl_name === 'test_delivery_authorizations'
                && $this->normalized($row->sql) === $this->normalized($this->create($name, 'test_delivery_authorizations', 'insert', $body));
        }

        return $row->TRIGGER_NAME === $name && $row->EVENT_OBJECT_TABLE === 'test_delivery_authorizations' && $row->ACTION_TIMING === 'BEFORE'
            && $row->EVENT_MANIPULATION === 'INSERT' && $this->normalized($row->ACTION_STATEMENT) === $this->normalized($body);
    }

    private function exists(string $name): bool
    {
        return $this->named($name)->isNotEmpty();
    }

    private function named(string $name): Collection
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->whereRaw('name COLLATE NOCASE = ?', [$name])->get()
            : DB::table('information_schema.TRIGGERS')->whereRaw('CAST(TRIGGER_SCHEMA AS BINARY) = ?', [DB::getDatabaseName()])
                ->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->get();
    }

    private function normalized(string $statement): string
    {
        return preg_replace('/\s+/', ' ', trim($statement)) ?? throw new LogicException('Guard definition could not be read.');
    }

    private function driver(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Test delivery guards require SQLite or MySQL.');
        }
    }

    public function down(): void
    {
        $this->driver();
        if (DB::table('test_delivery_authorizations')->where('policy_version', 'test-owner-delivery-v2')->exists()
            || (Schema::hasTable('test_delivery_coverage') && DB::table('test_delivery_coverage')->exists())
            || (Schema::hasTable('test_delivery_admissions') && DB::table('test_delivery_admissions')->exists())) {
            throw new LogicException('v2 test delivery evidence must be retained; rollback is refused.');
        }
        [$originalStatement, $admittedStatement] = $this->statements();
        if (! $this->installed(self::ADMITTED, $admittedStatement) || $this->exists(self::ORIGINAL)) {
            throw new LogicException('Unexpected test delivery authorization guard; nothing was changed. Investigate before retrying.');
        }
        DB::unprepared($this->create(self::ORIGINAL, 'test_delivery_authorizations', 'insert', $originalStatement));
        DB::unprepared('DROP TRIGGER '.self::ADMITTED);
        foreach (self::NEW_TRIGGERS as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        Schema::dropIfExists('test_delivery_coverage');
        Schema::dropIfExists('test_delivery_admissions');
    }
};
