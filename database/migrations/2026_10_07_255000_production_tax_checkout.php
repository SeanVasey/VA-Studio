<?php

use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchemaInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new TaxCheckoutSchemaInstaller)->up();
    }

    public function down(): void
    {
        (new TaxCheckoutSchemaInstaller)->down();
    }
};
