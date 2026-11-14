<?php

use App\Domain\Memberships\Billing\BillingRetrievalSchema;
use Illuminate\Database\Migrations\Migration;

/** Forward retained-data extension: interval allocation identities and database-issued time. */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        (new BillingRetrievalSchema)->upgrade();
    }

    public function down(): never
    {
        (new BillingRetrievalSchema)->down();
    }
};
