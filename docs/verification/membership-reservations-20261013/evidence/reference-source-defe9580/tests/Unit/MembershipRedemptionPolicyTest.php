<?php

namespace Tests\Unit;

use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPolicyDocument;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipRedemptionTerms;
use App\Support\CanonicalJson;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MembershipPolicyFixtures;

class MembershipRedemptionPolicyTest extends TestCase
{
    use MembershipPolicyFixtures;

    public function test_v1_canonical_bytes_hashes_and_record_seal_are_unchanged_and_no_redemption_is_implied(): void
    {
        $document = MembershipPolicyDocument::from($this->policyInput());
        $canonical = '{"award_terms":{"expiry":{"mode":"after_period_end","seconds":86400},"late_payment":{"mode":"grace","seconds":3600}},"billing_currency":"USD","billing_price_minor":1700,"credit_allowance":3,"period_source":"verified_invoice_line_service_period","plan_name":"SYNTHETIC policy fixture","provenance":"synthetic_rehearsal","schema_version":"membership-award-policy-v1","scope":"paid_period_award","terms_reference":"synthetic-policy-20261013","terms_text":"SYNTHETIC TEST TERMS. Not a customer offer or legal text."}';
        $this->assertSame($canonical, CanonicalJson::encode($document->facts));
        $this->assertSame('6a473aff176f624108bdd1d741de307aba7c05195b0e7aa52cdd74e9af0e1aef', $document->hash());
        $this->assertSame('1e8cd3e4d59f973284f18ea8762b48619ffea1a3ea8eebd2a95709bfcfe22366', $document->termsHash());
        // This fixed synthetic ciphertext only tests unchanged keyed-seal bytes, never a valid retained record.
        $row = ['id' => '00000000-0000-4000-8000-000000000001', 'policy_hash' => $document->hash(),
            'original_terms_hash' => $document->termsHash(), 'provenance' => 'synthetic_rehearsal',
            'payload_ciphertext' => 'SYNTHETIC fixed retained ciphertext bytes', 'created_at' => '2026-10-13 00:00:00'];
        $this->assertSame('5b12aa933d15f7a8bed87e5d3720c689ecc9193d23823d482ec6bd09c2cf2f74', MembershipRecords::seal($row, 'SYNTHETIC fixed policy seal key 32+'));
        $binding = $document->binding($row['id']);
        $this->assertNull($document->redemptionTerms);
        $this->assertNull($binding->redemptionTerms);
        $this->assertNull($binding->eligibleLicensePolicyHash);
        $this->assertNull($binding->reservationHonorPolicyHash);
        $this->assertSame(['paid_period_award'], $binding->supportedOperations);
        $this->assertTrue($binding->supports('paid_period_award'));
        foreach (['reserve', 'release', 'expire', 'consume'] as $operation) {
            $this->assertFalse($binding->supports($operation));
        }
    }

