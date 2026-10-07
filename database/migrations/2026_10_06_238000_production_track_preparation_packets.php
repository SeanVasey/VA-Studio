<?php

use App\Domain\Commerce\ProductionPolicy\CapabilityMigrationOwnership;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Support\CanonicalJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ((new CapabilityMigrationOwnership)->preflight($this->definitions(), $this->guards())) {
            throw new LogicException('Production preparation tables already exist; installation refused before DDL.');
        }
        foreach ($this->definitions() as $table => $definition) {
            Schema::create($table, $definition);
        }
        foreach ($this->guards() as $definition) {
            DB::unprepared($definition['statement']);
        }
    }

    private function definitions(): array
    {
        return [PacketEvidence::PACKETS => function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $this->identity($table, 'public_id', 36)->unique('ptp_public');
            $table->unsignedInteger('schema_version');
            $table->foreignId('production_track_policy_draft_id')->constrained(table: 'production_track_policy_drafts', indexName: 'ptp_source')->restrictOnDelete();
            $table->foreignId('production_track_capability_candidate_id')->constrained(table: 'production_track_capability_candidates', indexName: 'ptp_candidate')->restrictOnDelete();
            $table->foreignId('production_track_capability_approval_id')->constrained(table: 'production_track_capability_approvals', indexName: 'ptp_approval')->restrictOnDelete();
            $this->identity($table, 'request_key', 64);
            $this->identity($table, 'request_hash', 64);
            $this->identity($table, 'catalog_graph_hash', 64);
            $table->unsignedBigInteger('advertised_subtotal_minor');
            $table->unsignedInteger('line_count');
            $table->longText('payload_ciphertext');
            $this->identity($table, 'payload_hash', 64);
            $this->identity($table, 'canonicalization_version', 32);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->unique(['created_by', 'request_key'], 'ptp_actor_request');
        }, PacketEvidence::LINES => function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('production_track_preparation_packet_id')->constrained(table: PacketEvidence::PACKETS, indexName: 'ptp_line_parent')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->foreignId('track_id')->constrained(table: 'tracks', indexName: 'ptp_line_track_parent')->restrictOnDelete();
            $table->foreignId('offer_id')->constrained(table: 'offers', indexName: 'ptp_line_offer_parent')->restrictOnDelete();
            $table->foreignId('offer_revision_id')->constrained(table: 'offer_revisions', indexName: 'ptp_line_revision_parent')->restrictOnDelete();
            $table->foreignId('license_version_id')->constrained(table: 'license_versions', indexName: 'ptp_line_license_parent')->restrictOnDelete();
            $table->unsignedInteger('price_minor');
            $this->identity($table, 'offer_snapshot_hash', 64);
            $table->unique(['production_track_preparation_packet_id', 'position'], 'ptp_line_position');
            $table->unique(['production_track_preparation_packet_id', 'track_id'], 'ptp_line_track');
        }];
    }

    private function guards(): array
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $integer = $sqlite ? "TYPEOF(NEW.schema_version) = 'integer' AND TYPEOF(NEW.line_count) = 'integer' AND TYPEOF(NEW.advertised_subtotal_minor) = 'integer' AND " : '';
        $version = CanonicalJson::VERSION;
        $root = $integer.'NEW.schema_version = 1 AND NEW.line_count BETWEEN 1 AND 10 AND NEW.advertised_subtotal_minor BETWEEN 1 AND 21474836470'
            .' AND LENGTH(NEW.public_id) = 36 AND LENGTH(NEW.payload_ciphertext) BETWEEN 1 AND 1048576'
            ." AND NEW.canonicalization_version = '{$version}' AND ".$this->hash('request_key').' AND '.$this->hash('request_hash').' AND '.$this->hash('catalog_graph_hash').' AND '.$this->hash('payload_hash')
            .' AND EXISTS (SELECT 1 FROM production_track_capability_candidates c JOIN production_track_capability_approvals a ON a.production_track_capability_candidate_id = c.id'
            .' WHERE c.id = NEW.production_track_capability_candidate_id AND c.production_track_policy_draft_id = NEW.production_track_policy_draft_id AND a.id = NEW.production_track_capability_approval_id)'
            .' AND NOT EXISTS (SELECT 1 FROM '.PacketEvidence::PACKETS.' WHERE id = NEW.id OR public_id = NEW.public_id OR (created_by = NEW.created_by AND request_key = NEW.request_key))';
        $lineInteger = $sqlite ? "TYPEOF(NEW.position) = 'integer' AND TYPEOF(NEW.price_minor) = 'integer' AND " : '';
        $line = $lineInteger.'NEW.position BETWEEN 1 AND 10 AND NEW.price_minor BETWEEN 1 AND 2147483647 AND '.$this->hash('offer_snapshot_hash')
            .' AND EXISTS (SELECT 1 FROM '.PacketEvidence::PACKETS.' WHERE id = NEW.production_track_preparation_packet_id AND NEW.position <= line_count)'
            .' AND EXISTS (SELECT 1 FROM offer_revisions r JOIN offers o ON o.id = r.offer_id WHERE r.id = NEW.offer_revision_id AND o.id = NEW.offer_id AND o.track_id = NEW.track_id'
            .' AND r.track_id = NEW.track_id AND r.license_version_id = NEW.license_version_id AND r.price_minor = NEW.price_minor AND r.currency = \'USD\' AND r.snapshot_hash = NEW.offer_snapshot_hash)'
            .' AND NOT EXISTS (SELECT 1 FROM '.PacketEvidence::LINES.' WHERE id = NEW.id OR (production_track_preparation_packet_id = NEW.production_track_preparation_packet_id AND (position = NEW.position OR track_id = NEW.track_id)))';
        $guards = ['ptp_packet_insert' => $this->guard('ptp_packet_insert', PacketEvidence::PACKETS, 'insert', $root),
            'ptp_line_insert' => $this->guard('ptp_line_insert', PacketEvidence::LINES, 'insert', $line)];
        foreach ([PacketEvidence::PACKETS => 'ptp_packet', PacketEvidence::LINES => 'ptp_line'] as $table => $prefix) {
            foreach (['update', 'delete'] as $operation) {
                $guards[$prefix.'_'.$operation] = $this->guard($prefix.'_'.$operation, $table, $operation);
            }
        }

        return $guards;
    }

    private function identity(Blueprint $table, string $name, int $length): ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    private function hash(string $field): string
    {
        return DB::getDriverName() === 'sqlite' ? "LENGTH(NEW.{$field}) = 64 AND NEW.{$field} NOT GLOB '*[^a-f0-9]*'"
            : "LENGTH(NEW.{$field}) = 64 AND NEW.{$field} = LOWER(NEW.{$field}) AND NEW.{$field} NOT REGEXP '[^a-f0-9]'";
    }

    private function guard(string $name, string $table, string $operation, ?string $allowed = null): array
    {
        if (DB::getDriverName() === 'sqlite') {
            $when = $allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)";
            $statement = "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable production preparation packet'); END";
            $body = null;
        } else {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable production preparation packet';";
            $body = 'BEGIN '.($allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;").' END';
            $statement = "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW {$body}";
        }

        return ['table' => $table, 'operation' => strtoupper($operation), 'statement' => $statement, 'body' => $body];
    }

    public function down(): void
    {
        if (! (new CapabilityMigrationOwnership)->preflight($this->definitions(), $this->guards())) {
            return;
        }
        foreach ([PacketEvidence::LINES, PacketEvidence::PACKETS] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Retain populated production preparation evidence.');
            }
        }
        foreach (array_keys($this->guards()) as $name) {
            DB::unprepared('DROP TRIGGER '.$name);
        }
        Schema::drop(PacketEvidence::LINES);
        Schema::drop(PacketEvidence::PACKETS);
    }
};
