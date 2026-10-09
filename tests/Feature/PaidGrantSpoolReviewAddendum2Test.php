<?php

namespace Tests\Feature;

use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\Paid\PaidGrantPrepareStream;
use Tests\TestCase;

/**
 * Independent review addendum 2 (e5c2e1dc): only the unwritten part of another held slot's reservation is subtracted.
 * Synthetic bytes only; no database is used.
 */
final class PaidGrantSpoolReviewAddendum2Test extends TestCase
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
        $this->payload = str_repeat('q', self::BYTES);
    }

    public function test_a_hard_linked_or_oversized_held_snapshot_counts_its_full_reservation(): void
    {
        $spool = $this->spool();
        $spool->before = self::BYTES;
        // Fully written: normally nothing of slot 0 is pending, so this much space admits a nested snapshot.
        $spool->free = (float) (self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES);
        $snapshot = $this->dir().'/slot-0.snapshot';
        $link = $this->dir().'/../review-extra-link';
        // Control: an ordinary fully written single-link snapshot leaves nothing pending, so the nested snapshot fits.
        $inner = 'not run';
        $spool->during = function () use ($spool, &$inner): void {
            $spool->during = null;
            $inner = $this->nested($spool);
        };
        $spool->handle($this->target())->close();
        $this->assertNull($inner);
        $inner = 'not run';
        $spool->during = function () use ($spool, $snapshot, $link, &$inner): void {
            $spool->during = null;
            link($snapshot, $link);
            $inner = $this->nested($spool);
            unlink($link);
        };
        $spool->handle($this->target())->close();
        $this->assertSame('target_unavailable', $inner, 'nlink 2: the written size is not trusted');

        $outer = 'admitted';
        $spool->during = function () use ($spool, $snapshot, &$inner): void {
            $spool->during = null;
            $extra = fopen($snapshot, 'ab');
            fwrite($extra, 'q');
            fclose($extra);
            $inner = $this->nested($spool);
        };
        try {
            $spool->handle($this->target())->close();
        } catch (DeliveryException $error) {
            $outer = $error->reason;
        }
        $this->assertSame('target_unavailable', $inner, 'size above the reservation: the written size is not trusted');
        $this->assertSame('target_unavailable', $outer, 'the holder refuses its own oversized snapshot');
        $this->assertSame([], glob($this->dir().'/slot-*.snapshot'));
    }

    /**
     * A2-I2: `admit()` used to probe free space before it read the other slots' written sizes, so bytes another holder
     * wrote between the two probes were subtracted from its pending amount without being in the free-space figure, and
     * the admission over-counted free space by exactly those bytes. The integration owner now reads the sizes first, so
     * a write between the probes is counted conservatively. Reviewer's reproduction, now a regression test.
     */
    public function test_bytes_written_between_the_size_probe_and_the_free_space_probe_are_never_over_admitted(): void
    {
        $spool = $this->spool();
        $half = intdiv(self::BYTES, 2);
        $spool->before = $half;
        // Correct admission for the nested snapshot needs BYTES + the 500 still unwritten by slot 0 + the reserve.
        $spool->free = (float) (self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES + $half - 1);
        $snapshot = $this->dir().'/slot-0.snapshot';
        // Control: without the concurrent write, the half-written slot 0 keeps 500 bytes pending and the nested snapshot is refused.
        $inner = 'not run';
        $spool->during = function () use ($spool, &$inner): void {
            $spool->during = null;
            $inner = $this->nested($spool);
        };
        $spool->handle($this->target())->close();
        $this->assertSame('target_unavailable', $inner);
        $inner = 'not run';
        $spool->during = function () use ($spool, $snapshot, &$inner): void {
            $spool->during = null;
            // The concurrent holder writes 400 more bytes right after the nested admission's free-space probe.
            $spool->afterProbe = function () use ($snapshot): void {
                $extra = fopen($snapshot, 'ab');
                fwrite($extra, str_repeat('q', 400));
                fclose($extra);
            };
            $inner = $this->nested($spool);
            $spool->afterProbe = null;
        };
        // The holder's own handle then overwrites the same offsets with identical bytes, so it seals normally.
        $spool->handle($this->target())->close();
        $this->assertSame('target_unavailable', $inner, 'Only BYTES + RESERVE + 499 was free when 500 bytes of slot 0 were unwritten.');
    }

    private function nested(PaidGrantPrepareStream $spool): ?string
    {
        try {
            $spool->handle($this->target())->close();

            return null;
        } catch (DeliveryException $error) {
            return $error->reason;
        }
    }

    private function spool(): PaidGrantPrepareStream
    {
        return new class($this->payload) extends PaidGrantPrepareStream
        {
            public float $free = 0.0;

            public int $before = 0;

            public ?\Closure $during = null;

            public ?\Closure $afterProbe = null;

            public function __construct(private readonly string $payload) {}

            protected function freeBytes(string $directory): float|false
            {
                $free = $this->free;
                if ($this->afterProbe !== null) {
                    ($this->afterProbe)();
                }

                return $free;
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

    private function dir(): string
    {
        return app(DeliveryAssetFiles::class)->privateRoot().'/delivery/paid-spool';
    }
}
