<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\FreshCheckoutPolicy;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Media\PrivateMediaFiles;
use App\Support\CanonicalJson;
use ArrayObject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

class ProductionCheckoutFreshPolicyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('j', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true]);
        Queue::fake();
    }

    public function test_terminal_media_resolver_withdrawal_refuses_new_review_and_retains_original_review(): void
    {
        $f = $this->payable(false);
        $original = (array) DB::table(CheckoutSchema::TABLES['review'])->first();
        $body = Evidence::open($original, 'production_checkout_review');
        $initial = count($body['selection']['bytes']);
        $callbacks = 0;
        app()->resolving(PrivateMediaFiles::class, function () use (&$callbacks, $initial): void {
            if (++$callbacks > $initial) {
                config(['production_checkout.fresh_checkout_enabled' => false]);
            }
        });
        try {
            $f['checkout']->review($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id,
                $f['catalog']['items'], $f['basis']['public_id'], ['legalName' => 'Declared synthetic buyer'], 'synthetic-terminal-review');
            $this->fail('New review escaped terminal fresh policy withdrawal.');
        } catch (CheckoutException $error) {
            $this->assertSame('disabled', $error->reason);
        }
        $this->assertGreaterThan($initial, $callbacks);
        $this->assertFalse(config('production_checkout.fresh_checkout_enabled'));
        $this->assertSame($original, (array) DB::table(CheckoutSchema::TABLES['review'])->first());
        $this->assertDatabaseCount(CheckoutSchema::TABLES['review'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['order'], 0);
    }

    public function test_review_replay_and_original_order_read_are_available_after_fresh_policy_withdrawal(): void
    {
        $f = $this->payable();
        config(['production_checkout.fresh_checkout_enabled' => false]);
        $review = $f['checkout']->review($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id,
            $f['catalog']['items'], $f['basis']['public_id'], ['legalName' => 'Declared synthetic buyer'], 'synthetic-review');
        $this->assertSame(CanonicalJson::encode($f['review']), CanonicalJson::encode($review));
        $this->assertSame($f['order'], $f['checkout']->read($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['review'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['order'], 1);
    }

    public function test_late_arrayobject_parent_is_refused_without_offset_callbacks_after_capture(): void
    {
        $policy = FreshCheckoutPolicy::capture();
        $parent = new FreshPolicyArrayParent(['fresh_checkout_enabled' => true]);
        config(['production_checkout' => $parent]);
        try {
            $policy->prove();
            $this->fail('Terminal fresh proof adopted an application ArrayAccess parent.');
        } catch (CheckoutException $error) {
            $this->assertSame('disabled', $error->reason);
        }
        $this->assertSame(0, $parent->callbacks);
    }
}

/** An actual late parent with observable callback capability; never a production policy fixture. */
final class FreshPolicyArrayParent extends ArrayObject
{
    public int $callbacks = 0;

    public function offsetExists(mixed $key): bool
    {
        $this->callbacks++;

        return parent::offsetExists($key);
    }

    public function offsetGet(mixed $key): mixed
    {
        $this->callbacks++;

        return parent::offsetGet($key);
    }
}
