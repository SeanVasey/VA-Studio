<?php

namespace Tests\Unit\ProductionMemberOriginals;

use App\Domain\Commerce\ProductionPreparation\PreparationSelection;
use App\Domain\Grants\Member\MemberGrantDefinitionDocument;
use App\Domain\Grants\Member\MemberGrantException;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\MemberGrantDocumentFixtures as Fixture;

final class MemberGrantDocumentTimestampTest extends TestCase
{
    public function test_actual_catalog_timestamp_spelling_is_retained_without_changing_hashes(): void
    {
        // Exercise the actual pure catalog projection, without claiming a published catalog fixture.
        $iso = new ReflectionMethod(PreparationSelection::class, 'iso');
        $definition = Fixture::definition();
        $definition['license_manifest']['approved_at'] = $iso->invoke(null, '2026-10-13 01:02:03.123456');
        $definition['license_manifest']['effective_from'] = $iso->invoke(null, '2026-10-13 01:02:03');
        $definition['license_manifest']['effective_until'] = $iso->invoke(null, '2026-10-13 01:02:03.000001');
        self::assertSame('2026-10-13T01:02:03.123456Z', $definition['license_manifest']['approved_at']);
        self::assertSame('2026-10-13T01:02:03.000000Z', $definition['license_manifest']['effective_from']);
        try {
            $retained = MemberGrantDefinitionDocument::from($definition);
        } catch (MemberGrantException $e) {
            self::fail('Valid catalog timestamp spelling was refused: '.$e->reason);
        }
        self::assertSame(CanonicalJson::encode($definition), CanonicalJson::encode($retained->value()));
        self::assertSame(CanonicalJson::hash($definition), $retained->hash());
        self::assertSame(CanonicalJson::hash($definition['license_manifest']), CanonicalJson::hash($retained->value()['license_manifest']));
    }

    #[DataProvider('orderedBounds')]
    public function test_mixed_precision_bounds_compare_instants_and_preserve_spelling(?string $from, ?string $until): void
    {
        $definition = Fixture::definition();
        $definition['license_manifest']['effective_from'] = $from;
        $definition['license_manifest']['effective_until'] = $until;
        $retained = MemberGrantDefinitionDocument::from($definition)->value()['license_manifest'];
        self::assertSame($from, $retained['effective_from']);
        self::assertSame($until, $retained['effective_until']);
    }

    public static function orderedBounds(): iterable
    {
        yield ['2026-10-13T01:02:03Z', '2026-10-13T01:02:03.000001Z'];
        yield ['2026-10-13T01:02:03.999999Z', '2026-10-13T01:02:04Z'];
        yield [null, '2026-10-13T01:02:03.000001Z'];
        yield ['2026-10-13T01:02:03.000001Z', null];
    }

    #[DataProvider('unorderedBounds')]
    public function test_equal_or_reversed_effective_instants_are_refused(string $from, string $until): void
    {
        $definition = Fixture::definition();
        $definition['license_manifest']['effective_from'] = $from;
        $definition['license_manifest']['effective_until'] = $until;
        $this->expectException(MemberGrantException::class);
        MemberGrantDefinitionDocument::from($definition);
    }

    public static function unorderedBounds(): iterable
    {
        yield ['2026-10-13T01:02:03Z', '2026-10-13T01:02:03.000000Z'];
        yield ['2026-10-13T01:02:03.000000Z', '2026-10-13T01:02:03Z'];
        yield ['2026-10-13T01:02:03.000001Z', '2026-10-13T01:02:03Z'];
        yield ['2026-10-13T01:02:03.000001Z', '2026-10-13T01:02:03.000000Z'];
    }

    #[DataProvider('malformedTimestamps')]
    public function test_malformed_catalog_timestamp_is_refused(mixed $timestamp): void
    {
        $definition = Fixture::definition();
        $definition['license_manifest']['approved_at'] = $timestamp;
        $this->expectException(MemberGrantException::class);
        MemberGrantDefinitionDocument::from($definition);
    }

    public static function malformedTimestamps(): iterable
    {
        yield [null];
        yield ['2026-10-13T01:02:03.1Z'];
        yield ['2026-10-13T01:02:03.1234567Z'];
        yield ['2026-10-13T01:02:03.000000+00:00'];
        yield ['2026-10-13T01:02:03.000000z'];
        yield ['2026-10-13 01:02:03.000000Z'];
        yield ['2026-02-30T01:02:03.000000Z'];
        yield ['2026-02-30T01:02:03Z'];
        yield ['2026-10-13T24:02:03.000000Z'];
        yield ['2026-10-13T01:02:60.000000Z'];
        yield ["2026-10-13T01:02:03.000000Z\n"];
        yield ["2026-10-13T01:02:03.000000Z\0"];
    }
}
