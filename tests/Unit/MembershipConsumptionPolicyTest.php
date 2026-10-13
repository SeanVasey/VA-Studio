<?php

namespace Tests\Unit;

use App\Domain\Memberships\Production\MembershipAwardTerms;
use App\Domain\Memberships\Production\MembershipConsumptionTerms;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPolicyBinding;
use App\Domain\Memberships\Production\MembershipPolicyDocument;
use App\Domain\Memberships\Production\MembershipRedemptionTerms;
use App\Support\CanonicalJson;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MembershipPolicyFixtures;

final class MembershipConsumptionPolicyTest extends TestCase
{
    use MembershipPolicyFixtures;

    private const ID = '00000000-0000-4000-8000-000000000001';

    public function test_complete_explicit_policy_preserves_bytes_and_binds_retention_and_confirmation(): void
    {
        $input = $this->consumptionPolicyInput();
        $input['consumption_terms']['text'] .= "\r\n Deliberate spacing. \n";
        $document = MembershipPolicyDocument::from($input);
        $terms = $document->consumptionTerms;
        $binding = $document->binding(self::ID);
        self::assertSame(CanonicalJson::hash($input), $document->hash());
        self::assertSame($input['consumption_terms']['text'], $terms->value()['text']);
        self::assertSame(CanonicalJson::hash($input['consumption_terms']), $terms->hash());
        self::assertSame(CanonicalJson::hash($input['consumption_terms']['retention_policy']), $binding->retentionPolicyHash);
        self::assertSame(CanonicalJson::hash($input['consumption_terms']['authorization_policy']), $terms->authorizationHash());
        self::assertSame(['paid_period_award', 'reserve', 'release', 'expire', 'consume'], $binding->supportedOperations);
        self::assertTrue($binding->supports('consume'));
    }

    public function test_documents_and_direct_bindings_detach_nested_inputs_and_getter_arrays(): void
    {
        $input = $this->consumptionPolicyInput();
        $text = $input['consumption_terms']['authorization_policy']['display_text'];
        $input['consumption_terms']['authorization_policy']['display_text'] = &$text;
        $seconds = 3600;
        $input['award_terms']['late_payment']['seconds'] = &$seconds;
        $amount = 2;
        $input['redemption_terms']['eligibility_rules'][0]['credit_amount'] = &$amount;
        $document = MembershipPolicyDocument::from($input);
        $original = $document->hash();
        $arguments = get_object_vars($document->binding(self::ID));
        $arguments['awardTerms'] = MembershipAwardTerms::from($input['award_terms']);
        $arguments['redemptionTerms'] = MembershipRedemptionTerms::from($input['redemption_terms']);
        $operation = 'consume';
        $arguments['supportedOperations'][4] = &$operation;
        $binding = new MembershipPolicyBinding(...$arguments);
        $text = 'changed';
        $seconds = 1;
        $amount = 99;
        $operation = 'another';
        $copy = $binding->consumptionTerms->value();
        $copy['authorization_policy']['display_text'] = 'changed copy';
        $facts = $document->facts;
        $facts['consumption_terms']['text'] = 'changed copy';
        self::assertSame($original, $document->hash());
        self::assertSame(3600, $document->awardTerms->facts['late_payment']['seconds']);
        self::assertSame(2, $document->redemptionTerms->facts['eligibility_rules'][0]['credit_amount']);
        self::assertSame(3600, $binding->awardTerms->facts['late_payment']['seconds']);
        self::assertSame(2, $binding->redemptionTerms->facts['eligibility_rules'][0]['credit_amount']);
        self::assertTrue($binding->supports('consume'));
        self::assertNotSame('changed copy', $binding->consumptionTerms->value()['authorization_policy']['display_text']);
    }

    public function test_legacy_versions_and_empty_constructor_branch_keep_their_original_contract(): void
    {
        $v1 = $this->policyInput(['terms_text' => str_repeat("\n", 65536)]);
        // JSON escaping exceeds the v2 cap but was always legal in the v1 parser.
        $v1['terms_text'][0] = 'x';
        self::assertGreaterThan(122880, strlen(CanonicalJson::encode($v1)));
        $one = MembershipPolicyDocument::from($v1);
        self::assertSame(CanonicalJson::hash($v1), $one->hash());
        self::assertFalse($one->binding(self::ID)->supports('consume'));
        self::assertNull($one->consumptionTerms);
        $v2 = $this->consumptionPolicyInput(['schema_version' => MembershipPolicyDocument::CREDIT_VERSION, 'scope' => 'paid_period_credit_lifecycle']);
        unset($v2['consumption_terms']);
        $two = MembershipPolicyDocument::from($v2);
        self::assertSame(CanonicalJson::hash($v2), $two->hash());
        self::assertNull($two->binding(self::ID)->retentionPolicyHash);
        self::assertFalse($two->binding(self::ID)->supports('consume'));
        $args = get_object_vars($one->binding(self::ID));
        $args['supportedOperations'] = [];
        foreach (['rolloverPolicyHash', 'cancellationPolicyHash', 'grandfatherPolicyHash', 'retentionPolicyHash'] as $key) {
            $args[$key] = str_repeat('d', 64);
        }
        $legacy = new MembershipPolicyBinding(...$args);
        self::assertSame([], $legacy->supportedOperations);
        self::assertSame(str_repeat('d', 64), $legacy->retentionPolicyHash);
    }

