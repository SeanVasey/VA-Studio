<?php

use App\Domain\SiteBuilder\Models\SitePublication;
use Illuminate\Http\Request;
use Tests\Support\OrderFixtures;

require_once __DIR__.'/unpaid-release-fixture.php';

/** Enables synthetic order preparation only for an explicitly scoped disposable inquiry journey. */
final class OrderInquiryBrowserFixture
{
    public static function serve(): never
    {
        try {
            UnpaidReleaseBrowserFixture::check(PHP_SAPI === 'cli-server'
                && ($_SERVER['HTTP_HOST'] ?? '') === '127.0.0.1:8173' && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1');
            $directory = UnpaidReleaseBrowserFixture::directory();
            $capability = $_SERVER['HTTP_X_VASEY_ORDER_INQUIRY_FIXTURE'] ?? '';
            UnpaidReleaseBrowserFixture::check(is_string($capability) && preg_match('/\A[a-f0-9]{64}\z/D', $capability) === 1);
            $marker = getenv('VASEY_BROWSER_INQUIRY_MARKER');
            UnpaidReleaseBrowserFixture::check(is_string($marker) && preg_match('/\A[a-f0-9]{64}\z/D', $marker) === 1);
            UnpaidReleaseBrowserFixture::check(UnpaidReleaseBrowserFixture::json($directory.'/inquiry-fixture-marker.json', 65536) === [
                'marker' => $marker, 'database' => $directory.'/database.sqlite', 'origin' => 'http://127.0.0.1:8173', 'operatorId' => 1,
            ]);
            $matched = [];
            foreach (array_keys(UnpaidReleaseBrowserFixture::PROJECTS) as $project) {
                $path = $directory.'/inquiry-conversation-'.$project.'.json';
                if (! file_exists($path)) {
                    continue;
                }
                $fixture = UnpaidReleaseBrowserFixture::json($path, 65536);
                if (($fixture['marker'] ?? null) === $marker && ($fixture['project'] ?? null) === $project
                    && is_string($fixture['orderSupport']['capability'] ?? null)
                    && hash_equals($fixture['orderSupport']['capability'], $capability)) {
                    $matched[] = $fixture;
                }
            }
            UnpaidReleaseBrowserFixture::check(count($matched) === 1);
            $method = $_SERVER['REQUEST_METHOD'] ?? '';
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
            $allowed = ($method === 'POST' && ($uri === '/quotes' || $uri === '/orders' || preg_match('~\A/quotes/'.$uuid.'/pricing\z~D', $uri)))
                || ($method === 'GET' && preg_match('~\A/quotes/'.$uuid.'/(?:pricing|order-review)\z~D', $uri));
            UnpaidReleaseBrowserFixture::check($allowed && ($_SERVER['QUERY_STRING'] ?? '') === '');
            UnpaidReleaseBrowserFixture::check(! file_exists(__DIR__.'/../../public/hot') && ! file_exists(__DIR__.'/../../storage/framework/maintenance.php'));
            define('LARAVEL_START', microtime(true));
            require_once __DIR__.'/../../vendor/autoload.php';
            $app = require __DIR__.'/../../bootstrap/app.php';
            $app->booted(function () use ($directory, $matched): void {
                UnpaidReleaseBrowserFixture::effective($directory);
                UnpaidReleaseBrowserFixture::check(config('inquiries.test_order_inquiries_enabled') === true
                    && config('inquiries.enabled') === true && config('inquiries.operator_user_id') === '1'
                    && SitePublication::findOrFail(1)->active_release_id === $matched[0]['releaseId']);
                OrderFixtures::configure();
            });
            $app->handleRequest(Request::capture());
            exit;
        } catch (Throwable) {
            http_response_code(503);
            header('Cache-Control: private, no-store');
            echo 'Isolated order inquiry fixture refused.';
            exit;
        }
    }
}
