<?php

namespace Tests\Unit;

use App\Domain\Contracts\ContractText;
use App\Domain\Contracts\ContractIssuanceException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ContractRendererFixtures;

class ContractTextTest extends TestCase
{
    public function test_every_frozen_field_and_full_multiline_terms_are_retained_as_escaped_text(): void
    {
        $input = ContractRendererFixtures::input();
        $terms = "Opening terms\n<script>alert('private')</script><img src=\"file:///etc/passwd\">\nFinal terms";
        $input['disclosure']['termsText'] = $terms;
        $input['selection']['original_successor_invariant'] = 'ORIGINAL REVISION';
        $render = (new ContractText)->build($input);
        $this->assertStringContainsString($terms, $render['text']);
        $this->assertStringContainsString('ORIGINAL REVISION', $render['text']);
        $this->assertStringContainsString('Zoë Émile', $render['text']);
        $this->assertStringContainsString('&lt;script&gt;', $render['html']);
        $this->assertStringNotContainsString('<img ', $render['html']);
        $this->assertStringNotContainsString('<script>', $render['html']);
        $this->assertSame(hash('sha256', $render['text']), $render['text_digest']);
        $this->assertSame($render, (new ContractText)->build(array_reverse($input, true)));
    }

    public static function unsupported(): array
    {
        return [["abc\0def"], ["ab\u{202e}cd"], ["e\u{0301}"], ['עברית'], ['العربية'], ['漢字'], ["\xff"]];
    }

    #[DataProvider('unsupported')]
    public function test_unsupported_or_invalid_text_fails_closed(string $value): void
    {
        $input = ContractRendererFixtures::input();
        $input['buyer']['legal_name'] = $value;
        $this->expectException(ContractIssuanceException::class);
        (new ContractText)->build($input);
    }

    public function test_missing_terms_are_never_replaced_with_current_terms_or_a_stub(): void
    {
        $input = ContractRendererFixtures::input();
        unset($input['disclosure']['termsText']);
        $this->expectException(RuntimeException::class);
        (new ContractText)->build($input);
    }

    public function test_oversize_frozen_input_fails_before_pdf_construction(): void
    {
        $input = ContractRendererFixtures::input();
        $input['disclosure']['termsText'] = str_repeat('X', 1048576);
        $this->expectException(RuntimeException::class);
        (new ContractText)->build($input);
    }
}
