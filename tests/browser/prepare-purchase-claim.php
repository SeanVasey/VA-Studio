<?php

use App\Domain\Catalog\PublishTrack;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerPurchaseClaimPolicy;
use App\Domain\Customers\CustomerPurchaseClaims;
use App\Domain\Customers\Models\CustomerPurchaseClaim;
use App\Domain\Delivery\DeliveryAccessEvidence;
use App\Domain\Delivery\ReadTestOwnerDelivery;
use App\Domain\Media\MalwareScanner;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ActivationFixtures;
use Tests\Support\CheckoutFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;

// CLI-only. A private cookie from this exact disposable browser resolves possession; no application route bypasses it.
try {
    $directory = getenv('VASEY_BROWSER_DIRECTORY');
    $mode = $argv[1] ?? null;
    $project = $argv[2] ?? null;
    if (PHP_SAPI !== 'cli' || count($argv) !== 3 || ! in_array($mode, ['prepare', 'verify'], true)
        || ! in_array($project, ['chromium-desktop', 'webkit-mobile'], true)
        || ! is_string($directory) || is_link($directory) || realpath($directory) !== $directory
        || realpath(dirname($directory)) !== realpath(sys_get_temp_dir()) || ! preg_match('/\Avasey-browser-[A-Za-z0-9]+\z/D', basename($directory))
        || getenv('APP_ENV') !== 'local' || getenv('APP_URL') !== 'http://127.0.0.1:8173'
        || getenv('LARAVEL_STORAGE_PATH') !== $directory || getenv('DB_CONNECTION') !== 'sqlite'
        || getenv('DB_DATABASE') !== $directory.'/database.sqlite' || getenv('DB_URL') !== ''
        || realpath($directory.'/database.sqlite') !== $directory.'/database.sqlite' || is_link($directory.'/database.sqlite')
        || getenv('APP_CONFIG_CACHE') !== $directory.'/config.php' || file_exists($directory.'/config.php')
        || getenv('APP_ROUTES_CACHE') !== $directory.'/routes.php' || getenv('APP_EVENTS_CACHE') !== $directory.'/events.php'
        || file_exists($directory.'/routes.php') || file_exists($directory.'/events.php')
        || ! in_array(getenv('VASEY_BROWSER_RELATED_STAGE'), [false, ''], true)
        || ! in_array(getenv('VASEY_BROWSER_RELATED_MARKER'), [false, ''], true)
        || ! is_file($directory.'/customer-fixtures.json') || is_link($directory.'/customer-fixtures.json')
        || ! is_executable('/usr/bin/clamscan') || realpath('/usr/bin/clamscan') !== '/usr/bin/clamscan') {
        throw new RuntimeException('Not an isolated ordinary browser fixture.');
    }
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('local') || config('app.url') !== 'http://127.0.0.1:8173' || storage_path() !== $directory
        || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $directory.'/database.sqlite'
        || config('filesystems.disks.local.root') !== $directory.'/app/private' || config('session.driver') !== 'file'
        || config('session.files') !== $directory.'/framework/sessions' || ! config('session.encrypt')
        || ! app(CustomerPurchaseClaimPolicy::class)->enabled()) {
        throw new RuntimeException('Effective fixture confinement mismatch.');
    }
    $path = $directory.'/purchase-claim-'.$project.'.json';
    if (is_link($path)) {
        throw new RuntimeException('Unsafe fixture manifest.');
    }
    if ($mode === 'prepare') {
        if (file_exists($path)) {
            throw new RuntimeException('Fixture already exists.');
        }
        $input = stream_get_contents(STDIN, 8193);
        if (strlen($input) > 8192) {
            throw new RuntimeException('Invalid cookie input.');
        }
        $cookie = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
        if (! is_array($cookie) || array_keys($cookie) !== ['name', 'value'] || $cookie['name'] !== config('session.cookie') || ! is_string($cookie['value'])) {
            throw new RuntimeException('Invalid cookie identity.');
        }
        $sessionId = CookieValuePrefix::validate($cookie['name'], Crypt::decrypt(rawurldecode($cookie['value']), false), app('encrypter')->getAllKeys());
        if (! is_string($sessionId) || ! preg_match('/\A[A-Za-z0-9]{40}\z/D', $sessionId)
            || ! is_file($directory.'/framework/sessions/'.$sessionId) || is_link($directory.'/framework/sessions/'.$sessionId)) {
            throw new RuntimeException('Unknown original session.');
        }
        $session = app('session')->driver();
        $session->setId($sessionId);
        $session->start();
        $possession = $session->get('_quote_owner');
        if ($session->has('_customer_access') || ! is_array($possession) || ($possession['context'] ?? null) !== 'guest'
            || ! is_string($possession['secret'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $possession['secret'])) {
            throw new RuntimeException('Original guest possession missing.');
        }
        $owner = hash_hmac('sha256', "vasey-quote-owner-v1\0guest\0".$possession['secret'], config('app.key'));
        $app->detectEnvironment(fn () => 'testing');
        DeliveryFixtures::configure();
        config(['media.clamscan' => '/usr/bin/clamscan']);
        $scanner = new MalwareScanner;
        $gateway = PaymentFixtures::gateway();
        $suffix = $project === 'chromium-desktop' ? 'CLAIMCHROMIUM' : 'CLAIMWEBKIT';
        $gateway->onCreate = fn (array $params) => CheckoutFixtures::session($params, 'cs_test_'.$suffix);
        $app->instance(StripeCheckoutGateway::class, $gateway);
        $app->instance(StripePaymentGateway::class, $gateway);
        $app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $selection = InventoryFixtures::selection(scanner: $scanner);
        $quote = app(CreateQuote::class)->handle($owner, (string) Str::uuid(), $selection['items']);
        app(PriceQuote::class)->create($quote->public_id, $owner);
        $order = app(PrepareOrder::class)->handle($owner, (string) Str::uuid(), OrderFixtures::request($quote, $owner));
        app(HostedCheckout::class)->start($order->public_id, $owner);
        $gateway->session['status'] = 'complete';
        $gateway->session['payment_status'] = 'paid';
        $gateway->session['url'] = null;
        $gateway->session['payment_intent'] = 'pi_'.$suffix;
        $gateway->payment = array_replace(PaymentFixtures::payment($gateway->session), ['id' => 'pi_'.$suffix, 'latest_charge' => 'ch_'.$suffix]);
        $paid = DeliveryFixtures::activate(ActivationFixtures::issue(ContractFixtures::finalize(FinalizationFixtures::confirm([
            'order' => $order, 'intent' => CheckoutIntent::where('order_id', $order->id)->sole(),
        ]))));
        app(PublishTrack::class)->unpublish($selection['track'], $selection['actor']);
        $source = app(DeliveryAccessEvidence::class)->source($order, CheckoutFixtures::ACCOUNT);
        $files = [];
        foreach (app(ReadTestOwnerDelivery::class)->handle($order->public_id, $owner)['items'] as $item) {
            $target = app(DeliveryAccessEvidence::class)->target($source, $item['grantId'], $item['kind']);
            $files[] = ['kind' => $item['kind'], 'filename' => $target['filename'], 'sha256' => $target['file']['sha256'], 'sizeBytes' => $target['file']['size_bytes']];
        }
        $fixture = ['project' => $project, 'orderId' => $order->public_id, 'files' => $files, 'retainedHash' => CanonicalJson::hash(DeliveryFixtures::retained())];
        file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        echo json_encode(['orderId' => $fixture['orderId'], 'files' => $files], JSON_THROW_ON_ERROR);
    } else {
        $fixture = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if ($fixture['project'] !== $project || $fixture['retainedHash'] !== CanonicalJson::hash(DeliveryFixtures::retained())) {
            throw new RuntimeException('Original purchase evidence changed.');
        }
        $order = Order::where('public_id', $fixture['orderId'])->sole();
        $claim = CustomerPurchaseClaim::where('order_id', $order->id)->sole();
        $principal = app(CustomerAccess::class)->principal(User::where('email', 'browser-customer@example.test')->sole());
        app(CustomerPurchaseClaims::class)->verify($claim, $order, $principal);
        if (DB::table('audit_events')->where('action', 'customer.test_purchase.saved')->where('subject_id', $claim->id)->count() !== 1
            || DB::table('test_delivery_authorizations')->where('order_id', $order->id)->count() !== count($fixture['files'])
            || DB::table('test_delivery_redemptions')->whereIn('test_delivery_authorization_id', DB::table('test_delivery_authorizations')->where('order_id', $order->id)->select('id'))->count() !== count($fixture['files'])) {
            throw new RuntimeException('Unexpected claim or download effects.');
        }
        echo json_encode(['orderId' => $order->public_id, 'retainedOriginalsUnchanged' => true, 'singleClaim' => true, 'downloadAttempts' => count($fixture['files'])], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Isolated guest purchase fixture failed ('.$error::class.').'.PHP_EOL);
    exit(1);
}
