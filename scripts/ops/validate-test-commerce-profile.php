<?php

declare(strict_types=1);

/*
 * Validates a staging test-commerce env file against the application's real policy classes.
 *
 *   php scripts/ops/validate-test-commerce-profile.php /path/to/.env
 *   php scripts/ops/validate-test-commerce-profile.php --template ops/staging/test-commerce/env.test-commerce.example
 *   php scripts/ops/validate-test-commerce-profile.php --probe --i-understand-this-calls-stripe /path/to/.env
 *
 * The given file is the only dotenv source: the host .env and any cached configuration are not
 * read. Variables already exported in this process still win, exactly as they would for Laravel.
 * Output names checks and bounded reasons only; it never prints a value from the file.
 * --template replaces each documented placeholder with a synthetic value first, so the committed
 * template can be checked for shape. --probe additionally makes one read-only GET /v1/account with
 * the configured test key, and only after every other check passed.
 * Exit codes: 0 every check passed, 1 at least one check failed, 2 usage or unreadable file.
 */

use App\Domain\Commerce\Checkout\CheckoutPolicy;
use App\Domain\Commerce\Finalization\FinalizationPolicy;
use App\Domain\Commerce\Inventory\InventoryPolicy;
use App\Domain\Commerce\Orders\OrderPolicy;
use App\Domain\Commerce\Payments\PaymentProcessingPolicy;
use App\Domain\Commerce\Payments\StripeSdkCheckoutGateway;
use App\Domain\Commerce\Payments\VerifyStripeWebhook;
use App\Domain\Commerce\PricingPolicy;
use App\Domain\Commerce\PromotionPolicy;
use App\Domain\Contracts\ContractIssuancePolicy;
use App\Domain\Contracts\ContractRenderProfile;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerIdentityPolicy;
use App\Domain\Customers\CustomerPurchaseClaimPolicy;
use App\Domain\Delivery\ActivationPolicy;
use App\Domain\Delivery\TestAccessPolicy;
use Dotenv\Parser\Parser;
use Illuminate\Contracts\Console\Kernel;

ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');

const PROFILE_MAX_BYTES = 262144;

/** Synthetic stand-ins used only by --template. None is a real identifier or credential. */
const TEMPLATE_VALUES = [
    '<STAGING_HOST>' => 'staging.example.invalid',
    '<acct_ID>' => 'acct_TemplateSyntheticOnly',
    '<sk_test_KEY>' => 'sk_test_TemplateSyntheticOnly0000',
    '<whsec_SECRET>' => 'whsec_TemplateSyntheticOnly0000',
    '<SELLER_LEGAL_NAME>' => 'Synthetic Template Seller',
    '<ASSENT_TEXT>' => 'Synthetic template assent text for test orders only.',
];

/** Families outside this profile. Each must not be strictly true. */
const OUT_OF_PROFILE_FLAGS = [
    'production_checkout.http_enabled', 'production_checkout.fresh_checkout_enabled',
    'production_checkout.reconciliation_enabled', 'production_checkout.provider_io_enabled',
    'production_checkout.exemption_authoring_enabled', 'production_checkout.committed_read_receipts_enabled',
    'production-tax-checkout.enabled', 'free-grants.test_enabled', 'free-grants.operative_enabled',
    'memberships.test_mode_enabled', 'refund-resolution.enabled', 'unpaid-release.enabled',
    'transactional-notifications.test_enabled', 'services-projects.test_enabled',
    'customer-preferences.test_grants_enabled',
];

$usage = static function (string $reason): never {
    fwrite(STDERR, 'USAGE: php scripts/ops/validate-test-commerce-profile.php [--template] [--probe --i-understand-this-calls-stripe] ENV_FILE'.PHP_EOL);
    fwrite(STDERR, 'ERROR: '.$reason.PHP_EOL);
    exit(2);
};

