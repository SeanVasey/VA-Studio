<?php

namespace Tests\Review;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractIssuancePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActivationReviewCanaryTest extends TestCase
{
    public function test_default_issuance_remains_off_with_no_implicit_policy(): void
    {
        $this->assertFalse(config('contracts.test_issuance_enabled'));
        $this->assertNull(config('contracts.test_issuance_policy'));
        $this->expectException(ContractIssuanceException::class);
        (new ContractIssuancePolicy)->current();
    }

    public static function refusedConfigurations(): array
    {
        return ['production environment' => ['production', 'test', true, 'v2'],
            'live provider mode' => ['testing', 'live', true, 'v2'],
            'disabled issuance' => ['testing', 'test', false, 'v2'],
            'legacy policy' => ['testing', 'test', true, 'v1'],
            'altered entitlement effect' => ['testing', 'test', true, 'altered']];
    }

    #[DataProvider('refusedConfigurations')]
    public function test_explicit_refusal_boundaries_survive_current_version_move(string $environment, string $mode, bool $enabled, string $policy): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        $terms = $policy === 'v1' ? ContractIssuancePolicy::V1_CONTRACT : ContractIssuancePolicy::V2_CONTRACT;
        if ($policy === 'altered') {
            $terms['entitlements'] = 'active';
        }
        config(['contracts.test_issuance_enabled' => $enabled, 'contracts.test_issuance_policy' => json_encode($terms, JSON_THROW_ON_ERROR),
            'payments.stripe.account_id' => 'acct_SyntheticReview', 'payments.stripe.mode' => $mode]);
        try {
            (new ContractIssuancePolicy)->current();
            $this->fail('Refused configuration authorized test issuance.');
        } catch (ContractIssuanceException $exception) {
            $this->assertSame('unavailable', $exception->reason);
        }
    }
}
