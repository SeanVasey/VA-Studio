<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Codex P2 (3): every failed attempt appends a `claimed` and a `failed` work row, so the allowance of 32 attempts
 * counts claims, and the work ordinals run 0 to 63.
 */
final class ProductionFreeGrantAttemptLimitTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    public function test_thirty_one_failed_attempts_still_allow_a_thirty_second_claim(): void
    {
        $this->freeSetup();
        [$owner, $origin] = $this->origin();
        $documents = new ProductionFreeGrantDocuments;
        $this->failingRenderer();
        for ($attempt = 1; $attempt <= 31; $attempt++) {
            $this->refuses(fn () => $documents->render($origin['id']), 'render_failed', 'attempt '.$attempt);
        }
        $this->assertSame(62, DB::table('production_free_document_work')->count());

        $this->app->offsetUnset(ProductionFreeGrantRendererProcess::class);
        $this->app->forgetInstance(ProductionFreeGrantRendererProcess::class);
        $this->assertSame('complete', $documents->render($origin['id'])['documentStatus']);
        $this->assertSame(63, DB::table('production_free_document_work')->count());
        $shown = (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user']);
        $this->assertSame(32, $shown['renderAttempts']);
    }

    public function test_the_thirty_third_attempt_is_refused_and_all_sixty_four_work_rows_are_readable(): void
    {
        $this->freeSetup();
        [$owner, $origin] = $this->origin();
        $documents = new ProductionFreeGrantDocuments;
        $this->failingRenderer();
        for ($attempt = 1; $attempt <= 32; $attempt++) {
            $this->refuses(fn () => $documents->render($origin['id']), 'render_failed', 'attempt '.$attempt);
        }
        $this->assertSame(64, DB::table('production_free_document_work')->count());
        $this->assertSame(63, (int) DB::table('production_free_document_work')->max('ordinal'));

        $this->refuses(fn () => $documents->render($origin['id']), 'attempts_exhausted');
        $this->assertSame(64, DB::table('production_free_document_work')->count());
        $shown = (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user']);
        $this->assertSame(32, $shown['renderAttempts']);
        $this->assertSame('failed', $shown['documentStatus']);
    }

    private function origin(): array
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);

        return [$owner, $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user'])];
    }

    private function failingRenderer(): void
    {
        $this->app->bind(ProductionFreeGrantRendererProcess::class, fn () => new ProductionFreeGrantRendererProcess(
            function (array $command, string $cwd, array $environment, string $payload): Process {
                throw new \RuntimeException('synthetic renderer outage');
            }));
    }

    private function refuses(callable $operation, string $reason, string $message = ''): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.$reason.' '.$message);
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason, $message);
        }
    }
}
