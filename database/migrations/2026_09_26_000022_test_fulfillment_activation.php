<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'test_fulfillment_activations';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $this->identity($table, 'public_id', 36)->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_finalization_id')->unique()->constrained()->restrictOnDelete();
            $this->identity($table, 'policy_version', 64);
            $table->longText('evidence_ciphertext');
            $this->identity($table, 'evidence_hash', 64);
            $this->identity($table, 'canonicalization_version', 32);
            $table->dateTime('verified_from');
            $table->dateTime('verified_through');
            $table->dateTime('activated_at');
        });

        // Shape and relational completeness are defence in depth, never cryptographic activation authority.
        $binding = 'EXISTS (SELECT 1 FROM order_finalizations f WHERE f.id = NEW.order_finalization_id'
            ." AND f.order_id = NEW.order_id AND f.outcome = 'paid' AND f.mode = 'test'"
            .' AND f.finalized_at <= NEW.verified_from)';
        $lineCount = '(SELECT COUNT(*) FROM order_lines l WHERE l.order_id = NEW.order_id)';
        $grantCount = '(SELECT COUNT(*) FROM license_grants g WHERE g.order_finalization_id = NEW.order_finalization_id)';
        $completeCount = '(SELECT COUNT(*) FROM order_lines l JOIN license_grants g ON g.order_line_id = l.id'
            .' JOIN contract_render_requests r ON r.license_grant_id = g.id'
            .' JOIN contract_render_work w ON w.contract_render_request_id = r.id'
            .' JOIN grant_contracts c ON c.contract_render_request_id = r.id AND c.license_grant_id = g.id'
            .' WHERE l.order_id = NEW.order_id AND g.order_finalization_id = NEW.order_finalization_id'
            ." AND w.state = 'completed' AND c.issued_at <= NEW.verified_from"
            .' AND c.public_id = r.document_public_id AND c.input_hash = r.input_hash AND c.input_hash = g.render_input_hash'
            .' AND c.profile_hash = r.profile_hash AND EXISTS (SELECT 1 FROM pending_entitlements e'
            ." WHERE e.license_grant_id = g.id AND e.state = 'pending'))";
        $bytes = DB::getDriverName() === 'sqlite' ? 'LENGTH(CAST(NEW.evidence_ciphertext AS BLOB))' : 'OCTET_LENGTH(NEW.evidence_ciphertext)';
        $shape = $this->uuid('NEW.public_id').' AND '.$this->hash('NEW.evidence_hash')
            ." AND NEW.policy_version = 'test-fulfillment-activation-v1' AND NEW.canonicalization_version = 'vasey-json-v1'"
            ." AND {$bytes} BETWEEN 1 AND 4194304"
            .' AND '.$this->timestamp('NEW.verified_from').' AND '.$this->timestamp('NEW.verified_through')
            .' AND '.$this->timestamp('NEW.activated_at')
            .' AND NEW.verified_from <= NEW.verified_through AND NEW.verified_through <= NEW.activated_at'
            .' AND NEW.activated_at <= '.$this->plusSeconds('NEW.verified_from', 300);
        $this->guard('valid_insert', 'insert', "{$shape} AND {$binding} AND {$lineCount} > 0 AND {$grantCount} = {$lineCount} AND {$completeCount} = {$lineCount}");
        $this->guard('immutable_update', 'update');
        $this->guard('immutable_delete', 'delete');
    }

    private function identity(Blueprint $table, string $name, int $length): \Illuminate\Database\Schema\ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') { $column->charset('ascii')->collation('ascii_bin'); }

        return $column;
    }

    private function uuid(string $value): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return "LENGTH({$value}) = 36 AND SUBSTR({$value}, 9, 1) = '-' AND SUBSTR({$value}, 14, 1) = '-'"
                ." AND SUBSTR({$value}, 19, 1) = '-' AND SUBSTR({$value}, 24, 1) = '-' AND LENGTH(REPLACE({$value}, '-', '')) = 32"
                ." AND REPLACE({$value}, '-', '') NOT GLOB '*[^0-9a-f]*'";
        }

        return "REGEXP_LIKE({$value}, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c')";
    }

    private function hash(string $value): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "LENGTH({$value}) = 64 AND {$value} NOT GLOB '*[^0-9a-f]*'"
            : "REGEXP_LIKE({$value}, '^[0-9a-f]{64}$', 'c')";
    }

    private function timestamp(string $value): string
    {
        // SQLite has no DATETIME type. Require the same whole-second storage representation as MySQL DATETIME(0).
        return DB::getDriverName() === 'sqlite'
            ? "TYPEOF({$value}) = 'text' AND LENGTH({$value}) = 19 AND SUBSTR({$value}, 1, 4) BETWEEN '1000' AND '9999' AND DATETIME({$value}, '+0 seconds') = {$value}"
            : "YEAR({$value}) BETWEEN 1000 AND 9999 AND MICROSECOND({$value}) = 0";
    }

    private function plusSeconds(string $value, int $seconds): string
    {
        return DB::getDriverName() === 'sqlite' ? "DATETIME({$value}, '+{$seconds} seconds')" : "DATE_ADD({$value}, INTERVAL {$seconds} SECOND)";
    }

    private function guard(string $suffix, string $operation, ?string $allowed = null): void
    {
        $table = self::TABLE; $name = $table.'_'.$suffix;
        if ($allowed !== null && DB::getDriverName() === 'mysql') {
            // ASCII identity columns still use PAD SPACE collation; a policy/version is byte-exact.
            $allowed = $this->bytewise($allowed);
        }
        if (DB::getDriverName() === 'sqlite') {
            $when = $allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable test fulfillment activation'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable test fulfillment activation';";
            $body = $allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    /** Compare only whole whitelisted column names; longer or additionally qualified names stay unchanged. */
    public function bytewise(string $condition): string
    {
        $names = implode('|', array_map(fn (string $column): string => preg_quote($column, '/'), ['NEW.policy_version', 'NEW.canonicalization_version']));

        return preg_replace('/(?<![\w$.])(?:'.$names.')(?![\w$])/', 'CAST($0 AS BINARY)', $condition)
            ?? throw new \LogicException('The guard condition could not be rewritten.');
    }

    public function down(): void
    {
        // Refuse before touching any guard: a historic verification decision is retained evidence.
        if (DB::table(self::TABLE)->exists()) {
            throw new \LogicException('Test fulfillment activation evidence must be retained; populated rollback is refused.');
        }
        foreach (['valid_insert', 'immutable_update', 'immutable_delete'] as $suffix) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::TABLE.'_'.$suffix);
        }
        Schema::dropIfExists(self::TABLE);
    }
};
