<?php

use App\Domain\Grants\Paid\PaidGrantSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PaidGrantSchema::install();
    }

    public function down(): void
    {
        throw new LogicException('Retain immutable paid grant origins, originals and attempts.');
    }
};
