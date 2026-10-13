<?php

namespace Tests\Feature\ProductionMembership;

use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\SealedMembershipPolicyFacts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipPolicyFixtures;
use Tests\TestCase;

class MembershipPolicyClockTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipPolicyFixtures;

    public function test_terminal_clock_callback_cannot_withdraw_policy_or_replace_the_transaction(): void
    {
        $this->policySetup();
        $created = $this->createPolicy();
        foreach (['configuration', 'transaction'] as $mutation) {
            config(['production-memberships.enabled' => true, 'production-memberships.approved_policy_hash' => $created['policy_hash']]);
            DB::beginTransaction();
            try {
                $rows = new MembershipRows;
                $source = new SealedMembershipPolicyFacts;
                $proof = $source->lock($created['id'], $rows);
                $now = CarbonImmutable::now('UTC');
                $calls = 0;
                CarbonImmutable::setTestNow(function () use (&$calls, $mutation, $now) {
                    if (++$calls === 2) {
                        if ($mutation === 'configuration') {
                            config(['production-memberships.enabled' => false]);
                        } else {
                            DB::commit();
                            DB::beginTransaction();
                        }
                    }

                    return $now;
                });
                try {
                    $source->proveCurrent($proof, $rows);
                    $this->fail('A terminal clock mutation must invalidate the proof: '.$mutation);
                } catch (MembershipException $error) {
                    $this->assertSame($mutation === 'configuration' ? 'changed_policy' : 'changed_primary_or_schema', $error->reason);
                    $this->assertSame(2, $calls);
                }
            } finally {
                CarbonImmutable::setTestNow();
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
        }
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }
}
