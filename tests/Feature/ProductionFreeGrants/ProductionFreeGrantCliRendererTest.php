<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantFiles;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Support\PhpCliBinary;
use Closure;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\Support\PhpCliWrapperFixture;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** M-16: outside the CLI, the pinned production free renderer's child runs through the validated CLI binary. */
final class ProductionFreeGrantCliRendererTest extends TestCase
{
    use PhpCliWrapperFixture;
    use ProductionFreeGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
    }

    public function test_the_cli_builds_the_pinned_renderer_without_a_process_factory(): void
    {
        $this->assertNull($this->factoryOf(app(ProductionFreeGrantRendererProcess::class)));
        $this->simulateFpm(PHP_BINARY);
        $this->assertInstanceOf(Closure::class, $this->factoryOf(app(ProductionFreeGrantRendererProcess::class)));
    }

    public function test_outside_the_cli_the_original_renders_through_the_configured_cli_byte_identically(): void
    {
        $origin = $this->origin();
        $wrapper = $this->cliWrapper();
        $this->simulateFpm($wrapper);
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $original = (array) DB::table('production_free_originals')->sole();
        $bytes = file_get_contents($this->privateRoot.'/'.ProductionFreeGrantFiles::path($origin['id'], $original['claim_id']));
        $this->assertStringStartsWith('%PDF-1.', $bytes);
        $this->assertSame($original['sha256'], hash('sha256', $bytes));
        // The CLI process's own render of the stored origin reproduces the same bytes.
        $this->app->forgetInstance(PhpCliBinary::class);
        $this->assertTrue((new ProductionFreeGrantDocuments)->recover($origin['id'])['identical']);

        [$probe, $render] = $this->wrapperRuns($wrapper);
        $this->assertSame([$wrapper, '-n', '-r'], array_slice($probe, 0, 3));
        $this->assertSame([$wrapper, '-n'], array_slice($render, 0, 2));
        $this->assertSame(base_path('scripts/render-production-free-grant.php'), end($render));
        $this->assertCount(2, $this->wrapperRuns($wrapper));
    }

    public function test_outside_the_cli_without_a_configured_cli_the_render_fails_closed(): void
    {
        $origin = $this->origin();
        $this->simulateFpm(null);
        try {
            (new ProductionFreeGrantDocuments)->render($origin['id']);
            $this->fail('An unconfigured CLI binary cannot render.');
        } catch (ProductionFreeGrantException) {
        }
        $this->assertDatabaseCount('production_free_originals', 0);
    }

    private function origin(): array
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);

        return $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
    }

    private function factoryOf(ProductionFreeGrantRendererProcess $renderer): ?Closure
    {
        return (new ReflectionProperty($renderer, 'processFactory'))->getValue($renderer);
    }
}
