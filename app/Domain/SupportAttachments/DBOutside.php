<?php

namespace App\Domain\SupportAttachments;

use Illuminate\Support\Facades\DB;

final class DBOutside
{
    public static function check(): bool { return DB::transactionLevel() === 0; }
}
