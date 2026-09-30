<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SiteImageGuardBytesTest extends TestCase
{
    public function test_only_whole_column_names_are_compared_by_bytes(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_09_30_000027_site_images.php';

        $this->assertSame(
            "CAST(NEW.slot AS BINARY) = CAST(OLD.slot AS BINARY) AND LENGTH(TRIM(CAST(NEW.credit AS BINARY))) > 0 AND CAST(i.status AS BINARY) = 'ready'",
            $migration->bytewise("NEW.slot = OLD.slot AND LENGTH(TRIM(NEW.credit)) > 0 AND i.status = 'ready'"),
        );
        // A longer name, a name with a prefix and a qualified name are other columns and stay as they are.
        $others = 'NEW.slot_x = OLD.slot_x AND XNEW.slot = 1 AND NEW.slot$x = 2 AND t.i.status = 3 AND NEW.status2 = 4';
        $this->assertSame($others, $migration->bytewise($others));
    }

    public function test_the_release_image_index_compares_only_whole_column_names_by_bytes(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_09_30_000028_site_release_images.php';

        $this->assertSame(
            "CAST(NEW.slot AS BINARY) IN ('studio') AND CAST(i.status AS BINARY) = 'ready' AND CAST(i.slot AS BINARY) = CAST(NEW.slot AS BINARY)",
            $migration->bytewise("NEW.slot IN ('studio') AND i.status = 'ready' AND i.slot = NEW.slot"),
        );
        $others = 'i.slot_group = NEW.slot_x AND ri.slot = 1 AND NEW.slot$x = 2 AND t.i.status = 3 AND i.status2 = 4';
        $this->assertSame($others, $migration->bytewise($others));
    }
}
