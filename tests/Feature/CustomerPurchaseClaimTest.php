<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerPurchaseClaims;
use App\Domain\Customers\Models\CustomerPurchaseChallenge;
use App\Domain\Customers\Models\CustomerPurchaseClaim;
use App\Domain\Delivery\DeliveryAccessEvidence;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ActivationFixtures;
use Tests\Support\CheckoutFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class CustomerPurchaseClaimTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        CustomerFixtures::configure();
        DeliveryFixtures::configure();
        Queue::fake();
        config(['customer.test_purchase_claims_enabled' => true]);
    }

    private function ready(): array
    {
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());

        return DeliveryFixtures::activate(ActivationFixtures::issue(ContractFixtures::finalize(FinalizationFixtures::ownedHttp($this, $gateway))));
    }

    private function login(array $account): void
    {
        $this->postJson('/account/sign-in', ['email' => $account['user']->email, 'password' => CustomerFixtures::PASSWORD])->assertOk();
    }

    private function stage(array $paid): void
    {
        $this->postJson('/account/purchase-claim/stage', ['orderId' => $paid['order']->public_id])->assertOk()
            ->assertExactJson(['orderId' => $paid['order']->public_id, 'staged' => true])->assertHeader('Cache-Control', 'no-store, private');
    }

    private function saved(array $account, array $paid): void
    {
        $this->stage($paid);
        $this->login($account);
        $this->postJson('/account/purchase-claim/complete', ['orderId' => $paid['order']->public_id])->assertOk();
    }

    public function test_original_guest_purchase_is_explicitly_saved_and_fresh_account_session_reads_original_items_and_hash_verified_files(): void
    {
        $account = CustomerFixtures::account();
        $paid = $this->ready();
        $order = $paid['order'];
        $originals = DeliveryFixtures::retained();
        $this->stage($paid);
        $this->assertDatabaseCount('customer_purchase_claims', 0);
        $this->login($account);
        $this->assertNull(session()->get('_quote_owner'));
        $this->getJson('/orders/'.$order->public_id.'/status')->assertNotFound();
        $this->get('/account', ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/account')) ?? ''])->assertOk()->assertJsonPath('props.guestPurchaseClaim.orderId', $order->public_id)->assertDontSee('proof_hash')->assertDontSee($order->owner_key);
        $this->postJson('/account/purchase-claim/complete', ['orderId' => $order->public_id])->assertOk()->assertExactJson(['orderId' => $order->public_id, 'saved' => true]);
        $this->postJson('/account/purchase-claim/complete', ['orderId' => $order->public_id])->assertOk();
        $this->assertDatabaseCount('customer_purchase_claims', 1);
        $this->assertSame(1, AuditEvent::where('action', 'customer.test_purchase.saved')->count());
        $this->flushSession();
        Auth::forgetGuards();
        $this->getJson('/orders/'.$order->public_id.'/status')->assertNotFound();
        $this->login($account);
        $this->getJson('/orders/history')->assertOk()->assertJsonPath('history.orders.0.id', $order->public_id);
        $this->getJson('/orders/'.$order->public_id.'/status')->assertOk()->assertJsonPath('order.paymentStatus', 'verified');
        $this->getJson('/orders/'.$order->public_id.'/items')->assertOk()->assertJsonPath('items.orderId', $order->public_id);
        $delivery = $this->get('/orders/'.$order->public_id.'/delivery', ['Accept' => 'application/json'])->assertOk()->json('delivery');
        $source = app(DeliveryAccessEvidence::class)->source($order, CheckoutFixtures::ACCOUNT);
        foreach ($delivery['items'] as $item) {
            $target = app(DeliveryAccessEvidence::class)->target($source, $item['grantId'], $item['kind']);
            $auth = $this->postJson('/orders/'.$order->public_id.'/delivery/authorizations', ['grantId' => $item['grantId'], 'kind' => $item['kind']], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('authorization');
            $body = http_build_query(['authorizationId' => $auth['authorizationId'], 'token' => $auth['token'], '_token' => session()->token()]);
            $response = $this->call('POST', '/orders/'.$order->public_id.'/delivery/download', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)->assertOk();
            $bytes = $response->streamedContent();
            $this->assertSame($target['file']['sha256'], hash('sha256', $bytes));
            $this->assertSame($target['file']['size_bytes'], strlen($bytes));
        }
        $this->assertSame($originals, DeliveryFixtures::retained());
        $this->assertSame([$order->owner_key], DB::table('test_delivery_authorizations')->distinct()->pluck('owner_key')->all());
        $this->assertSame([$account['user']->id], DB::table('audit_events')->whereIn('action', ['customer.test_purchase.saved', 'commerce.delivery.authorized', 'commerce.delivery.redeemed'])->distinct()->pluck('actor_id')->all());
    }

    public function test_claim_never_creates_owner_alias_or_quote_checkout_or_sibling_order_access(): void
    {
        $account = CustomerFixtures::account();
        $paid = $this->ready();
        $owner = $paid['order']->owner_key;
        // A second preparation from the same browser must remain outside the exact claimed order.
        $selection = InventoryFixtures::selection();
        $quote = app(CreateQuote::class)->handle($owner, (string) Str::uuid(), $selection['items']);
        app(PriceQuote::class)->create($quote->public_id, $owner);
        $other = app(PrepareOrder::class)->handle($owner, (string) Str::uuid(), OrderFixtures::request($quote, $owner));
        $this->saved($account, $paid);
        $this->getJson('/orders/'.$other->public_id.'/status')->assertNotFound();
        $this->getJson('/orders/'.$other->public_id.'/items')->assertNotFound();
        $this->getJson('/quotes/'.Quote::findOrFail($paid['order']->quote_id)->public_id.'/order')->assertNotFound();
        $this->getJson('/orders/'.$paid['order']->public_id.'/checkout')->assertNotFound();
        $this->getJson('/orders/history')->assertOk()->assertJsonCount(1, 'history.orders');
    }

    public static function invalidations(): array
    {
        return array_map(fn ($value) => [$value], ['expire', 'gate', 'withdraw', 'password', 'staff', 'unverify', 'sign-out', 'account-switch', 'foreign-reference']);
    }

    #[DataProvider('invalidations')]
    public function test_pending_claim_fails_closed_after_invalidation(string $mode): void
    {
        $account = CustomerFixtures::account();
        $paid = $this->ready();
        $this->stage($paid);
        $this->login($account);
        $id = $paid['order']->public_id;
        match ($mode) {
            'expire' => $this->travel(600)->seconds(),
            'gate' => config(['customer.test_purchase_claims_enabled' => false]),
            'withdraw' => CustomerFixtures::withdraw($account),
            'password' => $account['user']->update(['password' => Hash::make('Changed-password-test-only')]),
            'staff' => $account['user']->forceFill(['is_admin' => true])->save(),
            'unverify' => $account['user']->forceFill(['email_verified_at' => null])->save(),
            'sign-out' => $this->call('POST', '/account/sign-out', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk(),
            'account-switch' => $this->login(CustomerFixtures::account()),
            'foreign-reference' => $id = (string) Str::uuid(),
        };
        $this->postJson('/account/purchase-claim/complete', ['orderId' => $id])->assertForbidden()->assertDontSee($paid['order']->owner_key);
        $this->assertDatabaseCount('customer_purchase_claims', 0);
    }

    public function test_claim_is_single_order_single_account_and_modified_challenge_retry_is_denied(): void
    {
        $account = CustomerFixtures::account();
        $other = CustomerFixtures::account();
        $paid = $this->ready();
        $claims = app(CustomerPurchaseClaims::class);
        $a = $claims->bind($claims->stage($paid['order']->public_id, $paid['order']->owner_key), $account['principal']);
        $b = $claims->bind($claims->stage($paid['order']->public_id, $paid['order']->owner_key), $other['principal']);
        $claims->complete($a, $paid['order']->public_id, $account['principal'], $account['user']);
        try {
            $claims->complete($b, $paid['order']->public_id, $other['principal'], $other['user']);
            $this->fail('Second account adopted a purchase.');
        } catch (CustomerAccessException) {
        }
        $b = $claims->bind(array_intersect_key($b, array_flip(['id', 'proof', 'orderId'])), $account['principal']);
        try {
            $claims->complete($b, $paid['order']->public_id, $account['principal'], $account['user']);
            $this->fail('Modified challenge replay accepted.');
        } catch (CustomerAccessException) {
        }
        $this->assertDatabaseCount('customer_purchase_claims', 1);
    }

    public function test_commit_time_expiry_rolls_back_claim_without_losing_possession_challenge(): void
    {
        $account = CustomerFixtures::account();
        $paid = $this->ready();
        $claims = app(CustomerPurchaseClaims::class);
        $marker = $claims->bind($claims->stage($paid['order']->public_id, $paid['order']->owner_key), $account['principal']);
        AuditEvent::created(function ($audit): void {
            if ($audit->action === 'customer.test_purchase.saved') {
                $this->travel(600)->seconds();
            }
        });
        try {
            $claims->complete($marker, $paid['order']->public_id, $account['principal'], $account['user']);
            $this->fail('Expired transaction committed.');
        } catch (CustomerAccessException) {
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('customer_purchase_claims', 0);
        $this->assertDatabaseCount('customer_purchase_challenges', 1);
        $this->assertSame(0, AuditEvent::where('action', 'customer.test_purchase.saved')->count());
    }

    public function test_claimed_download_still_rechecks_withdrawal_after_byte_preparation(): void
    {
        $account = CustomerFixtures::account();
        $paid = $this->ready();
        $this->saved($account, $paid);
        $streams = DeliveryFixtures::observingStreams();
        $streams->afterPrepare = fn () => CustomerFixtures::withdraw($account);
        $this->app->instance(PrepareTestDeliveryStream::class, $streams);
        $this->postJson('/orders/'.$paid['order']->public_id.'/delivery/authorizations', ['grantId' => $paid['grant']->public_id, 'kind' => 'contract'], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('test_delivery_authorizations', 0);
        $this->assertSame([0], $streams->transactionLevels);
        foreach ($streams->resources as $resource) {
            $this->assertFalse(is_resource($resource));
        }
    }

    public function test_original_guest_possession_is_required_and_account_owned_orders_cannot_be_staged(): void
    {
        $account = CustomerFixtures::account();
        $paid = $this->ready();
        $this->flushSession();
        Auth::forgetGuards();
        $this->postJson('/account/purchase-claim/stage', ['orderId' => $paid['order']->public_id])->assertForbidden();
        $owned = CustomerFixtures::ready($account['user'], 'CLAIM');
        $this->expectException(CustomerAccessException::class);
        app(CustomerPurchaseClaims::class)->stage($owned['order']->public_id, $account['principal']->ownerKey);
    }

    public static function downloadMutations(): array
    {
        return [['issue'], ['redeem']];
    }

    #[DataProvider('downloadMutations')]
    public function test_claim_gate_withdrawal_in_audit_rolls_back_download_effects(string $operation): void
    {
        $account = CustomerFixtures::account();
        $paid = $this->ready();
        $this->saved($account, $paid);
        $url = '/orders/'.$paid['order']->public_id.'/delivery';
        $auth = null;
        if ($operation === 'redeem') {
            $auth = $this->postJson($url.'/authorizations', ['grantId' => $paid['grant']->public_id, 'kind' => 'contract'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('authorization');
        }
        $action = $operation === 'issue' ? 'commerce.delivery.authorized' : 'commerce.delivery.redeemed';
        AuditEvent::created(function ($audit) use ($action): void {
            if ($audit->action === $action) {
                config(['customer.test_purchase_claims_enabled' => false]);
            }
        });
        try {
            if ($auth) {
                $body = http_build_query(['authorizationId' => $auth['authorizationId'], 'token' => $auth['token'], '_token' => session()->token()]);
                $this->call('POST', $url.'/download', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)->assertForbidden();
            } else {
                $this->postJson($url.'/authorizations', ['grantId' => $paid['grant']->public_id, 'kind' => 'contract'], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
            }
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('test_delivery_authorizations', $auth ? 1 : 0);
        $this->assertDatabaseCount('test_delivery_redemptions', 0);
        $this->assertSame(0, AuditEvent::where('action', $action)->count());
    }

    public function test_audit_write_failure_rolls_back_claim_and_keeps_originals_unchanged(): void
    {
        $account = CustomerFixtures::account();
        $paid = $this->ready();
        $originals = DeliveryFixtures::retained();
        $this->stage($paid);
        $this->login($account);
        AuditEvent::creating(function ($audit): void {
            if ($audit->action === 'customer.test_purchase.saved') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            $this->postJson('/account/purchase-claim/complete', ['orderId' => $paid['order']->public_id])->assertForbidden();
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('customer_purchase_claims', 0);
        $this->assertSame($originals, DeliveryFixtures::retained());
    }

    public function test_pending_challenge_limit_and_malformed_dates_are_enforced_by_durable_guards(): void
    {
        $paid = $this->ready();
        $claims = app(CustomerPurchaseClaims::class);
        for ($i = 0; $i < 4; $i++) {
            $claims->stage($paid['order']->public_id, $paid['order']->owner_key);
        }
        try {
            $claims->stage($paid['order']->public_id, $paid['order']->owner_key);
            $this->fail('Unbounded stage accepted.');
        } catch (CustomerAccessException) {
        }
        $this->assertDatabaseCount('customer_purchase_challenges', 4);
        $row = (array) DB::table('customer_purchase_challenges')->first();
        unset($row['id']);
        foreach (['created_at', 'expires_at'] as $field) {
            foreach (['not-a-date', '2026-10-06 12:00:00x', '2026-02-31 00:00:00'] as $invalid) {
                $bad = array_replace($row, ['public_id' => (string) Str::uuid(), 'proof_hash' => hash('sha256', Str::random(40)), $field => $invalid]);
                try {
                    DB::table('customer_purchase_challenges')->insert($bad);
                    $this->fail('Malformed durable timestamp accepted.');
                } catch (QueryException) {
                }
            }
        }
        $this->assertDatabaseCount('customer_purchase_challenges', 4);
    }

    public function test_disabled_and_production_gates_refuse_claim_without_creating_evidence(): void
    {
        $paid = $this->ready();
        foreach (['disabled', 'production'] as $gate) {
            config(['customer.test_purchase_claims_enabled' => $gate !== 'disabled']);
            if ($gate === 'production') {
                $this->app->detectEnvironment(fn () => 'production');
            }
            try {
                app(CustomerPurchaseClaims::class)->stage($paid['order']->public_id, $paid['order']->owner_key);
                $this->fail('Claim escaped test gate.');
            } catch (CustomerAccessException) {
            } finally {
                $this->app->detectEnvironment(fn () => 'testing');
            }
        }
        $this->assertDatabaseCount('customer_purchase_challenges', 0);
    }

    public function test_current_login_after_credential_recovery_retains_saved_order_but_cannot_replay_old_claim(): void
    {
        $account = CustomerFixtures::account();
        $paid = $this->ready();
        $this->saved($account, $paid);
        $this->travel(601)->seconds();
        $account['user']->update(['password' => Hash::make('Recovery-password-test-only-42!')]);
        $this->getJson('/orders/'.$paid['order']->public_id.'/status')->assertForbidden();
        $this->postJson('/account/sign-in', ['email' => $account['user']->email, 'password' => 'Recovery-password-test-only-42!'])->assertOk();
        $this->getJson('/orders/'.$paid['order']->public_id.'/status')->assertOk();
        $this->getJson('/orders/'.$paid['order']->public_id.'/items')->assertOk();
        $this->assertFalse(session()->has('_customer_purchase_claim'));
        $this->postJson('/account/purchase-claim/complete', ['orderId' => $paid['order']->public_id])->assertForbidden();
        $this->assertDatabaseCount('customer_purchase_claims', 1);
    }

    public function test_retained_challenge_policy_and_interval_corruption_fail_closed_without_requiring_live_proof_expiry(): void
    {
        $account = CustomerFixtures::account();
        $paid = $this->ready();
        $this->saved($account, $paid);
        $claim = CustomerPurchaseClaim::sole();
        $challenge = CustomerPurchaseChallenge::sole();
        $this->travel(601)->seconds();
        app(CustomerPurchaseClaims::class)->verify($claim, $paid['order'], $account['principal']);
        // Simulates corruption outside the guarded writer, restoring every original byte after each probe.
        DB::unprepared('DROP TRIGGER customer_purchase_challenges_update');
        foreach (['policy_version' => 'unknown', 'expires_at' => $challenge->expires_at->addSecond()->format('Y-m-d H:i:s'),
            'created_at' => $challenge->created_at->subSecond()->format('Y-m-d H:i:s')] as $field => $changed) {
            $original = DB::table('customer_purchase_challenges')->where('id', $challenge->id)->value($field);
            DB::table('customer_purchase_challenges')->where('id', $challenge->id)->update([$field => $changed]);
            try {
                app(CustomerPurchaseClaims::class)->verify($claim, $paid['order'], $account['principal']);
                $this->fail('Corrupt retained challenge accepted.');
            } catch (CustomerAccessException) {
            } finally {
                DB::table('customer_purchase_challenges')->where('id', $challenge->id)->update([$field => $original]);
            }
        }
        $this->assertDatabaseCount('customer_purchase_claims', 1);
    }

    public function test_malformed_claim_timestamp_cannot_fit_lexically_inside_a_valid_challenge(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06T01:00:00Z'));
        $account = CustomerFixtures::account();
        $paid = $this->ready();
        app(CustomerPurchaseClaims::class)->stage($paid['order']->public_id, $paid['order']->owner_key);
        $row = ['public_id' => (string) Str::uuid(), 'order_id' => $paid['order']->id, 'challenge_id' => CustomerPurchaseChallenge::sole()->id,
            'account_id' => $account['account']->id, 'evidence_ciphertext' => 'synthetic', 'evidence_hash' => hash('sha256', 'synthetic'), 'claimed_at' => '2026-10-06 01:0z:00'];
        try {
            DB::table('customer_purchase_claims')->insert($row);
            $this->fail('Malformed lexical timestamp accepted.');
        } catch (QueryException) {
        }
        $this->assertDatabaseCount('customer_purchase_claims', 0);
    }

    public function test_claim_http_requires_real_csrf_and_rejects_noncanonical_requests_before_writes(): void
    {
        $this->app->instance(PreventRequestForgery::class,
            new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            });
        $this->postJson('/account/purchase-claim/stage', ['orderId' => (string) Str::uuid()])->assertStatus(419);
        $csrf = Str::random(40);
        $this->withSession(['_token' => $csrf]);
        foreach (['{"orderId":"'.Str::uuid().'","accountId":1}', '{"orderId":"'.Str::uuid().'","orderId":"'.Str::uuid().'"}',
            '{"orderId":[]}', str_repeat('x', 4097)] as $body) {
            $response = $this->call('POST', '/account/purchase-claim/stage', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], $body);
            $this->assertContains($response->status(), [403, 413]);
        }
        $this->postJson('/account/purchase-claim/stage?orderId='.Str::uuid(), ['orderId' => (string) Str::uuid()], ['X-CSRF-TOKEN' => $csrf])->assertStatus(422);
        $this->postJson('/account/purchase-claim/stage', ['orderId' => (string) Str::uuid()], ['X-CSRF-TOKEN' => $csrf, 'Origin' => 'https://foreign.test'])->assertForbidden();
        $this->assertDatabaseCount('customer_purchase_challenges', 0);
        $this->assertDatabaseCount('customer_purchase_claims', 0);
    }

    public function test_guest_order_requires_verified_paid_finalization_and_issued_original_contracts(): void
    {
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $paid = FinalizationFixtures::ownedHttp($this, $gateway);
        $claims = app(CustomerPurchaseClaims::class);
        try {
            $claims->stage($paid['order']->public_id, $paid['order']->owner_key);
            $this->fail('Unfinalized purchase accepted.');
        } catch (CustomerAccessException) {
        }
        $paid = ContractFixtures::finalize($paid);
        try {
            $claims->stage($paid['order']->public_id, $paid['order']->owner_key);
            $this->fail('Missing original contracts accepted.');
        } catch (CustomerAccessException) {
        }
        $this->assertDatabaseCount('customer_purchase_challenges', 0);
        ActivationFixtures::issue($paid);
        $marker = $claims->stage($paid['order']->public_id, $paid['order']->owner_key);
        $this->assertSame($paid['order']->public_id, $marker['orderId']);
        $this->assertDatabaseCount('customer_purchase_challenges', 1);
    }

    public static function privateProjections(): array
    {
        return [['status'], ['items'], ['delivery'], ['history']];
    }

    #[DataProvider('privateProjections')]
    public function test_claim_gate_withdrawal_during_retained_projection_cannot_escape_in_response(string $surface): void
    {
        $account = CustomerFixtures::account();
        $paid = $this->ready();
        $this->saved($account, $paid);
        $changed = false;
        $orders = 0;
        $lines = 0;
        DB::listen(function ($query) use ($surface, &$changed, &$orders, &$lines): void {
            if ($changed || ! str_starts_with(strtolower($query->sql), 'select')) {
                return;
            }
            if (preg_match('/from [`"]orders[`"]/', $query->sql)) {
                $orders++;
            }
            if (preg_match('/from [`"]order_lines[`"]/', $query->sql)) {
                $lines++;
            }
            // Exact-order controllers resolve once, then their reader selects the original order.
            // History already owns its rows; withdraw while its first retained graph is reconstructed.
            if (($surface !== 'history' && $orders === 2) || ($surface === 'history' && $lines === 1)) {
                $changed = true;
                config(['customer.test_purchase_claims_enabled' => false]);
            }
        });
        $url = $surface === 'history' ? '/orders/history' : '/orders/'.$paid['order']->public_id.'/'.$surface;
        $this->get($url, ['Accept' => 'application/json'])->assertForbidden()->assertDontSee($paid['order']->public_id)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertTrue($changed);
        $this->assertDatabaseCount('customer_purchase_claims', 1);
    }


    public function test_claim_withdrawal_during_history_entry_payment_projection_hides_original_preview(): void
    {
        $account = CustomerFixtures::account(); $paid = $this->ready(); $this->saved($account, $paid);
        $before = DeliveryFixtures::retained(); $projected = 0;
        DB::listen(function ($query) use (&$projected): void {
            // The first outcome projection belongs to initial claim verification. The second is
            // historyEntry's own summary, after its original title/license fields were verified.
            if (preg_match('/\Aselect [`"]outcome[`"] from [`"]order_finalizations[`"]/i', $query->sql)) {
                $projected++;
                if ($projected === 2) config(['customer.test_purchase_claims_enabled' => false]);
            }
        });
        $this->getJson('/orders/history')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee($paid['order']->public_id)->assertDontSee('Synthetic quote recording');
        $this->assertSame(2, $projected);
        $this->assertSame($before, DeliveryFixtures::retained());
        $this->assertDatabaseCount('customer_purchase_claims', 1);
    }

    public function test_direct_sql_mutation_delete_and_replace_cannot_change_retained_claim_evidence(): void
    {
        $account = CustomerFixtures::account();
        $paid = $this->ready();
        $this->saved($account, $paid);
        try {
            (require database_path('migrations/2026_10_06_000048_customer_purchase_claims.php'))->down();
            $this->fail('Retained purchase evidence was dropped.');
        } catch (\LogicException) {
        }
        foreach (['customer_purchase_challenges', 'customer_purchase_claims'] as $table) {
            $row = (array) DB::table($table)->sole();
            foreach (['update', 'delete', 'replace'] as $operation) {
                try {
                    if ($operation === 'update') {
                        DB::table($table)->where('id', $row['id'])->update(['public_id' => (string) Str::uuid()]);
                    } elseif ($operation === 'delete') {
                        DB::table($table)->where('id', $row['id'])->delete();
                    } else {
                        $columns = array_keys($row);
                        $names = implode(',', array_map(fn ($column) => '`'.$column.'`', $columns));
                        DB::statement('REPLACE INTO '.$table.' ('.$names.') VALUES ('.implode(',', array_fill(0, count($row), '?')).')', array_values($row));
                    }
                    $this->fail('Retained '.$table.' allowed '.$operation);
                } catch (QueryException) {
                }
                $this->assertSame($row, (array) DB::table($table)->sole());
            }
        }
    }
}
