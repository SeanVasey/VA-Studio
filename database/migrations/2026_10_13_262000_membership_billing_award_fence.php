<?php

use App\Domain\Memberships\Billing\BillingAwardFence;
use Illuminate\Database\Migrations\Migration;

/** Forward-only ordering fence; existing billing evidence remains byte-for-byte retained. */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        (new BillingAwardFence)->upgrade();
    }

    public function down(): never
    {
        (new BillingAwardFence)->down();
    }
};
