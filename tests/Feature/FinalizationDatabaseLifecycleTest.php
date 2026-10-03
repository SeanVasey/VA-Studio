<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real child PHPUnit success/failure cleanup; no nested events enter the acceptance census. */
class FinalizationDatabaseLifecycleTest extends TestCase
{
    public static function lifecycles(): array
    {
        return [
            'successful default connection uses the default view policy' => [false, false, false],
            'throwing default connection removes requested views' => [true, false, true],
            'successful named default connection removes requested views' => [false, true, true],
            'throwing named default connection uses the default view policy' => [true, true, false],
        ];
    }

    #[DataProvider('lifecycles')]
    public function test_cleanup_and_the_next_setup_remain_isolated(bool $throws, bool $alias, bool $dropViews): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/lifecycle-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $database = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('vendor/bin/phpunit'), '--configuration', base_path('phpunit.xml'),
            '--log-junit', $directory.'/junit.xml', '--log-events-text', $directory.'/events.log', '--fail-on-phpunit-warning',
            base_path('tests/Support/FinalizationLifecycleFixture.php')], base_path(), [
                'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
                'DB_CONNECTION' => $database['driver'], 'DB_URL' => '',
                'DB_HOST' => (string) ($database['host'] ?? ''), 'DB_PORT' => (string) ($database['port'] ?? ''),
                'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) ($database['username'] ?? ''),
                'DB_PASSWORD' => (string) ($database['password'] ?? ''), 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                'VASEY_LIFECYCLE_RECEIPT' => $directory.'/receipt.jsonl',
                'VASEY_LIFECYCLE_THROW' => $throws ? '1' : '0', 'VASEY_LIFECYCLE_ALIAS' => $alias ? '1' : '0',
                'VASEY_LIFECYCLE_DROP_VIEWS' => $dropViews ? '1' : '0',
            ], null, 90);
        try {
            $process->run();
            $output = $process->getOutput().$process->getErrorOutput();
            $this->assertSame($throws ? 2 : 0, $process->getExitCode(), $output);
            $this->assertFileExists($directory.'/junit.xml', $output);
            $junit = simplexml_load_file($directory.'/junit.xml');
            $cases = $junit->xpath('//testcase');
            $errors = $junit->xpath('//testcase/error');
            $methods = ['test_first_fixture_is_removed_even_when_its_body_throws',
                'test_next_setup_recreates_the_complete_schema_without_previous_rows'];
            $this->assertCount(2, $cases);
            $this->assertSame($methods, array_map(fn ($case) => (string) $case['name'], $cases));
            $this->assertCount($throws ? 1 : 0, $errors);
            $this->assertCount(0, $junit->xpath('//testcase/failure | //testcase/skipped'));
            if ($throws) {
                $this->assertStringContainsString('EXPECTED_LIFECYCLE_BODY_FAILURE', (string) $errors[0]);
            }
            $this->assertFileExists($directory.'/receipt.jsonl', $output);
            $receipts = array_map(fn ($line) => json_decode($line, true, 16, JSON_THROW_ON_ERROR), file($directory.'/receipt.jsonl', FILE_IGNORE_NEW_LINES));
            $this->assertCount(2, $receipts);
            $this->assertSame($methods, array_column($receipts, 'test'));
            foreach ($receipts as $receipt) {
                $this->assertSame($alias ? 'lifecycle_fixture' : $database['driver'], $receipt['connection']);
                $retainsViews = ! $dropViews && $database['driver'] === 'mysql';
                $this->assertSame([0, $retainsViews ? 1 : 0, false, 0, true], [
                    $receipt['tables'], $receipt['views'], $receipt['migrated'], $receipt['transaction_level'], $receipt['callback_after_cleanup'],
                ]);
            }
            $this->assertFileExists($directory.'/events.log');
            // PHPUnit's error exit 2 wins over warning exit 1; JUnit itself omits these warnings.
            // Retain PHPUnit's default suppression semantics for PHP/user warnings (e.g. phpdotenv).
            $this->assertDoesNotMatchRegularExpression(
                '/^(?:Test Runner Triggered Warning|Test Triggered PHPUnit Warning) \('
                .'|^Test Triggered (?:PHP )?Warning \((?![^\r\n)]*, suppressed using operator(?:, ignored by baseline)?\) in )/m',
                file_get_contents($directory.'/events.log'),
                'Child PHPUnit warnings must not hide behind the deliberate body error.',
            );
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            // Child processes share this shard's disposable MySQL database, never another shard.
            RefreshDatabaseState::$migrated = false;
            $filesystem->deleteDirectory($directory);
        }
    }
}
