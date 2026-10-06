<?php

namespace Tests\Unit;

use App\Domain\Merch\MerchDraftManifest;
use App\Domain\Services\ServiceDraftManifest;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PrivateProductDraftFixtures as Fixtures;
use Tests\TestCase;

class PrivateProductDraftManifestTest extends TestCase
{
    public static function invalidInputs(): array
    {
        $cases = [];
        foreach (['service', 'merch'] as $kind) {
            foreach (['extra charge' => ['price_minor' => 0], 'stock' => ['stock' => 1], 'publication' => ['published' => true],
                'missing title' => ['title' => ''], 'surrounding whitespace' => ['title' => ' unsafe '],
                'invalid UTF8' => ['title' => "\xC0\xAF"], 'control' => ['description' => "private\0text"],
                'overlong title' => ['title' => str_repeat('a', 181)], 'nontext description' => ['description' => 1]] as $label => $changes) {
                $cases[$kind.' / '.$label] = [$kind, Fixtures::payload($kind, $changes)];
            }
            $field = $kind === 'service' ? 'scope' : 'shipping';
            foreach (['unresolved without reason' => ['status' => 'unresolved', 'text' => null, 'reason' => ''],
                'unresolved with invented value' => ['status' => 'unresolved', 'text' => 'zero', 'reason' => 'Unknown'],
                'authored without text' => ['status' => 'authored', 'text' => '', 'reason' => null],
                'authored with second reason' => ['status' => 'authored', 'text' => 'Synthetic supplied text', 'reason' => 'Unknown'],
                'approved state' => ['status' => 'approved', 'text' => 'Synthetic', 'reason' => null],
                'extra operational flag' => ['status' => 'authored', 'text' => 'Synthetic', 'reason' => null, 'active' => true]] as $label => $declaration) {
                $cases[$kind.' / '.$label] = [$kind, Fixtures::payload($kind, [$field => $declaration])];
            }
        }
        foreach (['duplicate questions' => ['Question', 'Question'], 'associative questions' => ['first' => 'Question'],
            'too many questions' => array_map(fn ($n): string => 'Question '.$n, range(1, 21)), 'nontext question' => [false]] as $label => $questions) {
            $cases['service / '.$label] = ['service', Fixtures::payload('service', ['brief_questions' => $questions])];
        }
        foreach (['no variants' => [], 'duplicate identities' => [Fixtures::variant('same'), Fixtures::variant('same')],
            'invalid stable identity' => [array_replace(Fixtures::variant('valid'), ['id' => 'UPPERCASE'])],
            'operational availability' => [array_replace(Fixtures::variant('valid'), ['availability' => ['status' => 'in_stock', 'text' => null, 'reason' => null]])],
            'extra stock count' => [Fixtures::variant('valid') + ['stock' => 0]],
            'too many variants' => array_map(fn ($n): array => Fixtures::variant('variant-'.$n), range(1, 51)),
            'empty source reference' => [array_replace(Fixtures::variant('valid'), ['source_reference' => ''])],
            'associative variants' => ['key' => Fixtures::variant('valid')]] as $label => $variants) {
            $cases['merch / '.$label] = ['merch', Fixtures::payload('merch', ['variants' => $variants])];
        }

        return $cases;
    }

    #[DataProvider('invalidInputs')]
    public function test_malformed_or_operational_fields_never_become_private_definition_evidence(string $kind, array $input): void
    {
        $this->expectException(ValidationException::class);
        ($kind === 'service' ? new ServiceDraftManifest : new MerchDraftManifest)->make($input);
    }

    public function test_actual_order_supplied_declarations_and_unknowns_round_trip_without_prices_or_approvals(): void
    {
        $service = (new ServiceDraftManifest)->make(Fixtures::payload('service', [
            'deposit' => ['status' => 'authored', 'text' => 'NONBINDING synthetic deposit wording for authoring tests.', 'reason' => null],
            'brief_questions' => ['Second intended question', 'First intended question'],
        ]));
        $this->assertSame(['Second intended question', 'First intended question'], $service['brief_questions']);
        $this->assertSame('authored', $service['deposit']['status']);
        $this->assertNull($service['scope']['text']);
        $this->assertSame($service, (new ServiceDraftManifest)->verified($service));
        $merch = (new MerchDraftManifest)->make(Fixtures::payload('merch', ['variants' => [Fixtures::variant('large'), Fixtures::variant('small')]]));
        $this->assertSame(['large', 'small'], array_column($merch['variants'], 'id'));
        $this->assertSame('unresolved', $merch['variants'][0]['availability']['status']);
        $this->assertNull($merch['variants'][0]['availability']['text']);
        $this->assertSame($merch, (new MerchDraftManifest)->verified($merch));
        foreach ([$service, $merch] as $manifest) {
            $this->assertArrayNotHasKey('price_minor', $manifest);
            $this->assertArrayNotHasKey('currency', $manifest);
            $this->assertArrayNotHasKey('active', $manifest);
        }
    }

    public function test_total_payload_bound_is_enforced_even_when_each_variant_field_is_individually_valid(): void
    {
        $variants = [];
        foreach (range(1, 50) as $number) {
            $variant = Fixtures::variant('variant-'.$number);
            $variant['availability'] = ['status' => 'authored', 'text' => str_repeat('x', 2000), 'reason' => null];
            $variants[] = $variant;
        }
        $this->expectException(ValidationException::class);
        (new MerchDraftManifest)->make(Fixtures::payload('merch', ['variants' => $variants]));
    }
}
