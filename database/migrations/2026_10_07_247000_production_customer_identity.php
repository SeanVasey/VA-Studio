<?php

use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Customers\ProductionIdentity\IdentitySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Keep application writers and competing migrators stopped while implicit-commit DDL resumes. */
    public function up(): void
    {
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $driver = $connection->getDriverName();
        [$present] = (new IdentityMigrationOwnership)->inspect($primary, $driver);
        foreach (array_keys(IdentitySchema::definitions()) as $table) {
            if (! $present[$table]) {
                $primary->exec(IdentitySchema::tableSql($table, $driver));
            }
        }
        foreach (IdentitySchema::guards($driver) as $name => $guard) {
            if (! $present[$name]) {
                $primary->exec($guard['sql']);
            }
        }
        (new IdentityMigrationOwnership)->inspect($primary, $driver);
    }

    public function down(): void
    {
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $driver = $connection->getDriverName();
        [$present, $populated] = (new IdentityMigrationOwnership)->inspect($primary, $driver);
        if ($populated) {
            throw new LogicException('Retain production identity and notification history during code rollback.');
        }
        foreach (array_reverse(array_keys(IdentitySchema::guards($driver))) as $name) {
            if ($present[$name]) {
                $primary->exec('DROP TRIGGER `'.$name.'`');
            }
        }
        foreach (array_reverse(array_keys(IdentitySchema::definitions())) as $table) {
            if ($present[$table]) {
                $primary->exec('DROP TABLE `'.$table.'`');
            }
        }
    }
};