    public function test_total_v3_document_limit_is_exact_and_does_not_truncate(): void
    {
        $input = $this->consumptionPolicyInput(['terms_text' => str_repeat('x', 65000)]);
        $padding = 122880 - strlen(CanonicalJson::encode($input));
        $input['consumption_terms']['text'] .= str_repeat('x', $padding);
        self::assertSame(122880, strlen(CanonicalJson::encode($input)));
        self::assertSame(CanonicalJson::hash($input), MembershipPolicyDocument::from($input)->hash());
        $input['consumption_terms']['text'] .= 'x';
        $this->expectException(MembershipException::class);
        MembershipPolicyDocument::from($input);
    }

    #[DataProvider('invalidInputs')]
    public function test_unselected_or_malformed_consumption_terms_are_refused(string $path, mixed $value): void
    {
        $input = $this->consumptionPolicyInput();
        Arr::set($input, $path, $value);
        $this->expectException(MembershipException::class);
        MembershipPolicyDocument::from($input);
    }

    public static function invalidInputs(): iterable
    {
        yield ['schema_version', 'membership-credit-policy-v4'];
        yield ['scope', 'paid_period_credit_lifecycle'];
        yield ['consumption_terms', null];
        yield ['consumption_terms.extra', true];
        yield ['consumption_terms.schema_version', 'membership-consumption-terms-v2'];
        yield ['consumption_terms.purpose', 'free-license'];
        yield ['consumption_terms.source_rule', 'grandfather'];
        yield ['consumption_terms.reference', ''];
        yield ['consumption_terms.text', str_repeat('x', 65537)];
        yield ['consumption_terms.text', "x\0y"];
        yield ['consumption_terms.text', "\xff"];
        yield ['consumption_terms.retention_policy', 'hash-only'];
        yield ['consumption_terms.retention_policy.schema_version', 'another'];
        yield ['consumption_terms.retention_policy.reference', "x\ny"];
        yield ['consumption_terms.retention_policy.text', "x\x01y"];
        yield ['consumption_terms.retention_policy.days', 30];
        yield ['consumption_terms.authorization_policy.mode', 'implicit'];
        yield ['consumption_terms.authorization_policy.record_claim', 'legal_assent'];
        yield ['consumption_terms.authorization_policy.action', 'reserve'];
        yield ['consumption_terms.authorization_policy.required_sources', 'paid_invoice'];
        yield ['consumption_terms.authorization_policy.display_reference', str_repeat('x', 161)];
        yield ['consumption_terms.authorization_policy.display_text', false];
        yield ['consumption_terms.authorization_policy.display_text', new \stdClass];
        yield ['credit_allowance', 3.0];
    }

    public function test_every_consumption_and_authorization_member_is_required(): void
    {
        $input = $this->consumptionPolicyInput();
        foreach (['consumption_terms', 'consumption_terms.authorization_policy', 'consumption_terms.retention_policy'] as $group) {
            foreach (array_keys(Arr::get($input, $group)) as $key) {
                $missing = $input;
                Arr::forget($missing, $group.'.'.$key);
                try {
                    MembershipPolicyDocument::from($missing);
                    self::fail('Missing authored member admitted: '.$group.'.'.$key);
                } catch (MembershipException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    public function test_consumption_cannot_be_attached_to_legacy_operations_or_wrong_retention(): void
    {
        $binding = MembershipPolicyDocument::from($this->consumptionPolicyInput())->binding(self::ID);
        foreach ([['supportedOperations', []], ['supportedOperations', ['paid_period_award']],
            ['consumptionTerms', null], ['retentionPolicyHash', null], ['retentionPolicyHash', str_repeat('a', 64)],
            ['cancellationPolicyHash', str_repeat('a', 64)], ['redemptionTerms', null], ['awardTerms', null]] as [$key, $value]) {
            $args = get_object_vars($binding);
            $args[$key] = $value;
            try {
                new MembershipPolicyBinding(...$args);
                self::fail('Inconsistent consumption binding admitted: '.$key);
            } catch (MembershipException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame($binding->consumptionTerms->hash(), MembershipConsumptionTerms::from($binding->consumptionTerms->value())->hash());
    }
}
