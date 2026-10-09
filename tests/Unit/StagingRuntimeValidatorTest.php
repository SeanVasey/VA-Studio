<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class StagingRuntimeValidatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/vasey-runtime-test-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function profile(): string
    {
        return implode("\n", [
            'APP_ENV=local', 'APP_DEBUG=false', 'APP_URL=https://staging.synthetic.invalid',
            'APP_KEY=base64:'.str_repeat('A', 43).'=', 'APP_MAINTENANCE_DRIVER=file',
            'SESSION_DRIVER=database', 'SESSION_SECURE_COOKIE=true', 'SESSION_HTTP_ONLY=true',
            'CACHE_STORE=database', 'QUEUE_CONNECTION=database', 'DB_QUEUE_RETRY_AFTER=1200',
            'DB_CONNECTION=mysql', 'DB_HOST=127.0.0.1', 'DB_DATABASE=vasey_staging',
            'DB_USERNAME=vasey_app', 'DB_PASSWORD=SyntheticPrivateRuntimeMarker',
            'FILESYSTEM_DISK=local', 'MAIL_MAILER=log', 'STRIPE_MODE=test', '',
        ]);
    }

    private function validate(string $profile, ?string $previous = null): Process
    {
        $file = $this->directory.'/candidate.env';
        file_put_contents($file, $profile);
        $arguments = [PHP_BINARY, dirname(__DIR__, 2).'/ops/staging/validate-runtime.php', $file,
            'local', 'staging.synthetic.invalid', 'vasey_staging'];
        if ($previous !== null) {
            $previousFile = $this->directory.'/previous.env';
            file_put_contents($previousFile, $previous);
            $arguments[] = $previousFile;
        }
        $environment = [];
        foreach (array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]) as $name) {
            if (is_string($name) && $name !== '') {
                $environment[$name] = false;
            }
        }
        $environment['PATH'] = getenv('PATH');
        $process = new Process($arguments, dirname(__DIR__, 2), $environment);
        $process->setTimeout(30);
        $process->run();
        $this->assertStringNotContainsString('SyntheticPrivateRuntimeMarker', $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString($file, $process->getOutput().$process->getErrorOutput());

        return $process;
    }

    public function test_valid_profile_is_checked_against_real_configuration_without_provider_io(): void
    {
        $process = $this->validate($this->profile(), $this->profile());
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('PASS runtime.profile', $process->getOutput());
    }

    public static function unsafeProfiles(): array
    {
        return [
            'quoted true' => ['PRODUCTION_CHECKOUT_ENABLED="true"', 'runtime.production_fresh_checkout_enabled'],
            'single-quoted true' => ["PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED='true'", 'runtime.production_provider_io_enabled'],
            'parenthesized true' => ['PRODUCTION_CHECKOUT_HTTP_ENABLED=(true)', 'runtime.production_http_enabled'],
            'interpolated true' => ["BAD_FLAG=true\nPRODUCTION_CHECKOUT_ENABLED=\"\${BAD_FLAG}\"", 'runtime.production_fresh_checkout_enabled'],
            'duplicates' => ['APP_DEBUG=true', 'runtime.unique_keys'],
            'database URL override' => ['DB_URL=mysql://SyntheticPrivateRuntimeMarker@foreign.invalid/foreign', 'runtime.database'],
            'socket override' => ['DB_SOCKET=/tmp/synthetic-foreign.sock', 'runtime.database'],
            'config cache override' => ['APP_CONFIG_CACHE=/tmp/synthetic-foreign.php', 'runtime.no_cache_override'],
            'production key' => ['PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY=sk_test_'.'SyntheticPrivateRuntimeMarker', 'runtime.production_credentials'],
            'live funds' => ['PRODUCTION_CHECKOUT_FUNDS_MODE=live', 'runtime.production_credentials'],
            'live key value' => ['UNRELATED_KEY=sk_'.'live_'.'SyntheticPrivateRuntimeMarker', 'runtime.no_live_keys'],
        ];
    }

    #[DataProvider('unsafeProfiles')]
    public function test_unsafe_profile_is_refused_without_secret_or_path_projection(string $extra, string $failure): void
    {
        $process = $this->validate($this->profile().$extra."\n");
        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('FAIL '.$failure, $process->getErrorOutput());
        $this->assertStringNotContainsString('PASS runtime.profile', $process->getOutput());
    }

    public function test_replacing_the_served_key_is_refused_before_configuration_changes(): void
    {
        $candidate = str_replace(str_repeat('A', 43), str_repeat('B', 43), $this->profile());
        $process = $this->validate($candidate, $this->profile());
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('FAIL runtime.key_custody', $process->getErrorOutput());
    }
}
