<?php

namespace Tests\IndependentIdentity;

use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class ProductionIdentityPermanentUserCanaryTest extends TestCase
{
    use ProductionIdentityFixture;

    public function test_sign_in_observes_permanent_credential_withdrawal_and_refuses_a_temporary_old_user_copy(): void
    {
        $this->identitySetup();
        $identity = $this->enrollThroughLocalSmtp();
        $pdo = DB::connection()->getPdo();
        $this->assertSame('sqlite', DB::getDriverName());
        $pdo->exec('CREATE TEMP TABLE users AS SELECT * FROM main.users');
        $statement = $pdo->prepare('UPDATE main.users SET password=? WHERE id=?');
        $statement->execute([Hash::make('RecoveredPassword456'), $identity['user']->id]);
        $before = $pdo->query('SELECT * FROM main.users ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        try {
            $result = (new ProductionCustomerSessions)->authenticate('buyer@example.test', 'MailboxPassword123');
        } finally {
            $pdo->exec('DROP TABLE temp.users');
        }
        $this->assertSame($before, $pdo->query('SELECT * FROM main.users ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertNull($result, 'Sign-in must refuse the actual withdrawn permanent credential even if a temporary user copy retains the old row.');
    }
}
