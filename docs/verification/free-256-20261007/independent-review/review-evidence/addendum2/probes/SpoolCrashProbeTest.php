<?php

namespace Tests\ReviewProbes\Free256\Addendum2;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSpool;
use Tests\TestCase;

/**
 * Independent review addendum 2 probe (not part of the suite). Drives ProductionFreeGrantSpool::prepare directly in
 * forked processes: a holder killed between reserve and release, a live holder mid-fill, and reclaim of residue
 * owned by another uid.
 */
final class SpoolCrashProbeTest extends TestCase
{
    private const SIZE = 1048576;

    private const RESERVE = 16777216;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $root = sys_get_temp_dir().'/va-free256-spool-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $this->directory = realpath($root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                @chmod($file, 0600);
                @unlink($file);
            }
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_dead_holder_reservation_does_not_leak_and_live_reservation_counts(): void
    {
        $bytes = str_repeat('a', self::SIZE);
        $sha = hash('sha256', $bytes);
        // Free space exactly fits ONE snapshot of SIZE plus the reserve.
        $spool = new class extends ProductionFreeGrantSpool
        {
            protected function freeBytes(string $directory): float|false
            {
                return (float) (1048576 + 16777216);
            }
        };
        $log = [];
        // (1) A child takes a slot, reserves SIZE, writes half and is SIGKILLed: residue + a non-zero sidecar + a
        // released flock remain.
        $this->inChild(function () use ($spool, $sha, $bytes): string {
            $spool->prepare($this->directory, 3, self::RESERVE, $sha, self::SIZE, hrtime(true) + 60_000_000_000, function ($output) use ($bytes): void {
                fwrite($output, substr($bytes, 0, self::SIZE / 2));
                fflush($output);
                posix_kill(posix_getpid(), SIGKILL);
            });

            return 'unreachable';
        });
        $sidecars = [];
        foreach (glob($this->directory.'/slot-*.reserve') ?: [] as $file) {
            $sidecars[basename($file)] = file_get_contents($file);
        }
        $residue = array_map('basename', glob($this->directory.'/slot-*.snapshot') ?: []);
        $log[] = 'after killed holder: sidecars='.json_encode($sidecars).' residue='.json_encode($residue);
        $this->assertContains(sprintf('%020d', self::SIZE), $sidecars);
        $this->assertNotSame([], $residue);
        // (2) The dead holder's reservation is ignored (its flock is free) and its residue is reclaimed: admitted.
        $first = $spool->prepare($this->directory, 3, self::RESERVE, $sha, self::SIZE, hrtime(true) + 60_000_000_000, fn ($o) => fwrite($o, $bytes));
        $log[] = 'next admission with space for one: admitted; residue now='.json_encode(array_map('basename', glob($this->directory.'/slot-*.snapshot') ?: []));
        $first->close();
        // (3) A LIVE holder mid-fill (child sleeping inside fill) is counted: the parent is refused spool_space.
        $flag = $this->directory.'/.child-filling';
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $spool->prepare($this->directory, 3, self::RESERVE, $sha, self::SIZE, hrtime(true) + 60_000_000_000, function ($output) use ($bytes, $flag): void {
                    touch($flag);
                    usleep(3_000_000);
                    fwrite($output, $bytes);
                })->close();
            } catch (\Throwable) {
            }
            posix_kill(posix_getpid(), SIGKILL);
        }
        $waited = 0;
        while (! is_file($flag) && $waited++ < 100) {
            usleep(50_000);
        }
        try {
            $spool->prepare($this->directory, 3, self::RESERVE, $sha, self::SIZE, hrtime(true) + 60_000_000_000, fn ($o) => fwrite($o, $bytes))->close();
            $live = 'admitted';
        } catch (ProductionFreeGrantException $error) {
            $live = 'refused:'.$error->reason;
        }
        pcntl_waitpid($pid, $status);
        $log[] = 'while a live holder is mid-fill: '.$live;
        $this->assertSame('refused:spool_space', $live);
        // (4) Residue owned by another uid is never reclaimed; that slot is skipped.
        foreach ([0, 1, 2] as $slot) {
            $path = $this->directory.'/slot-'.$slot.'.snapshot';
            file_put_contents($path, 'foreign');
            chown($path, 65534);
        }
        try {
            $spool->prepare($this->directory, 3, self::RESERVE, $sha, self::SIZE, hrtime(true) + 60_000_000_000, fn ($o) => fwrite($o, $bytes))->close();
            $foreign = 'admitted';
        } catch (ProductionFreeGrantException $error) {
            $foreign = 'refused:'.$error->reason;
        }
        $kept = count(glob($this->directory.'/slot-*.snapshot') ?: []);
        $log[] = 'residue owned by uid 65534 in all slots: '.$foreign.', residues kept='.$kept;
        $this->assertSame('refused:spool_busy', $foreign);
        $this->assertSame(3, $kept);
        fwrite(STDERR, "PROBE addendum2.spool:\n  ".implode("\n  ", $log)."\n");
    }

    private function inChild(callable $work): void
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $work();
            } catch (\Throwable) {
            }
            posix_kill(posix_getpid(), SIGKILL);
        }
        pcntl_waitpid($pid, $status);
    }
}
