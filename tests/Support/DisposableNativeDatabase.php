<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Decides whether a destructive native-MySQL test may run against the current database.
 *
 * Some native tests drop every table in the connection's database during setUp (for example
 * FreeGrantSchemaRecoveryTest calls Schema::dropAllTables()). Locally, several lanes share one MySQL
 * daemon, so those tests stay pinned to their own dedicated schema and refuse every other name.
 *
 * In Foundation CI, each MySQL job owns a disposable service container that nothing else uses. There
 * the externally selected DB_DATABASE is just as isolated as a dedicated schema. That path is admitted
 * only when every CI condition holds: the job's explicit VA_CI_DISPOSABLE_MYSQL=1 marker, CI=true
 * (set by both GitHub Actions and GitLab CI), the testing environment, and a connection whose database
 * is exactly DB_DATABASE. The name must also be a plain identifier that does not look like a real or
 * production schema.
 *
 * WARNING: set VA_CI_DISPOSABLE_MYSQL=1 only against a private, disposable mysqld that nothing else
 * uses. The guarded tests drop every table in DB_DATABASE. Copying the CI environment onto a shared
 * or long-lived server wipes whatever database DB_DATABASE names there.
 */
final class DisposableNativeDatabase
{
    /**
     * @param  string  $dedicated  The schema this test is pinned to outside CI.
     * @param  string|null  $actual  The connection's database name; defaults to the current connection's.
     */
    public static function isAdmitted(string $dedicated, ?string $actual = null): bool
    {
        $actual ??= (string) DB::getDatabaseName();
        if ($dedicated !== '' && $actual === $dedicated) {
            return true;
        }
        $selected = getenv('DB_DATABASE');

        return getenv('VA_CI_DISPOSABLE_MYSQL') === '1'
            && getenv('CI') === 'true'
            && app()->environment('testing')
            && is_string($selected) && $selected !== '' && $actual === $selected
            && self::isDisposableName($actual);
    }

    private static function isDisposableName(string $name): bool
    {
        // A plain identifier only; never the application's own schema name or one that reads as production or live.
        return preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $name) === 1
            && strtolower($name) !== 'vaseyaudio'
            && preg_match('/prod|live/i', $name) !== 1;
    }
}
