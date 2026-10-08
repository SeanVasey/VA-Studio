<?php

use App\Domain\Grants\Member\MemberGrantSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new MemberGrantSchema)->up();
    }

    public function down(): void
    {
        (new MemberGrantSchema)->down();
    }
};
