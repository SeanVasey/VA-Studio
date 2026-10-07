<?php

namespace Tests\IndependentIdentityRegistration;

use App\Domain\Customers\ProductionIdentity\IdentitySchema;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class IdentityNativeDependencyAdmissionCanaryTest extends TestCase
{
    use ProductionIdentityFixture;

    public function test_missing_native_parent_columns_are_refused_before_owned_ddl(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('I', 32))]);
        $this->identitySetup();
        $this->assertSame('mysql', DB::getDriverName());
        $migration = require database_path('migrations/2026_10_07_247000_production_customer_identity.php');
        $migration->down();
        $pdo = DB::connection()->getPdo();
        // Actual complete prior migrations; compatible id for existing foreign keys, missing required credential columns.
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $pdo->exec('DROP TABLE users');
            $pdo->exec('CREATE TABLE users (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $before = $pdo->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(\PDO::FETCH_ASSOC);
        $refused = false;
        try {
            $migration->up();
        } catch (LogicException) {
            $refused = true;
        }
        $after = $pdo->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(\PDO::FETCH_ASSOC);
        file_put_contents(base_path('docs/verification/cloud-identity-independent-20261007/native-dependency-admission-snapshot.json'), json_encode(['source' => trim(shell_exec('git rev-parse HEAD')), 'server_version' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'refused' => $refused, 'foreign_key_checks' => $pdo->query('SELECT @@session.foreign_key_checks')->fetchColumn(), 'parent_create' => $pdo->query('SHOW CREATE TABLE users')->fetch(\PDO::FETCH_ASSOC), 'owned_tables_created' => array_values(array_intersect(array_column($after, 'TABLE_NAME'), array_keys(IdentitySchema::definitions()))), 'before' => $before, 'after' => $after], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
        $this->assertTrue($refused, 'Missing required native users credential columns must be refused before identity DDL.');
        $this->assertSame($before, $after);
    }
}
