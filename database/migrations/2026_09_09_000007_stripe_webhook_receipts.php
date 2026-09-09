<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_receipts', function (Blueprint $table) {
            $table->id();
            // Provider IDs are case-sensitive. MySQL's usual table collation is not.
            foreach (['account_id' => 80, 'event_id' => 128] as $name => $length) {
                $column = $table->string($name, $length);
                if (DB::getDriverName() === 'mysql') {
                    $column->charset('ascii')->collation('ascii_bin');
                }
            }
            $table->boolean('livemode');
            $table->unique(['account_id', 'livemode', 'event_id'], 'stripe_receipt_scope_unique');
            $table->string('event_type', 160);
            $table->string('object_id', 128)->nullable();
            $table->string('object_type', 128);
            $table->string('api_version', 80)->nullable();
            $table->unsignedBigInteger('provider_created_at');
            $table->unsignedBigInteger('signature_timestamp');
            $table->char('payload_sha256', 64);
            $table->char('event_fingerprint', 64);
            $table->string('fingerprint_version', 24);
            $table->longText('payload_ciphertext');
            $table->timestamp('received_at');
        });
        foreach (['update', 'delete'] as $operation) {
            $name = 'stripe_receipts_immutable_'.$operation;
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON stripe_webhook_receipts BEGIN SELECT RAISE(ABORT, 'Stripe webhook evidence is immutable'); END");
            } elseif (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON stripe_webhook_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stripe webhook evidence is immutable'");
            }
        }
    }

    public function down(): void
    {
        // Development rollback only. Operational rollback retains this evidence table.
        foreach (['update', 'delete'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS stripe_receipts_immutable_'.$operation);
        }
        Schema::dropIfExists('stripe_webhook_receipts');
    }
};
