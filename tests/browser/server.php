<?php

// Test-only same-origin transport. This router is never loaded by public/index.php.
// Serve only the fixed synthetic attachment; Laravel feature tests verify real delivery authorization.
$directory = getenv('VASEY_BROWSER_DIRECTORY');
if (PHP_SAPI !== 'cli-server' || getenv('APP_ENV') !== 'local' || getenv('APP_URL') !== 'http://127.0.0.1:8173'
    || ! is_string($directory) || is_link($directory) || realpath($directory) !== $directory
    || realpath(dirname($directory)) !== realpath(sys_get_temp_dir()) || ! preg_match('/\Avasey-browser-[A-Za-z0-9]+\z/D', basename($directory))
    || getenv('LARAVEL_STORAGE_PATH') !== $directory || getenv('DB_DATABASE') !== $directory.'/database.sqlite'
    // Match Laravel Application::storagePath(), not only getenv(): GPCS cli-server otherwise uses checkout storage.
    || ($_ENV['LARAVEL_STORAGE_PATH'] ?? $_SERVER['LARAVEL_STORAGE_PATH'] ?? null) !== $directory
    || getenv('APP_CONFIG_CACHE') !== $directory.'/config.php' || ! is_file($directory.'/fixtures.json')) {
    http_response_code(503);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/orders/77000000-0000-4000-8000-000000000001/delivery/download') {
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); http_response_code(405); exit; }
    if (($_SERVER['QUERY_STRING'] ?? '') !== '') { http_response_code(422); exit; }
    if (($_SERVER['CONTENT_TYPE'] ?? '') !== 'application/x-www-form-urlencoded') { http_response_code(415); exit; }
    $input = fopen('php://input', 'rb');
    $body = is_resource($input) ? stream_get_contents($input, 4097) : false;
    if (is_resource($input)) { fclose($input); }
    if ($body === false) { http_response_code(503); exit; }
    if (strlen($body) > 4096) { http_response_code(413); exit; }
    $fields = [];
    foreach (explode('&', $body) as $part) {
        if (substr_count($part, '=') !== 1 || preg_match('/%(?![0-9a-fA-F]{2})/', $part)) { http_response_code(422); exit; }
        [$key, $value] = array_map('urldecode', explode('=', $part, 2));
        if (! in_array($key, ['authorizationId', 'token', '_token'], true) || array_key_exists($key, $fields)) { http_response_code(422); exit; }
        $fields[$key] = $value;
    }
    if (count($fields) !== 3 || ($fields['authorizationId'] ?? '') !== '77000000-0000-4000-8000-000000000003'
        || ($fields['token'] ?? '') !== str_pad('SYNTHETIC_BROWSER_ONLY_PRIVATE_TOKEN', 43, '0')
        || preg_match('/\A[A-Za-z0-9]{40}\z/D', $fields['_token'] ?? '') !== 1) { http_response_code(422); exit; }
    // Retain only the received-body hash in the wrapper-owned disposable directory.
    $receipt = $directory.'/native-attachment.sha256';
    if (is_link($receipt) || file_put_contents($receipt, hash('sha256', $body), LOCK_EX) !== 64) { http_response_code(503); exit; }
    chmod($receipt, 0600);
    $bytes = "%PDF-1.4\nSYNTHETIC TEST ONLY\n%%EOF\n";
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="77000000-0000-4000-8000-000000000002-contract.pdf"');
    header('Content-Length: '.strlen($bytes));
    echo $bytes;
    exit;
}

// A private test capability may bind only the unpaid component's synthetic GET transport.
// Login, other Livewire components and public/index.php retain the ordinary disabled providers.
if (isset($_SERVER['HTTP_X_VASEY_UNPAID_FIXTURE'])) {
    require __DIR__.'/unpaid-release-fixture.php';
    UnpaidReleaseBrowserFixture::serve();
}

// A separate private capability binds only the refund-resolution component's GET-only test transport.
if (isset($_SERVER['HTTP_X_VASEY_REFUND_FIXTURE'])) {
    require __DIR__.'/refund-resolution-fixture.php';
    RefundResolutionBrowserFixture::serve();
}

// Reuse Laravel's ordinary static-file/front-controller routing for every application route.
if (isset($_SERVER['HTTP_X_VASEY_ORDER_INQUIRY_FIXTURE'])) {
    require __DIR__.'/order-inquiry-fixture.php';
    OrderInquiryBrowserFixture::serve();
}

// All requests without a private fixture capability use the ordinary front controller.
$testRoot = dirname(__DIR__, 2);
chdir($testRoot.'/public');
return require $testRoot.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
