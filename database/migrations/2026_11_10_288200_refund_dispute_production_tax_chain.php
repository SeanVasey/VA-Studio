<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-42: extends the A5 refund/dispute ledger (2026_10_24_264100) to the production tax chain instead of forking it.
 *
 * Every order-keyed table gains `order_source` ('test' for the Stripe-test chain, the default every existing row and
 * writer keeps, or 'production_tax' for a finalized production tax batch), because the two chains number their orders
 * independently and a bare `order_id` would collide. Uniqueness is rebuilt per source, and the insert guards are
 * replaced so a 'test' row's order, line and grant must still exist in the Stripe-test tables. For the production
 * chain the "line" and the "grant" are the same retained row, a position of that batch.
 *
 * The production source's parent rows are NOT checked here. The paid-tax schema (PaidTaxSchema::preflight) refuses any
 * foreign object whose SQL names its tables ("External paid namespace or dependency collision"), so no trigger of this
 * ledger may reference them. Their existence and binding are proven in the ledger's own locked transaction instead
 * (ProductionTaxOrders::verify), which reads and authenticates the retained rows before any ledger row is written.
 *
 * Forward-only, like the migration it extends. Nothing existing is altered beyond the added column, the per-source
 * unique indexes and the replaced insert guards; the update and delete guards stay as installed.
 */
