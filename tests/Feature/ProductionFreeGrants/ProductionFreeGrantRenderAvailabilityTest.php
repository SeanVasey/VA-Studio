<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Codex P2: availability only gates new assent. A staff close (or reopen) while an accepted origin is being rendered
 * outside the transaction must not supersede the render: the original publishes and no attempt is consumed. A
 * revocation during the render still blocks publication.
 */
final class ProductionFreeGrantRenderAvailabilityTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    public function test_closing_the_definition_mid_render_still_publishes_the_original(): void
    {
        $this->freeSetup();
        $staff = $this->staff();
        $definition = $this->openDefinition(null, $staff);
        $origin = $this->origin($definition['id']);
        $this->duringRender(function () use ($definition, $staff): void {
            (new ProductionFreeGrantDefinitions)->close($definition['id'], ['definitionHash' => $definition['definitionHash'], 'expectedOrdinal' => 1], $staff);
        });

        $rendered = (new ProductionFreeGrantDocuments)->render($origin['id']);

        $this->assertSame('complete', $rendered['documentStatus']);
        $this->assertSame(1, $rendered['renderAttempts']);
        $this->assertSame(1, DB::table('production_free_originals')->count());
        $this->assertSame(['claimed'], DB::table('production_free_document_work')->orderBy('ordinal')->pluck('kind')->all());
        $this->assertFalse((new ProductionFreeGrantDefinitions)->read($definition['id'], $staff)['open']);
    }

    public function test_revoking_the_origin_mid_render_still_blocks_publication(): void
    {
        $this->freeSetup();
        $definition = $this->openDefinition();
        $origin = $this->origin($definition['id']);
        $seal = DB::table('production_free_origins')->where('id', $origin['id'])->value('seal');
        $staff = $this->staff();
        $this->duringRender(function () use ($origin, $seal, $staff): void {
            (new ProductionFreeGrants)->revoke($origin['id'], ['originSeal' => $seal, 'reason' => 'Synthetic revocation during render.'], $staff);
        });

        try {
            (new ProductionFreeGrantDocuments)->render($origin['id']);
            $this->fail('A revocation during the render must block publication');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('claim_superseded', $error->reason);
        }
        $this->assertSame(0, DB::table('production_free_originals')->count());
    }

    private function origin(string $definitionId): array
    {
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definitionId, 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);

        return $grants->accept($definitionId, $this->assentInput($review), $owner['principal'], $owner['user']);
    }

    /** Runs $during inside the renderer's process factory (outside any transaction), then renders for real. */
    private function duringRender(Closure $during): void
    {
        $this->app->bind(ProductionFreeGrantRendererProcess::class, fn () => new ProductionFreeGrantRendererProcess(
            function (array $command, string $cwd, array $environment, string $payload) use ($during): Process {
                $during();

                return new Process($command, $cwd, $environment, $payload, 60);
            }));
    }
}
