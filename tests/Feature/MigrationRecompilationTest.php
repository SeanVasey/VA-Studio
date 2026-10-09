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
}
