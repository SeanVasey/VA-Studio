<?php

use App\Domain\Commerce\ProductionCheckout\CheckoutSchemaInstaller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new CheckoutSchemaInstaller)->up();
    }

    public function down(): void
    {
        throw new LogicException('Retain production checkout authority, assent, orders and provider evidence.');
    }
};
