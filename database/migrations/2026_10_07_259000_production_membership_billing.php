<?php

use App\Domain\Memberships\Billing\BillingSchema;
use Illuminate\Database\Migrations\Migration;

/** Billing259 provider-evidence tables (default-off; reserved by root). No credit is awarded here. */
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
