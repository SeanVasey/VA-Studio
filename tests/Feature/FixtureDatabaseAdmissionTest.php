<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real child PHPUnit setup proves refusal precedes all four Laravel database helpers. */
class FixtureDatabaseAdmissionTest extends TestCase
{
    public static function refusedSetups(): array
    {
        $cases = [];
        foreach (['refresh', 'migrations', 'truncation', 'transactions'] as $trait) {
            foreach (['environment', 'configuration'] as $damage) {
                $cases[$trait.' '.$damage] = [$trait, $damage];
            }
        }

        return $cases;
    }

    public static function finalizationChanges(): array
    {
        return ['unchanged' => ['none', false], 'changed recipe' => ['recipe', false],
            'changed default' => ['default', false], 'pre-refresh hook' => ['setup-hook', true],
            'changed configuration' => ['configuration', true], 'changed physical PDO' => ['pdo', true]];
    }

    #[DataProvider('finalizationChanges')]
    public function test_finalization_uses_the_captured_recipe_and_rechecks_actual_cleanup(string $damage, bool $refused): void
    {
        $directory = storage_path('framework/testing/finalization-admission-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $file = $directory.'/retained.sqlite';
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('CREATE TABLE retained_fixture (sentinel TEXT NOT NULL)');
        $pdo->exec("INSERT INTO retained_fixture VALUES ('SYNTHETIC RETAINED SENTINEL')");
        $before = hash_file('sha256', $file);
        $process = new Process([PHP_BINARY, base_path('vendor/bin/phpunit'), '--configuration', base_path('phpunit.xml'),
            '--do-not-cache-result', '--colors=never', '--log-junit', $directory.'/junit.xml',
            base_path('tests/Support/FinalizationDatabaseAdmissionProbe.php')], base_path(), [
                'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'DB_SOCKET' => '',
                'VASEY_ADMISSION_DAMAGE' => $damage, 'VASEY_ADMISSION_FILE' => $file,
                'VASEY_ADMISSION_SPY' => $directory.'/spy.txt',
            ], null, 45);
        try {
            $process->run();
            $this->assertSame($refused ? 2 : 0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $xml = simplexml_load_file($directory.'/junit.xml');
            $this->assertCount(1, $xml->xpath('//testcase'));
            $this->assertCount(0, $xml->xpath('//testcase/failure | //testcase/skipped'));
            $errors = $xml->xpath('//testcase/error');
            $this->assertCount($refused ? 1 : 0, $errors);
            if ($refused) {
                $this->assertStringContainsString('Disposable test database admission refused.', (string) $errors[0]);
            }
            $records = is_file($directory.'/spy.txt')
                ? array_map(fn ($line) => json_decode($line, true, 16, JSON_THROW_ON_ERROR), file($directory.'/spy.txt', FILE_IGNORE_NEW_LINES)) : [];
            $commands = array_values(array_filter($records, fn ($record) => isset($record['command'])));
            if ($damage === 'setup-hook') {
                $this->assertSame([], $records, 'The hook cannot redirect setup to another database.');
            } else {
                $this->assertSame('migrate:fresh', $commands[0]['command']);
                $this->assertSame(['--database' => 'sqlite', '--drop-views' => false], $commands[0]['parameters']);
                $this->assertSame(['bookkeeping_reset' => true], $records[array_key_last($records)]);
                if ($refused) {
                    $this->assertCount(1, $commands, 'Refused cleanup must not invoke db:wipe.');
                } else {
                    $this->assertSame('db:wipe', $commands[1]['command']);
                    $this->assertSame(['--database' => 'sqlite', '--drop-views' => false], $commands[1]['parameters']);
                }
            }
            $this->assertSame($before, hash_file('sha256', $file));
            $this->assertSame(['SYNTHETIC RETAINED SENTINEL'], $pdo->query('SELECT sentinel FROM retained_fixture')->fetchAll(PDO::FETCH_COLUMN));
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            $pdo = null;
            $filesystem->deleteDirectory($directory);
        }
    }

    #[DataProvider('refusedSetups')]
    public function test_invalid_selection_refuses_before_framework_database_setup(string $trait, string $damage): void
    {
        $directory = storage_path('framework/testing/admission-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $file = $directory.'/retained.sqlite';
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('CREATE TABLE retained_fixture (sentinel TEXT NOT NULL)');
        $pdo->exec("INSERT INTO retained_fixture VALUES ('SYNTHETIC RETAINED SENTINEL')");
        $before = hash_file('sha256', $file);
        $process = new Process([PHP_BINARY, base_path('vendor/bin/phpunit'), '--configuration', base_path('phpunit.xml'),
            '--do-not-cache-result', '--colors=never', '--log-junit', $directory.'/junit.xml',
            base_path('tests/Support/FixtureDatabaseAdmissionProbe.php')], base_path(), [
                'APP_ENV' => $damage === 'environment' ? 'production' : 'testing',
                'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'DB_SOCKET' => '',
                'VASEY_ADMISSION_TRAIT' => $trait, 'VASEY_ADMISSION_DAMAGE' => $damage,
                'VASEY_ADMISSION_FILE' => $file, 'VASEY_ADMISSION_SPY' => $directory.'/spy.txt',
            ], null, 45);
        try {
            $process->run();
            $this->assertSame(2, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertFileDoesNotExist($directory.'/spy.txt', 'No migration, refresh, truncation, transaction or body may run.');
            $this->assertFileExists($directory.'/junit.xml');
            $xml = simplexml_load_file($directory.'/junit.xml');
            $this->assertCount(1, $xml->xpath('//testcase'));
            $errors = $xml->xpath('//testcase/error');
            $this->assertCount(1, $errors);
            $this->assertStringContainsString('Disposable test database admission refused.', (string) $errors[0]);
            $this->assertCount(0, $xml->xpath('//testcase/failure | //testcase/skipped'));
            $this->assertSame($before, hash_file('sha256', $file));
            $this->assertSame(['SYNTHETIC RETAINED SENTINEL'], $pdo->query('SELECT sentinel FROM retained_fixture')->fetchAll(PDO::FETCH_COLUMN));
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            $pdo = null;
            $filesystem->deleteDirectory($directory);
        }
    }
}
