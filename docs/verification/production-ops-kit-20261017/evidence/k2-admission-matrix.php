<?php
// Boots the real application with APP_ENV=$argv[1] and ONE capability flag set to true (argv[2]), then asks the real policy
// classes whether that capability is admitted. No database, network or host is touched.
//   php admission-matrix.php ENV FLAG [alone|complete|bound]
//   alone     the flag only; complete  also the policy JSON or companion settings that policy needs;
//   bound     for the two flags that also need an adapter bound by other code, a stand-in is bound.
// Run from the repository root with a built vendor/. Evidence for batch K2, not a test.
declare(strict_types=1);

$root = dirname(__DIR__, 4);
[$script, $environment, $flag, $companions] = $argv + [null, 'staging', '', 'alone'];
foreach ([
    'APP_ENV' => $environment, 'APP_DEBUG' => 'false', 'APP_URL' => 'https://staging.example.invalid',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)), 'STRIPE_MODE' => 'test', 'STRIPE_ACCOUNT_ID' => 'acct_MatrixSynthetic',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'APP_CONFIG_CACHE' => '/nonexistent/no-config-cache.php', 'LOG_CHANNEL' => 'null',
] as $k => $v) { putenv("$k=$v"); $_ENV[$k] = $_SERVER[$k] = $v; }
require $root.'/vendor/autoload.php';
$set = static function (string $k, string $v): void { putenv("$k=$v"); $_ENV[$k] = $_SERVER[$k] = $v; };
if ($flag !== '') {
    $set($flag, 'true');
    if ($companions === 'complete') {
        // Everything else the policy needs, so the environment gate is the only variable.
        match ($flag) {
            'VASEY_TEST_UNPAID_RELEASE_ENABLED' => $set('VASEY_TEST_UNPAID_RELEASE_POLICY', json_encode(App\Domain\Commerce\UnpaidRelease\UnpaidReleasePolicy::CONTRACT)),
            'VASEY_TEST_REFUND_RESOLUTION_ENABLED' => $set('VASEY_TEST_REFUND_RESOLUTION_POLICY', json_encode(App\Domain\Commerce\RefundResolution\RefundResolutionPolicy::CONTRACT)),
            'VASEY_TEST_TRANSACTIONAL_NOTIFICATIONS_ENABLED' => array_map(fn ($k, $v) => $set($k, $v),
                ['VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED', 'VASEY_TEST_CUSTOMER_IDENTITY_ENABLED', 'VASEY_TEST_TRANSACTIONAL_NOTIFICATION_TRANSPORT', 'VASEY_TEST_TRANSACTIONAL_NOTIFICATION_POLICY'],
                ['true', 'true', 'private_capture', App\Domain\Notifications\TransactionalNotificationPolicy::VERSION]),
            default => null,
        };
    }
}
$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath(sys_get_temp_dir());   // no .env is read
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($companions === 'bound') {
    // A reviewed adapter bound by someone else's change: the stand-ins implement only the interfaces' existence for the gate check.
    if ($flag === 'VASEY_OPERATIVE_FREE_GRANTS_ENABLED') { $app->instance(App\Domain\Grants\Free\FreeGrantIdentity::class, new App\Domain\Grants\Free\TestFreeGrantIdentity); }
    if ($flag === 'CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED') {
        $app->instance(App\Domain\Inquiries\Notifications\InquiryAlertTransport::class, new class implements App\Domain\Inquiries\Notifications\InquiryAlertTransport {
            public function submit(App\Domain\Inquiries\Notifications\OperatorInquiryAlert $alert): void {}
        });
    }
}
$probe = static function (callable $f): string {
    try { return $f() ? 'ADMITTED' : 'refused'; } catch (Throwable $e) { return 'refused'; }
};
$out = [
    'VASEY_TEST_SERVICE_PROJECTS_ENABLED' => $probe(fn () => app(App\Domain\Services\Projects\ServiceProjectPolicy::class)->enabled()),
    'VASEY_TEST_FREE_GRANTS_ENABLED' => $probe(fn () => app(App\Domain\Grants\Free\FreeGrantPolicy::class)->enabled()),
    'VASEY_TEST_CUSTOMER_CONSENT_ENABLED' => $probe(fn () => app(App\Domain\Customers\Preferences\LocalConsentRuntime::class)->grantsEnabled()),
    'VASEY_TEST_UNPAID_RELEASE_ENABLED' => $probe(function () { app(App\Domain\Commerce\UnpaidRelease\UnpaidReleasePolicy::class)->current(); return true; }),
    'VASEY_TEST_REFUND_RESOLUTION_ENABLED' => $probe(function () { app(App\Domain\Commerce\RefundResolution\RefundResolutionPolicy::class)->current(); return true; }),
    'VASEY_TEST_TRANSACTIONAL_NOTIFICATIONS_ENABLED' => $probe(function () { app(App\Domain\Notifications\TransactionalNotificationPolicy::class)->requireEnabled(); return true; }),
    'CONTACT_TEST_ORDER_INQUIRIES_ENABLED' => $probe(fn () => App\Support\Environment\TestEnvironment::admitsTestCommerce() && config('inquiries.test_order_inquiries_enabled') === true),
    'CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED' => $probe(fn () => app(App\Domain\Inquiries\Notifications\InquiryNotificationWork::class)->enabled()),
    'VASEY_OPERATIVE_FREE_GRANTS_ENABLED' => $probe(fn () => app(App\Domain\Grants\Free\FreeGrantPolicy::class)->enabled()),
    'VASEY_SUPPORT_ATTACHMENTS_FIXTURE_ENABLED' => $probe(fn () => App\Support\SupportAttachmentUi::enabled()),
];
echo json_encode(['env' => app()->environment(), 'flag' => $flag, 'with' => $companions, 'result' => $flag === '' ? $out : [$flag => $out[$flag]]]).PHP_EOL;
