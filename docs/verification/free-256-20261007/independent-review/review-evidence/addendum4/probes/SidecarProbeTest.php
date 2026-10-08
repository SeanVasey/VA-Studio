<?php

namespace Tests\ReviewProbes\Free256\Addendum4;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSpool;
use Tests\TestCase;

/** Independent review addendum 4 probe (not part of the suite): reservation sidecar residue at 2f787589. */
final class SidecarProbeTest extends TestCase
{
    public function test_sidecar_residue_handling(): void
    {
        $root = sys_get_temp_dir().'/va-free256-sidecar-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $directory = realpath($root);
        $bytes = str_repeat('s', 4096);
        $prepare = function () use ($directory, $bytes): string {
            try {
                (new ProductionFreeGrantSpool)->prepare($directory, 3, 16777216, hash('sha256', $bytes), strlen($bytes), hrtime(true) + 30_000_000_000,
                    fn ($o) => fwrite($o, $bytes))->close();

                return 'ok';
            } catch (ProductionFreeGrantException $error) {
                return 'refused:'.$error->reason;
            }
        };
        $log = [];
        $prepare();
        // (a) Own-user short sidecar on slot 0 (a creator died mid-write): reclaimed.
        file_put_contents($directory.'/slot-0.reserve', '00000');
        chmod($directory.'/slot-0.reserve', 0600);
        $log[] = 'own short sidecar slot 0: '.$prepare().' size now '.filesize($directory.'/slot-0.reserve');
        // (b) Foreign-owned short sidecar on slot 0 while slots 1 and 2 are free.
        unlink($directory.'/slot-0.reserve');
        file_put_contents($directory.'/slot-0.reserve', '00000');
        chown($directory.'/slot-0.reserve', 65534);
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $prepare();
        }
        $log[] = 'foreign short sidecar slot 0 (slots 1,2 free), 3 attempts: '.implode(',', $results);
        // (c) Directory planted at slot-0.reserve.
        unlink($directory.'/slot-0.reserve');
        mkdir($directory.'/slot-0.reserve', 0700);
        $log[] = 'directory at slot-0.reserve: '.$prepare();
        rmdir($directory.'/slot-0.reserve');
        $log[] = 'after removal: '.$prepare();
        $log[] = 'temporary sidecar names left: '.count(glob($directory.'/slot-*.reserve.*.tmp') ?: []);
        foreach (glob($directory.'/*') ?: [] as $file) {
            @chmod($file, 0600);
            @unlink($file);
        }
        @rmdir($directory);
        fwrite(STDERR, "PROBE addendum4.sidecar:\n  ".implode("\n  ", $log)."\n");
        $this->assertCount(5, $log);
    }
}
