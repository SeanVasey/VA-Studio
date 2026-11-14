<?php

use App\Domain\Memberships\Billing\BillingSchema;
use Illuminate\Database\Migrations\Migration;

/** Add retained non-identity attempt evidence without rewriting any Billing259 row or guard. */
return new class extends Migration
{
    public function up(): void
    {
        (new BillingSchema)->up();
    }

    public function down(): never
    {
        (new BillingSchema)->down();
    }
};
