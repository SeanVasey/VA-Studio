<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantFiles;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderInput;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantText;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** Deterministic isolated rendering, write-once originals, leased append-only claims and hash recovery. */
final class ProductionFreeGrantRenderingTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private ProductionFreeGrantDocuments $documents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->documents = new ProductionFreeGrantDocuments;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_render_is_write_once_idempotent_and_recovers_byte_identically(): void
    {
        $origin = $this->origin();
        $first = $this->documents->render($origin['id']);
        $this->assertSame($first, $this->documents->render($origin['id']));
        $this->assertSame(['claimed'], DB::table('production_free_document_work')->pluck('kind')->all());
        $original = (array) DB::table('production_free_originals')->first();
        $path = $this->privateRoot.'/'.ProductionFreeGrantFiles::path($origin['id'], $original['claim_id']);
        $this->assertSame(0400, fileperms($path) & 0777);
        $bytes = file_get_contents($path);
        $this->assertStringStartsWith('%PDF-1.', $bytes);
        $payload = json_decode(Crypt::decryptString(DB::table('production_free_origins')->value('payload_ciphertext')), true);
        $document = (new ProductionFreeGrantText)->build(ProductionFreeGrantRenderInput::fromOrigin($payload));
        $this->assertStringContainsString('SYNTHETIC REHEARSAL FREE GRANT - LOCAL/TESTING ONLY, NOT AN OPERATIVE LICENSE', $document['text']);
        $this->assertStringContainsString(self::SYNTHETIC_TERMS, $document['text']);
        $this->assertSame($document['text_digest'], $original['text_digest']);
        $this->assertSame($original['sha256'], hash('sha256', $bytes));
        $this->assertTrue($this->documents->recover($origin['id'])['identical']);
        $this->assertSame($bytes, file_get_contents($path));
    }

    public function test_tampered_stored_original_is_refused_not_repaired(): void
    {
        $origin = $this->origin();
        $this->documents->render($origin['id']);
        $original = (array) DB::table('production_free_originals')->first();
        $path = $this->privateRoot.'/'.ProductionFreeGrantFiles::path($origin['id'], $original['claim_id']);
        chmod($path, 0600);
        file_put_contents($path, "\n", FILE_APPEND);
        chmod($path, 0400);
        $tampered = file_get_contents($path);
        $this->refuses(fn () => $this->documents->recover($origin['id']), 'original_unavailable');
        $this->assertSame($tampered, file_get_contents($path));
        $this->assertSame(1, DB::table('production_free_originals')->count());
    }

    public function test_failed_render_appends_its_own_failure_and_a_retry_claims_anew(): void
    {
        $origin = $this->origin();
        $this->renderer(function (): void {
            throw new \RuntimeException('synthetic renderer outage');
        });
        $this->refuses(fn () => $this->documents->render($origin['id']), 'render_failed');
        $this->assertSame(['claimed', 'failed'], $this->work());
        $this->app->forgetInstance(ProductionFreeGrantRendererProcess::class);
        $this->app->offsetUnset(ProductionFreeGrantRendererProcess::class);
        $this->assertSame('complete', $this->documents->render($origin['id'])['documentStatus']);
        $this->assertSame(['claimed', 'failed', 'claimed'], $this->work());
        $claims = DB::table('production_free_document_work')->orderBy('ordinal')->pluck('claim_id')->all();
        $this->assertSame($claims[0], $claims[1]);
        $this->assertNotSame($claims[0], $claims[2]);
        $this->assertSame($claims[2], DB::table('production_free_originals')->value('claim_id'));
    }

    public function test_an_active_claim_is_never_reset_or_shared(): void
    {
        $origin = $this->origin();
        $inner = null;
        $this->renderer(function () use ($origin, &$inner): void {
            try {
                (new ProductionFreeGrantDocuments)->render($origin['id']);
            } catch (ProductionFreeGrantException $error) {
                $inner = $error->reason;
            }
        });
        $this->assertSame('complete', $this->documents->render($origin['id'])['documentStatus']);
        $this->assertSame('claim_in_progress', $inner);
        $this->assertSame(['claimed'], $this->work());
    }

    public function test_an_expired_lease_cannot_publish_and_its_stored_bytes_stay_untouched(): void
    {
        $origin = $this->origin();
        $this->renderer(function (): void {
            CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(301));
        });
        $this->refuses(fn () => $this->documents->render($origin['id']), 'lease_expired');
        // The uncertain claim cannot append a failure after its lease; it is superseded, never rewritten.
        $this->assertSame(['claimed'], $this->work());
        $stale = DB::table('production_free_document_work')->value('claim_id');
        $stalePath = $this->privateRoot.'/'.ProductionFreeGrantFiles::path($origin['id'], $stale);
        $staleBytes = file_get_contents($stalePath);
        $this->app->forgetInstance(ProductionFreeGrantRendererProcess::class);
        $this->app->offsetUnset(ProductionFreeGrantRendererProcess::class);
        $this->assertSame('complete', $this->documents->render($origin['id'])['documentStatus']);
        $this->assertSame(['claimed', 'claimed'], $this->work());
        $this->assertSame($staleBytes, file_get_contents($stalePath));
        $this->assertNotSame($stale, DB::table('production_free_originals')->value('claim_id'));
        // Deterministic profile: the superseded claim rendered the same bytes the published original holds.
        $this->assertSame(hash('sha256', $staleBytes), DB::table('production_free_originals')->value('sha256'));
    }

    public function test_a_preexisting_file_at_the_claim_path_is_never_overwritten(): void
    {
        $origin = $this->origin();
        $this->renderer(function () use ($origin): void {
            $claim = DB::table('production_free_document_work')->value('claim_id');
            $directory = $this->privateRoot.'/'.dirname(ProductionFreeGrantFiles::path($origin['id'], $claim));
            foreach (['contracts', 'contracts/production-free-v1', 'contracts/production-free-v1/'.$origin['id'], 'contracts/production-free-v1/'.$origin['id'].'/'.$claim] as $part) {
                @mkdir($this->privateRoot.'/'.$part, 0700);
            }
            file_put_contents($directory.'/original.pdf', 'planted');
        });
        $this->refuses(fn () => $this->documents->render($origin['id']), 'storage_failed');
        $claim = DB::table('production_free_document_work')->value('claim_id');
        $this->assertSame('planted', file_get_contents($this->privateRoot.'/'.ProductionFreeGrantFiles::path($origin['id'], $claim)));
        $this->assertSame(['claimed', 'failed'], $this->work());
        $this->assertSame(0, DB::table('production_free_originals')->count());
    }

    public function test_revoked_origin_is_not_rendered_and_disabled_policy_refuses_workers(): void
    {
        $origin = $this->origin();
        config(['production-free-grants.enabled' => false]);
        $this->refuses(fn () => $this->documents->render($origin['id']), 'disabled');
        config(['production-free-grants.enabled' => true]);
        $seal = DB::table('production_free_origins')->value('seal');
        (new ProductionFreeGrants)->revoke($origin['id'], ['originSeal' => $seal, 'reason' => 'Synthetic rehearsal revocation.'], $this->staff());
        $this->refuses(fn () => $this->documents->render($origin['id']), 'revoked');
        $this->assertSame(0, DB::table('production_free_document_work')->count());
    }

    public function test_library_reports_document_status_through_the_claim_chain(): void
    {
        $origin = $this->origin($owner);
        $library = new ProductionFreeGrantLibrary;
        $this->assertSame('pending', $library->show($origin['id'], $owner['principal'], $owner['user'])['documentStatus']);
        $this->renderer(function (): void {
            throw new \RuntimeException('synthetic renderer outage');
        });
        $this->refuses(fn () => $this->documents->render($origin['id']), 'render_failed');
        $this->assertSame('failed', $library->show($origin['id'], $owner['principal'], $owner['user'])['documentStatus']);
    }

    private function origin(?array &$owner = null): array
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);

        return $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
    }

    /** The hook runs in the parent before the real isolated child process starts. */
    private function renderer(Closure $hook): void
    {
        $this->app->bind(ProductionFreeGrantRendererProcess::class, fn () => new ProductionFreeGrantRendererProcess(
            function (array $command, string $cwd, array $environment, string $payload) use ($hook): Process {
                $hook();

                return new Process($command, $cwd, $environment, $payload, 60);
            }));
    }

    private function work(): array
    {
        return DB::table('production_free_document_work')->orderBy('ordinal')->pluck('kind')->all();
    }

    private function refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.$reason);
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
