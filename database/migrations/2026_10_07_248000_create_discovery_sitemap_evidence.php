<?php

use App\Domain\Catalog\DiscoverySitemap\SitemapSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new SitemapSchema)->up();
    }

    public function down(): void
    {
        (new SitemapSchema)->down();
    }
};
