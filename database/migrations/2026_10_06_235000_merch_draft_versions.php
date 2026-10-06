<?php

use App\Domain\ProductAuthoring\PrivateDraftSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PrivateDraftSchema::install('merch');
    }

    public function down(): void
    {
        // A separate reviewed retention migration is required before removing retained data.
    }
};
