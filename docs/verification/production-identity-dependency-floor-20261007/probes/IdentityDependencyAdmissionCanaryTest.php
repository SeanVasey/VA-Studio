<?php

namespace Tests\IndependentIdentityRegistration;

use App\Domain\Customers\ProductionIdentity\IdentitySchema;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class IdentityDependencyAdmissionCanaryTest extends TestCase
{
    use ProductionIdentityFixture;

    public function test_incompatible_actual_dependency_is_refused_before_owned_ddl(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('I', 32))]);
        $this->identitySetup();
        $this->assertSame('sqlite', DB::getDriverName());
        $migration = require database_path('migrations/2026_10_07_247000_production_customer_identity.php');
        $migration->down();
        $pdo = DB::connection()->getPdo();
        // Full real prior-migration graph, then an actual incompatible parent replacement.
        $pdo->exec('DROP TABLE users');
        $pdo->exec('CREATE TABLE users (id TEXT PRIMARY KEY NOT NULL)');
        $before = $pdo->query('SELECT type,name,tbl_name,sql FROM main.sqlite_master ORDER BY type,name')->fetchAll(\PDO::FETCH_ASSOC);
        $refused = false;
        try {
            $migration->up();
        } catch (LogicException) {
            $refused = true;
        }
        $after = $pdo->query('SELECT type,name,tbl_name,sql FROM main.sqlite_master ORDER BY type,name')->fetchAll(\PDO::FETCH_ASSOC);
        file_put_contents(base_path('docs/verification/cloud-identity-independent-20261007/dependency-admission-snapshot.json'), json_encode(['source' => trim(shell_exec('git rev-parse HEAD')), 'refused' => $refused, 'prior_actual_schema_objects' => count($before), 'post_migration_schema_objects' => count($after), 'owned_tables_created' => array_values(array_intersect(array_column($after, 'name'), array_keys(IdentitySchema::definitions()))), 'before' => $before, 'after' => $after], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
        $this->assertTrue($refused, 'Incompatible required users dependency must be refused before identity DDL.');
        $this->assertSame($before, $after);
    }
}
