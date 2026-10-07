<?php

use App\Domain\Services\Projects\ServiceProjectSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ServiceProjectSchema::install();
    }

    public function down(): void
    {
        throw new LogicException('Retain service briefs, authored quotes, decisions and milestone history.');
    }
};
