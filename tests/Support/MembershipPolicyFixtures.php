<?php

namespace Tests\Support;

use App\Domain\Memberships\Production\MembershipPolicyDocument;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Explicit synthetic terms, never owner-approved commerce defaults. */
trait MembershipPolicyFixtures
{
    protected function policySetup(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('P', 32)), 'production-memberships.enabled' => false,
            'production-memberships.authoring_enabled' => true, 'production-memberships.provenance' => 'synthetic_rehearsal']);
    }

    protected function policyInput(array $overrides = []): array
    {
        return [...['schema_version' => MembershipPolicyDocument::VERSION, 'scope' => 'paid_period_award',
            'provenance' => 'synthetic_rehearsal', 'plan_name' => 'SYNTHETIC policy fixture',
            'terms_reference' => 'synthetic-policy-20261013', 'terms_text' => 'SYNTHETIC TEST TERMS. Not a customer offer or legal text.',
            'credit_allowance' => 3, 'billing_price_minor' => 1700, 'billing_currency' => 'USD',
            'period_source' => 'verified_invoice_line_service_period',
            'award_terms' => ['expiry' => ['mode' => 'after_period_end', 'seconds' => 86400],
                'late_payment' => ['mode' => 'grace', 'seconds' => 3600]]], ...$overrides];
    }

    protected function policyStaff(bool $mfa = true, array $overrides = []): User
    {
        $user = User::factory()->create(['is_admin' => true, 'email_verified_at' => now(), ...$overrides]);
        if ($mfa) {
            $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
        }

        return User::query()->findOrFail($user->getKey());
    }

    protected function createPolicy(?User $actor = null, array $overrides = []): array
    {
        $actor ??= $this->policyStaff();
        $document = MembershipPolicyDocument::from($this->policyInput($overrides));

        // The test owns the commit. The service returns only a provisional identity.
        return DB::transaction(fn () => (new MembershipPolicyVersions)->create($document, $actor, new MembershipRows));
    }
}