    public function test_v2_exposes_only_explicit_credit_operations_and_retained_exact_cost_rules(): void
    {
        $input = $this->creditInput();
        $document = MembershipPolicyDocument::from($input);
        $binding = $document->binding('00000000-0000-4000-8000-000000000002');
        $this->assertSame($input, $document->facts);
        $this->assertSame($document->redemptionTerms, $binding->redemptionTerms);
        $this->assertSame(['paid_period_award', 'reserve', 'release', 'expire'], $binding->supportedOperations);
        $this->assertFalse($binding->supports('consume'));
        $this->assertFalse($binding->supports('cancellation'));
        $this->assertSame(CanonicalJson::hash($input['redemption_terms']['eligibility_rules']), $binding->eligibleLicensePolicyHash);
        $this->assertSame(CanonicalJson::hash($input['redemption_terms']['honor']), $binding->reservationHonorPolicyHash);
        $rule = $document->redemptionTerms->rule('synthetic-wav');
        $this->assertSame(2, $rule['credit_amount']);
        $this->assertSame($rule, $document->redemptionTerms->ruleFor(1, 2, 3, 4, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64)));
        $this->assertNull($binding->rolloverPolicyHash);
        $this->assertNull($binding->cancellationPolicyHash);
        $this->assertNull($binding->grandfatherPolicyHash);
        $this->assertNull($binding->retentionPolicyHash);
        $rule['credit_amount'] = 999;
        $this->assertSame(2, $document->redemptionTerms->rule('synthetic-wav')['credit_amount']);
    }

    public static function invalidDocuments(): array
    {
        return [
            ['schema_version', 'membership-credit-policy-v3'], ['scope', 'paid_period_award'],
            ['redemption_terms', null], ['redemption_terms.eligibility_rules', []],
            ['redemption_terms.eligibility_rules', ['named' => []]], ['redemption_terms.eligibility_rules.0', false],
            ['redemption_terms.eligibility_rules.0.rule_id', ''], ['redemption_terms.eligibility_rules.0.rule_id', 'UPPER'],
            ['redemption_terms.eligibility_rules.0.rule_id', str_repeat('a', 65)], ['redemption_terms.eligibility_rules.0.rule_id', 'has space'],
            ['redemption_terms.eligibility_rules.0.track_id', '1'], ['redemption_terms.eligibility_rules.0.track_id', 0],
            ['redemption_terms.eligibility_rules.0.offer_id', 1.0], ['redemption_terms.eligibility_rules.0.offer_id', 2147483648],
            ['redemption_terms.eligibility_rules.0.offer_revision_id', -1], ['redemption_terms.eligibility_rules.0.license_version_id', true],
            ['redemption_terms.eligibility_rules.0.license_manifest_hash', str_repeat('A', 64)],
            ['redemption_terms.eligibility_rules.0.asset_manifest_hash', str_repeat('b', 63)],
            ['redemption_terms.eligibility_rules.0.original_terms_hash', null],
            ['redemption_terms.eligibility_rules.0.credit_amount', 0], ['redemption_terms.eligibility_rules.0.credit_amount', 1000001],
            ['redemption_terms.eligibility_rules.0.credit_amount', '2'], ['redemption_terms.eligibility_rules.0.extra', true],
            ['redemption_terms.honor', null], ['redemption_terms.honor.duration_seconds', 0],
            ['redemption_terms.honor.duration_seconds', '3600'], ['redemption_terms.honor.duration_seconds', 315360001],
            ['redemption_terms.honor.expiry_mode', 'default'], ['redemption_terms.honor.extra', true],
            ['redemption_terms.release', []], ['redemption_terms.release.before_credit_expiry', 'consumed'],
            ['redemption_terms.release.at_or_after_credit_expiry', 'available'],
            ['redemption_terms.available_expiry.mode', 'expire_reserved'], ['redemption_terms.consume', true],
            ['supported_operations', ['consume']],
        ];
    }

    #[DataProvider('invalidDocuments')]
    public function test_v2_refuses_missing_typed_facts_unknown_rules_and_unsupported_operations(string $path, mixed $value): void
    {
        $input = $this->creditInput();
        Arr::set($input, $path, $value);
        $this->expectException(MembershipException::class);
        MembershipPolicyDocument::from($input);
    }

    public function test_each_redemption_term_and_each_rule_member_is_required_without_a_default(): void
    {
        $input = $this->creditInput();
        $paths = ['redemption_terms'];
        foreach (array_keys($input['redemption_terms']) as $key) {
            $paths[] = 'redemption_terms.'.$key;
        }
        foreach (array_keys($input['redemption_terms']['eligibility_rules'][0]) as $key) {
            $paths[] = 'redemption_terms.eligibility_rules.0.'.$key;
        }
        foreach (['honor', 'release', 'available_expiry'] as $group) {
            foreach (array_keys($input['redemption_terms'][$group]) as $key) {
                $paths[] = 'redemption_terms.'.$group.'.'.$key;
            }
        }
        foreach ($paths as $path) {
            $missing = $input;
            Arr::forget($missing, $path);
            try {
                MembershipPolicyDocument::from($missing);
                $this->fail('Missing explicit field admitted: '.$path);
            } catch (MembershipException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_v1_cannot_be_augmented_with_redemption_terms_and_an_unknown_version_is_not_inferred(): void
    {
        foreach ([['redemption_terms' => $this->terms()], ['schema_version' => 'membership-credit-policy-v2']] as $override) {
            try {
                MembershipPolicyDocument::from($this->policyInput($override));
                $this->fail('An old policy cannot gain implied redemption authority.');
            } catch (MembershipException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_duplicate_rule_ids_or_duplicate_exact_revision_targets_are_refused(): void
    {
        foreach (['duplicate_id', 'duplicate_target'] as $kind) {
            $facts = $this->terms();
            $other = $facts['eligibility_rules'][0];
            $other[$kind === 'duplicate_id' ? 'track_id' : 'rule_id'] = $kind === 'duplicate_id' ? 99 : 'other-synthetic-rule';
            $other['credit_amount'] = 7;
            $facts['eligibility_rules'][] = $other;
            try {
                MembershipRedemptionTerms::from($facts);
                $this->fail('Conflicting rule must not be selected by array order.');
            } catch (MembershipException $error) {
                $this->assertSame('ambiguous_eligibility_rule', $error->reason);
            }
        }
    }

    public function test_changed_cost_hash_or_honor_is_a_new_document_and_authored_rule_order_is_preserved(): void
    {
        $original = MembershipPolicyDocument::from($this->creditInput());
        foreach (['redemption_terms.eligibility_rules.0.credit_amount' => 3,
            'redemption_terms.eligibility_rules.0.original_terms_hash' => str_repeat('d', 64),
            'redemption_terms.honor.duration_seconds' => 7200,
            'redemption_terms.honor.expiry_mode' => 'allow_beyond_credit_expiry'] as $path => $value) {
            $changed = $this->creditInput();
            Arr::set($changed, $path, $value);
            $this->assertNotSame($original->hash(), MembershipPolicyDocument::from($changed)->hash());
        }
        $facts = $this->terms();
        $other = [...$facts['eligibility_rules'][0], 'rule_id' => 'another-synthetic-rule', 'offer_revision_id' => 9];
        $facts['eligibility_rules'][] = $other;
        $this->assertSame($facts, MembershipRedemptionTerms::from($facts)->facts);
        $reverse = $facts;
        $reverse['eligibility_rules'] = array_reverse($reverse['eligibility_rules']);
        $this->assertNotSame(MembershipRedemptionTerms::from($facts)->eligibilityHash(), MembershipRedemptionTerms::from($reverse)->eligibilityHash());
    }

    public static function changedSelectionFields(): array
    {
        return [[0, 99], [1, 99], [2, 99], [3, 99], [4, str_repeat('d', 64)], [5, str_repeat('e', 64)], [6, str_repeat('f', 64)]];
    }

    #[DataProvider('changedSelectionFields')]
    public function test_every_current_identity_and_manifest_must_match_the_retained_rule(int $index, int|string $replacement): void
    {
        $selection = [1, 2, 3, 4, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64)];
        $selection[$index] = $replacement;
        try {
            MembershipRedemptionTerms::from($this->terms())->ruleFor(...$selection);
            $this->fail('Changed current selection was admitted.');
        } catch (MembershipException $error) {
            $this->assertSame('ineligible_license', $error->reason);
        }
    }

    public function test_unknown_rule_does_not_fall_back_to_another_cost(): void
    {
        $this->expectException(MembershipException::class);
        MembershipRedemptionTerms::from($this->terms())->rule('missing');
    }

    public function test_explicit_honor_modes_and_release_destinations_observe_exact_expiry_boundaries(): void
    {
        $at = '2026-10-13 00:00:00';
        $expiry = '2026-10-13 00:30:00';
        $cap = MembershipRedemptionTerms::from($this->terms());
        $beyond = MembershipRedemptionTerms::from([...$this->terms(), 'honor' => ['duration_seconds' => 3600, 'expiry_mode' => 'allow_beyond_credit_expiry']]);
        $this->assertSame($expiry, $cap->honorDeadline($at, $expiry));
        $this->assertSame('2026-10-13 01:00:00', $beyond->honorDeadline($at, $expiry));
        foreach ([$cap, $beyond] as $terms) {
            $this->assertSame('2026-10-13 01:00:00', $terms->honorDeadline($at, null));
            $this->assertSame('2026-10-13 01:00:00', $terms->honorDeadline($at, '2026-10-13 02:00:00'));
            $this->assertSame('available', $terms->releaseDestination('2026-10-13 00:29:59', $expiry));
            $this->assertSame('expired', $terms->releaseDestination($expiry, $expiry));
            $this->assertSame('expired', $terms->releaseDestination('2026-10-13 00:30:01', $expiry));
            $this->assertSame('available', $terms->releaseDestination('9999-12-31 23:59:59', null));
            foreach ([$expiry, '2026-10-13 00:30:01'] as $late) {
                try {
                    $terms->honorDeadline($late, $expiry);
                    $this->fail('Beyond-expiry honor does not create new credits.');
                } catch (MembershipException $error) {
                    $this->assertSame('credit_expired', $error->reason);
                }
            }
        }
    }

    public function test_date_overflow_and_noncanonical_utc_values_refuse(): void
    {
        $terms = MembershipRedemptionTerms::from($this->terms());
        foreach ([['9999-12-31 23:59:59', null], ['2026-02-30 00:00:00', null],
            ['2026-10-13T00:00:00Z', null], ['2026-10-13 00:00:00', 'not-a-date']] as [$at, $expiry]) {
            try {
                $terms->honorDeadline($at, $expiry);
                $this->fail('Unrepresentable or noncanonical deadline admitted.');
            } catch (MembershipException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rule_count_and_retained_document_size_are_bounded(): void
    {
        $facts = $this->terms();
        $rule = $facts['eligibility_rules'][0];
        $facts['eligibility_rules'] = [];
        for ($i = 1; $i <= 256; $i++) {
            $facts['eligibility_rules'][] = [...$rule, 'rule_id' => 'synthetic-'.$i, 'offer_revision_id' => $i];
        }
        $this->assertCount(256, MembershipRedemptionTerms::from($facts)->facts['eligibility_rules']);
        $tooMany = $facts;
        $tooMany['eligibility_rules'][] = [...$rule, 'rule_id' => 'synthetic-257', 'offer_revision_id' => 257];
        foreach ([['redemption_terms' => $tooMany], ['redemption_terms' => $facts, 'terms_text' => str_repeat('S', 65536)]] as $override) {
            try {
                MembershipPolicyDocument::from([...$this->creditInput(), ...$override]);
                $this->fail('Oversized retained policy admitted.');
            } catch (MembershipException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function creditInput(): array
    {
        return $this->policyInput(['schema_version' => MembershipPolicyDocument::CREDIT_VERSION,
            'scope' => 'paid_period_credit_lifecycle', 'redemption_terms' => $this->terms()]);
    }

    private function terms(): array
    {
        return ['eligibility_rules' => [['rule_id' => 'synthetic-wav', 'track_id' => 1, 'offer_id' => 2,
            'offer_revision_id' => 3, 'license_version_id' => 4, 'license_manifest_hash' => str_repeat('a', 64),
            'asset_manifest_hash' => str_repeat('b', 64), 'original_terms_hash' => str_repeat('c', 64), 'credit_amount' => 2]],
            'honor' => ['duration_seconds' => 3600, 'expiry_mode' => 'cap_at_credit_expiry'],
            'release' => ['before_credit_expiry' => 'available', 'at_or_after_credit_expiry' => 'expired'],
            'available_expiry' => ['mode' => 'expire_available_at_credit_expiry']];
    }
}
