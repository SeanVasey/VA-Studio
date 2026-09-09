<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrackMetadataMigrationTest extends TestCase
{
    public function test_migration_preserves_known_public_urls_without_inventing_dates_or_edit_history(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.track_history_fixture' => array_replace(config('database.connections.sqlite'), ['database' => ':memory:', 'url' => null])]);
        DB::setDefaultConnection('track_history_fixture');
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (['0001_01_01_000000_create_users_table.php', '2026_09_04_000001_create_catalog_foundation.php'] as $filename) {
                (require database_path('migrations/'.$filename))->up();
            }
            DB::table('tracks')->insert([
                ['title' => 'Never published', 'slug' => 'draft', 'status' => 'draft', 'published_at' => null],
                ['title' => 'Unpublished history', 'slug' => 'retained', 'status' => 'draft', 'published_at' => '2026-08-01 12:00:00'],
                ['title' => 'Legacy published without date', 'slug' => 'legacy', 'status' => 'published', 'published_at' => null],
            ]);
            $before = DB::table('tracks')->orderBy('id')->get();
            (require database_path('migrations/2026_09_09_000008_track_metadata_and_public_urls.php'))->up();
            $after = DB::table('tracks')->orderBy('id')->get();
            foreach ($before as $index => $row) {
                foreach ((array) $row as $field => $value) {
                    $this->assertSame($value, $after[$index]->{$field}, 'Historical field changed: '.$field);
                }
                $this->assertSame(0, $after[$index]->metadata_version);
            }
            $this->assertSame([null, 'retained', 'legacy'], $after->pluck('published_slug')->all());
            DB::table('tracks')->where('slug', 'legacy')->update(['status' => 'draft']);
            $this->assertSame('legacy', DB::table('tracks')->where('slug', 'legacy')->value('published_slug'));
            $this->assertSame(0, DB::table('audit_events')->count());
        } finally {
            DB::purge('track_history_fixture');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
