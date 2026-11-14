<?php

namespace Tests\Feature\ProductionMemberGrants;

use App\Domain\Memberships\Production\MembershipPeriodRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;
use Throwable;

/** Native INSERT permission failures at the four real effect stages; not arbitrary failure coverage. */
class MemberGrantNativeWriteRollbackTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberActivationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires native MySQL table INSERT grants and a disposable test account.');
        }
        $this->memberActivationSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function writeStages(): array
    {
        return ['origin' => ['production_member_origins'], 'artifact' => ['production_member_artifacts'],
            'consume' => [MembershipPeriodRecords::EVENTS], 'activation' => ['production_member_activations']];
    }

    #[DataProvider('writeStages')]
    public function test_a_native_denial_at_each_owned_write_stage_rolls_back_every_partial_effect(string $target): void
    {
        $fixture = $this->memberActivationFixture();
        $before = $this->memberOriginalEffects();
        $audit = $this->ledgerSnapshot($fixture);
        $setupPdo = DB::connection()->getPdo();
        $schema = $setupPdo->query('SELECT DATABASE()')->fetchColumn();
        $client = $setupPdo->query('SELECT USER()')->fetchColumn();
        $host = substr($client, strrpos($client, '@') + 1);
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/\A[a-zA-Z0-9_]{1,64}\z/D', $schema);
        $this->assertNotContains(strtolower($schema), ['mysql', 'sys', 'information_schema', 'performance_schema']);
        $this->assertMatchesRegularExpression('/\A[a-zA-Z0-9_.:-]+\z/D', $host);
        $this->assertSame('', DB::connection()->getTablePrefix());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($setupPdo->inTransaction());

        // This private disposable account has no global/schema INSERT privilege. Only individual
        // non-target tables receive INSERT. Schema, guards, real PDO and domain writers stay intact.
        $username = 'va_c4c_'.bin2hex(random_bytes(8));
        $password = bin2hex(random_bytes(32));
        $account = $setupPdo->quote($username).'@'.$setupPdo->quote($host);
        $connectionName = DB::getDefaultConnection();
        $configurationKey = 'database.connections.'.$connectionName;
        $configuration = config($configurationKey);
        $created = false;
        try {
            $setupPdo->exec('CREATE USER '.$account.' IDENTIFIED BY '.$setupPdo->quote($password));
            $created = true;
            $setupPdo->exec('GRANT SELECT, UPDATE, DELETE, TRIGGER, REFERENCES, LOCK TABLES ON `'.$schema.'`.* TO '.$account);
            $tables = $setupPdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM);
            $names = array_column($tables, 0);
            $this->assertContains($target, $names);
            foreach ($names as $table) {
                $this->assertMatchesRegularExpression('/\A[a-zA-Z0-9_]{1,64}\z/D', $table);
                if ($table !== $target) {
                    $setupPdo->exec('GRANT INSERT ON `'.$schema.'`.`'.$table.'` TO '.$account);
                }
            }
            config([$configurationKey => [...$configuration, 'url' => null, 'username' => $username, 'password' => $password]]);
            DB::purge($connectionName);
            $restricted = DB::connection()->getPdo();
            $this->assertNotSame($setupPdo, $restricted);
            $this->assertSame($schema, $restricted->query('SELECT DATABASE()')->fetchColumn());

            // Start every captured workflow after the genuine restricted connection is installed.
            $authorization = $this->confirmMemberActivation($fixture);
            $failure = null;
            try {
                $this->activateMemberFixture($fixture, $authorization);
            } catch (Throwable $error) {
                $failure = $error;
            }
            $this->assertNotNull($failure, 'The specific target INSERT must fail, not commit.');
            $native = $this->nativeCause($failure);
            $this->assertNotNull($native, 'An earlier domain/schema refusal is not write-stage coverage.');
            $this->assertSame(1142, (int) ($native->errorInfo[1] ?? 0));
            $this->assertStringContainsString('INSERT command denied', $native->errorInfo[2] ?? '');
            $this->assertStringContainsString("for table '".$target."'", $native->errorInfo[2] ?? '');
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse($restricted->inTransaction());
            $this->assertMemberOriginalEffectsUnchanged($before);
            $this->assertSame($audit, $this->ledgerSnapshot($fixture));
        } finally {
            DB::purge($connectionName);
            config([$configurationKey => $configuration]);
            if ($created) {
                $setupPdo->exec('DROP USER '.$account);
            }
        }
        // The privileged setup connection independently sees the same durable rows after cleanup.
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    private function nativeCause(Throwable $error): ?PDOException
    {
        do {
            if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1142) {
                return $error;
            }
            $error = $error->getPrevious();
        } while ($error !== null);

        return null;
    }
}
