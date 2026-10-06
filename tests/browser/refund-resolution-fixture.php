<?php

use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\RefundResolution\RefundResolutionPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/unpaid-release-fixture.php';

/** Request-scoped synthetic GET transports, reachable only from the isolated browser router. */
final class RefundResolutionBrowserFixture
{
    public const COMPONENT = 'App\\Filament\\Resources\\TestPaymentExceptionResource\\Pages\\ListTestPaymentExceptions';

    public static function directory(): string
    {
        $directory = UnpaidReleaseBrowserFixture::directory();
        self::check(in_array(getenv('VASEY_TEST_REFUND_RESOLUTION_ENABLED'), [false, '', 'false'], true)
            && in_array(getenv('VASEY_TEST_REFUND_RESOLUTION_POLICY'), [false, ''], true));

        return $directory;
    }

    public static function effective(string $directory): void
    {
        UnpaidReleaseBrowserFixture::effective($directory);
        self::check(config('refund-resolution.enabled') === false && in_array(config('refund-resolution.policy'), [null, ''], true));
    }

    public static function manifest(string $directory, string $project): array
    {
        self::check(array_key_exists($project, UnpaidReleaseBrowserFixture::PROJECTS));
        $fixture = UnpaidReleaseBrowserFixture::json($directory.'/refund-resolution-'.$project.'.json', 8388608);
        self::check(($fixture['purpose'] ?? null) === 'test-refund-browser-v1' && ($fixture['project'] ?? null) === $project
            && ($fixture['marker'] ?? null) === getenv('VASEY_BROWSER_EXCEPTION_MARKER')
            && ($fixture['database'] ?? null) === $directory.'/database.sqlite' && ($fixture['operatorId'] ?? null) === 1
            && preg_match('/\A[a-f0-9]{64}\z/D', $fixture['capability'] ?? '') === 1
            && array_keys($fixture['records'] ?? []) === ['refunded', 'partial']);
        foreach ($fixture['records'] as $case => $record) {
            $suffix = UnpaidReleaseBrowserFixture::PROJECTS[$project].strtoupper($case);
            self::check(($record['session']['id'] ?? null) === 'cs_test_BROWSERREFUND'.$suffix
                && ($record['payment']['id'] ?? null) === 'pi_BROWSERREFUND'.$suffix
                && ($record['session']['payment_intent'] ?? null) === $record['payment']['id']
                && ($record['financial']['payment']['id'] ?? null) === $record['payment']['id']);
        }
        UnpaidReleaseBrowserFixture::privateFile($directory.'/refund-resolution-'.$project.'.jsonl', 16384, true);

        return $fixture;
    }

    /** The ordinary signed snapshot, session, CSRF and Filament authorization still execute. */
    public static function serve(): never
    {
        try {
            self::check(PHP_SAPI === 'cli-server' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
                && ($_SERVER['HTTP_HOST'] ?? '') === '127.0.0.1:8173' && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
                && ($_SERVER['QUERY_STRING'] ?? '') === ''
                && preg_match('~\A/livewire(?:-[A-Za-z0-9]+)?/update\z~D', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '') === 1
                && str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json'));
            self::check(preg_match('/\A(chromium-desktop|webkit-mobile):([a-f0-9]{64})\z/D', $_SERVER['HTTP_X_VASEY_REFUND_FIXTURE'] ?? '', $parts) === 1);
            $directory = self::directory();
            $fixture = self::manifest($directory, $parts[1]);
            self::check(hash_equals($fixture['capability'], $parts[2]));
            $input = fopen('php://input', 'rb');
            $body = is_resource($input) ? stream_get_contents($input, 131073) : false;
            if (is_resource($input)) {
                fclose($input);
            }
            self::check(is_string($body) && strlen($body) <= 131072);
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            self::check(is_array($payload['components'] ?? null) && count($payload['components']) === 1
                && is_string($payload['components'][0]['snapshot'] ?? null));
            $snapshot = json_decode($payload['components'][0]['snapshot'], true, 32, JSON_THROW_ON_ERROR);
            self::check(($snapshot['memo']['name'] ?? null) === self::COMPONENT);
            self::check(! file_exists(__DIR__.'/../../public/hot') && ! file_exists(__DIR__.'/../../storage/framework/maintenance.php'));
            define('LARAVEL_START', microtime(true));
            require_once __DIR__.'/../../vendor/autoload.php';
            $app = require __DIR__.'/../../bootstrap/app.php';
            $app->booted(function () use ($app, $directory, $fixture): void {
                self::effective($directory);
                config(['refund-resolution.enabled' => true,
                    'refund-resolution.policy' => json_encode(RefundResolutionPolicy::CONTRACT, JSON_THROW_ON_ERROR),
                    'payments.stripe.processing_enabled' => true]);
                $gateway = self::gateway($directory, $fixture);
                $app->instance(StripePaymentGateway::class, $gateway);
                $app->instance(StripeFinancialInspectionGateway::class, $gateway);
            });
            $app->handleRequest(Request::capture());
            exit;
        } catch (Throwable) {
            http_response_code(503);
            header('Cache-Control: no-store');
            echo 'Isolated refund-resolution fixture refused.';
            exit;
        }
    }

    private static function gateway(string $directory, array $fixture): StripePaymentGateway
    {
        return new class($directory, $fixture) implements StripeFinancialInspectionGateway, StripePaymentGateway
        {
            public function __construct(private string $directory, private array $fixture) {}

            public function account(): array
            {
                $this->record('account');

                return ['object' => 'account', 'id' => UnpaidReleaseBrowserFixture::ACCOUNT];
            }

            public function retrieve(string $sessionId): array
            {
                $record = $this->find('session', $sessionId);
                $this->record('retrieve');

                return $record['session'];
            }

            public function paymentIntent(string $paymentIntentId): array
            {
                $record = $this->find('payment', $paymentIntentId);
                $this->record('payment_intent');

                return $record['payment'];
            }

            public function financialState(string $paymentIntentId): array
            {
                $record = $this->find('payment', $paymentIntentId);
                $this->record('financial_state');

                return $record['financial'];
            }

            private function find(string $kind, string $id): array
            {
                foreach ($this->fixture['records'] as $record) {
                    if ($record[$kind]['id'] === $id) {
                        return $record;
                    }
                }
                throw new RuntimeException('Synthetic provider identity mismatch.');
            }

            private function record(string $operation): void
            {
                RefundResolutionBrowserFixture::check(DB::transactionLevel() === 0);
                $path = $this->directory.'/refund-resolution-'.$this->fixture['project'].'.jsonl';
                UnpaidReleaseBrowserFixture::privateFile($path, 16384, true);
                $line = json_encode(['operation' => $operation, 'transactionLevel' => 0], JSON_THROW_ON_ERROR)."\n";
                RefundResolutionBrowserFixture::check(file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === strlen($line));
            }
        };
    }

    public static function check(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Isolated refund-resolution fixture refused.');
        }
    }
}
