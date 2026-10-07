<?php

use App\Domain\Grants\Member\MemberGrantSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Additive successor of 258 (finding F2): installs only the missing activation/consume coupling guard
 * through the same owned-prefix installer. It refuses, without adopting, an activation table that
 * already holds rows, and never drops or rewrites an existing guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new MemberGrantSchema)->up();
    }

    public function down(): never
    {
        (new MemberGrantSchema)->down();
    }
};
