<?php

use App\Domain\Commerce\ProductionPolicy\CapabilityMigrationOwnership;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Domain\Commerce\ProductionPreparation\ProductionBuyerAssentObservations;
use App\Support\CanonicalJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ((new CapabilityMigrationOwnership)->preflight($this->definitions(), $this->guards())) {
            throw new LogicException('Buyer observation table already exists; installation refused before DDL.');
        }
        Schema::create(ProductionBuyerAssentObservations::TABLE, $this->definitions()[ProductionBuyerAssentObservations::TABLE]);
        foreach ($this->guards() as $guard) {
            DB::unprepared($guard['statement']);
        }
    }

    private function definitions(): array
    {
        return [ProductionBuyerAssentObservations::TABLE => function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            foreach (['public_id' => 36, 'request_key' => 64, 'payload_hash' => 64, 'canonicalization_version' => 32] as $name => $length) {
                $column = $table->string($name, $length);
                if (DB::getDriverName() === 'mysql') {
                    $column->charset('ascii')->collation('ascii_bin');
                }
            }
            $table->unique('public_id', 'pbao_public');
            $table->foreignId('production_track_preparation_packet_id')->constrained(table: PacketEvidence::PACKETS, indexName: 'pbao_packet')->restrictOnDelete();
            $table->foreignId('created_by')->constrained(table: 'users', indexName: 'pbao_observer')->restrictOnDelete();
            $table->unique(['created_by', 'request_key'], 'pbao_observer_request');
            $table->longText('payload_ciphertext');
            $table->dateTime('created_at');
        }];
    }

    private function guards(): array
    {
        $table = ProductionBuyerAssentObservations::TABLE;
        $version = CanonicalJson::VERSION;
        $allowed = "LENGTH(NEW.public_id) = 36 AND LENGTH(NEW.payload_ciphertext) BETWEEN 1 AND 1048576 AND NEW.canonicalization_version = '{$version}'";
        foreach (['request_key', 'payload_hash'] as $field) {
            $allowed .= DB::getDriverName() === 'sqlite' ? " AND LENGTH(NEW.{$field}) = 64 AND NEW.{$field} NOT GLOB '*[^a-f0-9]*'"
                : " AND LENGTH(NEW.{$field}) = 64 AND NEW.{$field} = LOWER(NEW.{$field}) AND NEW.{$field} NOT REGEXP '[^a-f0-9]'";
        }
        $allowed .= " AND NOT EXISTS (SELECT 1 FROM {$table} WHERE id = NEW.id OR public_id = NEW.public_id OR (created_by = NEW.created_by AND request_key = NEW.request_key))";
        $guards = [];
        foreach (['insert', 'update', 'delete'] as $operation) {
            $name = 'pbao_'.$operation;
            $condition = $operation === 'insert' ? $allowed : null;
            if (DB::getDriverName() === 'sqlite') {
                $when = $condition === null ? '' : " WHEN NOT COALESCE(({$condition}), 0)";
                $statement = "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable buyer observation'); END";
                $body = null;
            } else {
                $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable buyer observation';";
                $body = 'BEGIN '.($condition === null ? $signal : "IF NOT COALESCE(({$condition}), 0) THEN {$signal} END IF;").' END';
                $statement = "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW {$body}";
            }
            $guards[$name] = ['table' => $table, 'operation' => strtoupper($operation), 'statement' => $statement, 'body' => $body];
        }

        return $guards;
    }

    public function down(): void
    {
        throw new LogicException('Retain buyer observations and migration bookkeeping; no operational teardown is supported.');
    }
};
