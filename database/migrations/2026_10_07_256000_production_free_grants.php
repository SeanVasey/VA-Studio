<?php

use App\Domain\Grants\ProductionFree\ProductionFreeGrantSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new ProductionFreeGrantSchema)->up();
    }

    public function down(): void
    {
        (new ProductionFreeGrantSchema)->down();
    }
};
