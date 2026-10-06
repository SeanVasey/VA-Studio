<?php

namespace Tests\Support;

use App\Domain\Catalog\PublishTrack;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReviewOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccounts;
use App\Domain\Delivery\DeliveryAccessEvidence;
use App\Domain\Delivery\ReadTestOwnerDelivery;
use App\Domain\Media\MalwareScanner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CustomerFixtures
{
    public const PASSWORD = 'Synthetic-customer-test-only-42!';

    public static function configure(): void
    {
        config(['customer.test_accounts_enabled' => true]);
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        }
    }

    public static function account(array $attributes = []): array
    {
        self::configure();
        $user = User::factory()->create(['is_admin' => false, 'email_verified_at' => now(), 'password' => self::PASSWORD, ...$attributes]);
        $account = app(CustomerAccounts::class)->provision($user);
        $principal = app(CustomerAccess::class)->principal($user);

        return compact('user', 'account', 'principal');
    }

    public static function prepared(User $user, bool $hideCatalog = false, bool $configured = false, ?MalwareScanner $scanner = null): Order
    {
        if (! $configured) {
            OrderFixtures::configure();
        }
        $principal = app(CustomerAccess::class)->principal($user);
        $selection = InventoryFixtures::selection(scanner: $scanner);
        $quote = app(CreateQuote::class)->handle($principal->ownerKey, (string) Str::uuid(), $selection['items'], $user, $principal);
        app(PriceQuote::class)->create($quote->public_id, $principal->ownerKey, $user, $principal);
        $review = app(ReviewOrder::class)->handle($quote->public_id, $principal->ownerKey, $user, $principal);

        $order = app(PrepareOrder::class)->handle($principal->ownerKey, (string) Str::uuid(), [
            'quoteId' => $quote->public_id, 'reviewHash' => $review['reviewHash'], 'buyer' => OrderFixtures::buyer(), 'accepted' => true,
        ], $user, $principal);
        if ($hideCatalog) {
            app(PublishTrack::class)->unpublish($selection['track'], $selection['actor']);
        }

        return $order;
    }

    /** Real domain transitions and immutable private originals; external provider/renderer transports are synthetic. */
    public static function ready(User $user, string $suffix = 'ONE', bool $hideCatalog = false, ?MalwareScanner $scanner = null): array
    {
        DeliveryFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        $gateway->onCreate = fn (array $params) => CheckoutFixtures::session($params, 'cs_test_CUSTOMER'.$suffix);
        app()->instance(StripeCheckoutGateway::class, $gateway);
        app()->instance(StripePaymentGateway::class, $gateway);
        app()->instance(ContractRenderer::class, ContractFixtures::renderer());
        $order = self::prepared($user, $hideCatalog, true, $scanner);
        $principal = app(CustomerAccess::class)->principal($user);
        app(HostedCheckout::class)->start($order->public_id, $principal->ownerKey, $user, $principal);
        $gateway->session['status'] = 'complete';
        $gateway->session['payment_status'] = 'paid';
        $gateway->session['url'] = null;
        $gateway->session['payment_intent'] = 'pi_CUSTOMER'.$suffix;
        $gateway->payment = array_replace(PaymentFixtures::payment($gateway->session), ['id' => 'pi_CUSTOMER'.$suffix, 'latest_charge' => 'ch_CUSTOMER'.$suffix]);
        $fixture = FinalizationFixtures::confirm(['order' => $order, 'intent' => CheckoutIntent::where('order_id', $order->id)->sole()]);
        $fixture = DeliveryFixtures::activate(ActivationFixtures::issue(ContractFixtures::finalize($fixture)));

        return $fixture + compact('gateway', 'principal');
    }

    public static function browserManifest(array $fixture): array
    {
        $evidence = app(DeliveryAccessEvidence::class);
        $source = $evidence->source($fixture['order'], CheckoutFixtures::ACCOUNT);
        $items = app(ReadTestOwnerDelivery::class)->handle($fixture['order']->public_id, $fixture['principal']->ownerKey)['items'];
        $result = ['orderId' => $fixture['order']->public_id];
        foreach ($items as $item) {
            $target = $evidence->target($source, $item['grantId'], $item['kind']);
            $result[$item['kind'] === 'contract' ? 'contract' : 'asset'] = ['kind' => $item['kind'], 'filename' => $item['filename'],
                'sha256' => $target['file']['sha256'], 'sizeBytes' => $target['file']['size_bytes']];
        }

        return $result;
    }

    public static function withdraw(array $fixture): void
    {
        DB::transaction(function () use ($fixture): void {
            User::whereKey($fixture['user']->id)->lockForUpdate()->firstOrFail();
            $account = $fixture['account']->newQuery()->whereKey($fixture['account']->id)->lockForUpdate()->firstOrFail();
            $account->update(['active' => false, 'access_version' => $account->access_version + 1]);
        });
    }
}
