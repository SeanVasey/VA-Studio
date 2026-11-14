<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, forward-only archive of BeatStars history (lane MIG, FP-099/FP-101): import batches, customer, order,
 * line and original-contract records, separately reviewed `verified_grant` classifications and the exclusivity
 * authority ledger. Every row is immutable on both engines. No existing table is altered and nothing here is read
 * by checkout, grants, entitlements or delivery: an archived line is history, never a download right.
 */
return new class extends Migration
{
    public const TABLES = ['legacy_history_batches', 'legacy_history_customers', 'legacy_history_orders', 'legacy_history_lines',
        'legacy_history_contracts', 'legacy_history_grant_reviews', 'legacy_exclusivity_authority_events'];

    /** Insert guards: table => [trigger suffix => [column, allowed values]]. Byte-exact on both engines. */
    public const VALUE_GUARDS = [
        'legacy_history_customers' => ['consent' => ['marketing_consent', ['unknown']]],
        'legacy_history_lines' => ['classification' => ['classification', ['archive_only']]],
        'legacy_history_grant_reviews' => ['classification' => ['classification', ['verified_grant']]],
        'legacy_exclusivity_authority_events' => [
            'authority' => ['authority', ['beatstars', 'frozen', 'vasey_store']],
            'transition' => ['transition', ['declare', 'freeze', 'handover', 'unfreeze', 'revert']],
        ],
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new LogicException('The legacy history archive requires SQLite or MySQL.');
        }
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                // A partial or foreign installation is retained for explicit review, never adopted or reset.
                throw new LogicException('Retained legacy history archive schema needs explicit recovery review.');
            }
        }
        Schema::create('legacy_history_batches', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->char('snapshot_sha256', 64)->unique();
            $table->string('snapshot_id', 190);
            $table->string('source_system', 190);
            $table->string('acquisition_method', 64);
            $table->char('acquired_at', 20);
            $table->char('source_as_of', 20);
            $table->string('transform_version', 80);
            $table->unsignedInteger('expected_records');
            // Every (cohort, record key, record hash) of the snapshot, so the latest export of a source shows what it
            // still contains: a record a later export dropped or changed no longer supports a review.
            $table->longText('members');
            $table->char('members_sha256', 64);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('attestation_audit_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('legacy_history_customers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('batch_id')->constrained('legacy_history_batches')->restrictOnDelete();
            $table->char('record_key', 64);
            $table->char('record_sha256', 64);
            $table->string('source_customer_id', 190);
            $table->char('email_hmac', 64)->index();
            $table->string('marketing_consent', 16);
            $table->longText('record_ciphertext');
            // Who inserted this row (a segment may be run by another operator than the batch creator).
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('attestation_audit_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['record_key', 'record_sha256']);
        });
        Schema::create('legacy_history_orders', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('batch_id')->constrained('legacy_history_batches')->restrictOnDelete();
            $table->char('record_key', 64);
            $table->char('record_sha256', 64);
            $table->string('source_order_id', 190);
            $table->char('customer_record_key', 64);
            $table->string('status', 24);
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('refunded_minor');
            $table->char('ordered_at', 20);
            $table->longText('record_ciphertext');
            // Who inserted this row (a segment may be run by another operator than the batch creator).
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('attestation_audit_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['record_key', 'record_sha256']);
        });
        Schema::create('legacy_history_lines', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('batch_id')->constrained('legacy_history_batches')->restrictOnDelete();
            $table->char('record_key', 64);
            $table->char('record_sha256', 64);
            $table->char('order_record_key', 64);
            $table->string('source_order_id', 190);
            $table->string('source_line_id', 190);
            $table->string('track_source_id', 190);
            $table->string('license_label', 80);
            $table->boolean('exclusive');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('refunded_minor');
            $table->char('currency', 3);
            $table->string('classification', 16);
            $table->longText('record_ciphertext');
            // Who inserted this row (a segment may be run by another operator than the batch creator).
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('attestation_audit_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['record_key', 'record_sha256']);
        });
        Schema::create('legacy_history_contracts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('batch_id')->constrained('legacy_history_batches')->restrictOnDelete();
            $table->char('record_key', 64);
            $table->char('record_sha256', 64);
            $table->char('line_record_key', 64);
            $table->string('source_contract_id', 190);
            $table->char('original_sha256', 64);
            $table->unsignedBigInteger('original_bytes');
            $table->string('media_type', 64);
            $table->string('storage_path', 255);
            $table->longText('record_ciphertext');
            // Who inserted this row (a segment may be run by another operator than the batch creator).
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('attestation_audit_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['record_key', 'record_sha256']);
        });
        Schema::create('legacy_history_grant_reviews', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('line_id')->unique()->constrained('legacy_history_lines')->restrictOnDelete();
            $table->char('line_record_key', 64);
            $table->string('classification', 16);
            $table->char('evidence_sha256', 64);
            $table->char('reference_hmac', 64);
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('attestation_audit_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('legacy_exclusivity_authority_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            // One event per sequence number: two concurrent transitions from the same head cannot both commit.
            $table->unsignedInteger('sequence')->unique();
            $table->string('authority', 16);
            $table->string('transition', 16);
            $table->char('reference_hmac', 64);
            $table->char('baseline_snapshot_sha256', 64)->nullable();
            $table->char('later_snapshot_sha256', 64)->nullable();
            $table->char('delta_sha256', 64)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('attestation_audit_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        foreach (self::TABLES as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared($driver === 'sqlite'
                    ? "CREATE TRIGGER {$table}_immutable_{$operation} BEFORE ".strtoupper($operation)." ON {$table} BEGIN SELECT RAISE(ABORT, 'Legacy history evidence is immutable'); END"
                    : "CREATE TRIGGER {$table}_immutable_{$operation} BEFORE ".strtoupper($operation)." ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy history evidence is immutable'");
            }
            if ($driver === 'sqlite') {
                // SQLite REPLACE deletes do not fire DELETE triggers unless recursive triggers are enabled, so an
                // identity conflict is refused before insertion instead. MySQL REPLACE fires the DELETE trigger.
                $unique = match ($table) {
                    'legacy_history_batches' => [['snapshot_sha256']],
                    'legacy_history_grant_reviews' => [['line_id']],
                    'legacy_exclusivity_authority_events' => [['sequence']],
                    default => [['record_key', 'record_sha256']],
                };
                $conditions = ['"id" = NEW."id"'];
                foreach ($unique as $columns) {
                    $conditions[] = '('.implode(' AND ', array_map(static fn (string $column): string => '"'.$column.'" = NEW."'.$column.'"', $columns)).')';
                }
                DB::unprepared("CREATE TRIGGER {$table}_immutable_replace BEFORE INSERT ON {$table} WHEN EXISTS (SELECT 1 FROM {$table} WHERE "
                    .implode(' OR ', $conditions).") BEGIN SELECT RAISE(ABORT, 'Legacy history evidence is immutable'); END");
            }
        }
        foreach (self::VALUE_GUARDS as $table => $guards) {
            foreach ($guards as $suffix => [$column, $allowed]) {
                $name = "{$table}_{$suffix}_guard";
                if ($driver === 'sqlite') {
                    $list = implode(', ', array_map(static fn (string $value): string => "'".$value."'", $allowed));
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE INSERT ON {$table} WHEN NEW.\"{$column}\" IS NULL OR typeof(NEW.\"{$column}\") <> 'text' OR NEW.\"{$column}\" NOT IN ({$list}) BEGIN SELECT RAISE(ABORT, 'Legacy history value is not admitted'); END");
                } else {
                    $list = implode(', ', array_map(static fn (string $value): string => "CAST('".$value."' AS BINARY)", $allowed));
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.`{$column}` IS NULL OR CAST(NEW.`{$column}` AS BINARY) NOT IN ({$list}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy history value is not admitted'; END IF; END");
                }
            }
        }
    }

    /** Forward-only: archived history and authority events are evidence and are never dropped by a rollback. */
    public function down(): void
    {
        throw new LogicException('Retain the immutable legacy history archive and exclusivity authority ledger.');
    }
};
