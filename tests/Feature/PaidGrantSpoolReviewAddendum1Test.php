<?php

namespace Tests\Feature;

use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\Paid\PaidGrantPrepareStream;
use Tests\TestCase;

/**
 * Independent review addendum 1 (32440644): adversarial cases for the paid spool reservation sidecars.
 * Synthetic bytes only; no database is used.
 */
final class PaidGrantSpoolReviewAddendum1Test extends TestCase
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
        $this->payload = str_repeat('r', self::BYTES);
    }

    public function test_a_stale_reservation_of_an_unheld_slot_is_not_subtracted_and_its_next_holder_overwrites_it(): void
    {
        $spool = $this->spool();
        $spool->free = (float) (self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES);
        $spool->handle($this->target())->close();
        // Crash residue: slot 1's sidecar still claims a huge reservation, but nobody holds slot 1.
        $stale = $this->dir().'/slot-1.reserve';
        $this->sidecar($stale, sprintf('%020d', 999_999_999_999));
        // Exactly enough raw space for one snapshot: the stale value must not be subtracted.
        $alone = $spool->handle($this->target());
        $alone->close();
        // Now hold slot 0 and admit a nested snapshot, which lands on slot 1 and overwrites the stale value with its own size.
        $spool->free = (float) (2 * self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES);
        $seen = null;
        $spool->during = function () use ($spool, $stale, &$seen): void {
            $spool->during = null;
            $nested = $spool->handle($this->target());
            $seen = file_get_contents($stale);
            $nested->close();
        };
        $spool->handle($this->target())->close();
        $this->assertSame(str_repeat('0', 20), $seen, 'The nested snapshot completed, so its slot-1 reservation was released.');
        $this->assertSame(str_repeat('0', 20), file_get_contents($stale));
        $this->assertSame([], glob($this->dir().'/slot-*.snapshot'));
    }

    public function test_a_tampered_sidecar_of_a_held_slot_fails_admission_closed_instead_of_counting_zero(): void
    {
        $spool = $this->spool();
        // Room for both snapshots: without tampering, the nested admission below would succeed (see the Codex test).
        $spool->free = (float) (2 * self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES);
        $inner = null;
        $spool->during = function () use ($spool, &$inner): void {
            $spool->during = null;
            // Slot 0 is held; its sidecar is overwritten with 20 non-digit bytes (same inode, mode 0600).
            file_put_contents($this->dir().'/slot-0.reserve', 'garbage-not-digits!!');
            try {
                $spool->handle($this->target())->close();
            } catch (DeliveryException $error) {
                $inner = $error->reason;
            }
            $this->assertFileDoesNotExist($this->dir().'/slot-1.snapshot');
        };
        $spool->handle($this->target())->close();
        $this->assertSame('target_unavailable', $inner);
        // The holder's own release rewrote its sidecar to zero, so the slot is usable again.
        $this->assertSame(str_repeat('0', 20), file_get_contents($this->dir().'/slot-0.reserve'));
        $spool->handle($this->target())->close();
    }

    public function test_a_symlinked_or_loose_mode_sidecar_skips_its_slot_and_is_never_repaired_or_followed(): void
    {
        $spool = $this->spool();
        $spool->free = (float) (self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES);
        $spool->handle($this->target())->close();
        $outside = $this->dir().'/../outside.reserve';
        $this->sidecar($outside, str_repeat('0', 20));
        $slot0 = $this->dir().'/slot-0.reserve';
        unlink($slot0);
        symlink($outside, $slot0);
        $this->sidecar($this->dir().'/slot-1.reserve', str_repeat('0', 20), 0644);
        // Slots 0 (symlink) and 1 (0644) are skipped and left exactly as found; slot 2 is used.
        $spool->handle($this->target())->close();
        $this->assertTrue(is_link($slot0));
        $this->assertSame(0644, fileperms($this->dir().'/slot-1.reserve') & 07777);
        $this->assertSame(0600, fileperms($this->dir().'/slot-2.reserve') & 07777);
        $this->assertSame(str_repeat('0', 20), file_get_contents($outside), 'The symlink target was never written.');
        // Hold slot 2: no slot remains, so a further admission refuses instead of repairing slot 0 or 1.
        $inner = null;
        $spool->during = function () use ($spool, &$inner): void {
            $spool->during = null;
            try {
                $spool->handle($this->target())->close();
            } catch (DeliveryException $error) {
                $inner = $error->reason;
            }
        };
        $spool->free = (float) (3 * self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES);
        $spool->handle($this->target())->close();
        $this->assertSame('target_unavailable', $inner);
        $this->assertTrue(is_link($slot0));
        $this->assertSame([], glob($this->dir().'/slot-*.snapshot'));
    }

    private function sidecar(string $path, string $value, int $mode = 0600): void
    {
        file_put_contents($path, $value);
        chmod($path, $mode);
    }

    private function spool(): PaidGrantPrepareStream
    {
        return new class($this->payload) extends PaidGrantPrepareStream
        {
            public float $free = 0.0;

            public ?\Closure $during = null;

            public function __construct(private readonly string $payload) {}

            protected function freeBytes(string $directory): float|false
            {
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
