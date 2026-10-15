<?php

use App\Domain\Grants\PaidTax\PaidTaxSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PaidTaxSchema::install();
    }

    public function down(): void
    {
        throw new LogicException('Retain immutable Tax V2 origins, originals and work evidence.');
    }
};
