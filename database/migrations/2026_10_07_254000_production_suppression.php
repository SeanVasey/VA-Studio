<?php

use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new ProductionSuppressionSchema)->up();
    }

    public function down(): void
    {
        (new ProductionSuppressionSchema)->down();
    }
};
