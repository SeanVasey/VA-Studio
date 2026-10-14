<?php

namespace Tests\Unit\ProductionMemberOriginals;

use App\Domain\Grants\Member\MemberGrantDefinitionDocument;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantProfileDocument;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MemberGrantDocumentFixtures as Fixture;

final class MemberGrantDocumentsTest extends TestCase
{
    public function test_hashes_bind_every_authored_document_and_preserve_original_bytes(): void
    {
        $profile = Fixture::profile();
        $profile['original_terms']['authored_source'] .= "\n  Deliberate original spacing.  \n";
        $definition = Fixture::definition($profile);
        $p = MemberGrantProfileDocument::from($profile);
        $d = MemberGrantDefinitionDocument::from($definition);
        self::assertSame(CanonicalJson::encode($profile), CanonicalJson::encode($p->value()));
        self::assertSame(CanonicalJson::hash($profile), $p->hash());
        self::assertSame(CanonicalJson::hash($definition), $d->hash());
        self::assertNotSame(CanonicalJson::hash($profile['render_profile']), $p->hash());
        self::assertNotSame(CanonicalJson::hash($profile['original_terms']), $p->hash());
        $definition['retention_policy']['text'] .= ' Explicit revision.';
        $definition['storage_policy']['retention_policy_hash'] = CanonicalJson::hash($definition['retention_policy']);
        self::assertNotSame($d->hash(), MemberGrantDefinitionDocument::from($definition)->hash());
    }

    public function test_authored_source_at_the_released_renderer_limit_is_retained_exactly(): void
    {
        $profile = Fixture::profile();
        $profile['original_terms']['authored_source'] = str_repeat('x', 65536);
        $retained = MemberGrantProfileDocument::from($profile);
        self::assertSame($profile['original_terms']['authored_source'], $retained->value()['original_terms']['authored_source']);
    }

    public function test_nested_input_references_and_getter_results_cannot_change_retained_values(): void
    {
        $profile = Fixture::profile();
        $text = $profile['original_terms']['authored_source'];
        $profile['original_terms']['authored_source'] = &$text;
        $p = MemberGrantProfileDocument::from($profile);
        $pValue = $p->value();
        $pHash = $p->hash();
        $definition = Fixture::definition($pValue);
        $retention = $definition['retention_policy']['text'];
        $definition['retention_policy']['text'] = &$retention;
        $d = MemberGrantDefinitionDocument::from($definition);
        $dValue = $d->value();
        $dHash = $d->hash();
        $text = 'Mutated caller reference';
        $retention = 'Mutated caller retention';
        $copy = $d->value();
        $copy['license_manifest']['authored_source'] = 'Mutated getter result';
        self::assertSame($pValue, $p->value());
        self::assertSame($pHash, $p->hash());
        self::assertSame($dValue, $d->value());
        self::assertSame($dHash, $d->hash());
    }

    #[DataProvider('invalidDocuments')]
    public function test_unknown_or_conflicting_retained_values_are_refused(string $kind, array $path, mixed $value): void
    {
        $document = $kind === 'profile' ? Fixture::profile() : Fixture::definition();
        $cursor = &$document;
        foreach ($path as $key) {
            $cursor = &$cursor[$key];
        }
        $cursor = $value;
        $this->expectException(MemberGrantException::class);
        $kind === 'profile' ? MemberGrantProfileDocument::from($document) : MemberGrantDefinitionDocument::from($document);
    }

    public static function invalidDocuments(): iterable
    {
        yield 'source exceeds member renderer capacity' => ['profile', ['original_terms', 'authored_source'], str_repeat('x', 65537)];
        yield 'profile extra' => ['profile', ['unknown'], true];
        yield 'technical descriptor changed' => ['profile', ['render_profile', 'template'], 'another'];
        yield 'terms extra' => ['profile', ['original_terms', 'path'], '/private'];
        yield 'terms float' => ['profile', ['original_terms', 'structured_terms', 'schema_version'], 1.0];
        yield 'terms duplicate role' => ['profile', ['original_terms', 'structured_terms', 'required_asset_roles'], ['master_wav', 'master_wav']];
        yield 'definition extra' => ['definition', ['unknown'], true];
        yield 'wrong family' => ['definition', ['family'], 'free-grant'];
        yield 'wrong version type' => ['definition', ['version'], '1'];
        yield 'bad policy digest' => ['definition', ['policy_hash'], str_repeat('A', 64)];
        yield 'bad plan uuid' => ['definition', ['plan_version_id'], '1'];
        yield 'source hash mismatch' => ['definition', ['license_manifest', 'authored_source'], 'Changed authored source'];
        yield 'asset unknown column' => ['definition', ['asset_manifest', 0, 'storage_path'], '/private'];
        yield 'asset role mismatch' => ['definition', ['asset_manifest', 0, 'role'], 'download_mp3'];
        yield 'asset float size' => ['definition', ['asset_manifest', 0, 'size_bytes'], 100.0];
        yield 'retention unknown' => ['definition', ['retention_policy', 'days'], 30];
        yield 'retention absent' => ['definition', ['retention_policy', 'text'], ''];
        yield 'retention too large' => ['definition', ['retention_policy', 'text'], str_repeat('x', 65537)];
        yield 'storage wrong retention' => ['definition', ['storage_policy', 'retention_policy_hash'], str_repeat('0', 64)];
        yield 'storage private path' => ['definition', ['storage_policy', 'root'], '/private'];
        yield 'storage provenance mismatch' => ['definition', ['storage_policy', 'provenance'], 'verified_production'];
        yield 'object refused' => ['definition', ['retention_policy', 'text'], new \stdClass];
        yield 'invalid UTF8' => ['definition', ['retention_policy', 'text'], "\xff"];
    }
}
