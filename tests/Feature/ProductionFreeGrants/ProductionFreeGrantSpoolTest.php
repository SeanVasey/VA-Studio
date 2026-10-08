<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantFiles;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSpool;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantTransfer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * F-2: delivery snapshots are bounded by held flock slots, a free-space reserve, a read-only reopen and a
 * read-back hash taken from the spool. Probes and the post-write hook are overridden the way the main-resident
 * `PrepareTestDeliveryStream` tests override them.
 */
final class ProductionFreeGrantSpoolTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private array $owner;

    private array $origin;

    private string $seal;

    private ProductionFreeGrantDownloads $downloads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->downloads = new ProductionFreeGrantDownloads;
        $definition = $this->openDefinition();
        $this->owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $this->owner['principal'], $this->owner['user']);
        $this->origin = $grants->accept($definition['id'], $this->assentInput($review), $this->owner['principal'], $this->owner['user']);
        (new ProductionFreeGrantDocuments)->render($this->origin['id']);
        $this->seal = (new ProductionFreeGrantLibrary)->show($this->origin['id'], $this->owner['principal'], $this->owner['user'])['originSeal'];
    }

    public function test_a_fourth_concurrent_snapshot_is_refused_until_a_slot_is_released(): void
    {
        $held = [];
        for ($i = 0; $i < 3; $i++) {
            $held[] = $this->redeem('master_wav');
        }
        $this->assertSame(3, DB::table('production_free_redemptions')->count());

        $this->refuses(fn () => $this->redeem('master_wav'), 'spool_busy');
        $this->assertSame(3, DB::table('production_free_redemptions')->count(), 'A refused snapshot records no attempt.');
        $this->assertSame([], $this->snapshots());

        $held[0]->close();
        $fourth = $this->redeem('master_wav');
        $this->assertSame($this->sources->bytes['synthetic-master_wav'], $this->bytes($fourth));
        $this->assertSame(4, DB::table('production_free_redemptions')->count());
        foreach ([1, 2] as $index) {
            $held[$index]->close();
        }
    }

    public function test_the_slot_count_comes_from_configuration(): void
    {
        config(['production-free-grants.spool_slots' => 1]);
        $first = $this->redeem('download_mp3');
        $this->refuses(fn () => $this->redeem('download_mp3'), 'spool_busy');
        $this->assertSame($this->sources->bytes['synthetic-download_mp3'], $this->bytes($first));
        $this->assertSame($this->sources->bytes['synthetic-download_mp3'], $this->bytes($this->redeem('download_mp3')));
    }

    public function test_low_free_space_is_refused_before_any_byte_is_written(): void
    {
        $bytes = strlen($this->sources->bytes['synthetic-stems_zip']);
        $spool = new class extends ProductionFreeGrantSpool
        {
            public float|false $free = 0.0;

            public array $probed = [];

            public bool $flushed = false;

            protected function freeBytes(string $directory): float|false
            {
                $this->probed[] = $directory;

                return $this->free;
            }

            protected function flush($handle): bool
            {
                $this->flushed = true;

                return parent::flush($handle);
            }
        };
        $this->app->instance(ProductionFreeGrantSpool::class, $spool);
        $reserve = 16777216;
        $this->assertSame($reserve, config('production-free-grants.spool_reserve_bytes'));

        $spool->free = (float) ($bytes + $reserve - 1);
        $this->refuses(fn () => $this->redeem('stems_zip'), 'spool_space');
        $this->assertFalse($spool->flushed);
        $this->assertCount(1, $spool->probed);
        $this->assertSame([], $this->snapshots());
        $this->assertSame(0, DB::table('production_free_redemptions')->count());

        $spool->free = (float) ($bytes + $reserve);
        $this->assertSame($this->sources->bytes['synthetic-stems_zip'], $this->bytes($this->redeem('stems_zip')));
        foreach ([false, NAN, INF] as $unusable) {
            $spool->free = $unusable;
            $this->refuses(fn () => $this->redeem('stems_zip'), 'spool_space');
        }
    }

    public function test_bytes_that_change_after_the_write_fail_the_read_back_and_release_the_slot(): void
    {
        $spool = new class extends ProductionFreeGrantSpool
        {
            public bool $corrupt = true;

            protected function flush($handle): bool
            {
                if ($this->corrupt) {
                    // Same size, different byte: the write-path hash was correct, the spool no longer is.
                    fseek($handle, 0);
                    fwrite($handle, 'Z');
                }

                return parent::flush($handle);
            }
        };
        $this->app->instance(ProductionFreeGrantSpool::class, $spool);

        $this->refuses(fn () => $this->redeem('master_wav'), 'artifact_drift');
        $this->assertSame(0, DB::table('production_free_redemptions')->count());
        $this->assertSame([], $this->snapshots());

        $spool->corrupt = false;
        $this->assertSame($this->sources->bytes['synthetic-master_wav'], $this->bytes($this->redeem('master_wav')));
        $this->assertSame(1, DB::table('production_free_redemptions')->count());
    }

    public function test_a_prepared_snapshot_is_read_only_unlinked_and_closes_its_lease(): void
    {
        config(['production-free-grants.spool_slots' => 1]);
        $transfer = $this->redeem('download_mp3');
        $stat = fstat($transfer->stream->stream());
        $this->assertSame(0, $stat['nlink']);
        $this->assertSame(0400, $stat['mode'] & 07777);
        $this->assertSame([], $this->snapshots());
        $this->refuses(fn () => $this->redeem('download_mp3'), 'spool_busy');
        $transfer->close();
        $this->assertSame($this->sources->bytes['synthetic-download_mp3'], $this->bytes($this->redeem('download_mp3')));
    }

    private function redeem(string $role): ProductionFreeGrantTransfer
    {
        $authorization = $this->downloads->authorize($this->origin['id'], ['originSeal' => $this->seal, 'role' => $role], $this->owner['principal'], $this->owner['user']);

        return $this->downloads->redeem($authorization['id'], $authorization['token'], $this->owner['principal'], $this->owner['user']);
    }

    /** @return list<string> */
    private function snapshots(): array
    {
        return glob((new ProductionFreeGrantFiles)->spoolDirectory().'/*.snapshot') ?: [];
    }

    private function bytes(ProductionFreeGrantTransfer $transfer): string
    {
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });

        return $bytes;
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
