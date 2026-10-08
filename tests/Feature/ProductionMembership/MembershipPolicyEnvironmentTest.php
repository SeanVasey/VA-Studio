<?php

namespace Tests\Feature\ProductionMembership;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantFactsAuthority;
use App\Domain\Grants\Member\MemberGrantPolicy;
use App\Domain\Grants\Member\MemberOriginalArtifactAuthority;
use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MembershipEligibleLicenseAuthority;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use Mockery;
use Tests\TestCase;

/**
 * Laravel binds `env` through Container::offsetSet (a closure), never as an instance. Both policies
 * must read that captured value without resolving it: rehearsal is admitted in `testing`, refused in
 * `production`, and a replaced environment binding is refused without being invoked.
 *
 * The capability stubs are Mockery doubles bound only to pass the capability gate. They are not
 * invoice, policy, license or grant producers, and nothing here awards or activates anything.
 */
class MembershipPolicyEnvironmentTest extends TestCase
{
    public static int $calls = 0;

    public function test_membership_rehearsal_is_admitted_in_testing_and_refused_in_production(): void
    {
        $this->membershipRehearsal();
        $current = (new MembershipPolicy)->current();
        $this->assertSame(['testing', IdentityPolicy::REHEARSAL], [$current['environment'], $current['provenance']]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame('provenance', $this->membershipReason());
    }

    public function test_member_grant_rehearsal_is_admitted_in_testing_and_refused_in_production(): void
    {
        $this->memberGrantRehearsal();
        $current = (new MemberGrantPolicy)->current();
        $this->assertSame(['testing', IdentityPolicy::REHEARSAL], [$current['environment'], $current['provenance']]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame('provenance', $this->memberGrantReason());
    }

    public function test_replaced_environment_binding_is_refused_without_being_invoked(): void
    {
        $this->membershipRehearsal();
        $this->memberGrantRehearsal();
        self::$calls = 0;
        $this->app->bind('env', static function (): string {
            self::$calls++;
            config(['production-memberships.enabled' => false, 'member-grants.enabled' => false]);

            return 'testing';
        });
        $this->assertSame('changed_policy', $this->membershipReason());
        $this->assertSame('changed_policy', $this->memberGrantReason());
        $this->assertSame(0, self::$calls);
        $this->assertTrue(config('production-memberships.enabled'));
        $this->assertTrue(config('member-grants.enabled'));
    }

    private function membershipRehearsal(): void
    {
        config(['production-memberships.enabled' => true, 'production-memberships.version' => MembershipPolicy::VERSION,
            'production-memberships.provenance' => IdentityPolicy::REHEARSAL, 'production-memberships.approved_policy_hash' => str_repeat('a', 64)]);
        foreach ([MembershipPaidInvoiceAuthority::class, MembershipPolicyFactsAuthority::class, MembershipEligibleLicenseAuthority::class, MemberGrantAuthority::class] as $capability) {
            $double = Mockery::mock($capability);
            $this->app->instance($capability, $double);
        }
    }

    private function memberGrantRehearsal(): void
    {
        config(['member-grants.enabled' => true, 'member-grants.provenance' => IdentityPolicy::REHEARSAL,
            'member-grants.approved_definition_hash' => str_repeat('a', 64), 'member-grants.approved_profile_hash' => str_repeat('b', 64),
            'member-grants.approved_original_terms_hash' => str_repeat('c', 64)]);
        foreach ([MemberGrantFactsAuthority::class, MemberOriginalArtifactAuthority::class, MembershipReservationAuthority::class] as $capability) {
            $this->app->instance($capability, Mockery::mock($capability));
        }
    }

    private function membershipReason(): string
    {
        try {
            (new MembershipPolicy)->current();

            return 'admitted';
        } catch (MembershipException $error) {
            return $error->reason;
        }
    }

    private function memberGrantReason(): string
    {
        try {
            (new MemberGrantPolicy)->current();

            return 'admitted';
        } catch (MemberGrantException $error) {
            return $error->reason;
        }
    }
}
