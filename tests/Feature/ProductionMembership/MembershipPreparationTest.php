<?php

namespace Tests\Feature\ProductionMembership;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MemberGrantReceipt;
use App\Domain\Memberships\Production\MembershipEligibleLicenseAuthority;
use App\Domain\Memberships\Production\MembershipEligibleLicenseProof;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPaidInvoiceProof;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyBinding;
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\MembershipPolicyFactsProof;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\MembershipValues;
use App\Models\User;
use Tests\TestCase;

class MembershipPreparationTest extends TestCase
{
    public function test_default_off_and_missing_approved_facts_refuse_even_with_claimed_enablement(): void
    {
        foreach ([[], ['production-memberships.enabled' => true], ['production-memberships.enabled' => true, 'production-memberships.provenance' => IdentityPolicy::REHEARSAL]] as $configuration) {
            config($configuration);
            try {
                (new MembershipPolicy)->current();
                $this->fail('No missing source facts may award membership.');
            } catch (MembershipException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_flag_and_policy_hash_cannot_replace_actual_typed_invoice_capability(): void
    {
        $this->configuration();
        $this->app->bind(MembershipPaidInvoiceAuthority::class, fn () => new \stdClass);
        $this->expectException(MembershipException::class);
        (new MembershipPolicy)->current();
    }

    public function test_provider_callback_cannot_withdraw_policy_then_return_a_configuration_baseline(): void
    {
        $this->configuration();
        $this->app->bind(MembershipPaidInvoiceAuthority::class, function () {
            config(['production-memberships.enabled' => false]);

            return new class implements MembershipPaidInvoiceAuthority
            {
                public function lock(string $locator, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipPaidInvoiceProof
                {
                    throw new \LogicException('Unbound fixture');
                }

                public function proveCurrent(MembershipPaidInvoiceProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void
                {
                    throw new \LogicException('Unbound fixture');
                }
            };
        });
        $this->app->bind(MembershipPolicyFactsAuthority::class, fn () => new class implements MembershipPolicyFactsAuthority
        {
            public function lock(string $planVersion, MembershipRows $rows): MembershipPolicyFactsProof
            {
                throw new \LogicException('Unbound fixture');
            }

            public function proveCurrent(MembershipPolicyFactsProof $proof, MembershipRows $rows): void
            {
                throw new \LogicException('Unbound fixture');
            }
        });
        $this->app->bind(MembershipEligibleLicenseAuthority::class, fn () => new class implements MembershipEligibleLicenseAuthority
        {
            public function lock(string $selection, int $expectedRevision, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipEligibleLicenseProof
            {
                throw new \LogicException('Unbound fixture');
            }

            public function proveCurrent(MembershipEligibleLicenseProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void
            {
                throw new \LogicException('Unbound fixture');
            }
        });
        $this->app->bind(MemberGrantAuthority::class, fn () => new class implements MemberGrantAuthority
        {
            public function prepare(MemberGrantIntent $intent, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MemberGrantReceipt
            {
                throw new \LogicException('Unbound fixture');
            }

            public function proveReadyCurrent(MemberGrantReceipt $receipt, MemberGrantIntent $intent, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void
            {
                throw new \LogicException('Unbound fixture');
            }
        });
        $this->expectException(MembershipException::class);
        (new MembershipPolicy)->current();
    }

    public function test_value_bindings_refuse_secret_fields_and_test_owner_keys(): void
    {
        $this->expectException(MembershipException::class);
        MembershipValues::buyer(['owner_key' => str_repeat('a', 64), 'email' => 'synthetic@example.test']);
    }

    public function test_original_dates_are_exact_utc_and_do_not_silently_normalize(): void
    {
        $this->expectException(MembershipException::class);
        MembershipValues::utc('2026-02-30 00:00:00');
    }

    public function test_policy_value_requires_actual_bounded_allowance_and_explicit_billing_currency(): void
    {
        foreach ([[0, 'USD'], [MembershipPolicy::MAX_CREDITS + 1, 'USD'], [1, 'usd']] as [$allowance, $currency]) {
            try {
                new MembershipPolicyBinding('11111111-1111-4111-8111-111111111111', ...[str_repeat('a', 64), str_repeat('a', 64), str_repeat('a', 64), str_repeat('a', 64), str_repeat('a', 64), str_repeat('a', 64), str_repeat('a', 64), str_repeat('a', 64), IdentityPolicy::REHEARSAL, $allowance, 0, $currency, str_repeat('a', 64), str_repeat('a', 64)]);
                $this->fail('Technical value validation must reject an unusable benefit declaration.');
            } catch (MembershipException) {
                $this->assertTrue(true);
            }
        }
    }

    private function configuration(): void
    {
        config(['production-memberships.enabled' => true, 'production-memberships.version' => MembershipPolicy::VERSION,
            'production-memberships.provenance' => IdentityPolicy::REHEARSAL, 'production-memberships.approved_policy_hash' => str_repeat('a', 64)]);
    }
}
