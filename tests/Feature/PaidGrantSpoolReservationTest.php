<?php

namespace Tests\Feature;

use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\Paid\PaidGrantPrepareStream;
use Tests\TestCase;

/**
 * Codex P2 (PR #56): each paid spool slot probed free space alone, so concurrent large snapshots could each pass and
 * then fill the filesystem together. Admission now runs under one short global lock and subtracts the bytes reserved
 * by every other held slot; a slot's reservation is released when its snapshot is complete (the disk then reflects
 * it) or when the preparation fails. Ported from the Free256 spool (`ProductionFreeGrantSpoolReservationTest`).
 */
final class PaidGrantSpoolReservationTest extends TestCase
{
    private const BYTES = 1000;

    private string $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['paid-grants.rehearsal_enabled' => true,
            'paid-grants.delivery_policy' => ['schema_version' => 1, 'version' => 'explicit-synthetic-delivery-v1', 'purpose' => 'paid-original-delivery',
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
        $this->payload = str_repeat('s', self::BYTES);
    }

    public function test_two_snapshots_that_fit_alone_but_not_together_are_refused_and_admitted_after_release(): void
    {
        $spool = $this->spool();
        $spool->free = (float) (2 * self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES - 1);
        $inner = null;
        $spool->during = function () use ($spool, &$inner): void {
            $spool->during = null;
            try {
                $spool->handle($this->target())->close();
            } catch (DeliveryException $error) {
                $inner = $error->reason;
            }
        };
        $first = $spool->handle($this->target());
        $this->assertSame('target_unavailable', $inner);
        $this->assertSame([], glob($this->spoolDirectory().'/slot-*.snapshot'));

        // The first snapshot is complete and now accounted for by the disk itself, so its reservation is released.
        $this->assertSame(str_repeat('0', 20), file_get_contents($this->spoolDirectory().'/slot-0.reserve'));
        $second = $spool->handle($this->target());
        $this->assertSame($this->payload, stream_get_contents($second->stream()));
        $first->close();
        $second->close();
    }

    public function test_snapshots_that_fit_together_are_admitted_and_a_failed_copy_releases_its_reservation(): void
    {
        $spool = $this->spool();
        $spool->free = (float) (2 * self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES);
        $nested = null;
        $spool->during = function () use ($spool, &$nested): void {
            $spool->during = null;
            $nested = $spool->handle($this->target());
        };
        $first = $spool->handle($this->target());
        $this->assertNotNull($nested);
        $nested->close();
        $first->close();

        $spool->free = (float) (2 * self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES - 1);
        $inner = null;
        $spool->during = function () use ($spool, &$inner): void {
            $spool->during = null;
            try {
                $spool->handle($this->target());
            } catch (DeliveryException $error) {
                $inner = $error->reason;
            }
            throw new \RuntimeException('synthetic copy failure');
        };
        try {
            $spool->handle($this->target());
            $this->fail('A failed copy must refuse.');
        } catch (DeliveryException $error) {
            $this->assertSame('target_unavailable', $error->reason);
        }
        $this->assertSame('target_unavailable', $inner);
        // The failed slot released its reservation, so one snapshot alone is admitted again.
        $this->assertSame(str_repeat('0', 20), file_get_contents($this->spoolDirectory().'/slot-0.reserve'));
        $alone = $spool->handle($this->target());
        $alone->close();
        $this->assertSame([], glob($this->spoolDirectory().'/slot-*.snapshot'));
    }

    /**
     * Codex P2: bytes another slot has already written are reflected by the free-space probe, so only its unwritten
     * remainder is subtracted. `freeBytes()` is a constant here, standing for the probe after those bytes were written.
     */
    public function test_only_the_unwritten_part_of_another_slot_is_subtracted(): void
    {
        $spool = $this->spool();
        $spool->free = (float) (self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES);
        $spool->before = self::BYTES;
        $nested = null;
        $spool->during = function () use ($spool, &$nested): void {
            $spool->during = null;
            $nested = $spool->handle($this->target());
        };
        // The first snapshot is fully written (not yet sealed and released): nothing of it is still pending.
        $first = $spool->handle($this->target());
        $this->assertNotNull($nested);
        $nested->close();
        $first->close();

        // Half written: exactly the other half is still pending.
        $spool->before = intdiv(self::BYTES, 2);
        foreach ([self::BYTES / 2 - 1 => 'target_unavailable', self::BYTES / 2 => null] as $extra => $expected) {
            $spool->free = (float) (self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES + $extra);
            $inner = 'not run';
            $spool->during = function () use ($spool, &$inner): void {
                $spool->during = null;
                try {
                    $spool->handle($this->target())->close();
                    $inner = null;
                } catch (DeliveryException $error) {
                    $inner = $error->reason;
                }
            };
            $spool->handle($this->target())->close();
            $this->assertSame($expected, $inner);
        }
    }

    public function test_a_malformed_reservation_sidecar_is_never_repaired_and_its_slot_is_skipped(): void
    {
        $spool = $this->spool();
        $spool->free = (float) (self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES);
        $spool->handle($this->target())->close();
        $sidecar = $this->spoolDirectory().'/slot-0.reserve';
        file_put_contents($sidecar, 'not a reservation');
        $prepared = $spool->handle($this->target());
        $prepared->close();
        $this->assertSame('not a reservation', file_get_contents($sidecar));
        $this->assertFileExists($this->spoolDirectory().'/slot-1.reserve');
    }

    private function spool(): PaidGrantPrepareStream
    {
        return new class($this->payload) extends PaidGrantPrepareStream
        {
            public float $free = 0.0;

            public ?\Closure $during = null;

            /** Bytes of the snapshot already on disk when `during` runs (0: a preparation that has not started copying). */
            public int $before = 0;

            public function __construct(private readonly string $payload) {}

            protected function freeBytes(string $directory): float|false
            {
                return $this->free;
            }

            protected function copyTarget(array $target, $destination, int $deadline): void
            {
                fwrite($destination, substr($this->payload, 0, $this->before));
                fflush($destination);
                if ($this->during !== null) {
                    ($this->during)();
                }
                fwrite($destination, substr($this->payload, $this->before));
            }
        };
    }

    private function target(): array
    {
        return ['kind' => 'contract', 'disk' => 'local', 'size_bytes' => self::BYTES, 'sha256' => hash('sha256', $this->payload), 'provenance' => 'synthetic_rehearsal'];
    }

    private function spoolDirectory(): string
    {
        return app(DeliveryAssetFiles::class)->privateRoot().'/delivery/paid-spool';
    }
}
