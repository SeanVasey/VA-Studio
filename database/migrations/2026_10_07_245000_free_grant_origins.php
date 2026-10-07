<?php

use App\Domain\Grants\Free\FreeGrantSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        FreeGrantSchema::install();
    }

    public function down(): void
    {
        throw new LogicException('Free origins, assent and originals are retained. Use a reviewed forward successor.');
    }
};
