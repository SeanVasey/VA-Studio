<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('policy_key', 64)->unique();
            $table->string('code', 32)->unique();
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            $table->timestamp('created_at');
        });
        Schema::create('promotion_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('quote_pricing_id')->unique()->constrained()->restrictOnDelete();
            $table->string('state', 16)->default('held');
            $table->uuid('attempt_id')->nullable()->unique();
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->timestamp('pending_at')->nullable();
            $table->index(['promotion_campaign_id', 'state', 'expires_at']);
        });
        $sqlite = DB::getDriverName() === 'sqlite';
        foreach (['update', 'delete'] as $operation) {
            $this->reject('promotion_campaigns_immutable_'.$operation, 'promotion_campaigns', $operation, null, $sqlite);
        }
        $this->reject('promotion_uses_retain_delete', 'promotion_uses', 'delete', null, $sqlite);
        $same = $sqlite ? 'IS' : '<=>';
        $identity = implode(' AND ', array_map(static fn ($field) => "NEW.{$field} {$same} OLD.{$field}",
            ['id', 'promotion_campaign_id', 'quote_pricing_id', 'created_at', 'expires_at']));
        $validInsert = "NEW.state = 'held' AND NEW.attempt_id IS NULL AND NEW.pending_at IS NULL AND NEW.expires_at > NEW.created_at";
        $validUpdate = "{$identity} AND OLD.state = 'held' AND NEW.state = 'pending' AND OLD.attempt_id IS NULL AND OLD.pending_at IS NULL AND NEW.attempt_id IS NOT NULL AND NEW.pending_at IS NOT NULL AND NEW.pending_at >= OLD.created_at AND NEW.pending_at < OLD.expires_at";
        $this->reject('promotion_uses_guard_insert', 'promotion_uses', 'insert', $validInsert, $sqlite);
        $this->reject('promotion_uses_guard_update', 'promotion_uses', 'update', $validUpdate, $sqlite);
    }

    private function reject(string $name, string $table, string $operation, ?string $allowed, bool $sqlite): void
    {
        if ($sqlite) {
            $when = $allowed === null ? '' : " WHEN NOT ({$allowed})";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid promotion evidence transition'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid promotion evidence transition';";
            $body = $allowed === null ? $signal : "IF NOT ({$allowed}) THEN {$signal} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        foreach (['promotion_uses_guard_update', 'promotion_uses_guard_insert', 'promotion_uses_retain_delete',
            'promotion_campaigns_immutable_update', 'promotion_campaigns_immutable_delete'] as $name) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        }
        Schema::dropIfExists('promotion_uses');
        Schema::dropIfExists('promotion_campaigns');
    }
};
