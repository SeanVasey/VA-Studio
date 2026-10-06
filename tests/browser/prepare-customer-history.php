<?php

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Orders\ReviewOrder;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerAccounts;
use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Media\MalwareScanner;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CustomerFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;

// CLI-only preparation for one disposable native-browser installation. No application bypass route.
try {
    umask(0077);
    $directory = getenv('VASEY_BROWSER_DIRECTORY');
    $password = getenv('VASEY_BROWSER_PASSWORD');
    $mode = $argv[1] ?? null;
    $project = $argv[2] ?? null;
    historyCheck(PHP_SAPI === 'cli' && count($argv) === 3 && in_array($mode, ['prepare', 'withdraw', 'verify'], true)
        && in_array($project, ['chromium-desktop', 'webkit-mobile'], true)
        && is_string($directory) && ! is_link($directory) && realpath($directory) === $directory
        && realpath(dirname($directory)) === realpath(sys_get_temp_dir()) && preg_match('/\Avasey-browser-[A-Za-z0-9]+\z/D', basename($directory))
        && getenv('APP_ENV') === 'local' && getenv('APP_URL') === 'http://127.0.0.1:8173'
        && getenv('LARAVEL_STORAGE_PATH') === $directory && getenv('DB_CONNECTION') === 'sqlite'
        && getenv('DB_DATABASE') === $directory.'/database.sqlite' && getenv('DB_URL') === ''
        && realpath($directory.'/database.sqlite') === $directory.'/database.sqlite' && ! is_link($directory.'/database.sqlite')
        && getenv('APP_CONFIG_CACHE') === $directory.'/config.php' && ! file_exists($directory.'/config.php')
        && getenv('APP_ROUTES_CACHE') === $directory.'/routes.php' && ! file_exists($directory.'/routes.php')
        && getenv('APP_EVENTS_CACHE') === $directory.'/events.php' && ! file_exists($directory.'/events.php')
        && in_array(getenv('VASEY_BROWSER_RELATED_STAGE'), [false, ''], true)
        && in_array(getenv('VASEY_BROWSER_RELATED_MARKER'), [false, ''], true)
        && is_file($directory.'/customer-fixtures.json') && ! is_link($directory.'/customer-fixtures.json')
        && is_string($password) && strlen($password) >= 40
        && is_executable('/usr/bin/clamscan') && realpath('/usr/bin/clamscan') === '/usr/bin/clamscan');
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    historyCheck($app->environment('local') && config('app.url') === 'http://127.0.0.1:8173' && storage_path() === $directory
        && config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === $directory.'/database.sqlite'
        && config('filesystems.disks.local.root') === $directory.'/app/private' && config('session.driver') === 'file'
        && config('session.files') === $directory.'/framework/sessions' && config('session.encrypt') === true
        && app(CustomerAccessPolicy::class)->enabled());
    $baseline = json_decode(file_get_contents($directory.'/customer-fixtures.json'), true, 16, JSON_THROW_ON_ERROR);
    historyCheck(isset($baseline['projects'][$project]['orderId'])
        && Order::where('public_id', $baseline['projects'][$project]['orderId'])->exists()
        && User::where('email', 'browser-customer@example.test')->exists());
    $path = $directory.'/customer-history-'.$project.'.json';
    historyCheck(! is_link($path));
    $email = 'browser-history-'.$project.'@example.test';

    if ($mode === 'prepare') {
        historyCheck(! file_exists($path) && ! User::where('email', $email)->exists());
        $old = historyBusinessRows();
        $guards = historyGuardHash();
        $user = User::factory()->create(['name' => 'Synthetic History '.$project, 'email' => $email,
            'password' => $password, 'is_admin' => false, 'email_verified_at' => now()]);
        $account = app(CustomerAccounts::class)->provision($user);
        $principal = app(CustomerAccess::class)->principal($user);
        $scannerPath = config('media.clamscan');
        $app->detectEnvironment(fn () => 'testing');
        try {
            OrderFixtures::configure();
            config(['media.clamscan' => '/usr/bin/clamscan']);
            // Reuse one genuinely cleared track/media/license. Legacy pending scopes have capacity one,
            // so each real order needs a distinct synthetic offer and scope rather than reusing a reservation.
            $selection = InventoryFixtures::selection(scanner: new MalwareScanner);
            for ($i = 0; $i < 41; $i++) {
                $items = $selection['items'];
                if ($i > 0) {
                    $offer = app(SaveOfferDraft::class)->handle(null, [
                        'track_id' => $selection['track']->id, 'license_version_id' => $selection['offer']->license_version_id,
                        'price_minor' => 4999, 'currency' => 'USD', 'deliverable_asset_ids' => [$selection['media']['master_wav']->id],
                    ], $selection['actor']);
                    $revision = app(PublishOffer::class)->handle($offer, $selection['actor']);
                    $manage = app(ManageRightsScope::class);
                    $scope = $manage->register('history-'.Str::uuid(), 'SYNTHETIC-HISTORY-SCOPE', $selection['actor']);
                    $manage->link($scope->id, $revision->id, 'SYNTHETIC-HISTORY-LINK', $selection['actor']);
                    $items = [['trackId' => $selection['track']->id, 'offerId' => $offer->id,
                        'licenseVersionId' => $offer->license_version_id, 'offerRevisionId' => $revision->id]];
                }
                $quote = app(CreateQuote::class)->handle($principal->ownerKey, (string) Str::uuid(), $items, $user, $principal);
                app(PriceQuote::class)->create($quote->public_id, $principal->ownerKey, $user, $principal);
                $review = app(ReviewOrder::class)->handle($quote->public_id, $principal->ownerKey, $user, $principal);
                app(PrepareOrder::class)->handle($principal->ownerKey, (string) Str::uuid(), [
                    'quoteId' => $quote->public_id, 'reviewHash' => $review['reviewHash'],
                    'buyer' => OrderFixtures::buyer(), 'accepted' => true,
                ], $user, $principal);
            }
            $changedTitle = 'Synthetic live catalog changed after history '.$project;
            $track = app(SaveTrackMetadata::class)->handle($selection['track']->refresh(), [
                'title' => $changedTitle, 'metadata_version' => $selection['track']->metadata_version,
            ], $selection['actor']);
            app(PublishTrack::class)->unpublish($track, $selection['actor']);
        } finally {
            config(['media.clamscan' => $scannerPath]);
            $app->detectEnvironment(fn () => 'local');
        }
        $orders = Order::where('owner_key', $principal->ownerKey)->orderByDesc('created_at')->orderByDesc('id')->get();
        historyCheck($orders->count() === 41 && historyGuardHash() === $guards);
        $expected = [];
        foreach ($orders as $order) {
            $items = app(ReadOrder::class)->items($order->public_id, $principal->ownerKey);
            $line = $items['lines'][0];
            historyCheck($line['title'] !== $changedTitle && count($items['lines']) === 1);
            $expected[] = ['id' => $order->public_id, 'firstItem' => array_intersect_key($line, array_flip(['title', 'licenseName', 'licenseVersion'])),
                'items' => $items];
        }
        $retained = historyBusinessRows();
        foreach ($old as $table => $rows) {
            foreach ($rows as $id => $hash) {
                historyCheck(($retained[$table][$id] ?? null) === $hash);
            }
        }
        $fixture = ['purpose' => 'customer-history-native-v1', 'database' => $directory.'/database.sqlite',
            'project' => $project, 'email' => $email, 'userId' => $user->id, 'accountId' => $account->id,
            'accessVersion' => $account->access_version, 'trackId' => $track->id, 'changedTitle' => $changedTitle,
            'orders' => $expected, 'retainedHash' => CanonicalJson::hash($retained), 'guardHash' => $guards];
        $file = fopen($path, 'x');
        historyCheck(is_resource($file));
        $bytes = json_encode($fixture, JSON_THROW_ON_ERROR);
        historyCheck(fwrite($file, $bytes) === strlen($bytes));
        fclose($file);
        echo json_encode(['email' => $email, 'orders' => $expected, 'changedTitle' => $changedTitle], JSON_THROW_ON_ERROR);
    } else {
        historyCheck(is_file($path) && (fileperms($path) & 0077) === 0);
        $fixture = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        historyCheck($fixture['purpose'] === 'customer-history-native-v1' && $fixture['database'] === $directory.'/database.sqlite'
            && $fixture['project'] === $project && $fixture['email'] === $email
            && $fixture['retainedHash'] === CanonicalJson::hash(historyBusinessRows()) && $fixture['guardHash'] === historyGuardHash());
        $user = User::whereKey($fixture['userId'])->where('email', $email)->sole();
        $account = CustomerAccount::whereKey($fixture['accountId'])->where('user_id', $user->id)->sole();
        historyCheck(! $user->is_admin && $user->email_verified_at !== null
            && DB::table('tracks')->where('id', $fixture['trackId'])->value('title') === $fixture['changedTitle']);
        if ($mode === 'withdraw') {
            historyCheck($account->active && $account->access_version === $fixture['accessVersion']);
            CustomerFixtures::withdraw(compact('user', 'account'));
            historyCheck($fixture['retainedHash'] === CanonicalJson::hash(historyBusinessRows()) && $fixture['guardHash'] === historyGuardHash());
            echo json_encode(['withdrawn' => true], JSON_THROW_ON_ERROR);
        } else {
            historyCheck(! $account->active && $account->access_version === $fixture['accessVersion'] + 1);
            $orders = Order::where('owner_key', $account->owner_key)->orderByDesc('created_at')->orderByDesc('id')->get();
            historyCheck($orders->pluck('public_id')->all() === array_column($fixture['orders'], 'id'));
            foreach ($orders as $index => $order) {
                historyCheck(app(ReadOrder::class)->items($order->public_id, $account->owner_key) === $fixture['orders'][$index]['items']);
            }
            echo json_encode(['orderCount' => 41, 'retainedOriginalsUnchanged' => true, 'businessRowsUnchanged' => true,
                'guardsUnchanged' => true, 'accountWithdrawn' => true], JSON_THROW_ON_ERROR);
        }
    }
} catch (Throwable) {
    fwrite(STDERR, "Isolated customer history fixture failed; no private details are printed.\n");
    exit(1);
}

