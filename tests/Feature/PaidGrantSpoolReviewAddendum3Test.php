<?php

namespace Tests\Feature;

use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\Paid\PaidGrantPrepareStream;
use Tests\TestCase;

/**
 * Independent review addendum 3 (9c19dabb): the per-buyer holder sidecar. Synthetic bytes only; no database is used.
 */
final class PaidGrantSpoolReviewAddendum3Test extends TestCase
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
        $this->payload = str_repeat('h', self::BYTES);
    }

    public function test_a_holder_written_before_a_failed_reservation_and_a_stale_holder_of_an_unheld_slot_never_refuse_that_buyer(): void
    {
        $spool = $this->spool();
        $spool->free = (float) (3 * self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES);
        $buyer = hash('sha256', 'buyer-a');
        $other = hash('sha256', 'buyer-b');
        $spool->handle($this->target(), null, $other)->close();
        // Slot 0 now has well-formed sidecars from buyer B. Replace its reservation with a symlink right after the
        // free-space probe, which follows the holder check: the holder is then written and the reservation fails.
        $reserve = $this->dir().'/slot-0.reserve';
        $outside = $this->dir().'/../review-a3-outside';
        file_put_contents($outside, str_repeat('0', 20));
        chmod($outside, 0600);
        $spool->afterProbe = function () use ($reserve, $outside): void {
            unlink($reserve);
            symlink($outside, $reserve);
        };
        $this->assertSame('target_unavailable', $this->attempt($spool, $buyer));
        $spool->afterProbe = null;
        $this->assertSame($buyer, file_get_contents($this->dir().'/slot-0.holder'), 'holder written before the reservation failed');
        $this->assertTrue(is_link($reserve), 'never repaired');
        // Slot 0 is now retired by the existing no-repair rule (symlinked reservation), not by the holder sidecar.
        // Crash-residue equivalent: buyer A's digest sits in unheld slot 0; buyer A is still admitted (slot 1).
        $held = null;
        $spool->during = function () use ($spool, $buyer, &$held): void {
            $spool->during = null;
            // While buyer A holds slot 1, A is refused, and B is admitted on slot 2 despite B's stale digest in slot 1's past.
            $held = ['same' => $this->attempt($spool, $buyer), 'other' => $this->attempt($spool, hash('sha256', 'buyer-b'))];
        };
        $spool->handle($this->target(), null, $buyer)->close();
        $this->assertSame(['same' => 'target_unavailable', 'other' => null], $held);
        $this->assertSame($outside, readlink($reserve));
        $this->assertSame(str_repeat('0', 20), file_get_contents($outside), 'the symlink target was never written');
        $this->assertSame([], glob($this->dir().'/slot-*.snapshot'));
    }

    public function test_a_tampered_holder_of_a_held_slot_fails_closed_for_every_other_buyer_and_a_loose_one_retires_only_its_slot(): void
    {
        $spool = $this->spool();
        $spool->free = (float) (3 * self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES);
        $buyer = hash('sha256', 'buyer-a');
        $results = null;
        $spool->during = function () use ($spool, &$results): void {
            $spool->during = null;
            // Same inode, mode and size, but not hex: unreadable as a holder while slot 0 is held.
            file_put_contents($this->dir().'/slot-0.holder', str_repeat('Z', 64));
            $results = [$this->attempt($spool, hash('sha256', 'buyer-b')), $this->attempt($spool, null)];
        };
        $spool->handle($this->target(), null, $buyer)->close();
        // A null holder (no account; tests only) does not read other holders, so it is still admitted.
        $this->assertSame(['target_unavailable', null], $results);
        // Released: an unheld slot's holder content is never read, so slot 0 is usable again and is overwritten.
        $spool->handle($this->target(), null, $buyer)->close();
        $this->assertSame($buyer, file_get_contents($this->dir().'/slot-0.holder'));
        // A loose-mode holder sidecar retires its slot (no repair); the next slot serves the buyer.
        chmod($this->dir().'/slot-0.holder', 0644);
        $spool->handle($this->target(), null, $buyer)->close();
        $this->assertSame(0644, fileperms($this->dir().'/slot-0.holder') & 07777);
        $this->assertSame($buyer, file_get_contents($this->dir().'/slot-1.holder'));
    }

    private function attempt(PaidGrantPrepareStream $spool, ?string $holder): ?string
    {
        try {
            $spool->handle($this->target(), null, $holder)->close();

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

            public ?\Closure $during = null;

            public ?\Closure $afterProbe = null;

            public function __construct(private readonly string $payload) {}

            protected function freeBytes(string $directory): float|false
            {
                if ($this->afterProbe !== null) {
                    ($this->afterProbe)();
                }

                return $this->free;
            }

            protected function copyTarget(array $target, $destination, int $deadline): void
            {
                fwrite($destination, $this->payload);
                if ($this->during !== null) {
                    ($this->during)();
                }
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
