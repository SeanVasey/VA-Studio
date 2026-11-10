<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Contracts\RenderFit\PdfTextGlyphs;
use App\Domain\Contracts\RenderFit\RenderFitException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderable;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderInput;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderProfile;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantText;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * BL-72: the renderer's HTML cell breaks a token wider than its column but does not paginate the lines that makes, so a
 * long enough token was drawn below the page: the PDF held the glyphs and no reader showed them (65,000 `@` printed about
 * 2,700 of them over two pages), and the preflight, which only checked page count and size, admitted the text. The preflight
 * now always renders text holding such a token and checks the finished PDF against the text it came from.
 */
final class ProductionFreeGrantDrawnTextTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private int $renders = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->renders = 0;
        $this->app->bind(ProductionFreeGrantRendererProcess::class, fn () => new ProductionFreeGrantRendererProcess(
            function (array $command, string $cwd, array $environment, string $payload): Process {
                $this->renders++;

                return new Process($command, $cwd, $environment, $payload, 120);
            }));
    }

    private function refuses(string $terms): void
    {
        try {
            (new ProductionFreeGrantDefinitions)->propose($this->definitionInput(['termsText' => $terms]), $this->staff());
            $this->fail('Must refuse unrenderable_definition');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('unrenderable_definition', $error->reason);
        }
        $this->assertSame(0, DB::table('production_free_definitions')->count());
    }

    private function accepts(string $terms): void
    {
        $this->assertNotEmpty((new ProductionFreeGrantDefinitions)->propose($this->definitionInput(['termsText' => $terms]), $this->staff())['id']);
    }

    public function test_one_65000_character_token_is_refused_at_propose_with_nothing_written(): void
    {
        $this->refuses(str_repeat('@', 65000));
        $this->assertSame(1, $this->renders);
    }

    public function test_wide_unicode_glyphs_in_a_huge_token_are_refused_too(): void
    {
        $this->refuses(str_repeat("\u{2014}W\u{00D8}", 7000));
    }

    public function test_a_token_that_wraps_inside_the_page_is_rendered_checked_and_accepted_below_the_old_skip_bound(): void
    {
        $this->accepts(str_repeat('@', 200));
        $this->assertSame(1, $this->renders, 'A token wider than the column is rendered although the text is under 2,000 characters.');
    }

    public function test_many_medium_overwide_tokens_are_accepted_when_the_pages_hold_them(): void
    {
        $this->accepts(implode(' ', array_fill(0, 300, str_repeat('@', 60))));
        $this->assertSame(1, $this->renders);
    }

    public function test_the_exact_fit_boundary_a_token_of_the_column_width_needs_no_render(): void
    {
        // 51 `@` are 179.92 mm in the 180 mm column; 52 are 183.4 mm.
        $this->accepts(implode(' ', array_fill(0, 20, str_repeat('@', 51))));
        $this->assertSame(0, $this->renders);
        $this->accepts(implode(' ', array_fill(0, 20, str_repeat('@', 52))));
        $this->assertSame(1, $this->renders);
    }

    public function test_the_drawn_text_check_refuses_a_pdf_that_lost_text_or_drew_it_off_the_page(): void
    {
        $profile = ProductionFreeGrantRenderProfile::current('synthetic_rehearsal');
        $terms = "Plain terms.\nSecond line.";
        $origin = ['provenance' => 'synthetic_rehearsal', 'origin_id' => '00000000-0000-4000-8000-000000000000', 'accepted_at' => '2000-01-01T00:00:00Z',
            'declared_name' => 'Buyer Name', 'definition_id' => '00000000-0000-4000-8000-000000000001', 'definition_hash' => str_repeat('a', 64),
            'review_id' => '00000000-0000-4000-8000-000000000002', 'display_hash' => str_repeat('b', 64), 'buyer_binding' => ['account_public_id' => 'probe'],
            'definition' => ['title' => 'Title', 'terms_reference' => 'Reference', 'terms_text' => $terms, 'terms_hash' => hash('sha256', $terms), 'assent_text' => 'Assent',
                'assets' => [['role' => 'master_wav', 'source_id' => 'probe', 'sha256' => str_repeat('c', 64), 'bytes' => 1, 'mime_type' => 'audio/wav', 'filename' => 'production-free-master_wav.wav']]]];
        $input = ProductionFreeGrantRenderInput::fromOrigin($origin);
        $pdf = app(ProductionFreeGrantRendererProcess::class)->render($input, $profile)->pdfBytes;
        $text = (new ProductionFreeGrantText)->build($input)['text'];

        ProductionFreeGrantRenderable::requireDrawn($pdf, $text);

        $first = PdfTextGlyphs::runs($pdf)[0];
        $td = sprintf('%.6F %.6F Td', $first['x'], $first['y']);
        $this->assertSame(1, substr_count($pdf, $td));
        $this->drawnRefusal(str_replace($td, sprintf('%.6F %.6F Td', $first['x'], -30), $pdf), $text, 'below the page');
        $this->drawnRefusal(str_replace($td, sprintf('%.6F %.6F Td', 500.0, $first['y']), $pdf), $text, 'past the column');
        $this->drawnRefusal($pdf, $text."\nan extra line of terms the PDF never drew", 'not drawn');
        $this->drawnRefusal($pdf, str_replace('Plain', 'Plane', $text), 'not the next character');
    }

    private function drawnRefusal(string $pdf, string $text, string $containing): void
    {
        try {
            ProductionFreeGrantRenderable::requireDrawn($pdf, $text);
            $this->fail('Expected the drawn-text check to refuse.');
        } catch (RenderFitException $exception) {
            $this->assertStringContainsString($containing, $exception->getMessage());
        }
    }
}
