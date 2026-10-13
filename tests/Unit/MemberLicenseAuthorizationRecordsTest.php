<?php

namespace Tests\Unit;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberLicenseAuthorizationRecords;
use App\Domain\Grants\Member\MemberLicenseConfirmationInput;
use App\Domain\Grants\Member\MemberLicenseReviewDisplay;
use App\Domain\Grants\Member\MemberOriginalSourceValues;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MemberLicenseAuthorizationValues;

final class MemberLicenseAuthorizationRecordsTest extends TestCase
{
    public function test_exact_display_and_authorization_reconstruct_from_retained_values(): void
    {
        $f = MemberLicenseAuthorizationValues::fixture();
        $a = $this->record($f);
        $display = MemberLicenseAuthorizationRecords::display($f['source'], $f['policy']);
        $this->assertSame($a, MemberLicenseAuthorizationRecords::verifyRetained($a, $f['source'], $f['policy']));
        $this->assertSame(CanonicalJson::hash($display), $a['display_hash']);
        $this->assertSame($f['source']->document()['profile']['document']['original_terms'], $display['original_terms']);
        $this->assertSame('owner_requested_activation', $a['record_claim']);
        $this->assertSame(['display_hash' => $a['display_hash'], 'affirmed' => true],
            MemberLicenseConfirmationInput::from(['display_hash' => $a['display_hash'], 'affirmed' => true])->value());
    }

    public function test_independent_confirmations_share_intent_without_replacing_author_evidence(): void
    {
        $f = MemberLicenseAuthorizationValues::fixture();
        $a = $this->record($f);
        $other = MemberLicenseAuthorizationRecords::create($f['source'], $f['policy'], $f['actor'],
            '00000000-0000-4000-8000-000000000011', '2026-10-13 00:02:00');
        $this->assertSame($a['intent_hash'], $other['intent_hash']);
        $this->assertSame($a['display_hash'], $other['display_hash']);
        $this->assertNotSame(CanonicalJson::hash($a), CanonicalJson::hash($other));
        $actor = $f['actor'];
        $actor['verification_observation_id'] = '00000000-0000-4000-8000-000000000012';
        $actor['verification_observation_hash'] = hash('sha256', 'different synthetic current observation');
        $fresh = MemberLicenseAuthorizationRecords::create($f['source'], $f['policy'], $actor, $f['authorization_id'], $f['confirmed_at']);
        $this->assertSame($a['intent_hash'], $fresh['intent_hash']);
        $this->assertSame($actor, $fresh['actor_binding']);
    }

    public function test_nested_references_and_returned_values_cannot_mutate_retained_display(): void
    {
        $f = MemberLicenseAuthorizationValues::fixture();
        $value = MemberLicenseAuthorizationRecords::display($f['source'], $f['policy']);
        $text = $value['authorization_policy']['display_text'];
        $value['authorization_policy']['display_text'] = &$text;
        $display = MemberLicenseReviewDisplay::from($value);
        $before = $display->hash();
        $text = 'Changed through a caller reference';
        $copy = $display->value();
        $copy['original_terms']['structured_terms']['features'][0] = 'Changed through getter';
        $this->assertSame($before, $display->hash());
        $this->assertNotSame($value, $display->value());
        $this->assertNotSame($copy, $display->value());
        $this->assertSame(CanonicalJson::hash($display->value()), $before);
    }

    #[DataProvider('invalidConfirmation')]
    public function test_confirmation_requires_exact_hash_and_literal_boolean(array $input): void
    {
        $this->expectException(MemberGrantException::class);
        MemberLicenseConfirmationInput::from($input);
    }

    public static function invalidConfirmation(): array
    {
        $inputs = [['affirmed' => true], ['display_hash' => str_repeat('a', 64)],
            ['display_hash' => str_repeat('A', 64), 'affirmed' => true],
            ...array_map(static fn ($value): array => ['display_hash' => str_repeat('a', 64), 'affirmed' => $value], [false, 1, 0, 'true', '1', null]),
            ['display_hash' => str_repeat('a', 64), 'affirmed' => true, 'extra' => 1]];

        return array_map(static fn (array $input): array => [$input], $inputs);
    }

    #[DataProvider('changedAuthorization')]
    public function test_every_changed_authorization_field_is_refused(string $key): void
    {
        $f = MemberLicenseAuthorizationValues::fixture();
        $a = $this->record($f);
        $a[$key] = match ($key) {
            'affirmed' => false,
            'buyer_binding', 'actor_binding' => [...$a[$key], 'user_id' => 2],
            'confirmed_at' => '2026-10-13 00:30:00',
            'authorization_id' => 'not-a-uuid',
            default => 'altered',
        };
        $this->expectException(\Throwable::class);
        MemberLicenseAuthorizationRecords::verifyRetained($a, $f['source'], $f['policy']);
    }

    public static function changedAuthorization(): array
    {
        return array_map(static fn (string $key): array => [$key], ['schema_version', 'family', 'purpose', 'provenance',
            'authorization_id', 'action', 'record_claim', 'affirmed', 'display_hash', 'intent_hash', 'policy_hash',
            'consumption_terms_hash', 'authorization_policy_hash', 'definition_id', 'definition_hash', 'profile_hash',
            'redemption_id', 'redemption_seal', 'render_source_hash', 'original_terms_hash', 'buyer_binding', 'actor_binding', 'confirmed_at']);
    }

    public function test_changed_source_and_unknown_authorization_keys_are_refused(): void
    {
        $f = MemberLicenseAuthorizationValues::fixture();
        $a = $this->record($f);
        $changed = $f['source']->document();
        $changed['redemption']['seal'] = hash('sha256', 'different synthetic redemption');
        try {
            MemberLicenseAuthorizationRecords::verifyRetained($a, MemberOriginalSourceValues::from($changed), $f['policy']);
            $this->fail('Changed source accepted.');
        } catch (MemberGrantException $error) {
            $this->assertSame('invalid_authorization', $error->reason);
        }
        $this->expectException(MemberGrantException::class);
        MemberLicenseAuthorizationRecords::verifyRetained($a + ['extra' => 1], $f['source'], $f['policy']);
    }

    private function record(array $f): array
    {
        return MemberLicenseAuthorizationRecords::create($f['source'], $f['policy'], $f['actor'], $f['authorization_id'], $f['confirmed_at']);
    }
}
