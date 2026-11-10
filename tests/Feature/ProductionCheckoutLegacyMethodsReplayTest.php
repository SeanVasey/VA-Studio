<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\HostedEvidence;
use App\Domain\Commerce\ProductionCheckout\OrderEvidence;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * BL-68 review P1: a production intent stored before Link was offered holds `['card']` and must still verify through
 * HostedEvidence::intent(), while any other stored method list is refused. The immutable-evidence trigger forbids
 * updating a row, so each case inserts its own row before anything else reads it.
 */
class ProductionCheckoutLegacyMethodsReplayTest extends TestCase
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

    /** Inserts an intent whose stored request asked for `$methods`, then runs HostedEvidence::intent() on it. */
    private function verifyStored(array $f, mixed $methods): array
    {
        return CommandTransaction::run(function (Records $rows) use ($f, $methods): array {
            $order = OrderEvidence::order($rows, $rows->one('order', $f['order']['orderId']));
            $context = $order['context'];
            $at = CarbonImmutable::now('UTC');
            $public = '00000000-0000-4000-8000-000000000068';
            $created = $at->format('Y-m-d\TH:i:s\Z');
            $request = HostedEvidence::request($order, $public, $created);
            $request['params']['payment_method_types'] = $methods;
            $record = $rows->insert('intent', ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($request),
                'order_id' => $order['row']['id'], 'attempt_id' => $order['attempt']['id'], 'account_id' => $context->accountId, 'funds_mode' => $context->fundsMode,
                'idempotency_key' => $request['idempotency_key'], 'retry_before' => $at->addSeconds($context->retrySeconds)->format('Y-m-d\TH:i:s\Z'),
                'provider_expires_at' => $at->addSeconds($context->providerLifetimeSeconds)->format('Y-m-d\TH:i:s\Z')]);

            return HostedEvidence::intent($rows, $order, $record);
        });
    }

    public function test_stored_card_only_intent_still_verifies_and_keeps_its_legacy_request(): void
    {
        $f = $this->payable();
        $intent = $this->verifyStored($f, ['card']);
        $this->assertSame(['card'], $intent['body']['params']['payment_method_types']);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 1);
    }

    public function test_current_offered_intent_still_verifies(): void
    {
        $f = $this->payable();
        $intent = $this->verifyStored($f, ['card', 'link']);
        $this->assertSame(['card', 'link'], $intent['body']['params']['payment_method_types']);
    }

    public static function refusedStoredMethods(): array
    {
        return [[['link']], [['card', 'klarna']], [['link', 'card']], [['card', 'card']], [['us_bank_account']], [[]], [null], ['card']];
    }

    #[DataProvider('refusedStoredMethods')]
    public function test_stored_intent_with_any_other_method_list_is_refused(mixed $methods): void
    {
        $f = $this->payable();
        try {
            $this->verifyStored($f, $methods);
            $this->fail('A stored intent with an unexpected method list verified.');
        } catch (CheckoutException) {
            $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 0);
        }
    }
}
