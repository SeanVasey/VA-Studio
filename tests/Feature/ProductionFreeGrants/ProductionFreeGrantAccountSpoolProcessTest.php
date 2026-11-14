<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSpool;
use Tests\TestCase;

/** Separate native PHP processes prove local POSIX leases only, never deployed filesystem or blocked HTTP writes. */
final class ProductionFreeGrantAccountSpoolProcessTest extends TestCase
{
    private string $directory;

    private mixed $child = null;

    private array $pipes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/free-account-process-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->child)) {
            proc_terminate($this->child);
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->child);
        }
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function start(): void
    {
        $code = <<<'PHP'
require $argv[1];
$bytes = str_repeat('s', 4096);
$prepared = (new App\Domain\Grants\ProductionFree\ProductionFreeGrantSpool)->prepare(
    $argv[2], 3, 0, hash('sha256', $bytes), strlen($bytes), hrtime(true) + 60_000_000_000,
    static function ($output) use ($bytes): void {
        echo "FILL\n";
        fflush(STDOUT);
        if (trim(fgets(STDIN)) === 'EXIT') {
            exit(0);
        }
        fwrite($output, $bytes);
    }, hash('sha256', 'synthetic-account-a'));
echo "READY\n";
fflush(STDOUT);
fgets(STDIN);
$prepared->close();
PHP;
        $this->child = proc_open([PHP_BINARY, '-r', $code, base_path('vendor/autoload.php'), $this->directory],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, base_path());
        $this->assertIsResource($this->child);
        $this->line('FILL');
    }

    private function line(string $expected): void
    {
        $read = [$this->pipes[1]];
        $write = $except = [];
        $this->assertSame(1, stream_select($read, $write, $except, 10), 'Child must reach its explicit synchronization point.');
        $this->assertSame($expected."\n", fgets($this->pipes[1]));
    }

    private function finish(string $command): void
    {
        fwrite($this->pipes[0], $command."\n");
        fclose($this->pipes[0]);
        // EOF on both output pipes precedes the observed normal process exit.
        $read = [$this->pipes[1]];
        $write = $except = [];
        $this->assertSame(1, stream_select($read, $write, $except, 10));
        $this->assertSame('', stream_get_contents($this->pipes[1]));
        $this->assertSame('', stream_get_contents($this->pipes[2]));
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        $this->assertSame(0, proc_close($this->child));
        $this->child = null;
    }

    private function prepare(string $account, ?ProductionFreeGrantSpool $spool = null): void
    {
        $bytes = str_repeat('s', 4096);
        $prepared = ($spool ?? new ProductionFreeGrantSpool)->prepare($this->directory, 3, 0, hash('sha256', $bytes),
            strlen($bytes), hrtime(true) + 10_000_000_000, static fn ($output) => fwrite($output, $bytes),
            hash('sha256', 'synthetic-account-'.$account));
        $this->assertSame($bytes, stream_get_contents($prepared->stream()));
        $prepared->close();
    }

    private function refused(callable $operation, string $reason = 'spool_busy'): void
    {
        try {
            $operation();
            $this->fail('Another process holding the account must prevent a second snapshot.');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    public function test_another_process_publishes_its_account_before_fill_and_retains_it_until_close(): void
    {
        $this->start();
        $this->refused(fn () => $this->prepare('a'));
        $this->assertSame('00000000000000004096', file_get_contents($this->directory.'/slot-0.reserve'));
        $limited = new class extends ProductionFreeGrantSpool
        {
            protected function freeBytes(string $directory): float|false
            {
                return 8191.0;
            }
        };
        $this->refused(fn () => $this->prepare('b', $limited), 'spool_space');
        $this->prepare('b');
        fwrite($this->pipes[0], "FINISH\n");
        $this->line('READY');
        $this->refused(fn () => $this->prepare('a'));
        $this->assertSame(str_repeat('0', 20), file_get_contents($this->directory.'/slot-0.reserve'));
        $this->finish('CLOSE');
        $this->prepare('a');
    }

    public function test_process_exit_drops_its_lease_and_next_holder_reclaims_only_its_residual_snapshot(): void
    {
        $this->start();
        $this->refused(fn () => $this->prepare('a'));
        $this->finish('EXIT');
        $this->assertFileExists($this->directory.'/slot-0.snapshot');
        $this->prepare('a');
        $this->assertSame([], glob($this->directory.'/*.snapshot'));
    }
}
