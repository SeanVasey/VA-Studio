<?php

use App\Domain\Customers\ProductionIdentity\AccountAdmissionUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        (new AccountAdmissionUpgrade)->up(DB::connection());
    }

    public function down(): void
    {
        (new AccountAdmissionUpgrade)->down(DB::connection());
    }
};
