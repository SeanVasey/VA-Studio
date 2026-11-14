<?php

namespace Tests\Unit\ProductionMemberOriginals;

use App\Domain\Grants\Member\MemberGrantDocumentValues;
use App\Domain\Grants\Member\MemberGrantException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\TieredLicenseFixtures;

final class MemberGrantTieredTermsTest extends TestCase
{
    public function test_retained_v4_and_v5_vocabularies_are_each_admitted_only_with_their_own_usage_keys(): void
    {
        MemberGrantDocumentValues::terms(EconomicLicenseFixtures::terms());
        MemberGrantDocumentValues::terms(TieredLicenseFixtures::terms());
        $mixed = [
            array_replace(EconomicLicenseFixtures::terms(), ['usage' => TieredLicenseFixtures::terms()['usage']]),
            array_replace(TieredLicenseFixtures::terms(), ['usage' => EconomicLicenseFixtures::terms()['usage']]),
        ];
        foreach ($mixed as $terms) {
            try {
                MemberGrantDocumentValues::terms($terms);
                self::fail('A retained license mixed the v4 and v5 usage vocabularies.');
            } catch (MemberGrantException $exception) {
                self::assertSame('invalid_document', $exception->reason);
            }
        }
    }

    #[DataProvider('invalidTiered')]
    public function test_damaged_retained_v5_terms_are_refused(array $path, mixed $value, bool $unset = false): void
    {
        $terms = TieredLicenseFixtures::terms();
        $cursor = &$terms;
        foreach (array_slice($path, 0, -1) as $key) {
            $cursor = &$cursor[$key];
        }
        $last = $path[count($path) - 1];
        if ($unset) {
            unset($cursor[$last]);
        } else {
            $cursor[$last] = $value;
        }
        $this->expectException(MemberGrantException::class);
        MemberGrantDocumentValues::terms($terms);
    }

    public static function invalidTiered(): iterable
    {
        yield 'schema 6' => [['schema_version'], 6];
        yield 'schema string' => [['schema_version'], '5'];
        yield 'missing tier slot' => [['usage', 'free_downloads'], null, true];
        yield 'retired combined slot' => [['usage', 'live_performances'], ['mode' => 'unlimited']];
        yield 'unknown mode' => [['usage', 'for_profit_performances', 'mode'], 'some'];
        yield 'limited without limit' => [['usage', 'audio_streams'], ['mode' => 'limited']];
        yield 'float limit' => [['usage', 'monetized_video_streams', 'limit'], 50000.0];
        yield 'missing policies' => [['policies'], null, true];
        yield 'dangling policy' => [['ownership', 'source_recording', 'policy_key'], 'missing'];
        yield 'missing territory' => [['territory'], null, true];
    }
}
