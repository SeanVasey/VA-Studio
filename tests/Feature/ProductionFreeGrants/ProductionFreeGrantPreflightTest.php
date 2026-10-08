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
 * Terms too small to approach the limits skip the render.
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
