<?php

namespace Tests\Unit;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Grants\Member\MemberOriginalRenderInput;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MemberOriginalRendererFixtures;

final class MemberOriginalRenderInputTest extends TestCase
{
    public function test_pure_value_preserves_exact_terms_and_canonical_hash_without_authority(): void
    {
        $document = MemberOriginalRendererFixtures::document();
        $input = MemberOriginalRenderInput::from($document);
        $this->assertSame($document, $input->value());
        $this->assertSame(CanonicalJson::hash($document), $input->hash());
        $this->assertSame($input->hash(), MemberOriginalRenderInput::from(array_reverse($document, true))->hash());
        $copy = $input->value();
        $copy['original_terms']['authored_source'] = 'Different';
        $this->assertSame($document, $input->value());
    }

    public function test_caller_held_nested_references_cannot_change_validated_values_or_returned_projections(): void
    {
        $document = MemberOriginalRendererFixtures::document();
        $text = $document['original_terms']['authored_source'];
        $buyer = $document['buyer_account_id'];
        $features = $document['original_terms']['structured_terms']['features'];
        $document['original_terms']['authored_source'] = &$text;
        $document['buyer_account_id'] = &$buyer;
        $document['original_terms']['structured_terms']['features'] = &$features;
        $expectedHash = CanonicalJson::hash($document);
        $input = MemberOriginalRenderInput::from($document);
        $text = 'Changed after validation';
        $buyer = -1;
        $features[] = 'Changed feature';
        $this->assertSame($expectedHash, $input->hash());
        $this->assertSame(1, $input->value()['buyer_account_id']);
        $copy = $input->value();
        $copy['original_terms']['structured_terms']['features'][0] = 'Changed returned projection';
        $this->assertSame($expectedHash, $input->hash());
    }

    public static function invalid(): array
    {
        return [
            'unknown key' => ['extra', 'value'],
            'family' => ['family', 'production-free-origin-v1'],
            'purpose' => ['purpose', 'membership-first-period-award'],
            'uuid v5' => ['origin_id', '00000000-0000-5000-8000-000000000001'],
            'source hash' => ['render_source_hash', str_repeat('A', 64)],
            'buyer string' => ['buyer_account_id', '1'],
            'buyer negative' => ['buyer_account_id', -1],
            'date invalid' => ['reservation_created_at', '2026-02-30 00:00:00'],
            'date inferred format' => ['reservation_created_at', '2026-10-13T00:00:00Z'],
            'provenance' => ['provenance', 'production'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_wrong_or_extra_input_is_refused(string $key, mixed $value): void
    {
        $document = MemberOriginalRendererFixtures::document();
        $document[$key] = $value;
        $this->expectException(ContractIssuanceException::class);
        MemberOriginalRenderInput::from($document);
    }

    public function test_selection_cannot_substitute_different_original_terms(): void
    {
        $document = MemberOriginalRendererFixtures::document();
        $document['original_terms']['authored_source'] .= ' Changed';
        $this->expectException(ContractIssuanceException::class);
        MemberOriginalRenderInput::from($document);
    }

    public function test_unbounded_depth_is_refused_before_canonical_encoding(): void
    {
        $terms = MemberOriginalRendererFixtures::terms();
        $nested = ['text' => 'synthetic'];
        for ($i = 0; $i < 26; $i++) {
            $nested = ['child' => $nested];
        }
        $terms['structured_terms'] = $nested;
        $this->expectException(ContractIssuanceException::class);
        MemberOriginalRenderInput::from(MemberOriginalRendererFixtures::document($terms));
    }

    public function test_oversized_authored_source_is_refused_without_truncation(): void
    {
        $terms = MemberOriginalRendererFixtures::terms();
        $terms['authored_source'] = str_repeat('x', 65537);
        $this->expectException(ContractIssuanceException::class);
        MemberOriginalRenderInput::from(MemberOriginalRendererFixtures::document($terms));
    }
}
