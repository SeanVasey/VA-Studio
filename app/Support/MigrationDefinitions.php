<?php

namespace App\Support;

/**
 * Read-only access to an approved migration's own definitions (guards, triggers, table SQL, ownership checks).
 *
 * Requiring a migration file again recompiles it and declares its anonymous classes again, which PHP never frees. Each
 * file is therefore compiled at most once per process, as Laravel's migrator does, and callers get a copy.
 */
final class MigrationDefinitions
{
    /** @var array<string, object> */
    private static array $loaded = [];

    /** The migration object declared by `database/migrations/{$file}`. */
    public static function load(string $file): object
    {
        $path = database_path('migrations/'.$file);

        return clone (self::$loaded[$path] ??= require $path);
    }
}
