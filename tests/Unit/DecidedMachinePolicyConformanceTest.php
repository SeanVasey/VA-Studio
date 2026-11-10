<?php

namespace Tests\Unit;

use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DecidedMachinePolicyFixture as Decided;
use Tests\Support\ProductionTrackPolicyFixtures;
use Tests\TestCase;

/**
 * BL-43. Pins the decided machine-policy values on both sides: the fixture must validate against the
 * policy, and each fixture value must be the one the decision register and the approved TERMS state,
 * so a later edit to either side fails here. No production value is committed outside the fixture.
 */
class DecidedMachinePolicyConformanceTest extends TestCase
{
    private const TERMS_A8 = '/\*\*Approved by Sean on 2026-11-07:\*\* "(?<text>.+?)" Seller legal name for `seller_identity\.legal_name`: "(?<seller>[^"]+)"/';

    public function test_the_decided_values_assemble_into_a_policy_that_validates_for_its_source(): void
    {
        $source = ProductionTrackPolicyFixtures::authored();
        $policy = Decided::policy($source);

        $this->assertSame($policy, MachinePolicyV1::validate($policy));
        $this->assertSame($policy, MachinePolicyV1::forSource($policy, $source));
    }

    public function test_host_facts_stay_synthetic_and_no_price_or_approval_is_carried(): void
    {
        $policy = Decided::policy();
        $provider = $policy['choices']['provider_account'];

        $this->assertSame('acct_SYNTHETIC', $provider['account_id']);
        $this->assertSame('https://review.invalid', $provider['return_origin']);
        $this->assertSame('synthetic-decided-v1', $policy['version']);
        $this->assertSame('synthetic-assent-v1', $policy['choices']['assent']['version']);
        $this->assertSame('synthetic-posix-v1', $policy['choices']['storage']['adapter_version']);
        $this->assertSame('synthetic-retention-v1', $policy['choices']['privacy']['retention_policy_id']);
        $this->assertStringNotContainsStringIgnoringCase('price', json_encode($policy, JSON_THROW_ON_ERROR));
    }

    public function test_register_states_the_dd16_dd17_and_dd42_values_the_fixture_uses(): void
    {
        $register = $this->read('docs/architecture/decision-register.md');

        foreach (Decided::REGISTER_VALUES as $id => $expected) {
            $row = $this->registerRow($register, $id);
            $stated = [];
            preg_match_all('/`([a-z_.]+) = ([A-Za-z0-9_]+)`/', $row, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $stated[$m[1]] = ctype_digit($m[2]) ? (int) $m[2] : $m[2];
            }
            foreach ($expected as $field => $value) {
                $this->assertArrayHasKey($field, $stated, "$id no longer states $field");
                $this->assertSame($value, $stated[$field], "$id value for $field drifted from the fixture");
            }
            // A value the register adds but the fixture does not pin would go unchecked.
            $this->assertSame([], array_diff_key($stated, $expected), "$id states a value the fixture does not pin");
        }
        $this->assertStringContainsString('verified_account_recovery', $this->registerRow($register, 'DD-42'));
    }

    public function test_register_dd11_decides_one_us_dollar_currency_in_integer_cents(): void
    {
        $row = $this->registerRow($this->read('docs/architecture/decision-register.md'), 'DD-11');

        $this->assertStringContainsString('One currency: USD, stored as integer cents', $row);
        $this->assertSame(['code' => 'USD', 'minor_unit_exponent' => 2], Decided::CURRENCY);
        $this->assertSame(2, MachinePolicyV1::CURRENCIES['USD']);
    }

    public function test_assent_text_and_seller_name_are_those_sean_approved_in_the_terms(): void
    {
        $this->assertSame(1, preg_match(self::TERMS_A8, $this->read('docs/legal/TERMS.md'), $m));
        $this->assertSame($m['text'], Decided::ASSENT_TEXT);
        $this->assertSame($m['seller'], Decided::SELLER_LEGAL_NAME);
        $this->assertLessThanOrEqual(4096, strlen(Decided::ASSENT_TEXT));
    }

    /** @return array<string, array{0: string, 1: string, 2: mixed}> */
    public static function valuesTheDecisionsRuleOut(): array
    {
        return [
            'DD-16 partial refunds by whole order' => ['refunds_and_disputes', 'partial_refunds', 'whole_order_only'],
            'DD-16 refund authority in the application' => ['refunds_and_disputes', 'refund_authority', 'application_decides'],
            'DD-17 provider session outliving the reservation' => ['reservation_and_exclusives', 'provider_lifetime_seconds', 90000],
            'DD-17 automatic late-payment action' => ['reservation_and_exclusives', 'late_action', 'auto_refund'],
            'DD-42 unverified guest' => ['buyer_identity', 'mode', 'unverified_guest'],
            'DD-11 exponent for another currency' => ['currency', 'minor_unit_exponent', 0],
            'blank seller' => ['seller_identity', 'legal_name', ''],
        ];
    }

    #[DataProvider('valuesTheDecisionsRuleOut')]
    public function test_the_policy_refuses_values_the_decisions_rule_out(string $category, string $field, mixed $value): void
    {
        $policy = Decided::policy();
        $policy['choices'][$category][$field] = $value;

        $this->expectException(ValidationException::class);
        MachinePolicyV1::validate($policy);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents, "$path is unreadable");

        return $contents;
    }

    private function registerRow(string $register, string $id): string
    {
        $this->assertSame(1, preg_match('/^\| '.preg_quote($id, '/').' \|.*$/m', $register, $m), "$id row missing from the decision register");

        return $m[0];
    }
}
