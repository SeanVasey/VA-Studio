<?php

namespace Tests\Feature;

use App\Domain\Services\Invoicing\Models\ServiceInvoice;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\TestCase;

class ServiceInvoiceConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent service-invoice writers require native MySQL.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
    }

    public function test_two_independent_staff_issuing_the_same_deposit_serialize_to_one_invoice(): void
    {
        $f = F::setup();
        $project = F::accept($f);
        $directory = storage_path('framework/testing/service-invoice-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $processes = [];
        try {
            foreach (['first', 'second'] as $name) {
                $processes[$name] = $this->worker($directory, ['name' => $name, 'actor' => $f['operator']->id, 'project' => $project['id'], 'kind' => 'deposit', 'days' => 14]);
            }
            $this->await(fn (): bool => is_file($directory.'/ready-first') && is_file($directory.'/ready-second'), $processes);
            $this->assertNotSame(json_decode(file_get_contents($directory.'/ready-first'), true)['pid'], json_decode(file_get_contents($directory.'/ready-second'), true)['pid']);
            touch($directory.'/start');
            $results = [];
            foreach ($processes as $name => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[$name] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
                $this->assertSame(0, $results[$name]['transactionLevel']);
            }
            $this->assertSame(['issued', 'issued'], array_column($results, 'result'));
            $this->assertSame($results['first']['id'], $results['second']['id']);
            $this->assertSame(['open', 'open'], array_column($results, 'state'));
            $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['replayed'] === false), 'Exactly one writer reserved the invoice.');
            $this->assertSame(1, ServiceInvoice::count());
            $row = ServiceInvoice::sole();
            $this->assertSame('open', $row->state);
            $this->assertNotNull($row->stripe_invoice_id);
            $this->assertSame(3500, $row->amount_minor);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    private function worker(string $directory, array $input): Process
    {
        $db = config('database.connections.mysql');
        $process = new Process([PHP_BINARY, base_path('tests/Support/service-invoice-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ], json_encode($input + ['directory' => $directory], JSON_THROW_ON_ERROR), 60);
        $process->start();

        return $process;
    }

    private function await(callable $ready, array $processes): void
    {
        $deadline = microtime(true) + 30;
        do {
            clearstatcache();
            if ($ready()) {
                return;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), $process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Service invoice worker did not become ready.');
    }
}
