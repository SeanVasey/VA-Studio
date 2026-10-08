<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantFiles;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantTransfer;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Codex P2 (1): a worker that dies after creating its slot snapshot leaves the file behind while the OS releases
 * the flock. The next holder of that slot removes that residual (only the exact path the spool names for the slot,
 * only a regular single-link file owned by this process user) instead of skipping the slot forever.
 */
final class ProductionFreeGrantSpoolResidualTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private array $owner;

    private array $origin;

    private string $seal;

    private string $spool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $definition = $this->openDefinition();
        $this->owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $this->owner['principal'], $this->owner['user']);
        $this->origin = $grants->accept($definition['id'], $this->assentInput($review), $this->owner['principal'], $this->owner['user']);
        (new ProductionFreeGrantDocuments)->render($this->origin['id']);
        $this->seal = (new ProductionFreeGrantLibrary)->show($this->origin['id'], $this->owner['principal'], $this->owner['user'])['originSeal'];
        $this->spool = (new ProductionFreeGrantFiles)->spoolDirectory();
    }

    public function test_residual_snapshots_in_every_slot_are_removed_and_downloads_succeed(): void
    {
        for ($slot = 0; $slot < 3; $slot++) {
            file_put_contents($this->spool.'/slot-'.$slot.'.snapshot', 'abandoned by a crashed worker');
            chmod($this->spool.'/slot-'.$slot.'.snapshot', $slot === 1 ? 0400 : 0600);
        }
        $held = [];
        for ($i = 0; $i < 3; $i++) {
            $held[] = $this->redeem();
        }
        $this->assertSame([], glob($this->spool.'/slot-*.snapshot'));
        foreach ($held as $transfer) {
            $this->assertSame($this->sources->bytes['synthetic-master_wav'], $this->bytes($transfer));
        }
    }

    public function test_only_a_regular_single_link_file_at_the_exact_slot_path_is_removed(): void
    {
        config(['production-free-grants.spool_slots' => 1]);
        $outside = realpath(sys_get_temp_dir()).'/va-free256-residual-'.bin2hex(random_bytes(6));
        file_put_contents($outside, 'outside');
        $this->beforeApplicationDestroyed(function () use ($outside): void {
            @unlink($outside);
        });
        $snapshot = $this->spool.'/slot-0.snapshot';

        mkdir($snapshot, 0700);
        $this->refuses(fn () => $this->redeem());
        $this->assertDirectoryExists($snapshot);
        rmdir($snapshot);

        symlink($outside, $snapshot);
        $this->refuses(fn () => $this->redeem());
        $this->assertTrue(is_link($snapshot));
        $this->assertSame('outside', file_get_contents($outside));
        unlink($snapshot);

        file_put_contents($snapshot, 'linked');
        link($snapshot, $snapshot.'.other');
        $this->refuses(fn () => $this->redeem());
        $this->assertFileExists($snapshot);
        $this->assertFileExists($snapshot.'.other');
        unlink($snapshot.'.other');
        unlink($snapshot);

        // With nothing in the way the same slot works again.
        $this->assertSame($this->sources->bytes['synthetic-master_wav'], $this->bytes($this->redeem()));
    }

    private function redeem(): ProductionFreeGrantTransfer
    {
        $downloads = new ProductionFreeGrantDownloads;
        $authorization = $downloads->authorize($this->origin['id'], ['originSeal' => $this->seal, 'role' => 'master_wav'], $this->owner['principal'], $this->owner['user']);

        return $downloads->redeem($authorization['id'], $authorization['token'], $this->owner['principal'], $this->owner['user']);
    }

    private function bytes(ProductionFreeGrantTransfer $transfer): string
    {
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });

        return $bytes;
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Must refuse spool_busy');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('spool_busy', $error->reason);
        }
    }
}
