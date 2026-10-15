<?php

use App\Domain\Grants\PaidTax\PaidTaxDeliverySchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PaidTaxDeliverySchema::install();
    }

    public function down(): void
    {
        throw new LogicException('Retain immutable Tax authorizations and committed delivery attempts.');
    }
};
