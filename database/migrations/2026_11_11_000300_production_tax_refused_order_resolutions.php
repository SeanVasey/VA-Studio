<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FP-068 (Sean, 2026-11-10): a durable, append-only staff resolution for a paid production tax order whose finalization is refused
 * because the provider shows it fully refunded or the dispute lost. Such an order has no `paid_tax_order_origins` batch, so the
 * refund/dispute ledger (keyed to a batch) has nowhere to record it.
 *
 * A row creates no grant, license, contract or download authorization and changes no order, payment, refund or dispute record. It
 * only closes the order to finalization (the finalize frame refuses a resolved order) and keeps who decided, why and on what
 * evidence. One row per order. No foreign key points at a Tax255 table (its installer refuses external references), so the order
 * link is the order's public id. For the same reason (the Tax255 and PaidTax installers refuse an external object whose SQL names their
 * tables) no guard here reads those tables: that the order is paid, matches the recorded facts and has no finalized batch is proven by
 * ResolveRefusedProductionOrder under lock, and the finalize frame refuses a resolved order. The database guards fix the row's shape
 * (closed reason set, USD, integer amount in range, 36/64-character identities, ordered times), allow one row per order and refuse
 * every update and delete. Forward-only.
 */
return new class extends Migration
{
    private const TABLE = 'production_tax_refused_order_resolutions';

    private const MESSAGE = 'Invalid or immutable refused-order resolution';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Refused-order resolutions require SQLite or MySQL guards.');
        }
        if (Schema::hasTable(self::TABLE)) {
            throw new LogicException('Unexpected refused-order resolution objects; migration refused.');
        }
        $mysql = DB::getDriverName() === 'mysql';
        Schema::create(self::TABLE, function (Blueprint $table) use ($mysql): void {
            $ascii = fn ($column) => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;
            $table->id();
            $ascii($table->char('public_id', 36))->unique('refused_order_resolution_public_unique');
            $ascii($table->char('order_public_id', 36))->unique('refused_order_resolution_order_unique');
            $ascii($table->char('order_hash', 64));
            $ascii($table->string('provider_account', 80));
            $ascii($table->string('provider_payment_id', 128));
            $ascii($table->string('reason', 16));
            $table->unsignedBigInteger('gross_minor');
            $ascii($table->char('currency', 3));
            $table->foreignId('actor_id')->constrained('users', indexName: 'refused_order_resolution_actor_fk')->restrictOnDelete();
            $table->longText('evidence_ciphertext');
            $ascii($table->char('evidence_hash', 64));
            $ascii($table->string('canonicalization_version', 32));
            $table->dateTime('observed_at');
            $table->dateTime('resolved_at');
        });
        $b = fn (string $column): string => $mysql ? "CAST(NEW.{$column} AS BINARY)" : "NEW.{$column}";
        $integer = $mysql ? '' : "typeof(NEW.gross_minor) = 'integer' AND ";
        $valid = 'LENGTH(NEW.public_id) = 36 AND LENGTH(NEW.order_public_id) = 36 AND LENGTH(NEW.order_hash) = 64 AND LENGTH(NEW.evidence_hash) = 64'
            ." AND {$b('reason')} IN ('full_refund', 'dispute_lost') AND {$b('currency')} = 'USD' AND {$integer}NEW.gross_minor BETWEEN 1 AND 99999999"
            .' AND LENGTH(NEW.provider_payment_id) > 0 AND LENGTH(NEW.provider_account) > 0 AND LENGTH(NEW.evidence_ciphertext) > 0'
            .' AND NEW.resolved_at >= NEW.observed_at'
            .' AND NOT EXISTS (SELECT 1 FROM '.self::TABLE.' x WHERE x.id = NEW.id OR x.public_id = NEW.public_id OR x.order_public_id = NEW.order_public_id)';
        $this->guard('refused_order_resolution_insert', 'insert', $valid);
        // Append-only: a resolution is never changed or removed.
        $this->guard('refused_order_resolution_update', 'update', null);
        $this->guard('refused_order_resolution_delete', 'delete', null);
    }

    /** Forward-only: the resolution is retained decision evidence, so rollback always refuses before any query. */
    public function down(): void
    {
        throw new LogicException('Retain refused-order resolutions; this migration is forward-only.');
    }

    private function guard(string $name, string $operation, ?string $predicate): void
    {
        $table = self::TABLE;
        $message = self::MESSAGE;
        if (DB::getDriverName() === 'sqlite') {
            $when = $predicate === null ? '' : " WHEN NOT COALESCE(({$predicate}), 0)";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, '{$message}'); END");

            return;
        }
        $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';";
        $body = $predicate === null ? $signal : "IF NOT COALESCE(({$predicate}), 0) THEN {$signal} END IF;";
        DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
    }
};