return new class extends Migration
{
    private const KEYED = ['refund_dispute_work', 'refund_dispute_observations', 'refund_dispute_objects', 'refund_line_allocations',
        'refund_dispute_effects', 'refund_dispute_holds'];

    private const REPLACED = ['refund_dispute_work_insert', 'refund_dispute_work_update', 'refund_dispute_observations_insert',
        'refund_dispute_objects_insert', 'refund_dispute_statuses_insert', 'refund_line_allocations_insert', 'refund_dispute_effects_insert',
        'refund_dispute_holds_insert', 'refund_dispute_holds_update'];

    public function up(): void
    {
        foreach (self::REPLACED as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
        foreach (self::KEYED as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('order_source', 16)->default('test');
            });
        }
        Schema::table('refund_dispute_work', function (Blueprint $table): void {
            $table->dropUnique('refund_dispute_work_order_unique');
            $table->unique(['order_source', 'order_id'], 'refund_dispute_work_source_order_unique');
        });
        Schema::table('refund_dispute_observations', function (Blueprint $table): void {
            $table->dropUnique('refund_dispute_observation_request_unique');
            $table->dropUnique('refund_dispute_observation_sequence_unique');
            $table->unique(['order_source', 'order_id', 'request_id'], 'refund_dispute_observation_source_request_unique');
            $table->unique(['order_source', 'order_id', 'sequence'], 'refund_dispute_observation_source_sequence_unique');
        });
        Schema::table('refund_dispute_objects', function (Blueprint $table): void {
            $table->dropUnique('refund_dispute_object_identity_unique');
            $table->unique(['order_source', 'order_id', 'kind', 'object_hash'], 'refund_dispute_object_source_identity_unique');
        });
        Schema::table('refund_dispute_effects', function (Blueprint $table): void {
            $table->dropUnique('refund_dispute_effect_key_unique');
            $table->unique(['order_source', 'order_id', 'effect_key'], 'refund_dispute_effect_source_key_unique');
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
        $test = $b('order_source')." = 'test'";
        $prod = $b('order_source')." = 'production_tax'";
        $source = $b('order_source')." IN ('test', 'production_tax')";
        $order = "(({$test} AND EXISTS (SELECT 1 FROM orders o WHERE o.id = NEW.order_id)) OR ({$prod} AND NEW.order_id >= 1))";
        $uuid = fn (string $column): string => "LENGTH(NEW.{$column}) = 36";
        $hash = fn (string $column): string => "LENGTH(NEW.{$column}) = 64";
        $line = "(({$test} AND EXISTS (SELECT 1 FROM order_lines l WHERE l.id = NEW.order_line_id AND l.order_id = NEW.order_id))"
            ." OR ({$prod} AND NEW.order_line_id >= 1))";
        $grant = "(({$test} AND EXISTS (SELECT 1 FROM license_grants g JOIN order_lines gl ON gl.id = g.order_line_id WHERE g.id = NEW.license_grant_id AND gl.order_id = NEW.order_id))"
            ." OR ({$prod} AND NEW.license_grant_id >= 1))";
        $refundStatuses = "'pending', 'requires_action', 'succeeded', 'failed', 'canceled'";
        $disputeStatuses = "'warning_needs_response', 'warning_under_review', 'warning_closed', 'needs_response', 'under_review', 'won', 'lost', 'prevented'";
        $effectKinds = "'allocation_required', 'allocation_recorded', 'line_refunded_review_required', 'refund_reversed_review_required',"
            ." 'dispute_hold_applied', 'dispute_hold_released', 'dispute_review_required', 'dispute_lost_review_required',"
            ." 'exclusive_remains_sold', 'exclusive_reopening_review_required', 'exclusive_reopening_qualified',"
            ." 'refund_suspension_applied', 'dispute_staff_hold_applied', 'hold_released_by_staff', 'observation_attention'";

        return [
            'refund_dispute_work_insert' => ['refund_dispute_work', 'insert', "{$source} AND NEW.sequence = 0 AND NEW.request_id IS NULL AND NEW.claim_token IS NULL AND NEW.lease_expires_at IS NULL AND {$order}"
                .' AND NOT EXISTS (SELECT 1 FROM refund_dispute_work w WHERE w.id = NEW.id OR (w.order_id = NEW.order_id AND w.order_source = NEW.order_source))'],
            'refund_dispute_work_update' => ['refund_dispute_work', 'update', "{$same('id')} AND {$same('order_id')} AND {$same('order_source')} AND {$same('created_at')} AND NEW.sequence >= OLD.sequence"
                ." AND (NEW.request_id IS NULL OR {$uuid('request_id')}) AND (NEW.claim_token IS NULL OR {$uuid('claim_token')})"],
            'refund_dispute_observations_insert' => ['refund_dispute_observations', 'insert',
                "{$source} AND {$b('outcome')} IN ('observed', 'incomplete', 'attention', 'unavailable') AND {$b('currency')} = 'USD' AND NEW.paid_minor > 0"
                ." AND {$b('canonicalization_version')} = 'vasey-json-v1' AND LENGTH(NEW.evidence_ciphertext) > 0 AND {$hash('evidence_hash')} AND {$hash('policy_hash')}"
                ." AND {$uuid('public_id')} AND {$uuid('request_id')} AND NEW.sequence > 0 AND {$order}"
                ." AND ((NEW.outcome = 'observed' AND {$b('refund_state')} IN ('none', 'partial', 'full') AND {$b('dispute_state')} IN ('none', 'open', 'won', 'lost', 'closed')"
                .' AND NEW.succeeded_refund_minor IS NOT NULL AND NEW.pending_refund_minor IS NOT NULL'
                .' AND NEW.succeeded_refund_minor + NEW.pending_refund_minor <= NEW.paid_minor)'
                ." OR (NEW.outcome <> 'observed' AND NEW.refund_state IS NULL AND NEW.dispute_state IS NULL AND NEW.succeeded_refund_minor IS NULL AND NEW.pending_refund_minor IS NULL))"
                .' AND NOT EXISTS (SELECT 1 FROM refund_dispute_observations x WHERE x.id = NEW.id OR x.public_id = NEW.public_id'
                .' OR (x.order_id = NEW.order_id AND x.order_source = NEW.order_source AND (x.request_id = NEW.request_id OR x.sequence = NEW.sequence)))'],
            'refund_dispute_objects_insert' => ['refund_dispute_objects', 'insert',
                "{$source} AND {$b('kind')} IN ('refund', 'dispute') AND {$b('currency')} = 'USD' AND NEW.amount_minor BETWEEN 1 AND 99999999 AND {$hash('object_hash')} AND {$uuid('public_id')}"
                ." AND EXISTS (SELECT 1 FROM refund_dispute_observations s WHERE s.id = NEW.first_observation_id AND s.order_id = NEW.order_id AND s.order_source = NEW.order_source AND s.outcome = 'observed')"
                .' AND NOT EXISTS (SELECT 1 FROM refund_dispute_objects x WHERE x.id = NEW.id OR x.public_id = NEW.public_id'
                .' OR (x.order_id = NEW.order_id AND x.order_source = NEW.order_source AND x.kind = NEW.kind AND x.object_hash = NEW.object_hash))'],
            'refund_dispute_statuses_insert' => ['refund_dispute_statuses', 'insert',
                'EXISTS (SELECT 1 FROM refund_dispute_objects j JOIN refund_dispute_observations s ON s.order_id = j.order_id AND s.order_source = j.order_source'
                ." WHERE j.id = NEW.refund_dispute_object_id AND s.id = NEW.observation_id AND s.outcome = 'observed'"
                ." AND ((j.kind = 'refund' AND {$b('status')} IN ({$refundStatuses})) OR (j.kind = 'dispute' AND {$b('status')} IN ({$disputeStatuses}))))"
                .' AND NOT EXISTS (SELECT 1 FROM refund_dispute_statuses x WHERE x.id = NEW.id'
                .' OR (x.refund_dispute_object_id = NEW.refund_dispute_object_id AND x.status = NEW.status))'],
            'refund_line_allocations_insert' => ['refund_line_allocations', 'insert',
                "{$source} AND {$b('currency')} = 'USD' AND NEW.amount_minor BETWEEN 1 AND 99999999 AND {$uuid('public_id')} AND NEW.sequence > 0 AND {$line}"
                ." AND (({$b('source')} = 'staff' AND NEW.actor_id IS NOT NULL) OR ({$b('source')} IN ('automatic_full_refund', 'automatic_single_line') AND NEW.actor_id IS NULL))"
                ." AND EXISTS (SELECT 1 FROM refund_dispute_objects j WHERE j.id = NEW.refund_dispute_object_id AND j.order_id = NEW.order_id AND j.order_source = NEW.order_source AND j.kind = 'refund')"
                .' AND NOT EXISTS (SELECT 1 FROM refund_line_allocations x WHERE x.id = NEW.id OR x.public_id = NEW.public_id'
                .' OR (x.refund_dispute_object_id = NEW.refund_dispute_object_id AND x.order_line_id = NEW.order_line_id))'],
            'refund_dispute_effects_insert' => ['refund_dispute_effects', 'insert',
                "{$source} AND {$b('kind')} IN ({$effectKinds}) AND {$uuid('public_id')} AND NEW.sequence > 0 AND LENGTH(NEW.effect_key) BETWEEN 1 AND 160 AND {$hash('policy_hash')} AND {$order}"
                ." AND (NEW.order_line_id IS NULL OR {$line}) AND (NEW.license_grant_id IS NULL OR {$grant})"
                ." AND (NEW.request_id IS NULL OR ({$uuid('request_id')} AND NEW.request_hash IS NOT NULL AND {$hash('request_hash')} AND NEW.actor_id IS NOT NULL))"
                .' AND (NEW.refund_dispute_object_id IS NULL OR EXISTS (SELECT 1 FROM refund_dispute_objects j WHERE j.id = NEW.refund_dispute_object_id AND j.order_id = NEW.order_id AND j.order_source = NEW.order_source))'
                .' AND (NEW.observation_id IS NULL OR EXISTS (SELECT 1 FROM refund_dispute_observations s WHERE s.id = NEW.observation_id AND s.order_id = NEW.order_id AND s.order_source = NEW.order_source))'
                .' AND NOT EXISTS (SELECT 1 FROM refund_dispute_effects x WHERE x.id = NEW.id OR x.public_id = NEW.public_id'
                .' OR (x.order_id = NEW.order_id AND x.order_source = NEW.order_source AND x.effect_key = NEW.effect_key))'],
            'refund_dispute_holds_insert' => ['refund_dispute_holds', 'insert',
                "{$source} AND {$b('reason')} IN ('dispute_reconciling', 'dispute_staff_hold', 'refund_suspension') AND {$uuid('public_id')} AND {$order}"
                .' AND NEW.released_effect_id IS NULL AND NEW.released_at IS NULL'
                ." AND ((NEW.reason = 'refund_suspension' AND NEW.license_grant_id IS NOT NULL AND {$grant}) OR (NEW.reason <> 'refund_suspension' AND NEW.license_grant_id IS NULL))"
                .' AND EXISTS (SELECT 1 FROM refund_dispute_effects e WHERE e.id = NEW.applied_effect_id AND e.order_id = NEW.order_id AND e.order_source = NEW.order_source'
                ." AND e.kind IN ('dispute_hold_applied', 'dispute_staff_hold_applied', 'refund_suspension_applied'))"
                .' AND NOT EXISTS (SELECT 1 FROM refund_dispute_holds x WHERE x.id = NEW.id OR x.public_id = NEW.public_id OR x.applied_effect_id = NEW.applied_effect_id)'],
            // One release only: every identity column is unchanged and the release pair moves from NULL to set.
            'refund_dispute_holds_update' => ['refund_dispute_holds', 'update',
                "{$same('id')} AND {$same('public_id')} AND {$same('order_id')} AND {$same('order_source')} AND {$same('license_grant_id')} AND {$same('reason')}"
                ." AND {$same('applied_effect_id')} AND {$same('created_at')} AND OLD.released_at IS NULL AND OLD.released_effect_id IS NULL"
                .' AND NEW.released_at IS NOT NULL AND NEW.released_at >= OLD.created_at AND NEW.released_effect_id IS NOT NULL'
                .' AND EXISTS (SELECT 1 FROM refund_dispute_effects e WHERE e.id = NEW.released_effect_id AND e.order_id = OLD.order_id AND e.order_source = OLD.order_source'
                ." AND e.kind IN ('dispute_hold_released', 'hold_released_by_staff'))"],
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
