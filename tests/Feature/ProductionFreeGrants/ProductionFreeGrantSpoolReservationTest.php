<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantFiles;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSpool;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Codex P2 (2): free space is probed per slot, so concurrent large snapshots could each pass alone. Admission now
 * subtracts the bytes reserved by every other active slot, under one short global lock; a slot's reservation is
 * released when its snapshot is complete (the disk then reflects it) or when it fails.
 */
final class ProductionFreeGrantSpoolReservationTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private const RESERVE = 16777216;

    private const BYTES = 1000;

    private string $directory;

    private string $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->directory = (new ProductionFreeGrantFiles)->spoolDirectory();
        $this->payload = str_repeat('s', self::BYTES);
    }

    public function test_two_reservations_that_fit_alone_but_not_together_are_refused_and_admitted_after_release(): void
    {
        $spool = $this->spool();
        $spool->free = (float) (2 * self::BYTES + self::RESERVE - 1);
        $inner = null;
        $first = $this->prepare($spool, function () use ($spool, &$inner): void {
            try {
                $this->prepare($spool)->close();
            } catch (ProductionFreeGrantException $error) {
                $inner = $error->reason;
            }
        });
        $this->assertSame('spool_space', $inner);
        $this->assertSame([], glob($this->directory.'/slot-*.snapshot'));

        // The first snapshot is complete and now accounted for by the disk itself, so the reservation is released.
        $this->assertSame(str_repeat('0', 20), file_get_contents($this->directory.'/slot-0.reserve'));
        $second = $this->prepare($spool);
        $this->assertSame($this->payload, stream_get_contents($second->stream()));
        $first->close();
        $second->close();
    }

    public function test_reservations_that_fit_together_are_admitted_and_a_failed_fill_releases_its_reservation(): void
    {
        $spool = $this->spool();
        $spool->free = (float) (2 * self::BYTES + self::RESERVE);
        $inner = null;
        $nested = null;
        $first = $this->prepare($spool, function () use ($spool, &$nested): void {
            $nested = $this->prepare($spool);
        });
        $this->assertNotNull($nested);
        $nested->close();
        $first->close();

        $spool->free = (float) (2 * self::BYTES + self::RESERVE - 1);
        try {
            $this->prepare($spool, function () use ($spool, &$inner): void {
                try {
                    $this->prepare($spool);
                } catch (ProductionFreeGrantException $error) {
                    $inner = $error->reason;
                }
                throw new \RuntimeException('synthetic fill failure');
            });
            $this->fail('A failed fill must refuse.');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('artifact_unavailable', $error->reason);
        }
        $this->assertSame('spool_space', $inner);
        $alone = $this->prepare($spool);
        $alone->close();
        $this->assertSame([], glob($this->directory.'/slot-*.snapshot'));
    }

    private function spool(): ProductionFreeGrantSpool
    {
        return new class extends ProductionFreeGrantSpool
        {
            public float $free = 0.0;

            protected function freeBytes(string $directory): float|false
            {
                return $this->free;
            }
        };
    }

    private function prepare(ProductionFreeGrantSpool $spool, ?callable $during = null)
    {
        return $spool->prepare($this->directory, 3, self::RESERVE, hash('sha256', $this->payload), self::BYTES, hrtime(true) + 60_000_000_000,
            function ($output) use ($during): void {
                fwrite($output, $this->payload);
                if ($during !== null) {
                    $during();
                }
            });
    }
}
