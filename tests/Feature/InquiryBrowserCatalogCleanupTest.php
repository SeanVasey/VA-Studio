<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InquiryBrowserCatalogCleanupTest extends TestCase
{
    private string $directory;

    private array $environment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()).'/vasey-browser-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        foreach (['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private'] as $child) {
            mkdir($this->directory.'/'.$child, 0700, true);
        }
        touch($this->directory.'/database.sqlite');
        chmod($this->directory.'/database.sqlite', 0600);
        $this->environment = [
            'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8173', 'ASSET_URL' => '',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_CONFIG_CACHE' => $this->directory.'/config.php',
            'APP_ROUTES_CACHE' => $this->directory.'/routes.php', 'APP_EVENTS_CACHE' => $this->directory.'/events.php',
            'LARAVEL_STORAGE_PATH' => $this->directory, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->directory.'/database.sqlite',
            'DB_URL' => '', 'DB_FOREIGN_KEYS' => 'true', 'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'single', 'LOG_LEVEL' => 'error', 'FILESYSTEM_DISK' => 'local',
            'STRIPE_MODE' => 'test', 'STRIPE_ACCOUNT_ID' => 'acct_SYNTHETICONLY', 'STRIPE_WEBHOOK_ENABLED' => 'false',
            'STRIPE_TEST_CHECKOUT_ENABLED' => 'false', 'STRIPE_TEST_PAYMENT_PROCESSING_ENABLED' => 'false',
            'STRIPE_TEST_FINALIZATION_ENABLED' => 'false', 'STRIPE_TEST_SECRET_KEY' => '', 'STRIPE_WEBHOOK_SECRET' => '',
            'CONTACT_INQUIRIES_ENABLED' => 'true', 'CONTACT_TEST_ORDER_INQUIRIES_ENABLED' => 'true', 'CONTACT_INQUIRIES_OPERATOR_ID' => '1',
            'CONTACT_INQUIRIES_PRIVACY_NOTICE' => 'Synthetic browser privacy notice. Inquiries are saved privately for verification.',
            'CONTACT_INQUIRIES_RETENTION_REFERENCE' => 'SYNTHETIC-BROWSER-ONLY', 'VASEY_BROWSER_DIRECTORY' => $this->directory,
            'VASEY_BROWSER_INQUIRY_MARKER' => bin2hex(random_bytes(32)), 'VASEY_INQUIRY_CATALOG_PROBE' => 'isolated-subprocess-regression',
            'VASEY_INQUIRY_CATALOG_UNRELATED' => '0',
        ];
    }

    protected function tearDown(): void
    {
        try {
            (new Filesystem)->deleteDirectory($this->directory);
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('catalogs')]
    public function test_real_helper_restores_the_catalog_without_changing_private_history_or_repeating_withdrawal(bool $unrelated): void
    {
        $this->environment['VASEY_INQUIRY_CATALOG_UNRELATED'] = $unrelated ? '1' : '0';
        $fixture = $this->worker('prepare');
        $before = $this->worker('snapshot');
        $this->assertCount($unrelated ? 2 : 1, $before['catalog']);
        $this->assertSame(2, $before['inquiries']);
        $this->assertSame(1, $before['contexts']);
        $this->assertSame(['restored' => true], $this->restore());
        $after = $this->worker('snapshot');
        $this->assertSame($unrelated ? [(string) $fixture['unrelatedId']] : [], array_column($after['catalog'], 'id'));
        foreach (['commerceHash', 'inquiryHash', 'filesHash'] as $evidence) {
            $this->assertSame($before[$evidence], $after[$evidence]);
        }
        $track = array_column($after['tracks'], null, 'id')[$fixture['trackId']];
        $this->assertSame('draft', $track['status']);
        $this->assertSame($fixture['publicationVersion'] + 1, $track['publication_version']);
        $this->assertSame($track['slug'], $track['published_slug']);
        $this->assertSame($fixture['restoreReleaseId'], $after['publication']['active_release_id']);
        $this->assertCount(1, $after['withdrawals']);
        $this->assertSame($fixture['trackId'], $after['withdrawals'][0]['subject_id']);
        $this->assertSame(1, $after['withdrawals'][0]['actor_id']);
        if ($unrelated) {
            $this->assertSame(array_column($before['tracks'], null, 'id')[$fixture['unrelatedId']],
                array_column($after['tracks'], null, 'id')[$fixture['unrelatedId']]);
        }
        $this->assertSame(['restored' => true], $this->restore());
        $this->assertSame($after, $this->worker('snapshot'));
    }

    public static function catalogs(): array
    {
        return ['empty ordinary catalog' => [false], 'unrelated ready recording' => [true]];
    }

    #[DataProvider('changedIdentities')]
    public function test_changed_private_catalog_identity_refuses_restoration_without_effects(string $field): void
    {
        $this->environment['VASEY_INQUIRY_CATALOG_UNRELATED'] = '1';
        $prepared = $this->worker('prepare');
        $path = $this->directory.'/inquiry-conversation-chromium-desktop.json';
        $fixture = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if ($field === 'trackId') {
            $fixture['orderSupport']['items'][0]['trackId'] = $prepared['unrelatedId'];
        } elseif ($field === 'publicationVersion') {
            $fixture['orderSupport']['publicationVersion']++;
        } else {
            $fixture['orderSupport']['slug'] .= '-changed';
        }
        file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
        $before = $this->worker('snapshot');
        $result = $this->runWorkerProcess('tests/browser/prepare-contact-inquiry.php', 'conversation-restore');
        $this->assertSame(1, $result->getExitCode());
        $this->assertSame('', $result->getOutput());
        $this->assertSame("Isolated inquiry fixture operation failed; no private details are printed.\n", $result->getErrorOutput());
        $this->assertSame($before, $this->worker('snapshot'));
        $this->assertSame($fixture, json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR));
    }

    public static function changedIdentities(): array
    {
        return [['trackId'], ['slug'], ['publicationVersion']];
    }

    public function test_failed_withdrawal_audit_keeps_the_ready_track_site_release_and_private_history_unchanged(): void
    {
        $this->worker('prepare');
        $this->assertSame(['installed' => true], $this->worker('fail-withdrawal-audit'));
        $before = $this->worker('snapshot');
        $result = $this->runWorkerProcess('tests/browser/prepare-contact-inquiry.php', 'conversation-restore');
        $this->assertSame(1, $result->getExitCode());
        $this->assertSame('', $result->getOutput());
        $this->assertSame("Isolated inquiry fixture operation failed; no private details are printed.\n", $result->getErrorOutput());
        $this->assertSame($before, $this->worker('snapshot'));
        $fixture = json_decode(file_get_contents($this->directory.'/inquiry-conversation-chromium-desktop.json'), true, 16, JSON_THROW_ON_ERROR);
        $this->assertFalse($fixture['restored']);
    }

    private function worker(string $operation): array
    {
        $result = $this->runWorkerProcess('tests/Support/inquiry-browser-catalog-cleanup-worker.php', $operation);
        $this->assertSame(0, $result->getExitCode(), 'Isolated cleanup regression worker failed: '.$result->getErrorOutput());
        $this->assertSame('', $result->getErrorOutput());

        return json_decode($result->getOutput(), true, 32, JSON_THROW_ON_ERROR);
    }

    private function restore(): array
    {
        $result = $this->runWorkerProcess('tests/browser/prepare-contact-inquiry.php', 'conversation-restore');
        $this->assertSame(0, $result->getExitCode(), 'The actual guarded inquiry restore helper failed.');
        $this->assertSame('', $result->getErrorOutput());

        return json_decode($result->getOutput(), true, 8, JSON_THROW_ON_ERROR);
    }

    private function runWorkerProcess(string $script, string $operation): Process
    {
        $process = new Process([PHP_BINARY, '-d', 'variables_order=EGPCS', $script, $operation, 'chromium-desktop'], base_path(), $this->environment);
        $process->setTimeout(60)->run();

        return $process;
    }
}
