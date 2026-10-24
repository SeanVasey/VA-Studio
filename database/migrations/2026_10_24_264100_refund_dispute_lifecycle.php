<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, forward-only A5 refund/dispute evidence. Nothing existing is altered.
 *
 * Parent rows (orders, order lines, license grants) are themselves immutable and undeletable, so their
 * existence and order binding are checked by insert guards rather than foreign keys. That keeps the existing
 * empty-table roundtrips of the order/finalization migrations independent of this later child. Foreign keys
 * are kept to `users` and between the new tables. Every new table is append-only except two narrow, guarded
 * transitions: the coordination row's monotonic sequence/claim and a hold's single release. Rollback refuses.
 */
return new class extends Migration
{
    private const TABLES = ['refund_dispute_holds', 'refund_dispute_effects', 'refund_line_allocations',
        'refund_dispute_statuses', 'refund_dispute_objects', 'refund_dispute_observations', 'refund_dispute_work'];

    public function up(): void
    {
        Schema::create('refund_dispute_work', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique('refund_dispute_work_order_unique');
            $table->unsignedInteger('sequence');
            $table->char('request_id', 36)->nullable();
            $table->char('claim_token', 36)->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        Schema::create('refund_dispute_observations', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 36)->unique('refund_dispute_observation_public_unique');
            $table->unsignedBigInteger('order_id');
            $table->foreignId('actor_id')->constrained('users', indexName: 'refund_dispute_observation_actor_foreign')->restrictOnDelete();
            $table->char('request_id', 36);
            $table->unsignedInteger('sequence');
            $table->string('outcome', 16);
            $table->string('refund_state', 16)->nullable();
            $table->string('dispute_state', 16)->nullable();
            $table->unsignedBigInteger('paid_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('succeeded_refund_minor')->nullable();
            $table->unsignedBigInteger('pending_refund_minor')->nullable();
            $table->char('policy_hash', 64);
            $table->mediumText('evidence_ciphertext');
            $table->char('evidence_hash', 64);
            $table->string('canonicalization_version', 32);
            $table->dateTime('observed_at');
            $table->dateTime('created_at');
            $table->unique(['order_id', 'request_id'], 'refund_dispute_observation_request_unique');
            $table->unique(['order_id', 'sequence'], 'refund_dispute_observation_sequence_unique');
        });
        Schema::create('refund_dispute_objects', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 36)->unique('refund_dispute_object_public_unique');
            $table->unsignedBigInteger('order_id');
            $table->string('kind', 16);
            $table->char('object_hash', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->foreignId('first_observation_id')->constrained('refund_dispute_observations', indexName: 'refund_dispute_object_observation_foreign')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->unique(['order_id', 'kind', 'object_hash'], 'refund_dispute_object_identity_unique');
        });
        Schema::create('refund_dispute_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_dispute_object_id')->constrained('refund_dispute_objects', indexName: 'refund_dispute_status_object_foreign')->restrictOnDelete();
            $table->string('status', 32);
            $table->foreignId('observation_id')->constrained('refund_dispute_observations', indexName: 'refund_dispute_status_observation_foreign')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->unique(['refund_dispute_object_id', 'status'], 'refund_dispute_status_unique');
        });
        Schema::create('refund_line_allocations', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 36)->unique('refund_line_allocation_public_unique');
            $table->unsignedBigInteger('order_id');
            $table->foreignId('refund_dispute_object_id')->constrained('refund_dispute_objects', indexName: 'refund_line_allocation_object_foreign')->restrictOnDelete();
            $table->unsignedBigInteger('order_line_id');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('source', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users', indexName: 'refund_line_allocation_actor_foreign')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->dateTime('created_at');
            $table->unique(['refund_dispute_object_id', 'order_line_id'], 'refund_line_allocation_identity_unique');
        });
        Schema::create('refund_dispute_effects', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 36)->unique('refund_dispute_effect_public_unique');
            $table->unsignedBigInteger('order_id');
            $table->unsignedInteger('sequence');
            $table->string('effect_key', 160);
            $table->string('kind', 48);
            $table->unsignedBigInteger('order_line_id')->nullable();
            $table->unsignedBigInteger('license_grant_id')->nullable();
            $table->foreignId('refund_dispute_object_id')->nullable()->constrained('refund_dispute_objects', indexName: 'refund_dispute_effect_object_foreign')->restrictOnDelete();
            $table->foreignId('observation_id')->nullable()->constrained('refund_dispute_observations', indexName: 'refund_dispute_effect_observation_foreign')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users', indexName: 'refund_dispute_effect_actor_foreign')->restrictOnDelete();
            $table->char('request_id', 36)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->char('policy_hash', 64);
            $table->json('context');
            $table->dateTime('created_at');
            $table->unique(['order_id', 'effect_key'], 'refund_dispute_effect_key_unique');
            $table->index(['order_id', 'request_id'], 'refund_dispute_effect_request_index');
        });
        Schema::create('refund_dispute_holds', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 36)->unique('refund_dispute_hold_public_unique');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('license_grant_id')->nullable();
            $table->string('reason', 32);
            $table->foreignId('applied_effect_id')->unique('refund_dispute_hold_applied_unique')->constrained('refund_dispute_effects', indexName: 'refund_dispute_hold_applied_foreign')->restrictOnDelete();
            $table->foreignId('released_effect_id')->nullable()->unique('refund_dispute_hold_released_unique')->constrained('refund_dispute_effects', indexName: 'refund_dispute_hold_released_foreign')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->dateTime('released_at')->nullable();
            $table->index(['order_id', 'released_at'], 'refund_dispute_hold_active_index');
        });
        foreach ($this->guards() as $name => [$table, $operation, $predicate]) {
            $this->guard($name, $table, $operation, $predicate);
        }
    }

    /** Forward-only: refund and dispute evidence is retained, so operational rollback always refuses before any query. */
    public function down(): void
    {
        throw new LogicException('Retain refund and dispute evidence; this migration is forward-only.');
    }

    /** @return array<string, array{0: string, 1: string, 2: ?string}> */
    private function guards(): array
    {
        $mysql = DB::getDriverName() === 'mysql';
        $b = fn (string $column): string => $mysql ? "CAST(NEW.{$column} AS BINARY)" : "NEW.{$column}";
        $same = fn (string $column): string => $mysql ? "NEW.{$column} <=> OLD.{$column}" : "NEW.{$column} IS OLD.{$column}";
        $order = 'EXISTS (SELECT 1 FROM orders o WHERE o.id = NEW.order_id)';
        $uuid = fn (string $column): string => "LENGTH(NEW.{$column}) = 36";
        $hash = fn (string $column): string => "LENGTH(NEW.{$column}) = 64";
        $line = 'EXISTS (SELECT 1 FROM order_lines l WHERE l.id = NEW.order_line_id AND l.order_id = NEW.order_id)';
        $grant = 'EXISTS (SELECT 1 FROM license_grants g JOIN order_lines gl ON gl.id = g.order_line_id WHERE g.id = NEW.license_grant_id AND gl.order_id = NEW.order_id)';
        $refundStatuses = "'pending', 'requires_action', 'succeeded', 'failed', 'canceled'";
        $disputeStatuses = "'warning_needs_response', 'warning_under_review', 'warning_closed', 'needs_response', 'under_review', 'won', 'lost', 'prevented'";
        $effectKinds = "'allocation_required', 'allocation_recorded', 'line_refunded_review_required', 'refund_reversed_review_required',"
            ." 'dispute_hold_applied', 'dispute_hold_released', 'dispute_review_required', 'dispute_lost_review_required',"
            ." 'exclusive_remains_sold', 'exclusive_reopening_review_required', 'exclusive_reopening_qualified',"
            ." 'refund_suspension_applied', 'dispute_staff_hold_applied', 'hold_released_by_staff', 'observation_attention'";

        return [
            'refund_dispute_work_insert' => ['refund_dispute_work', 'insert', "NEW.sequence = 0 AND NEW.request_id IS NULL AND NEW.claim_token IS NULL AND NEW.lease_expires_at IS NULL AND {$order}"
                .' AND NOT EXISTS (SELECT 1 FROM refund_dispute_work w WHERE w.id = NEW.id OR w.order_id = NEW.order_id)'],
            'refund_dispute_work_update' => ['refund_dispute_work', 'update', "{$same('id')} AND {$same('order_id')} AND {$same('created_at')} AND NEW.sequence >= OLD.sequence"
                ." AND (NEW.request_id IS NULL OR {$uuid('request_id')}) AND (NEW.claim_token IS NULL OR {$uuid('claim_token')})"],
            'refund_dispute_work_delete' => ['refund_dispute_work', 'delete', null],
            'refund_dispute_observations_insert' => ['refund_dispute_observations', 'insert',
                "{$b('outcome')} IN ('observed', 'incomplete', 'attention', 'unavailable') AND {$b('currency')} = 'USD' AND NEW.paid_minor > 0"
                ." AND {$b('canonicalization_version')} = 'vasey-json-v1' AND LENGTH(NEW.evidence_ciphertext) > 0 AND {$hash('evidence_hash')} AND {$hash('policy_hash')}"
                ." AND {$uuid('public_id')} AND {$uuid('request_id')} AND NEW.sequence > 0 AND {$order}"
                ." AND ((NEW.outcome = 'observed' AND {$b('refund_state')} IN ('none', 'partial', 'full') AND {$b('dispute_state')} IN ('none', 'open', 'won', 'lost', 'closed')"
                .' AND NEW.succeeded_refund_minor IS NOT NULL AND NEW.pending_refund_minor IS NOT NULL'
                .' AND NEW.succeeded_refund_minor + NEW.pending_refund_minor <= NEW.paid_minor)'
                ." OR (NEW.outcome <> 'observed' AND NEW.refund_state IS NULL AND NEW.dispute_state IS NULL AND NEW.succeeded_refund_minor IS NULL AND NEW.pending_refund_minor IS NULL))"
                .' AND NOT EXISTS (SELECT 1 FROM refund_dispute_observations x WHERE x.id = NEW.id OR x.public_id = NEW.public_id'
                .' OR (x.order_id = NEW.order_id AND (x.request_id = NEW.request_id OR x.sequence = NEW.sequence)))'],
            'refund_dispute_observations_update' => ['refund_dispute_observations', 'update', null],
            'refund_dispute_observations_delete' => ['refund_dispute_observations', 'delete', null],
            'refund_dispute_objects_insert' => ['refund_dispute_objects', 'insert',
                "{$b('kind')} IN ('refund', 'dispute') AND {$b('currency')} = 'USD' AND NEW.amount_minor BETWEEN 1 AND 99999999 AND {$hash('object_hash')} AND {$uuid('public_id')}"
                ." AND EXISTS (SELECT 1 FROM refund_dispute_observations s WHERE s.id = NEW.first_observation_id AND s.order_id = NEW.order_id AND s.outcome = 'observed')"
                .' AND NOT EXISTS (SELECT 1 FROM refund_dispute_objects x WHERE x.id = NEW.id OR x.public_id = NEW.public_id'
                .' OR (x.order_id = NEW.order_id AND x.kind = NEW.kind AND x.object_hash = NEW.object_hash))'],
            'refund_dispute_objects_update' => ['refund_dispute_objects', 'update', null],
            'refund_dispute_objects_delete' => ['refund_dispute_objects', 'delete', null],
            'refund_dispute_statuses_insert' => ['refund_dispute_statuses', 'insert',
                'EXISTS (SELECT 1 FROM refund_dispute_objects j JOIN refund_dispute_observations s ON s.order_id = j.order_id'
                ." WHERE j.id = NEW.refund_dispute_object_id AND s.id = NEW.observation_id AND s.outcome = 'observed'"
                ." AND ((j.kind = 'refund' AND {$b('status')} IN ({$refundStatuses})) OR (j.kind = 'dispute' AND {$b('status')} IN ({$disputeStatuses}))))"
                .' AND NOT EXISTS (SELECT 1 FROM refund_dispute_statuses x WHERE x.id = NEW.id'
                .' OR (x.refund_dispute_object_id = NEW.refund_dispute_object_id AND x.status = NEW.status))'],
            'refund_dispute_statuses_update' => ['refund_dispute_statuses', 'update', null],
            'refund_dispute_statuses_delete' => ['refund_dispute_statuses', 'delete', null],
            'refund_line_allocations_insert' => ['refund_line_allocations', 'insert',
                "{$b('currency')} = 'USD' AND NEW.amount_minor BETWEEN 1 AND 99999999 AND {$uuid('public_id')} AND NEW.sequence > 0 AND {$line}"
                ." AND (({$b('source')} = 'staff' AND NEW.actor_id IS NOT NULL) OR ({$b('source')} IN ('automatic_full_refund', 'automatic_single_line') AND NEW.actor_id IS NULL))"
                ." AND EXISTS (SELECT 1 FROM refund_dispute_objects j WHERE j.id = NEW.refund_dispute_object_id AND j.order_id = NEW.order_id AND j.kind = 'refund')"
                .' AND NOT EXISTS (SELECT 1 FROM refund_line_allocations x WHERE x.id = NEW.id OR x.public_id = NEW.public_id'
                .' OR (x.refund_dispute_object_id = NEW.refund_dispute_object_id AND x.order_line_id = NEW.order_line_id))'],
            'refund_line_allocations_update' => ['refund_line_allocations', 'update', null],
            'refund_line_allocations_delete' => ['refund_line_allocations', 'delete', null],
            'refund_dispute_effects_insert' => ['refund_dispute_effects', 'insert',
                "{$b('kind')} IN ({$effectKinds}) AND {$uuid('public_id')} AND NEW.sequence > 0 AND LENGTH(NEW.effect_key) BETWEEN 1 AND 160 AND {$hash('policy_hash')} AND {$order}"
                ." AND (NEW.order_line_id IS NULL OR {$line}) AND (NEW.license_grant_id IS NULL OR {$grant})"
                ." AND (NEW.request_id IS NULL OR ({$uuid('request_id')} AND NEW.request_hash IS NOT NULL AND {$hash('request_hash')} AND NEW.actor_id IS NOT NULL))"
                .' AND (NEW.refund_dispute_object_id IS NULL OR EXISTS (SELECT 1 FROM refund_dispute_objects j WHERE j.id = NEW.refund_dispute_object_id AND j.order_id = NEW.order_id))'
                .' AND (NEW.observation_id IS NULL OR EXISTS (SELECT 1 FROM refund_dispute_observations s WHERE s.id = NEW.observation_id AND s.order_id = NEW.order_id))'
                .' AND NOT EXISTS (SELECT 1 FROM refund_dispute_effects x WHERE x.id = NEW.id OR x.public_id = NEW.public_id'
                .' OR (x.order_id = NEW.order_id AND x.effect_key = NEW.effect_key))'],
            'refund_dispute_effects_update' => ['refund_dispute_effects', 'update', null],
            'refund_dispute_effects_delete' => ['refund_dispute_effects', 'delete', null],
            'refund_dispute_holds_insert' => ['refund_dispute_holds', 'insert',
                "{$b('reason')} IN ('dispute_reconciling', 'dispute_staff_hold', 'refund_suspension') AND {$uuid('public_id')} AND {$order}"
                .' AND NEW.released_effect_id IS NULL AND NEW.released_at IS NULL'
                ." AND ((NEW.reason = 'refund_suspension' AND NEW.license_grant_id IS NOT NULL AND {$grant}) OR (NEW.reason <> 'refund_suspension' AND NEW.license_grant_id IS NULL))"
                .' AND EXISTS (SELECT 1 FROM refund_dispute_effects e WHERE e.id = NEW.applied_effect_id AND e.order_id = NEW.order_id'
                ." AND e.kind IN ('dispute_hold_applied', 'dispute_staff_hold_applied', 'refund_suspension_applied'))"
                .' AND NOT EXISTS (SELECT 1 FROM refund_dispute_holds x WHERE x.id = NEW.id OR x.public_id = NEW.public_id OR x.applied_effect_id = NEW.applied_effect_id)'],
            // One release only: every identity column is unchanged and the release pair moves from NULL to set.
            'refund_dispute_holds_update' => ['refund_dispute_holds', 'update',
                "{$same('id')} AND {$same('public_id')} AND {$same('order_id')} AND {$same('license_grant_id')} AND {$same('reason')}"
                ." AND {$same('applied_effect_id')} AND {$same('created_at')} AND OLD.released_at IS NULL AND OLD.released_effect_id IS NULL"
                .' AND NEW.released_at IS NOT NULL AND NEW.released_at >= OLD.created_at AND NEW.released_effect_id IS NOT NULL'
                .' AND EXISTS (SELECT 1 FROM refund_dispute_effects e WHERE e.id = NEW.released_effect_id AND e.order_id = OLD.order_id'
                ." AND e.kind IN ('dispute_hold_released', 'hold_released_by_staff'))"],
            'refund_dispute_holds_delete' => ['refund_dispute_holds', 'delete', null],
        ];
    }

    private function guard(string $name, string $table, string $operation, ?string $predicate): void
    {
        $message = 'Invalid or immutable refund and dispute evidence';
        if (DB::getDriverName() === 'sqlite') {
            $when = $predicate === null ? '' : " WHEN NOT COALESCE(({$predicate}), 0)";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';";
            $body = $predicate === null ? $signal : "IF NOT COALESCE(({$predicate}), 0) THEN {$signal} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        } else {
            throw new LogicException('Refund and dispute evidence requires SQLite or MySQL guards.');
        }
    }
};
