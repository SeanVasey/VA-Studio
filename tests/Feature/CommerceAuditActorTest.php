<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReviewOrder;
use App\Domain\Commerce\Payments\PaymentWork;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PromotionUsage;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\ReservePricedQuote;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class CommerceAuditActorTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        OrderFixtures::configure();
    }

    public static function operations(): array
    {
        return array_combine(['create', 'price', 'promoted-price', 'inventory-hold', 'reserve-hold', 'inventory-attempt', 'reserve-attempt', 'promotion-attempt', 'prepare', 'review'], array_map(fn ($operation) => [$operation], ['create', 'price', 'promoted-price', 'inventory-hold', 'reserve-hold', 'inventory-attempt', 'reserve-attempt', 'promotion-attempt', 'prepare', 'review']));
    }

    private function fixture(string $operation): array
    {
        $fixture = InventoryFixtures::selection();
        PromotionFixtures::configure([PromotionFixtures::policy()]);
        if (in_array($operation, ['inventory-attempt', 'reserve-attempt', 'promotion-attempt', 'prepare', 'review'], true)) {
            app(ReservePricedQuote::class)->hold($fixture['quote']->public_id, InventoryFixtures::OWNER, 'SYNTHETIC');
        }
        if ($operation === 'prepare') {
            $fixture['request'] = OrderFixtures::request($fixture['quote']);
        }

        return $fixture;
    }

    private function invoke(string $operation, array $fixture, ?User $actor): mixed
    {
        $quote = $fixture['quote']->public_id;
        $owner = InventoryFixtures::OWNER;
        $attempt = (string) Str::uuid();

        return match ($operation) {
            'create' => app(CreateQuote::class)->handle($owner, (string) Str::uuid(), $fixture['items'], $actor),
            'price' => app(PriceQuote::class)->create($quote, $owner, $actor),
            'promoted-price' => app(PriceQuote::class)->createWithPromotion($quote, $owner, 'SYNTHETIC', $actor),
            'inventory-hold' => app(ReserveQuoteInventory::class)->hold($quote, $owner, $actor),
            'reserve-hold' => app(ReservePricedQuote::class)->hold($quote, $owner, 'SYNTHETIC', $actor),
            'inventory-attempt' => app(ReserveQuoteInventory::class)->beginAttempt($quote, $owner, $attempt, $actor),
            'reserve-attempt' => app(ReservePricedQuote::class)->beginAttempt($quote, $owner, $attempt, $actor),
            'promotion-attempt' => app(PromotionUsage::class)->beginAttempt($quote, $owner, $attempt, $actor),
            'prepare' => app(PrepareOrder::class)->handle($owner, (string) Str::uuid(), $fixture['request'], $actor),
            'review' => app(ReviewOrder::class)->handle($quote, $owner, $actor),
        };
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['quotes', 'quote_lines', 'quote_pricings', 'promotion_campaigns', 'promotion_uses', 'inventory_reservations', 'inventory_claims', 'orders', 'order_lines', 'order_attempts', 'audit_events']);
    }

    #[DataProvider('operations')]
    public function test_current_unverified_customer_is_fenced_before_resources_without_staff_authority(string $operation): void
    {
        $fixture = $this->fixture($operation);
        $actor = User::factory()->unverified()->create(['is_admin' => false]);
        $this->actingAs(User::factory()->create());
        $lastAudit = AuditEvent::max('id');
        $trace = [];
        $active = true;
        DB::listen(function (QueryExecuted $query) use (&$trace, &$active): void {
            if ($active && preg_match('/\Aselect\b/i', $query->sql)) {
                foreach (['users', 'quote_owners', 'quotes', 'tracks', 'offers', 'quote_pricings', 'promotion_campaigns', 'rights_scopes', 'orders'] as $table) {
                    if (preg_match('/\bfrom\s+["`]?'.$table.'["`]?(?:\s|$)/i', $query->sql)) {
                        $trace[] = ['table' => $table, 'level' => DB::transactionLevel()];
                    }
                }
            }
        });
        try {
            $this->invoke($operation, $fixture, $actor);
        } finally {
            $active = false;
        }
        $this->assertSame('users', $trace[0]['table']);
        $this->assertSame([], array_values(array_filter($trace, fn ($entry) => $entry['level'] < 1)));
        foreach (AuditEvent::where('id', '>', $lastAudit)->get() as $audit) {
            $this->assertSame($actor->id, $audit->actor_id);
        }
        if ($operation !== 'review') {
            $this->assertGreaterThan($lastAudit, AuditEvent::max('id'));
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('operations')]
    public function test_explicit_null_has_no_customer_lookup_or_ambient_auth_attribution(string $operation): void
    {
        $fixture = $this->fixture($operation);
        $this->actingAs(User::factory()->create());
        $lastAudit = AuditEvent::max('id');
        $userReads = [];
        $active = true;
        DB::listen(function (QueryExecuted $query) use (&$userReads, &$active): void {
            if ($active && preg_match('/\Aselect\b.*\bfrom\s+["`]?users["`]?(?:\s|$)/i', $query->sql)) {
                $userReads[] = $query->sql;
            }
        });
        try {
            $this->invoke($operation, $fixture, null);
        } finally {
            $active = false;
        }
        $this->assertSame([], $userReads);
        foreach (AuditEvent::where('id', '>', $lastAudit)->get() as $audit) {
            $this->assertNull($audit->actor_id);
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('operations')]
    public function test_missing_supplied_actor_never_silently_becomes_anonymous(string $operation): void
    {
        $fixture = $this->fixture($operation);
        $actor = User::factory()->create();
        DB::table('users')->where('id', $actor->id)->delete();
        $before = $this->evidence();
        try {
            $this->invoke($operation, $fixture, $actor);
            $this->fail('Missing supplied actor silently created commerce effects.');
        } catch (QuoteException $error) {
            $this->assertSame('COMMERCE_UNAVAILABLE', $error->errorCode);
            $this->assertSame(503, $error->status);
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('operations')]
    public function test_caller_transaction_and_original_evidence_survive_outer_rollback(string $operation): void
    {
        $fixture = $this->fixture($operation);
        $actor = User::factory()->unverified()->create();
        $before = $this->evidence();
        DB::beginTransaction();
        try {
            $this->invoke($operation, $fixture, $actor);
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_failed_customer_audit_rolls_back_composed_pricing_promotion_and_inventory(): void
    {
        $fixture = $this->fixture('reserve-hold');
        $actor = User::factory()->create();
        $before = $this->evidence();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic commerce audit failure'));
        try {
            $this->invoke('reserve-hold', $fixture, $actor);
            $this->fail('Failed audit allowed partial commerce effects.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic commerce audit failure', $error->getMessage());
        }
        $this->assertSame($before, $this->evidence());
    }

    public static function ownedOperations(): array
    {
        return array_filter(self::operations(), fn ($case) => $case[0] !== 'create');
    }

    #[DataProvider('ownedOperations')]
    public function test_customer_identity_never_replaces_guest_owner_key(string $operation): void
    {
        $fixture = $this->fixture($operation);
        $actor = User::factory()->create();
        $before = $this->evidence();
        try {
            $quote = $fixture['quote']->public_id;
            $wrong = str_repeat('b', 64);
            $attempt = (string) Str::uuid();
            match ($operation) {
                'price' => app(PriceQuote::class)->create($quote, $wrong, $actor),
                'promoted-price' => app(PriceQuote::class)->createWithPromotion($quote, $wrong, 'SYNTHETIC', $actor),
                'inventory-hold' => app(ReserveQuoteInventory::class)->hold($quote, $wrong, $actor),
                'reserve-hold' => app(ReservePricedQuote::class)->hold($quote, $wrong, 'SYNTHETIC', $actor),
                'inventory-attempt' => app(ReserveQuoteInventory::class)->beginAttempt($quote, $wrong, $attempt, $actor),
                'reserve-attempt' => app(ReservePricedQuote::class)->beginAttempt($quote, $wrong, $attempt, $actor),
                'promotion-attempt' => app(PromotionUsage::class)->beginAttempt($quote, $wrong, $attempt, $actor),
                'prepare' => app(PrepareOrder::class)->handle($wrong, (string) Str::uuid(), $fixture['request'], $actor),
                'review' => app(ReviewOrder::class)->handle($quote, $wrong, $actor)
            };
            $this->fail('Customer identity replaced the isolated owner key.');
        } catch (QuoteException $error) {
            $this->assertSame(404, $error->status);
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_omitted_actor_on_trusted_caller_is_explicit_system_and_legacy_audit_keeps_its_fallback(): void
    {
        $f = $this->fixture('create');
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $last = AuditEvent::max('id');
        $quote = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
        $this->assertNull(AuditEvent::where('id', '>', $last)->sole()->actor_id);
        AuditEvent::record('synthetic.legacy.compatibility', $quote);
        $this->assertSame($actor->id, AuditEvent::where('action', 'synthetic.legacy.compatibility')->sole()->actor_id);
    }

    public function test_unsaved_customer_is_not_downgraded_to_guest(): void
    {
        $f = $this->fixture('create');
        $before = $this->evidence();
        try {
            $this->invoke('create', $f, new User);
            $this->fail('Unsaved customer was accepted.');
        } catch (QuoteException $error) {
            $this->assertSame('COMMERCE_UNAVAILABLE', $error->errorCode);
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_http_quote_uses_request_customer_and_retains_session_ownership(): void
    {
        $f = $this->fixture('create');
        $actor = User::factory()->unverified()->create();
        $this->actingAs($actor);
        $last = AuditEvent::max('id');
        $response = $this->postJson('/quotes', ['items' => $f['items']], ['Idempotency-Key' => (string) Str::uuid()])->assertSuccessful();
        $this->assertSame($actor->id, AuditEvent::where('id', '>', $last)->sole()->actor_id);
        $quote = Quote::where('public_id', $response->json('quote.id'))->first();
        // Identity does not alter the existing opaque browser-session owner contract.
        $this->assertNotNull($quote);
        $this->assertNotSame(InventoryFixtures::OWNER, $quote->owner_key);
    }

    public function test_hosted_checkout_attributes_only_customer_initiation_and_keeps_provider_binding_system_null(): void
    {
        CheckoutFixtures::configure();
        $f = CheckoutFixtures::prepared();
        $actor = User::factory()->unverified()->create();
        $this->actingAs($actor);
        $last = AuditEvent::max('id');
        $gateway = CheckoutFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER, $actor);
        $audits = AuditEvent::where('id', '>', $last)->get();
        $this->assertCount(2, $audits);
        $this->assertSame($actor->id, $audits->firstWhere('action', 'commerce.checkout.initiated')->actor_id);
        $this->assertNull($audits->firstWhere('action', 'commerce.checkout.bound')->actor_id);
        foreach ($gateway->calls as $call) {
            $this->assertSame(0, $call['transaction_level']);
        }
    }

    public function test_provider_verification_finalization_and_receipt_replay_remain_system_under_ambient_auth(): void
    {
        Queue::fake();
        FinalizationFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $f = PaymentFixtures::started($gateway);
        $this->actingAs(User::factory()->create());
        $last = AuditEvent::max('id');
        $userReads = [];
        $active = true;
        DB::listen(function (QueryExecuted $query) use (&$active, &$userReads): void {
            if ($active && preg_match('/\Aselect\b.*\bfrom\s+["`]?users["`]?(?:\s|$)/i', $query->sql)) {
                $userReads[] = $query->sql;
            }
        });
        try {
            $this->assertSame('awaiting_finalization', app(VerifyTestPayment::class)->reconcile($f['intent']));
            $payment = VerifiedPayment::where('order_id', $f['order']->id)->sole();
            $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($payment->id));
            $receipt = PaymentFixtures::receipt($gateway->session);
            $this->assertNotNull(app(PaymentWork::class)->claim($receipt->id, true));
        } finally {
            $active = false;
        }
        $this->assertSame([], $userReads);
        $audits = AuditEvent::where('id', '>', $last)->get();
        $this->assertCount(3, $audits);
        $this->assertSame(['commerce.payment.test_verified', 'commerce.order.test_finalized', 'commerce.payment.receipt_replayed'], $audits->pluck('action')->all());
        foreach ($audits as $audit) {
            $this->assertNull($audit->actor_id);
        }
        foreach ($gateway->calls as $call) {
            $this->assertSame(0, $call['transaction_level']);
        }
    }
}
