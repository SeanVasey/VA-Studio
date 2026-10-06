<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Customers\CustomerPurchaseClaims;
use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Inquiries\InquiryConversation;
use App\Domain\Inquiries\InquiryException;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\OrderInquiry;
use App\Domain\Inquiries\SubmitInquiry;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ActivationFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InquiryConversationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderInquiryFixtures as Fixture;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class OrderInquiryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $this->fixture = Fixture::configure();
    }

    public function test_explicit_guest_link_retries_exactly_and_retains_originals_hash_contract_and_private_reference(): void
    {
        $order = Fixture::guest();
        $body = Fixture::body();
        $before = $this->commerce();
        $original = $this->fixture['inquiry']->getAttributes();
        $service = app(OrderInquiry::class);
        $setup = $service->setup($order->public_id, InventoryFixtures::OWNER);
        $this->assertSame(['orderInquirySchema', 'orderId', 'testOnly', 'privacyNotice', 'noticeToken'], array_keys($setup));
        $this->assertSame($order->public_id, $setup['orderId']);
        $saved = $service->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, $body);
        $this->assertFalse($saved['replayed']);
        $replay = $service->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, $body);
        $this->assertSame(array_replace($saved, ['replayed' => true]), $replay);
        $inquiry = CustomerInquiry::where('public_id', $saved['receipt'])->sole();
        $this->assertSame(CanonicalJson::hash(array_diff_key($body, ['requestKey' => true])), $inquiry->payload_hash);
        $this->assertSame(array_diff_key($body, ['requestKey' => true, 'noticeToken' => true]), $inquiry->payload);
        $this->assertSame(['orderInquiryContextSchema' => 1, 'order' => ['id' => $order->public_id, 'testOnly' => true]],
            $service->ownerContext($saved['receipt'], InquiryConversationFixtures::OWNER));
        $this->assertSame($before, $this->commerce());
        $this->assertSame($original, $this->fixture['inquiry']->fresh()->getAttributes());
        $this->assertDatabaseCount('inquiry_order_contexts', 1);
        $this->assertSame(1, AuditEvent::where('action', 'inquiry.test_order_linked')->count());
        $this->assertNull(AuditEvent::where('action', 'inquiry.test_order_linked')->sole()->actor_id);
        $this->assertStringNotContainsString($body['message'], DB::table('customer_inquiries')->get()->toJson());
        $this->assertStringNotContainsString($body['email'], AuditEvent::all()->toJson());
    }

    public function test_generic_inquiry_has_no_context_and_cannot_adopt_or_retry_an_order_linked_key(): void
    {
        $service = app(OrderInquiry::class);
        $this->assertSame(['orderInquiryContextSchema' => 1, 'order' => null], $service->ownerContext($this->fixture['inquiry']->public_id, InquiryConversationFixtures::OWNER));
        $order = Fixture::guest();
        $this->refused(fn () => $service->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, $this->fixture['body']), 409);
        $body = Fixture::body();
        $service->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, $body);
        $this->refused(fn () => app(SubmitInquiry::class)->handle($body, InquiryConversationFixtures::OWNER), 409);
        $this->assertDatabaseCount('customer_inquiries', 2);
        $this->assertDatabaseCount('inquiry_order_contexts', 1);
    }

    public function test_request_key_binds_order_body_and_inquiry_owner_without_rebinding_or_writes(): void
    {
        $service = app(OrderInquiry::class);
        $order = Fixture::guest();
        $other = Fixture::guest();
        $body = Fixture::body();
        $service->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, $body);
        $before = $this->retained();
        foreach ([[$other->public_id, InquiryConversationFixtures::OWNER, $body],
            [$order->public_id, str_repeat('b', 64), $body],
            [$order->public_id, InquiryConversationFixtures::OWNER, array_replace($body, ['message' => 'Changed'])]] as [$id, $owner, $input]) {
            $this->refused(fn () => $service->submit($id, InventoryFixtures::OWNER, $owner, $input), 409);
        }
        $this->assertSame($before, $this->retained());
    }

    public function test_current_direct_account_can_submit_but_its_order_does_not_adopt_the_conversation(): void
    {
        $account = CustomerFixtures::account();
        $order = CustomerFixtures::prepared($account['user']);
        $service = app(OrderInquiry::class);
        $saved = $service->submit($order->public_id, $account['principal']->ownerKey, InquiryConversationFixtures::OWNER,
            Fixture::body(), $account['principal'], $account['user']);
        $this->assertSame($account['user']->id, AuditEvent::where('action', 'inquiry.test_order_linked')->sole()->actor_id);
        $this->refused(fn () => $service->ownerContext($saved['receipt'], $account['principal']->ownerKey), 404);
        $this->assertSame($order->public_id, $service->ownerContext($saved['receipt'], InquiryConversationFixtures::OWNER)['order']['id']);
    }

    #[DataProvider('gateWithdrawals')]
    public function test_admission_requires_explicit_local_enabled_intake_and_current_operator(string $kind): void
    {
        $order = Fixture::guest();
        $body = Fixture::body();
        $before = $this->retained();
        match ($kind) {
            'new flag' => config(['inquiries.test_order_inquiries_enabled' => false]),
            'intake' => config(['inquiries.enabled' => false]),
            'notice' => config(['inquiries.privacy_notice' => null]),
            'operator' => $this->fixture['actor']->forceFill(['is_admin' => false])->save(),
            'production' => $this->app->instance('env', 'production'),
        };
        try {
            $this->refused(fn () => app(OrderInquiry::class)->setup($order->public_id, InventoryFixtures::OWNER), 404);
            $this->refused(fn () => app(OrderInquiry::class)->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, $body), 404);
            $this->assertSame($before, $this->retained());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public static function gateWithdrawals(): array
    {
        return array_combine(['new flag', 'intake', 'notice', 'operator', 'production'], array_map(fn ($kind) => [$kind], ['new flag', 'intake', 'notice', 'operator', 'production']));
    }

    public function test_foreign_unknown_and_case_changed_orders_are_indistinguishable_without_inquiry_or_audit_writes(): void
    {
        $order = Fixture::guest();
        $body = Fixture::body();
        $before = $this->retained();
        $service = app(OrderInquiry::class);
        foreach ([[$order->public_id, str_repeat('b', 64)], [(string) Str::uuid(), InventoryFixtures::OWNER], [strtoupper($order->public_id), InventoryFixtures::OWNER]] as [$id, $owner]) {
            $this->refused(fn () => $service->setup($id, $owner), 404);
            $this->refused(fn () => $service->submit($id, $owner, InquiryConversationFixtures::OWNER, $body), 404);
        }
        $this->assertSame($before, $this->retained());
    }

    public function test_retained_context_survives_write_withdrawal_and_archive_without_granting_current_order_access(): void
    {
        $order = Fixture::guest();
        $service = app(OrderInquiry::class);
        $saved = $service->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, Fixture::body());
        $inquiry = CustomerInquiry::where('public_id', $saved['receipt'])->sole();
        app(InquiryAdministration::class)->transition($inquiry->id, 'archived', 0, $this->fixture['actor']);
        config(['inquiries.test_order_inquiries_enabled' => false, 'inquiries.enabled' => false]);
        $before = $this->retained();
        $context = $service->ownerContext($saved['receipt'], InquiryConversationFixtures::OWNER);
        $this->assertSame(['id' => $order->public_id, 'testOnly' => true], $context['order']);
        $this->assertSame($before, $this->retained());
        $this->refused(fn () => $service->ownerContext($saved['receipt'], str_repeat('c', 64)), 404);
        $this->assertFalse(app(InquiryConversation::class)->owner($saved['receipt'], InquiryConversationFixtures::OWNER)['canReply']);
    }

    public function test_staff_context_rechecks_current_authority_and_audits_without_returning_buyer_data(): void
    {
        $order = Fixture::guest();
        $service = app(OrderInquiry::class);
        $saved = $service->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, Fixture::body());
        $inquiry = CustomerInquiry::where('public_id', $saved['receipt'])->sole();
        $this->assertSame(['orderInquiryContextSchema' => 1, 'order' => ['id' => $order->public_id, 'testOnly' => true]], $service->staffContext($inquiry->id, $this->fixture['actor']));
        $this->assertSame($this->fixture['actor']->id, AuditEvent::where('action', 'inquiry.order_context_viewed')->sole()->actor_id);
        $this->fixture['actor']->forceFill(['is_admin' => false])->save();
        $this->expectException(AuthorizationException::class);
        $service->staffContext($inquiry->id, $this->fixture['actor']);
    }

    public function test_audit_failure_rolls_back_the_inquiry_context_and_all_original_rows(): void
    {
        $order = Fixture::guest();
        $body = Fixture::body();
        $before = $this->retained();
        $fail = true;
        AuditEvent::creating(function (AuditEvent $event) use (&$fail): void {
            if ($fail && $event->action === 'inquiry.test_order_linked') {
                $fail = false;
                throw new RuntimeException('SYNTHETIC PRIVATE FAILURE');
            }
        });
        try {
            app(OrderInquiry::class)->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, $body);
            $this->fail('Audit failure was ignored.');
        } catch (RuntimeException $error) {
            $this->assertSame('SYNTHETIC PRIVATE FAILURE', $error->getMessage());
        }
        $this->assertSame($before, $this->retained());
        $saved = app(OrderInquiry::class)->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, $body);
        $this->assertFalse($saved['replayed']);
    }

    public function test_post_audit_withdrawal_rolls_back_and_ambient_transaction_is_refused(): void
    {
        $order = Fixture::guest();
        $body = Fixture::body();
        $before = $this->retained();
        AuditEvent::created(function (AuditEvent $event): void {
            if ($event->action === 'inquiry.test_order_linked') {
                config(['inquiries.test_order_inquiries_enabled' => false]);
            }
        });
        $this->refused(fn () => app(OrderInquiry::class)->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, $body), 404);
        $this->assertSame($before, $this->retained());
        DB::beginTransaction();
        try {
            app(OrderInquiry::class)->setup($order->public_id, InventoryFixtures::OWNER);
            $this->fail('Ambient transaction admitted.');
        } catch (LogicException $error) {
            $this->assertSame('Order inquiry admission requires its own current transaction.', $error->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_exact_claim_access_retains_the_original_order_and_never_adopts_conversation_identity(): void
    {
        DeliveryFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        app()->instance(StripeCheckoutGateway::class, $gateway);
        app()->instance(StripePaymentGateway::class, $gateway);
        app()->instance(ContractRenderer::class, ContractFixtures::renderer());
        $paid = DeliveryFixtures::activate(ActivationFixtures::issue(
            ContractFixtures::finalize(FinalizationFixtures::confirmed($gateway))));
        $order = $paid['order'];
        $account = CustomerFixtures::account();
        $foreign = CustomerFixtures::account();
        config(['customer.test_purchase_claims_enabled' => true]);
        $claims = app(CustomerPurchaseClaims::class);
        $marker = $claims->bind($claims->stage($order->public_id, $order->owner_key), $account['principal']);
        $claims->complete($marker, $order->public_id, $account['principal'], $account['user']);
        $before = DeliveryFixtures::retained();
        $claimRows = DB::table('customer_purchase_claims')->get()->toJson();
        $calls = $gateway->calls;
        $service = app(OrderInquiry::class);
        $body = Fixture::body();
        $this->refused(fn () => $service->submit($order->public_id, $foreign['principal']->ownerKey, InquiryConversationFixtures::OWNER,
            $body, $foreign['principal'], $foreign['user']), 404);
        $saved = $service->submit($order->public_id, $account['principal']->ownerKey, InquiryConversationFixtures::OWNER,
            $body, $account['principal'], $account['user']);
        $this->assertSame($order->public_id, $service->ownerContext($saved['receipt'], InquiryConversationFixtures::OWNER)['order']['id']);
        $this->refused(fn () => $service->ownerContext($saved['receipt'], $account['principal']->ownerKey), 404);
        $this->assertSame($before, DeliveryFixtures::retained());
        $this->assertSame($claimRows, DB::table('customer_purchase_claims')->get()->toJson());
        $this->assertSame($calls, $gateway->calls);
        config(['customer.test_purchase_claims_enabled' => false]);
        $this->refused(fn () => $service->submit($order->public_id, $account['principal']->ownerKey, InquiryConversationFixtures::OWNER,
            $body, $account['principal'], $account['user']), 404);
    }

    #[DataProvider('customerWithdrawals')]
    public function test_current_customer_credentials_authority_and_access_version_fence_new_and_exact_retry(string $kind): void
    {
        $account = CustomerFixtures::account();
        $order = CustomerFixtures::prepared($account['user']);
        $body = Fixture::body();
        $service = app(OrderInquiry::class);
        $service->submit($order->public_id, $account['principal']->ownerKey, InquiryConversationFixtures::OWNER, $body, $account['principal'], $account['user']);
        match ($kind) {
            'account' => CustomerFixtures::withdraw($account),
            'credential' => $account['user']->forceFill(['password' => 'Changed-synthetic-password-43!'])->save(),
            'verified' => $account['user']->forceFill(['email_verified_at' => null])->save(),
            'role' => $account['user']->forceFill(['is_admin' => true])->save(),
            'version' => (function () use ($account): void {
                CustomerFixtures::withdraw($account);
                $current = $account['account']->fresh();
                $current->update(['active' => true, 'access_version' => $current->access_version + 1]);
            })(),
        };
        $before = $this->retained();
        $this->refused(fn () => $service->setup($order->public_id, $account['principal']->ownerKey, $account['principal'], $account['user']), 404);
        foreach ([$body, Fixture::body()] as $input) {
            $this->refused(fn () => $service->submit($order->public_id, $account['principal']->ownerKey, InquiryConversationFixtures::OWNER,
                $input, $account['principal'], $account['user']), 404);
        }
        $this->assertSame($before, $this->retained());
    }

    public static function customerWithdrawals(): array
    {
        return array_map(fn ($kind) => [$kind], ['account', 'credential', 'verified', 'role', 'version']);
    }

    public function test_post_audit_customer_withdrawal_rolls_back_and_stale_notice_cannot_create_a_link(): void
    {
        $account = CustomerFixtures::account();
        $order = CustomerFixtures::prepared($account['user']);
        $body = Fixture::body();
        $before = $this->retained();
        $original = $account['account']->fresh()->getAttributes();
        $armed = true;
        AuditEvent::created(function (AuditEvent $event) use ($account, &$armed): void {
            if ($armed && $event->action === 'inquiry.test_order_linked') {
                $armed = false;
                CustomerFixtures::withdraw($account);
            }
        });
        $this->refused(fn () => app(OrderInquiry::class)->submit($order->public_id, $account['principal']->ownerKey,
            InquiryConversationFixtures::OWNER, $body, $account['principal'], $account['user']), 404);
        $this->assertSame($before, $this->retained());
        $this->assertSame($original, $account['account']->fresh()->getAttributes());
        config(['inquiries.privacy_notice' => 'New synthetic notice for explicit review']);
        $this->refused(fn () => app(OrderInquiry::class)->submit($order->public_id, $account['principal']->ownerKey,
            InquiryConversationFixtures::OWNER, $body, $account['principal'], $account['user']), 422);
        $this->assertSame($before, $this->retained());
    }

    public function test_corrupted_retained_order_is_not_projected_as_a_verified_test_reference(): void
    {
        $order = Fixture::guest();
        $service = app(OrderInquiry::class);
        $saved = $service->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, Fixture::body());
        // Simulate an at-rest reader fault without dropping immutable database guards.
        Order::retrieved(function ($order): void {
            $order->payload_ciphertext = 'corrupt';
        });
        $this->refused(fn () => $service->ownerContext($saved['receipt'], InquiryConversationFixtures::OWNER), 503);
        $this->refused(fn () => $service->staffContext(CustomerInquiry::where('public_id', $saved['receipt'])->sole()->id, $this->fixture['actor']), 503);
    }

    #[DataProvider('lateStaffWithdrawals')]
    public function test_staff_context_rechecks_authority_after_reconstruction_and_audit(string $event, string $field): void
    {
        $actor = $this->fixture['actor'];
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $order = Fixture::guest();
            $service = app(OrderInquiry::class);
            $saved = $service->submit($order->public_id, InventoryFixtures::OWNER, InquiryConversationFixtures::OWNER, Fixture::body());
            $inquiry = CustomerInquiry::where('public_id', $saved['receipt'])->sole();
            $original = $actor->fresh()->getAttributes();
            $armed = true;
            $withdraw = function () use ($actor, $field, &$armed): void {
                if (! $armed) {
                    return;
                } $armed = false;
                $current = $actor->fresh();
                if ($field === 'role') {
                    $current->forceFill(['is_admin' => false])->save();
                } else {
                    $current->saveAppAuthenticationSecret(null);
                }
            };
            if ($event === 'read') {
                Order::retrieved($withdraw);
            } else {
                AuditEvent::created(function ($event) use ($withdraw): void {
                    if ($event->action === 'inquiry.order_context_viewed') {
                        $withdraw();
                    }
                });
            }
            try {
                $service->staffContext($inquiry->id, $actor);
                $this->fail('Late staff withdrawal escaped.');
            } catch (AuthorizationException) {
                $this->assertSame(0, AuditEvent::where('action', 'inquiry.order_context_viewed')->count());
            }
            $this->assertSame($original, $actor->fresh()->getAttributes());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public static function lateStaffWithdrawals(): array
    {
        return [['read', 'role'], ['read', 'mfa'], ['audit', 'role'], ['audit', 'mfa']];
    }

    public function test_setup_does_not_return_a_reference_after_retained_order_reconstruction_withdraws_customer_access(): void
    {
        $account = CustomerFixtures::account();
        $order = CustomerFixtures::prepared($account['user']);
        $original = $account['account']->fresh()->getAttributes();
        $armed = true;
        Order::retrieved(function () use ($account, &$armed): void {
            if ($armed) {
                $armed = false;
                CustomerFixtures::withdraw($account);
            }
        });
        $this->refused(fn () => app(OrderInquiry::class)->setup($order->public_id, $account['principal']->ownerKey, $account['principal'], $account['user']), 404);
        $this->assertSame($original, $account['account']->fresh()->getAttributes());
    }

    private function commerce(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['orders', 'order_lines', 'order_attempts', 'quotes', 'quote_lines', 'quote_pricings', 'inventory_reservations', 'promotion_uses', 'customer_purchase_claims']);
    }

    private function retained(): array
    {
        return [...$this->commerce(), ...array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['customer_inquiries', 'inquiry_order_contexts', 'inquiry_messages', 'audit_events'])];
    }

    private function refused(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('Expected private inquiry refusal.');
        } catch (InquiryException $error) {
            $this->assertSame($status, $error->status);
        }
    }
}
