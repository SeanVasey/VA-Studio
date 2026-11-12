<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Admission and ownership checks read definitions from approved earlier migrations. Requiring a migration file again
 * recompiles it and declares its anonymous classes again, which PHP never frees, so every database refresh grew the
 * process by about 1 MB. Foundation's SQLite shards, which refresh an in-memory database for each test, exhausted their
 * 512 MB limit about 1,100 tests in. A repeated refresh must not declare new classes.
 */
final class MigrationRecompilationTest extends TestCase
{
    public function test_repeated_migrations_do_not_recompile_migration_files(): void
    {
        // The first two runs compile everything the migrations and their admission checks load.
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->artisan('migrate:fresh')->assertSuccessful();
        $declared = count(get_declared_classes());

        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->artisan('migrate:fresh')->assertSuccessful();

        $this->assertSame($declared, count(get_declared_classes()));
    }

    /**
     * The runtime check above sees only the paths the current engine takes (a MySQL-only branch never runs on SQLite), so
     * every source that reads another migration must go through MigrationDefinitions. Two migrations of 2026-11-09/10
     * required one directly and brought the leak back on Foundation's SQLite shards (run 43304362814).
     */
    public function test_no_source_requires_a_migration_file_directly(): void
    {
        $offenders = [];
        foreach ([app_path(), database_path('migrations')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php' || $file->getPathname() === app_path('Support/MigrationDefinitions.php')) {
                    continue;
                }
                if (preg_match('/\b(?:require|include)(?:_once)?\b[^;]*migrations/', (string) file_get_contents($file->getPathname())) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'Use App\\Support\\MigrationDefinitions::load() to read another migration.');
    }
}
