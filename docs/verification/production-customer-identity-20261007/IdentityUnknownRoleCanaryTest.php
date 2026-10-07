<?php
namespace Tests\AuthorIdentityCanaries;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;
final class IdentityUnknownRoleCanaryTest extends TestCase
{
    use ProductionIdentityFixture;
    public function test_unknown_permanent_role_flag_never_counts_as_nonstaff_customer_authority(): void
    {
        $this->identitySetup(); $identity = $this->enrollThroughLocalSmtp();
        DB::connection()->getPdo()->exec("UPDATE main.users SET is_admin='withdrawn'");
        $this->assertTrue($identity['user']->fresh()->is_admin);
        $this->assertNull((new ProductionCustomerSessions)->authenticate('buyer@example.test', 'MailboxPassword123'));
    }
}
