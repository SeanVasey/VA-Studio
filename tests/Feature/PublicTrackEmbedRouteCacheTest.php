<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PublicTrackEmbedRouteCacheTest extends TestCase
{
    public static function routeModes(): array
    {
        return ['uncached' => [false], 'cached' => [true]];
    }

    #[DataProvider('routeModes')]
    public function test_fresh_boots_preserve_ip_only_separate_embed_budgets(bool $cached): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir().'/vasey-embed-cache-'.bin2hex(random_bytes(12));
        $this->assertTrue(mkdir($directory, 0700));
        foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
            mkdir($directory.'/'.$path, 0700, true);
        }
        touch($directory.'/database.sqlite');
        $environment = [
            'VASEY_EMBED_CACHE_DIRECTORY' => $directory,
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'true',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_URL' => 'https://audio.example.test',
            'APP_CONFIG_CACHE' => $directory.'/config.php',
            'APP_ROUTES_CACHE' => $directory.'/routes.php',
            'APP_SERVICES_CACHE' => $directory.'/services.php',
            'APP_PACKAGES_CACHE' => $directory.'/packages.php',
            'APP_EVENTS_CACHE' => $directory.'/events.php',
            'LARAVEL_STORAGE_PATH' => $directory,
            'VIEW_COMPILED_PATH' => $directory.'/framework/views',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
            'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'stderr',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
        ];

        try {
            if ($cached) {
                $compiled = $this->worker($root, $environment, 'cache');
                $this->assertSame(['cached' => true], $compiled);
                $this->assertFileExists($directory.'/routes.php');
            } else {
                $this->assertFileDoesNotExist($directory.'/routes.php');
            }

            // A distinct process must load the compiled collection; the cache-building
            // process has already executed the route file and cannot reveal this bug.
            $result = $this->worker($root, $environment, 'requests');
            $this->assertSame($cached, $result['cached']);
            $this->assertSame(0, $result['authGuardCalls']);
            $this->assertSame([], $result['privacyFailures']);
            $this->assertSame([
                'embeds.show' => ['throttle:public-track-embed'],
                'embeds.preview' => ['throttle:public-track-embed-audio'],
            ], $result['middleware']);

            $expected = [];
            for ($attempt = 1; $attempt <= 121; $attempt++) {
                $expected[] = [$attempt <= 120 ? 404 : 429, max(0, 120 - $attempt), 120];
            }
            // The same IP receives an independent audio budget after exhausting pages.
            for ($attempt = 1; $attempt <= 241; $attempt++) {
                $expected[] = [$attempt <= 240 ? 404 : 429, max(0, 240 - $attempt), 240];
            }
            // A different IP is not charged to either exhausted budget.
            $expected[] = [404, 119, 120];
            $expected[] = [404, 239, 240];
            $this->assertCount(count($expected), $result['responses']);
            foreach ($expected as $index => [$status, $remaining, $limit]) {
                $this->assertSame([$status, $remaining, $limit], $result['responses'][$index], 'Request '.($index + 1));
            }
            $this->assertSame(2, $result['genericRateLimited']);
            $this->assertSame(2, $result['retryAfterCount']);
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    private function worker(string $root, array $environment, string $operation): array
    {
        $process = new Process([PHP_BINARY, $root.'/tests/Support/public-embed-route-cache-worker.php', $operation], $root, $environment, timeout: 60);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
    }
}
