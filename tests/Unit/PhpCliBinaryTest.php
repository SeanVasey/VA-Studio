<?php

namespace Tests\Unit;

use App\Support\PhpCliBinary;
use App\Support\PhpCliBinaryUnavailable;
use PHPUnit\Framework\TestCase;

/** Resolver behavior with the SAPI seam: FPM is simulated in-process; fakes are local shell scripts. */
class PhpCliBinaryTest extends TestCase
{
    private const FPM_BINARY = '/usr/sbin/php-fpm8.4';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/php-cli-binary-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $name) {
            unlink($this->directory.'/'.$name);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function fpm(?string $configured): PhpCliBinary
    {
        return new PhpCliBinary($configured, 'fpm-fcgi', self::FPM_BINARY);
    }

    /** A fake binary that ignores its arguments, prints $output and records each run. */
    private function fake(string $name, string $output, int $exit = 0, int $mode = 0700): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, "#!/bin/sh\necho run >> ".escapeshellarg($path.'.runs')."\nprintf '%s' "
            .escapeshellarg($output)."\nexit ".$exit."\n");
        chmod($path, $mode);

        return $path;
    }

    private function runs(string $fake): int
    {
        return is_file($fake.'.runs') ? count(file($fake.'.runs')) : 0;
    }

    private function refused(string $reason, PhpCliBinary $resolver): void
    {
        try {
            $resolver->path();
            $this->fail('An unavailable CLI binary was accepted.');
        } catch (PhpCliBinaryUnavailable $error) {
            $this->assertSame($reason, $error->reason);
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString($this->directory, $error->getMessage());
        }
    }

    public function test_cli_sapi_returns_the_running_binary_without_configuration_or_probe(): void
    {
        $this->assertSame(PHP_BINARY, (new PhpCliBinary(null))->path());
        $fake = $this->fake('other', 'cli 0.0.0');
        $this->assertSame('/opt/running/php', (new PhpCliBinary($fake, 'cli', '/opt/running/php'))->path());
        $this->assertSame(0, $this->runs($fake));
    }

    public function test_configured_same_version_cli_binary_is_returned_canonically_under_fpm(): void
    {
        $this->assertSame(realpath(PHP_BINARY), $this->fpm(PHP_BINARY)->path());
        $link = $this->directory.'/php-link';
        symlink(PHP_BINARY, $link);
        $this->assertSame(realpath(PHP_BINARY), $this->fpm($link)->path());
    }

    public function test_successful_validation_is_cached_per_process_and_failures_are_not(): void
    {
        $good = $this->fake('good', 'cli '.PHP_VERSION);
        $this->assertSame(realpath($good), $this->fpm($good)->path());
        $this->assertSame(realpath($good), $this->fpm($good)->path());
        $this->assertSame(1, $this->runs($good));

        $bad = $this->fake('bad', 'cli 0.0.0');
        $this->refused('version_mismatch', $this->fpm($bad));
        $this->refused('version_mismatch', $this->fpm($bad));
        $this->assertSame(2, $this->runs($bad));
    }

    public function test_unset_or_blank_configuration_outside_cli_is_refused_without_falling_back(): void
    {
        foreach ([null, '', '   '] as $configured) {
            $this->refused('unconfigured', $this->fpm($configured));
            $this->refused('unconfigured', new PhpCliBinary($configured, 'cli-server', PHP_BINARY));
        }
    }

    public function test_relative_missing_or_non_regular_paths_are_refused(): void
    {
        $this->refused('invalid_path', $this->fpm('php8.4'));
        $this->refused('invalid_path', $this->fpm($this->directory.'/missing'));
        $this->refused('invalid_path', $this->fpm($this->directory));
        $this->refused('invalid_path', $this->fpm(PHP_BINARY."\n"));
        $dangling = $this->directory.'/dangling';
        symlink($this->directory.'/missing', $dangling);
        $this->refused('invalid_path', $this->fpm($dangling));
    }

    public function test_non_executable_file_is_refused_before_it_is_run(): void
    {
        $fake = $this->fake('plain', 'cli '.PHP_VERSION, 0, 0600);
        $this->refused('not_executable', $this->fpm($fake));
        $this->assertSame(0, $this->runs($fake));
    }

    public function test_binary_or_symlink_reporting_another_version_is_refused(): void
    {
        $other = $this->fake('php-other', 'cli 8.3.99');
        $this->refused('version_mismatch', $this->fpm($other));
        $alternative = $this->directory.'/php';
        symlink($other, $alternative);
        $this->refused('version_mismatch', $this->fpm($alternative));
        $this->refused('version_mismatch', new PhpCliBinary(PHP_BINARY, 'fpm-fcgi', self::FPM_BINARY, PHP_VERSION.'-other'));
    }

    public function test_binary_reporting_a_non_cli_sapi_is_refused(): void
    {
        $this->refused('not_cli', $this->fpm($this->fake('fpm', 'fpm-fcgi '.PHP_VERSION)));
        $this->refused('not_cli', $this->fpm($this->fake('cgi', 'cgi-fcgi '.PHP_VERSION)));
    }

    public function test_failing_or_malformed_probes_are_refused(): void
    {
        $this->refused('probe_failed', $this->fpm($this->fake('exit', 'cli '.PHP_VERSION, 64)));
        $this->refused('probe_failed', $this->fpm($this->fake('silent', '')));
        $this->refused('probe_failed', $this->fpm($this->fake('extra', 'cli '.PHP_VERSION.' extra')));
        $this->refused('probe_failed', $this->fpm($this->fake('newline', "cli ".PHP_VERSION."\n")));
        $stderr = $this->directory.'/stderr';
        file_put_contents($stderr, "#!/bin/sh\nprintf 'warning' >&2\nprintf '%s' ".escapeshellarg('cli '.PHP_VERSION)."\n");
        chmod($stderr, 0700);
        $this->refused('probe_failed', $this->fpm($stderr));
    }
}