$template = false;
$probe = false;
$confirmed = false;
$file = null;
foreach (array_slice($argv, 1) as $argument) {
    match ($argument) {
        '--template' => $template = true,
        '--probe' => $probe = true,
        '--i-understand-this-calls-stripe' => $confirmed = true,
        default => str_starts_with($argument, '-') || $file !== null
            ? $usage('unknown or repeated argument') : $file = $argument,
    };
}
if ($file === null) {
    $usage('an env file is required');
}
if ($probe !== $confirmed) {
    $usage('--probe and --i-understand-this-calls-stripe must be given together');
}
if ($probe && $template) {
    $usage('--probe cannot be combined with --template');
}
if (! is_file($file) || is_link($file) || ! is_readable($file) || filesize($file) > PROFILE_MAX_BYTES) {
    $usage('the env file must be a readable regular file of at most 256 KiB');
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$raw = (string) file_get_contents($file);
if ($template) {
    $raw = strtr($raw, TEMPLATE_VALUES);
}

$checks = [];
$record = static function (string $id, bool $ok, string $message) use (&$checks): void {
    $checks[] = [$id, $ok, $message];
};
$attempt = static function (string $id, string $message, callable $check) use ($record): bool {
    try {
        $ok = $check() !== false;
    } catch (Throwable) {
        $ok = false;
    }
    $record($id, $ok, $message);

    return $ok;
};

// ---- File-level checks: parsed with the same dotenv parser Laravel uses. Values never printed.
try {
    $entries = (new Parser)->parse($raw);
} catch (Throwable) {
    $record('profile.parse', false, 'The file is not valid dotenv syntax (check quoting; JSON must not contain a single quote).');
    $entries = null;
}
$values = [];
$unreplaced = [];
if ($entries !== null) {
    $record('profile.parse', true, 'The file parses as dotenv.');
    $duplicates = [];
    foreach ($entries as $entry) {
        $name = $entry->getName();
        $value = $entry->getValue()->isDefined() ? $entry->getValue()->get()->getChars() : '';
        if (array_key_exists($name, $values)) {
            $duplicates[] = $name;
        }
        $values[$name] = $value;
    }
    $record('profile.unique_keys', $duplicates === [], $duplicates === [] ? 'Every key appears once.'
        : 'Duplicate keys (a later copy silently wins): '.implode(', ', array_unique($duplicates)).'.');
    $unreplaced = array_keys(array_filter($values, static fn (string $v): bool => preg_match('/<[A-Za-z][A-Za-z0-9_]*>/', $v) === 1));
    $record('profile.placeholders_replaced', $unreplaced === [], $unreplaced === [] ? 'No <PLACEHOLDER> remains.'
        : 'Unreplaced placeholders in: '.implode(', ', $unreplaced).'.');
    $live = array_keys(array_filter($values, static fn (string $v): bool => preg_match('/(?:sk|rk|pk)_live_/', $v) === 1));
    $record('profile.no_live_keys', $live === [], $live === [] ? 'No live-mode key appears in the parsed configuration values.'
        : 'Live-mode key material found in: '.implode(', ', $live).'. Remove it.');
}

// ---- Boot the real application with this file as its only dotenv source.
$booted = false;
if ($entries !== null && $unreplaced !== []) {
    $record('runtime.boot', false, 'Policy checks skipped: replace every placeholder first, or use --template for a shape check.');
} elseif ($entries !== null) {
    $scratch = sys_get_temp_dir().'/vasey-profile-'.bin2hex(random_bytes(8));
    $envDirectory = $scratch;
    try {
        if (! mkdir($scratch, 0700)) {
            throw new RuntimeException;
        }
        // A copy keeps --template substitutions out of the source file and fixes the dotenv name.
        $copy = $scratch.'/profile.env';
        if (file_put_contents($copy, $raw) !== strlen($raw)) {
            throw new RuntimeException;
        }
        chmod($copy, 0600);
        // Never load bootstrap/cache/config.php: it would ignore the file under test.
        foreach (['APP_CONFIG_CACHE' => $scratch.'/no-config-cache.php'] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $app = require $root.'/bootstrap/app.php';
        $app->useEnvironmentPath($envDirectory);
        $app->loadEnvironmentFrom('profile.env');
        $app->make(Kernel::class)->bootstrap();
        $booted = true;
    } catch (Throwable) {
        $record('runtime.boot', false, 'The application could not boot with this file.');
    } finally {
        foreach ([$scratch.'/profile.env', $scratch] as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
    }
}

if ($booted) {
    $record('runtime.boot', true, 'The application booted with this file only (no host .env, no cached config).');

    // ---- Runtime
    $attempt('runtime.app_env_local', 'APP_ENV is exactly local (every test-commerce gate requires local or testing; hosts never run testing).',
        fn () => app()->environment() === 'local');
    $attempt('runtime.app_debug_off', 'APP_DEBUG is false.', fn () => config('app.debug') === false);
    $attempt('runtime.app_url_equals_return_origin', 'APP_URL is an HTTPS origin equal to the checkout return_origin.', function () {
        $policy = json_decode((string) config('commerce.test_checkout_policy'), true, 16, JSON_THROW_ON_ERROR);
        $url = config('app.url');

        return is_string($url) && str_starts_with($url, 'https://') && $url === ($policy['return_origin'] ?? null);
    });
    $attempt('runtime.queue_database', 'QUEUE_CONNECTION is database (receipt, finalization and contract jobs need an asynchronous queue).',
        fn () => config('queue.default') === 'database' && config('queue.connections.database.driver') === 'database');
    $attempt('runtime.session_secure_cookie', 'SESSION_SECURE_COOKIE is true.', fn () => config('session.secure') === true);

    // ---- Stripe test mode
    $attempt('stripe.mode_test', 'STRIPE_MODE is test.', fn () => config('payments.stripe.mode') === 'test');
    $attempt('stripe.account', 'STRIPE_ACCOUNT_ID is an own-account acct_ identifier (CheckoutPolicy::account).',
        fn () => app(CheckoutPolicy::class)->account());
    $attempt('stripe.secret_key_test', 'STRIPE_TEST_SECRET_KEY is a standard sk_test_ key (same rule as StripeSdkCheckoutGateway).',
        fn () => is_string(config('payments.stripe.secret_key'))
            && preg_match('/\Ask_test_[A-Za-z0-9]{8,200}\z/', config('payments.stripe.secret_key')) === 1);
    $attempt('stripe.webhook_receiver', 'A synthetic event signed with STRIPE_WEBHOOK_SECRET passes the real VerifyStripeWebhook, and a wrongly signed one is refused.', function () {
        $secret = (string) config('payments.stripe.webhook_secret');
        $body = json_encode(['id' => 'evt_ProfileValidatorSynthetic', 'object' => 'event', 'api_version' => CheckoutPolicy::API_VERSION,
            'created' => time(), 'livemode' => false, 'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_ProfileValidatorSynthetic', 'object' => 'checkout.session', 'livemode' => false]]], JSON_THROW_ON_ERROR);
        $time = time();
        $verified = app(VerifyStripeWebhook::class)->handle($body, 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, $secret));
        try {
            app(VerifyStripeWebhook::class)->handle($body, 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, $secret.'x'));

            return false;
        } catch (Throwable) {
            return $verified->accountId === config('payments.stripe.account_id');
        }
    });

    // ---- Quote, pricing, inventory, order, checkout
    $attempt('commerce.pricing', 'VASEY_TEST_PRICING_POLICY passes PricingPolicy::current() and is in its effective window now.',
        fn () => app(PricingPolicy::class)->current() !== null);
    $attempt('commerce.pricing_zero_test_tax', 'Pricing tax is fixed_test at 0 bps for provider stripe and the configured account (CheckoutEvidence::request).', function () {
        $policy = app(PricingPolicy::class)->current();

        return $policy['tax']['mode'] === 'fixed_test' && $policy['tax']['rate_bps'] === 0
            && $policy['provider'] === 'stripe' && $policy['account'] === config('payments.stripe.account_id');
    });
    $attempt('commerce.pricing_window_margin', 'The pricing window stays open for at least 14 more days.', fn () => app(PricingPolicy::class)
        ->timestamp(app(PricingPolicy::class)->current()['effective_until'])->greaterThan(now()->addDays(14)));
    $attempt('commerce.promotions', 'VASEY_TEST_PROMOTIONS is empty, or every campaign passes PromotionPolicy.',
        fn () => is_array(app(PromotionPolicy::class)->configuredPolicies(true)));
    $attempt('commerce.inventory', 'VASEY_TEST_INVENTORY_POLICY passes InventoryPolicy::current().', fn () => app(InventoryPolicy::class)->current());
    $attempt('commerce.inventory_ttl_covers_quote', 'Inventory ttl_seconds is at least the 900-second quote lifetime.',
        fn () => app(InventoryPolicy::class)->current()['ttl_seconds'] >= 900);
    $attempt('commerce.exclusive_selection_off', 'VASEY_TEST_EXCLUSIVE_SELECTION_POLICY is empty (exclusive test activation is outside this profile).',
        fn () => in_array(config('commerce.test_exclusive_selection_policy'), [null, ''], true));
    $attempt('commerce.order', 'VASEY_TEST_ORDER_POLICY passes OrderPolicy::current().', fn () => app(OrderPolicy::class)->current());
    $attempt('commerce.checkout', 'VASEY_TEST_CHECKOUT_POLICY passes CheckoutPolicy::current() and checkout is enabled.',
        fn () => app(CheckoutPolicy::class)->current() !== [] && app(CheckoutPolicy::class)->enabled());
    $attempt('commerce.checkout_https_origin', 'The checkout return_origin is HTTPS (the loopback HTTP allowance is for local development only).',
        fn () => str_starts_with(app(CheckoutPolicy::class)->current()['return_origin'], 'https://'));

    // ---- Post-payment chain
    $attempt('payments.processing', 'Payment processing is enabled for the configured test account (PaymentProcessingPolicy::account).',
        fn () => app(PaymentProcessingPolicy::class)->account());
    $attempt('payments.finalization', 'STRIPE_TEST_FINALIZATION_POLICY equals FinalizationPolicy::CONTRACT and finalization is enabled.',
        fn () => app(FinalizationPolicy::class)->current());
    $attempt('contracts.issuance_v2', 'VASEY_TEST_CONTRACT_ISSUANCE_POLICY equals the v2 contract and issuance is enabled.',
        fn () => app(ContractIssuancePolicy::class)->current()['profile'] === 'test-buyer-pdf-v2');
    // current() verifies the PHP 8.4 runtime, pinned package references and font asset hashes.
    $attempt('contracts.renderer_runtime', 'The pinned test-buyer-pdf-v2 renderer runtime, packages and font assets verify on this PHP.',
        fn () => ContractRenderProfile::current()['version'] === 'test-buyer-pdf-v2');
    $attempt('delivery.activation', 'VASEY_TEST_FULFILLMENT_ACTIVATION_POLICY equals ActivationPolicy::CONTRACT and activation is enabled.',
        fn () => app(ActivationPolicy::class)->current());
    $attempt('delivery.access', 'VASEY_TEST_DELIVERY_ACCESS_POLICY equals TestAccessPolicy::CONTRACT and owner delivery is enabled.',
        fn () => app(TestAccessPolicy::class)->current());

    // ---- Test customer accounts
    $attempt('customer.accounts', 'Test customer accounts are enabled (CustomerAccessPolicy).', fn () => app(CustomerAccessPolicy::class)->enabled());
    $attempt('customer.identity_private_capture', 'Test identity uses private_capture: no email is sent (CustomerIdentityPolicy).',
        fn () => app(CustomerIdentityPolicy::class)->enabled());
    $attempt('customer.purchase_claims', 'Guest purchase claims are enabled (CustomerPurchaseClaimPolicy).',
        fn () => app(CustomerPurchaseClaimPolicy::class)->enabled());

    // ---- Boundary
    $enabled = array_values(array_filter(OUT_OF_PROFILE_FLAGS, static fn (string $key): bool => config($key) === true));
    $record('boundary.out_of_profile_families_off', $enabled === [], $enabled === []
        ? 'Production checkout, production tax checkout and the other test families are off.'
        : 'Enabled outside this profile: '.implode(', ', $enabled).'.');
    $attempt('boundary.no_production_checkout_key', 'No production checkout key or funds mode is configured.',
        fn () => in_array(config('production_checkout.secret_key'), [null, ''], true)
            && in_array(config('production_checkout.funds_mode'), [null, ''], true));
}

$failed = array_filter($checks, static fn (array $check): bool => ! $check[1]);
if ($probe) {
    if ($booted && $failed === []) {
        $attempt('stripe.probe_account', 'Read-only GET /v1/account with the test key returned the configured account.', function () {
            $account = (new StripeSdkCheckoutGateway)->account();

            return ($account['id'] ?? null) === config('payments.stripe.account_id');
        });
    } else {
        $record('stripe.probe_account', false, 'Probe not attempted: fix the failed checks first. No Stripe request was made.');
    }
    $failed = array_filter($checks, static fn (array $check): bool => ! $check[1]);
}

foreach ($checks as [$id, $ok, $message]) {
    echo ($ok ? 'PASS ' : 'FAIL ').$id.' - '.$message.PHP_EOL;
}
echo ($failed === [] ? 'RESULT: PASS' : 'RESULT: FAIL').' ('.count($failed).' of '.count($checks).' checks failed'
    .($template ? '; template mode, synthetic placeholder values' : '').($probe ? '' : '; no Stripe request made').')'.PHP_EOL;
exit($failed === [] ? 0 : 1);
