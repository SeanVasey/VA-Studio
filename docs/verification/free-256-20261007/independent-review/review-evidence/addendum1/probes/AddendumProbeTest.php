<?php

namespace Tests\ReviewProbes\Free256\Addendum1;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRecords;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSpool;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantTransfer;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Independent review addendum 1 probe (not part of the suite), SQLite. Spool crash residue, cross-process slot
 * leases, aggregate free-space admission, the render attempt budget, and previous-key forging power.
 */
final class AddendumProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private array $owner;

    private array $origin;

    private string $seal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
    }

    public function test_spool_crash_residue_cross_process_leases_and_aggregate_space(): void
    {
        $this->granted();
        $spool = $this->privateRoot.'/delivery/production-free-spool';
        // (a) Cross-process: the parent holds three snapshots; a forked child is refused spool_busy.
        $held = [$this->redeem('master_wav'), $this->redeem('download_mp3'), $this->redeem('stems_zip')];
        $result = $this->inChild(function (): string {
            try {
                $this->redeem('contract');

                return 'ok';
            } catch (ProductionFreeGrantException $error) {
                return 'refused:'.$error->reason;
            }
        });
        foreach ($held as $transfer) {
            $transfer->close();
        }
        $this->assertSame('refused:spool_busy', $result);
        \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::now('UTC')->addSeconds(61));
        // (b) Crash residue: a snapshot path left by a killed worker makes that slot permanently skipped.
        foreach ([0, 1, 2] as $slot) {
            file_put_contents($spool.'/slot-'.$slot.'.snapshot', 'residue of a killed worker');
        }
        $residue = [];
        foreach (['contract', 'master_wav'] as $role) {
            try {
                $this->redeem($role);
                $residue[] = $role.':ok';
            } catch (ProductionFreeGrantException $error) {
                $residue[] = $role.':'.$error->reason;
            }
        }
        $this->assertSame(['contract:spool_busy', 'master_wav:spool_busy'], $residue);
        foreach ([0, 1, 2] as $slot) {
            $this->assertFileExists($spool.'/slot-'.$slot.'.snapshot');
            unlink($spool.'/slot-'.$slot.'.snapshot');
        }
        \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::now('UTC')->addSeconds(61));
        // (c) Free-space admission is per slot: with free space fixed at size+reserve, two concurrent snapshots of
        // the same size are both admitted although together they need 2 x size + reserve.
        $this->app->instance(ProductionFreeGrantSpool::class, new class extends ProductionFreeGrantSpool
        {
            protected function freeBytes(string $directory): float|false
            {
                return 16777216.0 + 64.0;
            }
        });
        $both = [];
        $first = $this->redeem('master_wav');
        $second = $this->redeem('download_mp3');
        $both = [$first, $second];
        foreach ($both as $transfer) {
            $transfer->close();
        }
        $this->assertCount(2, $both);
        \Carbon\CarbonImmutable::setTestNow();
        fwrite(STDERR, "PROBE addendum.spool: cross-process 4th snapshot -> {$result}; residue in all 3 slots -> ".implode(',', $residue)
            ." (slots stay skipped until an operator removes residue); per-slot free-space check admitted 2 concurrent snapshots against space for 1\n");
    }

    public function test_render_attempt_budget_and_previous_key_forging_power(): void
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $origin = $grants->accept($definition['id'], $this->assentInput($grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user'])), $owner['principal'], $owner['user']);
        $this->app->bind(ProductionFreeGrantRendererProcess::class, fn () => new ProductionFreeGrantRendererProcess(
            function (): Process {
                throw new \RuntimeException('synthetic renderer outage');
            }));
        $attempts = 0;
        $reason = null;
        while ($attempts < 40) {
            try {
                (new ProductionFreeGrantDocuments)->render($origin['id']);
            } catch (ProductionFreeGrantException $error) {
                $reason = $error->reason;
                if ($reason !== 'render_failed') {
                    break;
                }
            }
            $attempts++;
        }
        $rows = DB::table('production_free_document_work')->where('origin_id', $origin['id'])->count();
        $this->assertSame('attempts_exhausted', $reason);
        // Previous keys: a row sealed now with a listed previous key verifies, i.e. a holder of an old listed key keeps
        // forging power; delisting it makes every row it sealed read as tampered (append-only rows cannot be resealed).
        $current = config('app.key');
        $old = 'base64:'.base64_encode(str_repeat('O', 32));
        config(['app.previous_keys' => [$old]]);
        $row = (array) DB::table('production_free_definitions')->where('id', $definition['id'])->first();
        $forged = $row;
        $forged['seal'] = ProductionFreeGrantRecords::seal('production_free_definitions', $row, $old);
        $acceptedWithOld = $this->verifies($forged);
        config(['app.previous_keys' => []]);
        $acceptedDelisted = $this->verifies($forged);
        $this->assertTrue($acceptedWithOld);
        $this->assertFalse($acceptedDelisted);
        $this->assertSame($current, config('app.key'));
        fwrite(STDERR, "PROBE addendum.attempts: {$attempts} failed render attempts before attempts_exhausted ({$rows} work rows, claimed+failed per attempt); "
            .'previous-key seal verifies while listed='.json_encode($acceptedWithOld).', after delisting='.json_encode($acceptedDelisted)."\n");
    }

    private function verifies(array $row): bool
    {
        try {
            ProductionFreeGrantRecords::verify('production_free_definitions', $row);

            return true;
        } catch (ProductionFreeGrantException) {
            return false;
        }
    }

    private function granted(): void
    {
        $definition = $this->openDefinition();
        $this->owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $this->owner['principal'], $this->owner['user']);
        $this->origin = $grants->accept($definition['id'], $this->assentInput($review), $this->owner['principal'], $this->owner['user']);
        (new ProductionFreeGrantDocuments)->render($this->origin['id']);
        $this->seal = (new ProductionFreeGrantLibrary)->show($this->origin['id'], $this->owner['principal'], $this->owner['user'])['originSeal'];
    }

    private function redeem(string $role): ProductionFreeGrantTransfer
    {
        $downloads = new ProductionFreeGrantDownloads;
        $authorization = $downloads->authorize($this->origin['id'], ['originSeal' => $this->seal, 'role' => $role], $this->owner['principal'], $this->owner['user']);

        return $downloads->redeem($authorization['id'], $authorization['token'], $this->owner['principal'], $this->owner['user']);
    }

    private function inChild(callable $work): string
    {
        $file = tempnam(sys_get_temp_dir(), 'free256-child-');
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                file_put_contents($file, $work());
            } catch (\Throwable $error) {
                file_put_contents($file, 'error:'.$error::class.':'.$error->getMessage());
            }
            posix_kill(posix_getpid(), SIGKILL);
        }
        pcntl_waitpid($pid, $status);
        $result = (string) file_get_contents($file);
        unlink($file);

        return $result;
    }
}
