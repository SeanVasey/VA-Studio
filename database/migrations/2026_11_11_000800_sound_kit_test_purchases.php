<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-13 (FP-007): Stripe test-mode purchase, grant, contract and delivery of a published sound kit. Default-off and admitted only
 * where TestEnvironment::admitsTestCommerce() admits the track chain (docs/sound-kit-storefront.md, "Kit checkout").
 *
 * Orders, provider session bindings, verified payments, grants and contract originals are immutable evidence (insert only, by
 * trigger). A verified payment must carry exactly its order's integer price and currency, and a grant must name the payment of its
 * own order; one payment and one grant per order (unique), so a webhook replay can never write a second. A delivery control
 * advances only by one version at a time. A download authorization can change once: from unredeemed to redeemed.
 */
return new class extends Migration
{
    private const TABLES = ['sound_kit_delivery_authorizations', 'sound_kit_delivery_controls', 'sound_kit_contracts', 'sound_kit_grants',
        'sound_kit_payments', 'sound_kit_checkout_sessions', 'sound_kit_orders'];

    private const TRIGGERS = ['sound_kit_orders_immutable_update', 'sound_kit_orders_immutable_delete', 'sound_kit_checkout_sessions_immutable_update',
        'sound_kit_checkout_sessions_immutable_delete', 'sound_kit_payments_valid_insert', 'sound_kit_payments_immutable_update',
        'sound_kit_payments_immutable_delete', 'sound_kit_grants_valid_insert', 'sound_kit_grants_immutable_update', 'sound_kit_grants_immutable_delete',
        'sound_kit_contracts_immutable_update', 'sound_kit_contracts_immutable_delete', 'sound_kit_delivery_controls_valid_insert',
        'sound_kit_delivery_controls_valid_update', 'sound_kit_delivery_controls_retain', 'sound_kit_delivery_authorizations_valid_insert',
        'sound_kit_delivery_authorizations_valid_update', 'sound_kit_delivery_authorizations_retain'];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Sound-kit test purchases require SQLite or MySQL.');
        }
        Schema::create('sound_kit_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('owner_key', 64);
            $table->foreign('owner_key')->references('owner_key')->on('quote_owners')->restrictOnDelete();
            $table->char('idempotency_key_hash', 64);
            $table->unique(['owner_key', 'idempotency_key_hash']);
            $table->char('request_hash', 64);
            $table->foreignId('sound_kit_product_id')->constrained('sound_kit_products')->restrictOnDelete();
            // Server-built purchase snapshot: kit revision, archive hash and size, license version and hashes, price, currency.
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            $table->unsignedInteger('price_minor');
            $table->char('currency', 3);
            // The licensee's confirmed name for the contract, encrypted; the hash binds the ciphertext.
            $table->text('licensee_ciphertext');
            $table->char('licensee_hash', 64);
            $table->string('account_id', 80);
            // The exact Stripe test Checkout request, frozen at order time and replayed byte for byte.
            $table->json('checkout_request');
            $table->char('checkout_request_hash', 64);
            $table->dateTime('created_at');
            $table->dateTime('checkout_before');
        });
        Schema::create('sound_kit_checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sound_kit_order_id')->unique()->constrained('sound_kit_orders')->restrictOnDelete();
            $table->string('account_id', 80);
            $table->string('provider_session_id', 128)->unique();
            $table->dateTime('created_at');
        });
        Schema::create('sound_kit_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('sound_kit_order_id')->unique()->constrained('sound_kit_orders')->restrictOnDelete();
            $table->string('account_id', 80);
            $table->string('provider_session_id', 128)->unique();
            $table->string('provider_payment_intent_id', 128)->unique();
            $table->foreignId('stripe_webhook_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 16);
            $table->unsignedInteger('amount_minor');
            $table->char('currency', 3);
            // Whitelisted provider facts that were re-read and compared (never the Checkout URL or customer details).
            $table->json('evidence');
            $table->char('evidence_hash', 64);
            $table->dateTime('verified_at');
        });
        Schema::create('sound_kit_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('sound_kit_order_id')->unique()->constrained('sound_kit_orders')->restrictOnDelete();
            $table->foreignId('sound_kit_payment_id')->unique()->constrained('sound_kit_payments')->restrictOnDelete();
            $table->foreignId('sound_kit_product_id')->constrained('sound_kit_products')->restrictOnDelete();
            $table->foreignId('sound_kit_revision_id')->constrained('sound_kit_revisions')->restrictOnDelete();
            $table->foreignId('license_version_id')->constrained('license_versions')->restrictOnDelete();
            $table->char('snapshot_hash', 64);
            $table->dateTime('granted_at');
        });
        Schema::create('sound_kit_contracts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('sound_kit_grant_id')->unique()->constrained('sound_kit_grants')->restrictOnDelete();
            $table->string('storage_path', 255)->unique();
            $table->char('sha256', 64);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('pages');
            $table->char('text_sha256', 64);
            $table->char('profile_sha256', 64);
            $table->char('input_sha256', 64);
            $table->dateTime('issued_at');
        });
        Schema::create('sound_kit_delivery_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sound_kit_order_id')->unique()->constrained('sound_kit_orders')->restrictOnDelete();
            $table->string('state', 16);
            $table->unsignedInteger('version');
            $table->char('reference_hash', 64);
            $table->dateTime('updated_at');
        });
        Schema::create('sound_kit_delivery_authorizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('sound_kit_order_id')->constrained('sound_kit_orders')->restrictOnDelete();
            $table->foreignId('sound_kit_grant_id')->constrained('sound_kit_grants')->restrictOnDelete();
            $table->string('kind', 16);
            $table->char('token_hash', 64);
            $table->dateTime('created_at');
            $table->dateTime('expires_at');
            $table->dateTime('redeemed_at')->nullable();
            $table->string('outcome', 24)->nullable();
            $table->index(['sound_kit_order_id', 'created_at'], 'sound_kit_delivery_auth_order_created');
        });

        foreach (['sound_kit_orders', 'sound_kit_checkout_sessions', 'sound_kit_payments', 'sound_kit_grants', 'sound_kit_contracts'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $this->guard($table.'_immutable_'.$operation, $table, $operation);
            }
        }
        $integer = fn (string $column): string => DB::getDriverName() === 'sqlite' ? " AND TYPEOF(NEW.{$column}) = 'integer'" : '';
        $this->guard('sound_kit_payments_valid_insert', 'sound_kit_payments', 'insert',
            "NEW.amount_minor > 0{$integer('amount_minor')} AND NEW.currency = 'USD' AND NEW.source IN ('receipt', 'reconciliation')"
            .' AND EXISTS (SELECT 1 FROM sound_kit_orders o WHERE o.id = NEW.sound_kit_order_id AND o.price_minor = NEW.amount_minor'
            .' AND o.currency = NEW.currency AND o.account_id = NEW.account_id)'
            .' AND EXISTS (SELECT 1 FROM sound_kit_checkout_sessions s WHERE s.sound_kit_order_id = NEW.sound_kit_order_id'
            .' AND s.provider_session_id = NEW.provider_session_id)');
        $this->guard('sound_kit_grants_valid_insert', 'sound_kit_grants', 'insert',
            'EXISTS (SELECT 1 FROM sound_kit_payments p WHERE p.id = NEW.sound_kit_payment_id AND p.sound_kit_order_id = NEW.sound_kit_order_id)'
            .' AND EXISTS (SELECT 1 FROM sound_kit_orders o WHERE o.id = NEW.sound_kit_order_id AND o.sound_kit_product_id = NEW.sound_kit_product_id'
            .' AND o.snapshot_hash = NEW.snapshot_hash)');
        $this->guard('sound_kit_delivery_controls_valid_insert', 'sound_kit_delivery_controls', 'insert',
            "NEW.version = 1 AND NEW.state IN ('enabled', 'blocked')");
        $this->guard('sound_kit_delivery_controls_valid_update', 'sound_kit_delivery_controls', 'update',
            "NEW.id = OLD.id AND NEW.sound_kit_order_id = OLD.sound_kit_order_id AND NEW.version = OLD.version + 1 AND NEW.state IN ('enabled', 'blocked')");
        $this->guard('sound_kit_delivery_controls_retain', 'sound_kit_delivery_controls', 'delete');
        $this->guard('sound_kit_delivery_authorizations_valid_insert', 'sound_kit_delivery_authorizations', 'insert',
            "NEW.redeemed_at IS NULL AND NEW.outcome IS NULL AND NEW.kind IN ('archive', 'contract') AND NEW.expires_at > NEW.created_at"
            .' AND EXISTS (SELECT 1 FROM sound_kit_grants g WHERE g.id = NEW.sound_kit_grant_id AND g.sound_kit_order_id = NEW.sound_kit_order_id)');
        $same = DB::getDriverName() === 'sqlite' ? 'IS' : '<=>';
        $this->guard('sound_kit_delivery_authorizations_valid_update', 'sound_kit_delivery_authorizations', 'update',
            implode(' AND ', array_map(fn (string $column): string => "NEW.{$column} {$same} OLD.{$column}",
                ['id', 'public_id', 'sound_kit_order_id', 'sound_kit_grant_id', 'kind', 'token_hash', 'created_at', 'expires_at']))
            .' AND OLD.redeemed_at IS NULL AND NEW.redeemed_at IS NOT NULL AND NEW.outcome IS NOT NULL');
        $this->guard('sound_kit_delivery_authorizations_retain', 'sound_kit_delivery_authorizations', 'delete');
    }

    private function guard(string $name, string $table, string $operation, ?string $allowed = null): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $when = $allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable sound-kit purchase evidence'); END");
        } else {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable sound-kit purchase evidence';";
            $body = $allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        // Disposable development rollback only: purchase evidence is never discarded.
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new LogicException('Sound-kit purchase evidence exists; rollback refused.');
            }
        }
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
