<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * A4-1: terms of ~32,768 short lines fit the 65,536-byte allowance but exceed the pinned renderer's 100-page limit, so a
 * definition could be approved and assented but never render. Propose and open now run the real pinned renderer on a
 * worst-case preflight input (the longest declared name) and refuse `unrenderable_definition` before anything is written.
 * Only text short enough to fit even at one character per line (2,000 characters) skips the render; A6-1 showed a
 * bytes-per-line estimate admitted long unbreakable tokens that the renderer then could never lay out.
 */
final class ProductionFreeGrantPreflightTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private int $renders = 0;

    private array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->countRenders();
    }

    public function test_terms_that_cannot_fit_the_renderer_limits_are_refused_at_propose_with_nothing_written(): void
    {
        $definitions = new ProductionFreeGrantDefinitions;
        $terms = str_repeat("a\n", 32767).'a';
        $this->assertSame(65535, strlen($terms));

        $this->refuses(fn () => $definitions->propose($this->definitionInput(['termsText' => $terms]), $this->staff()));

        $this->assertSame(0, DB::table('production_free_definitions')->count());
        $this->assertSame(1, $this->renders);
    }

    public function test_ordinary_terms_skip_the_render_and_large_valid_terms_pass_the_worst_case_render(): void
    {
        $definitions = new ProductionFreeGrantDefinitions;
        $this->assertNotEmpty($definitions->propose($this->definitionInput(), $this->staff())['id']);
        $this->assertSame(0, $this->renders, 'Ordinary terms cannot approach the limits and cost no render.');

        $large = str_repeat("a\n", 2999).'a';
        $proposed = $definitions->propose($this->definitionInput(['termsText' => $large]), $this->staff());
        $this->assertNotEmpty($proposed['id']);
        $this->assertSame(1, $this->renders);
        $buyer = $this->inputs[0]['input']['buyer']['declared_name'];
        $this->assertSame(120, mb_strlen($buyer), 'The preflight uses the longest allowed declared name.');
    }

    public function test_open_re_checks_the_render_and_refuses_without_writing(): void
    {
        $definitions = new ProductionFreeGrantDefinitions;
        $author = $this->staff();
        $reviewer = $this->staff();
        $large = str_repeat("a\n", 2999).'a';
        config(['production-free-grants.approved_terms_hashes' => [hash('sha256', $large)]]);
        $proposed = $definitions->propose($this->definitionInput(['termsText' => $large]), $author);
        $definitions->approve($proposed['id'], ['definitionHash' => $proposed['definitionHash']], $reviewer);
        $input = ['definitionHash' => $proposed['definitionHash'], 'expectedOrdinal' => 0];

        $this->app->bind(ProductionFreeGrantRendererProcess::class, fn () => new ProductionFreeGrantRendererProcess(
            function (array $command, string $cwd, array $environment, string $payload): Process {
                throw new \RuntimeException('synthetic renderer outage');
            }));
        $this->refuses(fn () => $definitions->open($proposed['id'], $input, $reviewer));
        $this->assertSame(0, DB::table('production_free_availability')->count());

        $this->countRenders();
        $this->assertTrue($definitions->open($proposed['id'], $input, $reviewer)['open']);
        $this->assertSame(1, $this->renders);
    }

    /**
     * tc-lib-pdf 8.81.1 (BL-55): this input was refused under 8.76.3, whose page count grew non-linearly with long
     * unbreakable tokens (250 tokens of 40 characters already passed the 100-page limit). The updated renderer lays it out
     * linearly: every token on its own line inside the column, 6 pages against the limit of 100. The retired estimate still
     * would have skipped the render, so the render is counted once and propose() accepts the text. Refusal of text that
     * truly exceeds the limits stays pinned by the short-lines test above.
     */
    public function test_long_unbreakable_tokens_under_the_old_estimate_are_rendered_within_the_column_and_accepted(): void
    {
        $definitions = new ProductionFreeGrantDefinitions;
        $terms = implode(' ', array_fill(0, 300, str_repeat('@', 51)));
        $this->assertLessThan(1500, substr_count($terms, "\n") + 1 + intdiv(strlen($terms), 60), 'The retired estimate skipped this text.');

        $this->assertNotEmpty($definitions->propose($this->definitionInput(['termsText' => $terms]), $this->staff())['id']);
        $this->assertSame(1, $this->renders);
        $this->assertSame(1, DB::table('production_free_definitions')->count());

        $payload = $this->inputs[0];
        $rendered = app(ProductionFreeGrantRendererProcess::class)->render($payload['input'], $payload['profile']);
        $this->assertLessThanOrEqual(10, $rendered->pageCount, 'Linear layout: well under the profile limit of 100 pages.');

        $path = tempnam(sys_get_temp_dir(), 'pfg');
        try {
            file_put_contents($path, $rendered->pdfBytes);
            $extract = new Process(['pdftotext', '-enc', 'UTF-8', '-bbox', $path, '-'], timeout: 30);
            $extract->mustRun();
            $boxes = $extract->getOutput();
        } finally {
            @unlink($path);
        }
        preg_match('/<page width="([\d.]+)"/', $boxes, $page);
        preg_match_all('/xMin="([\d.]+)"[^>]*xMax="([\d.]+)"[^>]*>(@+)</', $boxes, $words, PREG_SET_ORDER);
        $this->assertCount(300, $words, 'Every token is on the page, each as one word.');
        $this->assertSame(300 * 51, array_sum(array_map(fn (array $word): int => strlen($word[3]), $words)), 'No character is lost.');
        $margin = 42.52;
        foreach ($words as $word) {
            $this->assertGreaterThanOrEqual($margin - 0.01, (float) $word[1]);
            $this->assertLessThanOrEqual((float) $page[1] - $margin + 0.01, (float) $word[2], 'No glyph passes the right margin.');
        }
    }

    public function test_the_skip_bound_counts_every_character_of_the_rendered_definition_text(): void
    {
        $definitions = new ProductionFreeGrantDefinitions;
        $input = $this->definitionInput();
        $fixed = mb_strlen($input['title']."\n".$input['termsReference']."\n\n".$input['assentText']);

        // Words, not one token: a token wider than the column is refused by the fit check (BL-72) before any render.
        $text = static fn (int $length): string => mb_substr(mb_substr(str_repeat('ééé ', intdiv($length, 4) + 1), 0, $length), 0, $length - 1).'é';

        $definitions->propose($this->definitionInput(['termsText' => $text(2000 - $fixed)]), $this->staff());
        $this->assertSame(0, $this->renders, 'Exactly 2,000 characters cannot reach the page limit and skip the render.');

        $definitions->propose($this->definitionInput(['termsText' => $text(2001 - $fixed)]), $this->staff());
        $this->assertSame(1, $this->renders, 'One character more is rendered.');
    }

    private function countRenders(): void
    {
        $this->renders = 0;
        $this->inputs = [];
        $this->app->bind(ProductionFreeGrantRendererProcess::class, fn () => new ProductionFreeGrantRendererProcess(
            function (array $command, string $cwd, array $environment, string $payload): Process {
                $this->renders++;
                $this->inputs[] = json_decode($payload, true);

                return new Process($command, $cwd, $environment, $payload, 60);
            }));
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Must refuse unrenderable_definition');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('unrenderable_definition', $error->reason);
        }
    }
}