function historyCheck(bool $condition): void
{
    if (! $condition) {
        throw new RuntimeException('Customer history fixture confinement or evidence mismatch.');
    }
}

/** Full row hashes, including encrypted original bytes and empty payment/delivery tables. Reads may change none of them. */
function historyBusinessRows(): array
{
    $result = [];
    foreach (['orders', 'order_lines', 'order_attempts', 'quotes', 'quote_lines', 'quote_pricings', 'rights_scopes', 'rights_scope_offers', 'inventory_reservations',
        'inventory_claims', 'promotion_uses', 'checkout_intents', 'checkout_sessions', 'checkout_observations',
        'verified_payments', 'payment_observations', 'order_finalizations', 'license_grants', 'exclusive_sales',
        'pending_entitlements', 'fulfillment_outbox', 'contract_render_requests', 'contract_render_work', 'grant_contracts',
        'test_fulfillment_activations', 'test_delivery_controls', 'test_delivery_authorizations', 'test_delivery_redemptions',
        'test_unpaid_releases', 'test_unpaid_release_events', 'test_unpaid_release_work',
        'test_payment_exception_events', 'test_payment_exception_work', 'test_payment_financial_observations',
        'test_refund_resolution_requests', 'test_refund_resolutions',
        'customer_purchase_challenges', 'customer_purchase_claims'] as $table) {
        $result[$table] = [];
        foreach (DB::table($table)->orderBy('id')->get() as $row) {
            $result[$table][$row->id] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        }
    }

    return $result;
}

function historyGuardHash(): string
{
    return hash('sha256', json_encode(DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->get(['name', 'tbl_name', 'sql']), JSON_THROW_ON_ERROR));
}
