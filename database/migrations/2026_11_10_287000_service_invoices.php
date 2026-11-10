<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-70 phase 1: Stripe test-mode invoices for service deposits and balances, and the verified service payments they produce.
 * A service invoice is coordination state (it advances creating -> open -> paid/void/uncollectible and is never deleted); a
 * verified service payment is immutable evidence. Neither table touches orders, grants, entitlements or `verified_payments`.
 *
 * `project_id` deliberately has no foreign key: ServiceProjectSchema installs and recovers an exact owned prefix and refuses any
 * outside object that references its tables. Service projects are retained by their own triggers (never updated or deleted), and
 * every write here binds the project inside the locked ServiceProjects transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_invoices', function (Blueprint $table) {
            $table->id();
            $this->identity($table, 'public_id', 36)->unique();
            $table->unsignedBigInteger('project_id');
            $this->identity($table, 'kind', 12);
            $this->identity($table, 'quote_id', 36);
            $table->char('quote_hash', 64);
            $table->unsignedSmallInteger('revision');
            $table->unsignedBigInteger('amount_minor');
            $this->identity($table, 'currency', 3);
            $table->unsignedSmallInteger('days_until_due');
            $table->char('idempotency_key', 64)->unique();
            $this->identity($table, 'state', 16);
            $this->identity($table, 'stripe_customer_id', 80)->nullable();
            $this->identity($table, 'stripe_invoice_id', 128)->nullable()->unique();
            $table->string('hosted_invoice_url', 1024)->nullable();
            $table->unsignedInteger('payment_failures')->default(0);
            $table->dateTime('last_payment_failed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['project_id', 'kind', 'quote_id', 'revision'], 'service_invoice_identity_unique');
        });
        Schema::create('service_invoice_payments', function (Blueprint $table) {
            $table->id();
            $this->identity($table, 'public_id', 36)->unique();
            $table->foreignId('service_invoice_id')->unique()->constrained('service_invoices')->restrictOnDelete();
            $table->unsignedBigInteger('project_id');
            $this->identity($table, 'quote_id', 36);
            $this->identity($table, 'stripe_invoice_id', 128)->unique();
            $table->foreignId('stripe_webhook_receipt_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $this->identity($table, 'currency', 3);
            // Hash of the canonical provider facts that were re-read and compared; never the private invoice itself.
            $table->char('evidence_hash', 64);
            $table->dateTime('verified_at');
            $table->index(['project_id', 'quote_id'], 'service_invoice_payment_project');
        });

        $integer = fn (string $column): string => DB::getDriverName() === 'sqlite' ? " AND TYPEOF(NEW.{$column}) = 'integer'" : '';
        $this->guard('service_invoices_valid_insert', 'service_invoices', 'insert',
            "NEW.state = 'creating' AND NEW.stripe_invoice_id IS NULL AND NEW.stripe_customer_id IS NULL AND NEW.hosted_invoice_url IS NULL"
            ." AND NEW.kind IN ('deposit', 'balance') AND NEW.currency = 'USD' AND NEW.amount_minor > 0 AND NEW.revision >= 1"
            .' AND NEW.days_until_due BETWEEN 1 AND 90 AND NEW.payment_failures = 0'
            .$integer('amount_minor').$integer('revision').$integer('days_until_due'));
        // Identity is fixed; paid, void are final; a provider id is set once; paid needs its verified payment row.
        $paidProof = 'EXISTS (SELECT 1 FROM service_invoice_payments p WHERE p.service_invoice_id = NEW.id AND p.stripe_invoice_id = NEW.stripe_invoice_id'
            .' AND p.amount_minor = NEW.amount_minor AND p.currency = NEW.currency AND p.project_id = NEW.project_id AND p.quote_id = NEW.quote_id)';
        $this->guard('service_invoices_valid_update', 'service_invoices', 'update',
            $this->unchanged(['id', 'public_id', 'project_id', 'kind', 'quote_id', 'quote_hash', 'revision', 'amount_minor', 'currency',
                'days_until_due', 'idempotency_key', 'created_by', 'created_at'])
            ." AND NEW.state IN ('creating', 'open', 'paid', 'void', 'uncollectible')"
            ." AND OLD.state NOT IN ('paid', 'void')"
            ." AND (NEW.state = 'creating' OR NEW.stripe_invoice_id IS NOT NULL)"
            .' AND (OLD.stripe_invoice_id IS NULL OR '.$this->unchanged(['stripe_invoice_id']).')'
            ." AND (NEW.state <> 'paid' OR {$paidProof})");
        $this->guard('service_invoices_retain', 'service_invoices', 'delete');
        $this->guard('service_invoice_payments_valid_insert', 'service_invoice_payments', 'insert',
            'NEW.amount_minor > 0'.$integer('amount_minor')." AND NEW.currency = 'USD' AND EXISTS (SELECT 1 FROM service_invoices i"
            .' WHERE i.id = NEW.service_invoice_id AND i.project_id = NEW.project_id AND i.quote_id = NEW.quote_id'
            .' AND i.amount_minor = NEW.amount_minor AND i.currency = NEW.currency AND i.stripe_invoice_id = NEW.stripe_invoice_id'
            ." AND i.state IN ('open', 'uncollectible'))");
        foreach (['update', 'delete'] as $operation) {
            $this->guard('service_invoice_payments_immutable_'.$operation, 'service_invoice_payments', $operation);
        }
    }

    private function identity(Blueprint $table, string $name, int $length): ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    private function unchanged(array $fields): string
    {
        $operator = DB::getDriverName() === 'sqlite' ? 'IS' : '<=>';

        return implode(' AND ', array_map(fn ($field) => "NEW.{$field} {$operator} OLD.{$field}", $fields));
    }

    private function guard(string $name, string $table, string $operation, ?string $allowed = null): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $when = $allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable service invoice evidence'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable service invoice evidence';";
            $body = $allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        // Empty disposable development rollback only. Operational rollback retains payment evidence.
        foreach (['service_invoices_valid_insert', 'service_invoices_valid_update', 'service_invoices_retain', 'service_invoice_payments_valid_insert',
            'service_invoice_payments_immutable_update', 'service_invoice_payments_immutable_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        Schema::dropIfExists('service_invoice_payments');
        Schema::dropIfExists('service_invoices');
    }
};
