<?php

namespace Tests\Unit;

use App\Domain\Catalog\FamilyRevisionSelection;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteRequest;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FamilyRevisionSelectionTest extends TestCase
{
    private const ID = '11111111-2222-3333-4444-555555555555';

    public function test_only_the_exact_supported_revision_reference_is_typed_without_coercion(): void
    {
        $input = ['family' => 'wav_sample_kit', 'revisionId' => self::ID, 'manifestHash' => str_repeat('a', 64)];
        $selection = FamilyRevisionSelection::fromArray(array_reverse($input, true));
        $this->assertSame([$input['family'], self::ID, $input['manifestHash']], [$selection->family, $selection->revisionId, $selection->manifestHash]);
    }

    public static function invalidSelections(): array
    {
        $valid = ['family' => 'wav_sample_kit', 'revisionId' => self::ID, 'manifestHash' => str_repeat('a', 64)];

        return [
            'unsupported track' => [array_replace($valid, ['family' => 'track'])],
            'unsupported collection' => [array_replace($valid, ['family' => 'collection'])],
            'missing hash' => [array_diff_key($valid, ['manifestHash' => true])],
            'malformed revision' => [array_replace($valid, ['revisionId' => '../private/archive'])],
            'integer revision' => [array_replace($valid, ['revisionId' => 1])],
            'coercion callback' => [array_replace($valid, ['revisionId' => new class implements \Stringable
            {
                public function __toString(): string
                {
                    throw new \LogicException('Must not coerce a revision.');
                }
            }])],
            'noncanonical hash' => [array_replace($valid, ['manifestHash' => str_repeat('A', 64)])],
            'short hash' => [array_replace($valid, ['manifestHash' => 'a'])],
            'client price' => [$valid + ['price_minor' => 0]],
            'client assets' => [$valid + ['assets' => []]],
            'client license' => [$valid + ['licenseVersionId' => 1]],
        ];
    }

    #[DataProvider('invalidSelections')]
    public function test_unsupported_or_client_commercial_facts_are_refused(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        FamilyRevisionSelection::fromArray($input);
    }

    public function test_existing_track_request_shape_is_unchanged_and_cannot_accept_family_references(): void
    {
        $this->assertSame([['trackId' => 1, 'offerId' => 2, 'licenseVersionId' => 3, 'offerRevisionId' => 4]],
            QuoteRequest::items([['trackId' => '1', 'offerId' => '2', 'licenseVersionId' => '3', 'offerRevisionId' => '4']]));
        $this->expectException(QuoteException::class);
        QuoteRequest::items([['family' => 'wav_sample_kit', 'revisionId' => self::ID, 'manifestHash' => str_repeat('a', 64)]]);
    }
}
