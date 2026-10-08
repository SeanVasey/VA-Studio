<?php

namespace Tests\Feature;

use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\Paid\PaidGrantPrepareStream;
use Closure;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Codex P2 (comment 4224514955): a worker killed while copying leaves its named `slot-N.snapshot`, which the no-repair rule
 * then skipped for good, so three crashes made every redemption `target_unavailable`. A slot whose exclusive `flock` this
 * process holds has no live owner, so a leftover snapshot that is exactly what this spool writes (a regular single-link file,
 * mode 0600 or 0400, owned by this process user) is removed and the slot reused. Anything else is still skipped and never
 * touched. Synthetic bytes only; no database is used.
 */
final class PaidGrantSpoolRecoveryTest extends TestCase
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

    public function test_a_crashed_holders_leftover_snapshot_is_recovered_and_the_redemption_succeeds(): void
    {
        $spool = $this->primed();
        Log::spy();
        foreach ([0600, 0400] as $mode) {
            // A worker died mid-copy (0600) or mid-read-back (0400): snapshot and its sidecars stay, the slot lock is free.
            $this->leftover(0, $mode);
            $this->assertSame($this->payload, $this->read($spool->handle($this->target(), null, $this->holder('b'))));
            $this->assertSame([], glob($this->dir().'/slot-*.snapshot'));
            $this->assertSame(str_repeat('0', 20), file_get_contents($this->dir().'/slot-0.reserve'));
        }
        // Each recovery is reported to operators with the slot index only: no path, hash or customer data.
        Log::shouldHaveReceived('warning')->twice()->with('Paid delivery spool slot recovered after a worker stopped mid-snapshot.', ['slot' => 0]);
    }

    public function test_three_leftover_slots_all_recover(): void
    {
        $spool = $this->primed();
        foreach ([0, 1, 2] as $slot) {
            $this->leftover($slot, 0600);
        }
        // Three nested snapshots hold all three slots at once, so each leftover must have been recovered.
        $read = [];
        $spool->during = function () use ($spool, &$read): void {
            $spool->during = function () use ($spool, &$read): void {
                $spool->during = null;
                $read[] = $this->read($spool->handle($this->target(), null, $this->holder('c')));
            };
            $read[] = $this->read($spool->handle($this->target(), null, $this->holder('b')));
        };
        $read[] = $this->read($spool->handle($this->target(), null, $this->holder('a')));
        $this->assertSame([$this->payload, $this->payload, $this->payload], $read);
        $this->assertSame([], glob($this->dir().'/slot-*.snapshot'));
    }

    public function test_a_symlinked_loose_mode_or_multi_link_leftover_is_skipped_and_never_touched(): void
    {
        $spool = $this->primed();
        $outside = $this->dir().'/../outside.snapshot';
        file_put_contents($outside, 'OUTSIDE');
        chmod($outside, 0600);
        symlink($outside, $this->dir().'/slot-0.snapshot');
        $this->leftover(1, 0644);
        $this->leftover(2, 0600);
        link($this->dir().'/slot-2.snapshot', $this->dir().'/../second-link.snapshot');
        $before = $this->identities();

        $this->assertSame('target_unavailable', $this->refusal(fn () => $spool->handle($this->target())));
        $this->assertSame($before, $this->identities(), 'Nothing was deleted, repaired or followed.');
        $this->assertTrue(is_link($this->dir().'/slot-0.snapshot'));
        $this->assertSame('OUTSIDE', file_get_contents($outside));
        $this->assertSame(0644, fileperms($this->dir().'/slot-1.snapshot') & 07777);
        $this->assertSame(2, stat($this->dir().'/slot-2.snapshot')['nlink']);
    }

    public function test_a_leftover_larger_than_the_largest_deliverable_is_skipped_and_never_touched(): void
    {
        $spool = $this->primed();
        $this->leftover(0, 0600);
        // A sparse file one byte past DeliveryAssetFiles::MAX_BYTES: no snapshot this spool writes can be that large.
        $handle = fopen($this->dir().'/slot-0.snapshot', 'r+b');
        ftruncate($handle, DeliveryAssetFiles::MAX_BYTES + 1);
        fclose($handle);
        $before = $this->identities();
        Log::spy();
        $this->assertSame($this->payload, $this->read($spool->handle($this->target())));
        $this->assertSame($before, $this->identities());
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_leftover_with_a_malformed_sidecar_is_skipped_and_never_touched(): void
    {
        $spool = $this->primed();
        $this->leftover(0, 0600);
        chmod($this->dir().'/slot-0.reserve', 0644);
        $before = $this->identities();
        // Slot 0 stays as found; slot 1 serves the snapshot.
        $this->assertSame($this->payload, $this->read($spool->handle($this->target())));
        $this->assertSame($before, $this->identities());
        $this->assertSame(0644, fileperms($this->dir().'/slot-0.reserve') & 07777);
    }

    public function test_a_slot_whose_lock_a_live_process_holds_is_never_recovered(): void
    {
        $spool = $this->primed();
        $this->leftover(0, 0600);
        $inode = fileinode($this->dir().'/slot-0.snapshot');
        $input = new InputStream;
        $child = new Process(['python3', '-c', 'import fcntl, sys; f = open(sys.argv[1], "rb"); fcntl.flock(f, fcntl.LOCK_EX); print("ready", flush=True); sys.stdin.read()',
            $this->dir().'/slot-0.lock'], timeout: 30);
        $child->setInput($input);
        $child->start();
        try {
            $child->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready'));
            // Slot 0 is owned by a live process: its snapshot stays exactly as it is and slot 1 is used instead.
            $this->assertSame($this->payload, $this->read($spool->handle($this->target())));
            clearstatcache();
            $this->assertSame($inode, fileinode($this->dir().'/slot-0.snapshot'));
        } finally {
            $input->close();
            $child->wait();
        }
        // Once that process has gone, the slot's lock is free and its leftover is recovered.
        $this->assertSame($this->payload, $this->read($spool->handle($this->target())));
        $this->assertSame([], glob($this->dir().'/slot-*.snapshot'));
    }

    /** One completed snapshot creates the spool directory, the slot locks and their sidecars as the real flow does. */
    private function primed(): PaidGrantPrepareStream
    {
        $spool = $this->spool();
        $spool->free = (float) (3 * self::BYTES + PaidGrantPrepareStream::RESERVE_BYTES);
        // Three nested snapshots touch every slot once, so each has its lock and sidecars.
        $spool->during = function () use ($spool): void {
            $spool->during = function () use ($spool): void {
                $spool->during = null;
                $spool->handle($this->target())->close();
            };
            $spool->handle($this->target())->close();
        };
        $spool->handle($this->target())->close();
        $this->assertSame([], glob($this->dir().'/slot-*.snapshot'));

        return $spool;
    }

    /** A crash leftover as the spool itself leaves it: partial snapshot of `$mode`, a live-looking reservation and holder. */
    private function leftover(int $slot, int $mode): void
    {
        $path = $this->dir().'/slot-'.$slot.'.snapshot';
        file_put_contents($path, substr($this->payload, 0, 400));
        chmod($path, $mode);
        file_put_contents($this->dir().'/slot-'.$slot.'.reserve', sprintf('%020d', self::BYTES));
        file_put_contents($this->dir().'/slot-'.$slot.'.holder', $this->holder('9'));
    }

    /** A 64-hex holder digest; distinct values stand for distinct buyer accounts. */
    private function holder(string $hex): string
    {
        return str_repeat($hex, 64);
    }

    /** @return array<string, array{int, int, int, int}> */
    private function identities(): array
    {
        clearstatcache();
        $result = [];
        foreach (glob($this->dir().'/slot-*') as $path) {
            $stat = lstat($path);
            $result[basename($path)] = [$stat['ino'], $stat['mode'], $stat['nlink'], $stat['size']];
        }

        return $result;
    }

    private function read($prepared): string
    {
        $bytes = '';
        try {
            $prepared->writeTo(function (string $chunk) use (&$bytes): void {
                $bytes .= $chunk;
            });
        } finally {
            $prepared->close();
        }

        return $bytes;
    }

    private function refusal(Closure $call): string
    {
        try {
            $call();
        } catch (DeliveryException $error) {
            return $error->reason;
        }
        $this->fail('Expected a refusal.');
    }

    private function spool(): PaidGrantPrepareStream
    {
        return new class($this->payload) extends PaidGrantPrepareStream
        {
            public float $free = 0.0;

            public ?Closure $during = null;

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
