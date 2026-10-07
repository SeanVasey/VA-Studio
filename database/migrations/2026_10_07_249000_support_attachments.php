<?php

use App\Domain\SupportAttachments\AttachmentSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new AttachmentSchema)->up();
    }

    public function down(): void
    {
        (new AttachmentSchema)->down();
    }
};
