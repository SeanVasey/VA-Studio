<?php

use App\Domain\Customers\ProductionFeatures\ProductionFeatureSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new ProductionFeatureSchema)->up();
    }

    public function down(): void
    {
        (new ProductionFeatureSchema)->down();
    }
};
