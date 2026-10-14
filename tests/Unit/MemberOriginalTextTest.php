<?php

namespace Tests\Unit;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Grants\Member\MemberOriginalRenderInput;
use App\Domain\Grants\Member\MemberOriginalText;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MemberOriginalRendererFixtures;

final class MemberOriginalTextTest extends TestCase
{
    public function test_exact_multiline_terms_are_escaped_and_every_source_field_is_present(): void
    {
        $terms = MemberOriginalRendererFixtures::terms();
        $terms['authored_source'] = "Synthetic first line\r\n<script>literal</script><img src=\"file:///private/source\">\nSynthetic last line\tEND";
        $input = MemberOriginalRenderInput::from(MemberOriginalRendererFixtures::document($terms));
        $built = (new MemberOriginalText)->build($input);
        $this->assertStringContainsString($terms['authored_source'], $built['text']);
        $this->assertStringContainsString('selection.asset_manifest_hash', $built['text']);
        $this->assertStringContainsString('original_terms.structured_terms.required_asset_roles.0', $built['text']);
        $this->assertStringContainsString('NOT AN OPERATIVE LICENSE', $built['text']);
        $this->assertStringContainsString('not an effective or assent date', $built['text']);
        $this->assertStringContainsString('&lt;script&gt;', $built['html']);
        $this->assertStringNotContainsString('<script>', $built['html']);
        $this->assertStringNotContainsString('<img ', $built['html']);
        $this->assertSame(hash('sha256', $built['text']), $built['text_digest']);
        $this->assertSame($input->hash(), $built['input_hash']);
        $this->assertSame(1791849600, $built['timestamp']);
        $this->assertSame($built, (new MemberOriginalText)->build(MemberOriginalRenderInput::from(array_reverse($input->value(), true))));
    }

    public static function unsupported(): array
    {
        return [['abc'."\0".'def'], ["ab\u{202e}cd"], ["e\u{0301}"], ['עברית'], ['العربية'], ['漢字'], ["\xff"]];
    }

    #[DataProvider('unsupported')]
    public function test_unrepresentable_text_fails_without_a_substitute(string $text): void
    {
        $document = MemberOriginalRendererFixtures::document();
        $document['original_terms']['authored_source'] = $text;
        if (mb_check_encoding($text, 'UTF-8')) {
            $document['selection']['original_terms_hash'] = CanonicalJson::hash($document['original_terms']);
        }
        $this->expectException(ContractIssuanceException::class);
        (new MemberOriginalText)->build(MemberOriginalRenderInput::from($document));
    }

    public function test_supported_greek_cyrillic_and_latin_remain_exact(): void
    {
        $built = (new MemberOriginalText)->build(MemberOriginalRendererFixtures::input());
        $this->assertStringContainsString('Ελληνικά Кириллица', $built['text']);
    }
}
