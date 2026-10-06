<?php

use App\Domain\ProductAuthoring\PrivateDraftSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PrivateDraftSchema::install('service');
    }

    public function down(): void
    {
        // Code rollback must retain private definitions, original keys and audit subjects.
    }
};
