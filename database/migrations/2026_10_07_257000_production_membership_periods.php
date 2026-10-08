<?php

use App\Domain\Memberships\Production\MembershipSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new MembershipSchema)->up();
    }

    public function down(): never
    {
        (new MembershipSchema)->down();
    }
};
