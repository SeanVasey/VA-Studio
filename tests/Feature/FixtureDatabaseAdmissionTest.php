<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use WeakReference;

/** Real child PHPUnit setup proves refusal precedes all four Laravel database helpers. */
class FixtureDatabaseAdmissionTest extends TestCase
{
    public function test_owned_file_cleanup_releases_the_source_pdo_and_cannot_reopen_a_released_target(): void
    {
        $connection = $this->createFileSqliteFixture();
        $path = $connection->getDatabaseName();
        $name = $connection->getName();
        $reference = WeakReference::create($connection->getPdo());
        $this->assertSame('delete', $connection->getPdo()->query('PRAGMA journal_mode')->fetchColumn());
        $this->assertSame('DEFERRED', $connection->getConfig('transaction_mode'));
        $this->artisan('migrate', ['--database' => $name, '--force' => true])->assertSuccessful();
        $this->assertTrue($connection->getSchemaBuilder()->hasTable('users'));
        $this->releaseOwnedSqliteFixture($name);
        $this->assertNull($reference->get(), 'No registry-held source PDO may satisfy the independent restore.');
        $this->assertFileDoesNotExist($path);
        $this->assertDirectoryDoesNotExist(dirname($path));
        $this->releaseOwnedSqliteFixture($name);
        try {
            $this->artisan('db:wipe', ['--database' => $name, '--force' => true]);
            $this->fail('An unregistered released file was admitted again.');
        } catch (LogicException $error) {
            $this->assertSame('Disposable test database admission refused.', $error->getMessage());
        }
        $this->assertFileDoesNotExist($path, 'Refusal must precede opening a replacement file.');
    }

    public static function ownedFileChanges(): array
    {
        return ['replaced file' => ['file'], 'symlinked file' => ['symlink'], 'replaced directory' => ['directory'],
            'changed file mode' => ['mode'], 'changed PDO' => ['pdo'], 'changed configuration' => ['configuration']];
    }

    #[DataProvider('ownedFileChanges')]
    public function test_owned_file_admission_and_release_refuse_replacement_without_touching_either_database(string $damage): void
    {
        $connection = $this->createFileSqliteFixture();
        $name = $connection->getName();
        $path = $connection->getDatabaseName();
        $directory = dirname($path);
        $original = $connection->getPdo();
        $original->exec('CREATE TABLE retained_fixture (id INTEGER)');
        $original->exec('INSERT INTO retained_fixture VALUES (47)');
        $foreign = tempnam(sys_get_temp_dir(), 'unregistered-file-fixture-');
        $foreignPdo = new PDO('sqlite:'.$foreign);
        $foreignPdo->exec('CREATE TABLE retained_fixture (id INTEGER)');
        $foreignPdo->exec('INSERT INTO retained_fixture VALUES (59)');
        $before = hash_file('sha256', $foreign);
        try {
            if (in_array($damage, ['file', 'symlink'], true)) {
                rename($path, $path.'.retained');
                $damage === 'file' ? copy($foreign, $path) : symlink($foreign, $path);
            } elseif ($damage === 'directory') {
                rename($directory, $directory.'.retained');
                mkdir($directory, 0700);
                copy($foreign, $path);
            } elseif ($damage === 'mode') {
                chmod($path, 0644);
            } elseif ($damage === 'pdo') {
                $connection->setPdo($foreignPdo);
            } else {
                $this->app['config']->set('database.connections.'.$name.'.database', $foreign);
            }
            foreach ([fn () => $this->artisan('db:wipe', ['--database' => $name, '--force' => true]),
                fn () => $this->releaseOwnedSqliteFixture($name)] as $operation) {
                try {
                    $operation();
                    $this->fail('A replaced owned file was admitted or released.');
                } catch (LogicException $error) {
                    $this->assertSame('Disposable test database admission refused.', $error->getMessage());
                }
            }
            $this->assertSame([47], $original->query('SELECT id FROM retained_fixture')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame([59], $foreignPdo->query('SELECT id FROM retained_fixture')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame($before, hash_file('sha256', $foreign));
        } finally {
            if (in_array($damage, ['file', 'symlink'], true)) {
                unlink($path);
                rename($path.'.retained', $path);
            } elseif ($damage === 'directory') {
                unlink($path);
                rmdir($directory);
                rename($directory.'.retained', $directory);
            } elseif ($damage === 'mode') {
                chmod($path, 0600);
            } elseif ($damage === 'pdo') {
                $connection->setPdo($original);
            } else {
                $this->app['config']->set('database.connections.'.$name.'.database', $path);
            }
            unset($foreignPdo);
            unlink($foreign);
        }
    }

    public function test_owned_memory_fixture_is_distinct_and_admitted_for_actual_artisan_cleanup(): void
    {
        $first = $this->createInMemorySqliteFixture();
        $second = $this->createInMemorySqliteFixture();
        $this->assertNotSame($first->getName(), $second->getName());
        $this->assertNotSame($first->getPdo(), $second->getPdo());
        $first->statement('CREATE TABLE retained_fixture (id INTEGER)');
        $second->statement('CREATE TABLE retained_fixture (id INTEGER)');
        $second->statement('INSERT INTO retained_fixture VALUES (47)');
        $this->artisan('db:wipe', ['--database' => $first->getName(), '--force' => true])->assertExitCode(0);
        $this->assertFalse($first->getSchemaBuilder()->hasTable('retained_fixture'));
        $this->assertSame([47], $second->getPdo()->query('SELECT id FROM retained_fixture')->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function ownedMemoryChanges(): array
    {
        return ['changed memory PDO' => ['memory-pdo'], 'changed file PDO' => ['file-pdo'],
            'changed config' => ['configuration'], 'purged connection' => ['purge']];
    }

    #[DataProvider('ownedMemoryChanges')]
    public function test_owned_memory_cleanup_refuses_redirects_and_preserves_original_and_file_rows(string $damage): void
    {
        $connection = $this->createInMemorySqliteFixture();
        $original = $connection->getPdo();
        $original->exec('CREATE TABLE retained_fixture (id INTEGER)');
        $original->exec('INSERT INTO retained_fixture VALUES (47)');
        $file = tempnam(sys_get_temp_dir(), 'owned-fixture-refusal-');
        $filePdo = new PDO('sqlite:'.$file);
        $filePdo->exec('CREATE TABLE retained_fixture (id INTEGER)');
        $filePdo->exec('INSERT INTO retained_fixture VALUES (59)');
        $before = hash_file('sha256', $file);
        $name = $connection->getName();
        try {
            match ($damage) {
                'memory-pdo' => $connection->setPdo(new PDO('sqlite::memory:')),
                'file-pdo' => $connection->setPdo($filePdo),
                'configuration' => $this->app['config']->set('database.connections.'.$name.'.database', $file),
                'purge' => $this->app['db']->purge($name),
            };
            try {
                $this->artisan('db:wipe', ['--database' => $name, '--force' => true]);
                $this->fail('A redirected owned fixture was admitted for cleanup.');
            } catch (LogicException $error) {
                $this->assertSame('Disposable test database admission refused.', $error->getMessage());
            }
            $this->assertSame([47], $original->query('SELECT id FROM retained_fixture')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame([59], $filePdo->query('SELECT id FROM retained_fixture')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame($before, hash_file('sha256', $file));
        } finally {
            unset($filePdo);
            unlink($file);
        }
    }

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
