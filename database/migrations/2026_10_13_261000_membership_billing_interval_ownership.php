<?php

use App\Domain\Memberships\Billing\BillingSchema;
use Illuminate\Database\Migrations\Migration;

/** Exact old graph -> supplementary cross-ledger guards. Retained conflicts require operator investigation. */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        (new BillingSchema)->upgradeIntervals();
    }

    public function down(): never
    {
        (new BillingSchema)->down();
    }
};
