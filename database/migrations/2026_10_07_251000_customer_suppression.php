<?php

use App\Domain\Customers\Preferences\Suppression\SuppressionSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new SuppressionSchema)->up();
    }

    public function down(): void
    {
        (new SuppressionSchema)->down();
    }
};
