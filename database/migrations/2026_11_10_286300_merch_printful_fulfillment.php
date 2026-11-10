<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One fulfillment per store order and per verified payment: the unique keys, not application code alone, are what
        // keep a retry, a replay or a race from handing the same paid order to Printful twice.
        Schema::create('merch_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('verified_payment_id')->unique()->constrained()->restrictOnDelete();
            // Printful's external_id: derived from public_id, so Printful can be asked "did you get this one?" after a lost reply.
            $table->string('external_id', 64)->unique();
            $table->string('state', 16)->default('recorded');
            // Recipient (a postal address) and the Printful order items, encrypted with the application key.
            $table->longText('request_ciphertext');
            $table->char('request_hash', 64);
            $table->string('shipping_method', 32);
            $table->string('printful_order_id', 32)->nullable()->unique();
            $table->string('printful_status', 32)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('lease_expires_at')->nullable();
            $table->string('tracking_number', 128)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('shipped_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['state', 'lease_expires_at']);
        });

        // Receipt of every Printful webhook event: the unique event key is the replay guard. No body is kept (it carries the
        // recipient); only the identifiers, the type, a body hash and what the store did with it.
        Schema::create('merch_fulfillment_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_key', 64)->unique();
            $table->foreignId('merch_fulfillment_id')->nullable()->constrained('merch_fulfillments')->restrictOnDelete();
            $table->string('event_type', 48);
            $table->string('external_id', 64)->nullable();
            $table->string('printful_order_id', 32)->nullable();
            $table->string('outcome', 16);
            $table->char('body_sha256', 64);
            $table->dateTime('occurred_at')->nullable();
            $table->dateTime('received_at');
            $table->index('merch_fulfillment_id');
        });
    }

    public function down(): void
    {
        // Disposable development rollback only; retain fulfillment evidence operationally.
        Schema::dropIfExists('merch_fulfillment_events');
        Schema::dropIfExists('merch_fulfillments');
    }
};
