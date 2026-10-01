<?php

namespace Tests\Feature;

use App\Domain\Commerce\Finalization\DispatchTestFinalization;
use App\Domain\Commerce\Finalization\FinalizationAssets;
use App\Domain\Commerce\Finalization\FinalizationEvidence;
use App\Domain\Commerce\Finalization\FinalizationPolicy;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Operations\InspectRetainedTestPaymentException;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Filament\Resources\TestPaymentExceptionResource\Pages\ListTestPaymentExceptions;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures as F;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class TestPaymentExceptionInspectionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
    }

    public static function recordedReasons(): array
    {
        return [['late_confirmation', false], ['inventory_blocked', true], ['inventory_unavailable', true],
            ['asset_unavailable', false], ['rights_unavailable', true]];
    }

    #[DataProvider('recordedReasons')]
    public function test_inspection_verifies_each_retained_reason_without_changing_business_evidence(string $reason, bool $promoted): void
    {
        $f = $this->exception($reason, $promoted);
        $actor = LicenseFixtures::admin();
        $before = F::retained();
        $calls = $this->gateway->calls;
        $auditCount = AuditEvent::count();
        Queue::fake();
        $result = app(InspectRetainedTestPaymentException::class)->handle($f['finalization']->public_id, $actor);
        $this->assertSame(['testOnly', 'inspectionStatus', 'finalizationId', 'orderId', 'recordedReason',
            'confirmedAt', 'eligibilityCutoff', 'finalizedAt', 'inventoryState', 'promotionState',
            'grantCount', 'exclusiveSaleCount', 'outboxCount', 'currentProviderState'], array_keys($result));
        $this->assertTrue($result['testOnly']);
        $this->assertSame('verified', $result['inspectionStatus']);
        $this->assertSame($f['order']->public_id, $result['orderId']);
        $this->assertSame($reason, $result['recordedReason']);
        $this->assertSame($f['payment']->confirmed_at->toIso8601ZuluString(), $result['confirmedAt']);
        $this->assertSame($f['order']->attempt()->sole()->expires_at->toIso8601ZuluString(), $result['eligibilityCutoff']);
        $this->assertSame('pending', $result['inventoryState']);
        $this->assertSame($promoted ? 'pending' : 'none', $result['promotionState']);
        $this->assertSame([0, 0, 1], [$result['grantCount'], $result['exclusiveSaleCount'], $result['outboxCount']]);
        $this->assertSame('not_inspected', $result['currentProviderState']);
        $this->assertSame($before, F::retained());
        $this->assertSame($calls, $this->gateway->calls);
        Queue::assertNothingPushed();
        $audit = AuditEvent::latest('id')->first();
        $this->assertSame($auditCount + 1, AuditEvent::count());
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame('commerce.payment_exception.inspected', $audit->action);
        $this->assertSame(OrderFinalization::class, $audit->subject_type);
        $this->assertSame($f['finalization']->id, $audit->subject_id);
        $context = $audit->context;
        // JSON object member order is not retained by MySQL; types, values and the exact key set remain strict.
        ksort($context, SORT_STRING);
        $this->assertSame(['finalization_id' => $f['finalization']->public_id, 'result' => 'verified', 'test_only' => true], $context);
        $this->assertPrivateAbsent(json_encode([$result, $audit->context], JSON_THROW_ON_ERROR), $f);
    }

    public function test_replay_and_policy_withdrawal_keep_the_exception_pending_without_provider_or_storage_work(): void
    {
        $f = $this->exception('inventory_blocked', true);
        $actor = LicenseFixtures::admin();
        app(ManageRightsScope::class)->block($f['scope']->id, false, 1, 'SYNTHETIC-INSPECTION-UNBLOCK', $f['actor']);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $before = F::retained();
        $auditCount = AuditEvent::count();
        Queue::fake();
        config(['payments.stripe.checkout_enabled' => false, 'payments.stripe.processing_enabled' => false,
            'payments.stripe.finalization_enabled' => false, 'payments.stripe.finalization_policy' => null,
            'commerce.test_order_policy' => null, 'commerce.test_checkout_policy' => null]);
        $unavailable = new class implements StripeCheckoutGateway, StripePaymentGateway
        {
            public function account(): array
            {
                throw new RuntimeException('Provider must not be called.');
            }

            public function create(array $params, string $idempotencyKey): array
            {
                throw new RuntimeException('Provider must not be called.');
            }

            public function retrieve(string $sessionId): array
            {
                throw new RuntimeException('Provider must not be called.');
            }

            public function paymentIntent(string $paymentIntentId): array
            {
                throw new RuntimeException('Provider must not be called.');
            }
        };
        $this->app->instance(StripeCheckoutGateway::class, $unavailable);
        $this->app->instance(StripePaymentGateway::class, $unavailable);
        foreach ([FinalizationAssets::class, ContractRenderer::class, DispatchTestFinalization::class] as $service) {
            $this->app->bind($service, fn () => throw new RuntimeException('Private fulfillment services must not be called.'));
        }
        Storage::shouldReceive('disk')->never();
        $first = app(InspectRetainedTestPaymentException::class)->handle($f['finalization']->public_id, $actor);
        $second = app(InspectRetainedTestPaymentException::class)->handle($f['finalization']->public_id, $actor);
        $this->assertSame($first, $second);
        $this->assertSame('inventory_blocked', $second['recordedReason']);
        $this->assertSame($before, F::retained());
        $this->assertSame($auditCount + 2, AuditEvent::count());
        Queue::assertNothingPushed();
    }

    public static function cartTypes(): array
    {
        return [['nonexclusive'], ['mixed']];
    }

    #[DataProvider('cartTypes')]
    public function test_nonexclusive_and_mixed_order_exceptions_do_not_acquire_or_partially_issue_rights(string $type): void
    {
        if ($type === 'nonexclusive') {
            $f = $this->exception('late_confirmation', false, false);
        } else {
            $f = F::confirmedMixedCart($this->gateway);
            $this->assertCount(2, $f['original']['lines']);
            app(ManageRightsScope::class)->block($f['exclusive']['scope']->id, true, 0,
                'SYNTHETIC-MIXED-INSPECTION-BLOCK', $f['exclusive']['actor']);
            $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        }
        $record = OrderFinalization::where('order_id', $f['order']->id)->sole();
        $before = F::retained();
        $result = app(InspectRetainedTestPaymentException::class)->handle($record->public_id, LicenseFixtures::admin());
        $this->assertSame('verified', $result['inspectionStatus']);
        $this->assertSame($type === 'mixed' ? 'pending' : 'none', $result['promotionState']);
        $this->assertSame(0, $result['grantCount']);
        $this->assertSame(0, $result['exclusiveSaleCount']);
        $this->assertSame($before, F::retained());
        $this->actingAs(LicenseFixtures::admin());
        Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('inspectEvidence', $record)
            ->assertMountedActionModalSee('Stored graph verified')->assertMountedActionModalSee('0 grants')
            ->assertMountedActionModalSee('0 exclusive sales');
        $this->assertSame($before, F::retained());
    }

    public static function withdrawnAuthority(): array
    {
        return [['guest'], ['customer'], ['unverified'], ['revoked'], ['deleted'], ['mfa_withdrawn']];
    }

    #[DataProvider('withdrawnAuthority')]
    public function test_direct_service_reloads_authority_before_decrypting_or_auditing(string $scenario): void
    {
        $f = $this->exception();
        $actor = LicenseFixtures::admin();
        $before = F::retained();
        if ($scenario === 'customer') {
            $actor = User::factory()->create();
        }
        if ($scenario === 'unverified') {
            DB::table('users')->where('id', $actor->id)->update(['email_verified_at' => null]);
        }
        if ($scenario === 'revoked') {
            DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
        }
        if ($scenario === 'deleted') {
            $actor->delete();
        }
        if ($scenario === 'mfa_withdrawn') {
            Filament::getPanel('admin')->multiFactorAuthentication(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders(), isRequired: true);
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $actor = $actor->fresh();
            User::findOrFail($actor->id)->saveAppAuthenticationSecret(null);
        }
        $auditCount = AuditEvent::count();
        Crypt::shouldReceive('decryptString')->never();
        try {
            app(InspectRetainedTestPaymentException::class)->handle($f['finalization']->public_id, $scenario === 'guest' ? null : $actor);
            $this->fail('Withdrawn authority inspected retained evidence.');
        } catch (AuthorizationException) {
        }
        $this->assertSame($before, F::retained());
        $this->assertSame($auditCount, AuditEvent::count());
    }

    public static function wrongScope(): array
    {
        return [['unknown'], ['malformed'], ['uppercase'], ['newline'], ['null_account'], ['invalid_account'],
            ['account_newline'], ['wrong_account'], ['paid_outcome']];
    }

    #[DataProvider('wrongScope')]
    public function test_unknown_or_foreign_record_never_falls_back_to_another_account(string $scenario): void
    {
        $f = $this->exception();
        $id = $f['finalization']->public_id;
        if ($scenario === 'paid_outcome') {
            DB::unprepared('DROP TRIGGER order_finalizations_immutable_update');
            DB::table('order_finalizations')->where('id', $f['finalization']->id)->update(['outcome' => 'paid']);
        }
        $id = match ($scenario) {
            'unknown' => (string) Str::uuid(), 'malformed' => '1',
            'uppercase' => strtoupper($id), 'newline' => $id."\n", default => $id
        };
        match ($scenario) {
            'null_account' => config(['payments.stripe.account_id' => null]),
            'invalid_account' => config(['payments.stripe.account_id' => 'unscoped']),
            'account_newline' => config(['payments.stripe.account_id' => CheckoutFixtures::ACCOUNT."\n"]),
            'wrong_account' => config(['payments.stripe.account_id' => 'acct_OTHER']), default => null
        };
        $before = F::retained();
        $auditCount = AuditEvent::count();
        Crypt::shouldReceive('decryptString')->never();
        try {
            app(InspectRetainedTestPaymentException::class)->handle($id, LicenseFixtures::admin());
            $this->fail('Foreign record inspected.');
        } catch (ModelNotFoundException $error) {
            $this->assertSame([], $error->getIds());
        }
        $this->assertSame($before, F::retained());
        $this->assertSame($auditCount, AuditEvent::count());
    }

    public static function corruption(): array
    {
        return [['order_hash'], ['order_semantics'], ['payment_amount'], ['finalization_hash'], ['finalization_checks'],
            ['finalization_reason'], ['missing_outbox'], ['extra_outbox'], ['outbox_payload'], ['unexpected_grant'],
            ['consumed_inventory'], ['consumed_promotion']];
    }

    #[DataProvider('corruption')]
    public function test_partial_or_corrupted_graph_is_only_attention_and_preserves_the_unverified_evidence(string $scenario): void
    {
        $f = $this->exception('late_confirmation', true);
        $this->corrupt($f, $scenario);
        $before = F::retained();
        $auditCount = AuditEvent::count();
        $result = app(InspectRetainedTestPaymentException::class)->handle($f['finalization']->public_id, LicenseFixtures::admin());
        $this->assertSame('attention', $result['inspectionStatus']);
        foreach (['orderId', 'recordedReason', 'confirmedAt', 'eligibilityCutoff', 'finalizedAt', 'inventoryState',
            'promotionState', 'grantCount', 'exclusiveSaleCount', 'outboxCount'] as $key) {
            $this->assertNull($result[$key], $key);
        }
        $this->assertSame('not_inspected', $result['currentProviderState']);
        $this->assertSame($before, F::retained());
        $this->assertSame($auditCount + 1, AuditEvent::count());
        $this->assertSame('attention', AuditEvent::latest('id')->first()->context['result']);
        $this->assertPrivateAbsent(json_encode($result, JSON_THROW_ON_ERROR), $f);
    }

    public function test_audit_failure_cannot_return_a_verified_projection_or_retain_an_inspection_event(): void
    {
        $f = $this->exception();
        $before = F::retained();
        $auditCount = AuditEvent::count();
        Event::listen('eloquent.created: '.AuditEvent::class, function (AuditEvent $audit): void {
            if ($audit->action === 'commerce.payment_exception.inspected') {
                throw new RuntimeException('SYNTHETIC-PRIVATE-AUDIT-FAILURE');
            }
        });
        try {
            app(InspectRetainedTestPaymentException::class)->handle($f['finalization']->public_id, LicenseFixtures::admin());
            $this->fail('Unaudited result returned.');
        } catch (RuntimeException $error) {
            $this->assertSame('Test exception inspection is unavailable.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame($before, F::retained());
        $this->assertSame($auditCount, AuditEvent::count());
    }

    public function test_a_caller_owned_transaction_cannot_receive_an_uncommitted_audit_disclosure(): void
    {
        $f = $this->exception();
        $before = F::retained();
        $auditCount = AuditEvent::count();
        $actor = LicenseFixtures::admin();
        DB::beginTransaction();
        try {
            app(InspectRetainedTestPaymentException::class)->handle($f['finalization']->public_id, $actor);
            $this->fail('Nested inspection succeeded.');
        } catch (RuntimeException $error) {
            $this->assertSame('Test exception inspection is unavailable.', $error->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, F::retained());
        $this->assertSame($auditCount, AuditEvent::count());
    }

    public function test_another_open_connection_also_prevents_uncommitted_inspection(): void
    {
        $f = $this->exception();
        $actor = LicenseFixtures::admin();
        $before = F::retained();
        $audits = AuditEvent::count();
        config(['database.connections.inspection-held' => array_replace(config('database.connections.sqlite'),
            ['database' => ':memory:', 'url' => null])]);
        $other = DB::connection('inspection-held');
        $other->beginTransaction();
        try {
            app(InspectRetainedTestPaymentException::class)->handle($f['finalization']->public_id, $actor);
            $this->fail('Inspection ignored another caller-owned transaction.');
        } catch (RuntimeException $error) {
            $this->assertSame('Test exception inspection is unavailable.', $error->getMessage());
        } finally {
            $other->rollBack();
            DB::purge('inspection-held');
        }
        $this->assertSame($before, F::retained());
        $this->assertSame($audits, AuditEvent::count());
    }

    public function test_operator_modal_uses_verified_historical_labels_without_sensitive_material_or_resolution_actions(): void
    {
        $f = $this->exception('late_confirmation', true);
        $this->actingAs(LicenseFixtures::admin());
        $before = F::retained();
        $component = Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('inspectEvidence', $f['finalization'])
            ->assertMountedActionModalSee('Stored graph verified')->assertMountedActionModalSee('Original eligibility cutoff')
            ->assertMountedActionModalSee('Confirmation observed too late')->assertMountedActionModalSee('0 grants')
            ->assertMountedActionModalSee('not inspected')->assertMountedActionModalSee('does not resolve');
        $this->assertPrivateAbsent($component->html(), $f);
        $this->assertPrivateAbsent(json_encode($component->snapshot, JSON_THROW_ON_ERROR), $f);
        $this->assertSame($before, F::retained());
        $action = $component->instance()->getTable()->getAction('inspectEvidence');
        $this->assertNull($action->getModalSubmitAction());
        $this->assertSame('Close', $action->getModalCancelAction()->getLabel());
        $component->unmountTableAction()->assertActionNotMounted()->mountTableAction('inspectEvidence', $f['finalization'])
            ->assertMountedActionModalSee('Stored graph verified');
        $this->assertSame($before, F::retained());
    }

    public function test_corrupt_reason_is_not_reflected_as_a_diagnosis_in_the_operator_modal(): void
    {
        $f = $this->exception();
        $this->corrupt($f, 'finalization_reason');
        $this->actingAs(LicenseFixtures::admin());
        Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('inspectEvidence', $f['finalization'])
            ->assertMountedActionModalSee('Evidence needs attention')->assertMountedActionModalSee('could not be verified')
            ->assertMountedActionModalDontSee('Stored graph verified')->assertDontSee('ATTACKER-SCRIPT-MARKER');
    }

    public function test_open_modal_denies_reactive_requests_after_staff_or_mfa_withdrawal(): void
    {
        $f = $this->exception();
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        foreach (['role', 'mfa'] as $withdrawal) {
            $actor = LicenseFixtures::admin();
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $this->actingAs($actor->fresh());
            $component = Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('inspectEvidence', $f['finalization'])
                ->assertMountedActionModalSee('Stored graph verified');
            $auditCount = AuditEvent::count();
            if ($withdrawal === 'role') {
                DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
            } else {
                $actor->saveAppAuthenticationSecret(null);
            }
            $component->call('$refresh')->assertForbidden();
            $this->assertSame($auditCount, AuditEvent::count());
        }
    }

    private function exception(string $reason = 'late_confirmation', bool $promoted = false, bool $exclusive = true): array
    {
        $f = PaymentFixtures::started($this->gateway, $exclusive, $promoted);
        if ($reason === 'late_confirmation') {
            $this->travelTo($f['order']->attempt()->sole()->expires_at);
        }
        $f = F::confirm($f);
        if ($reason === 'inventory_blocked') {
            app(ManageRightsScope::class)->block($f['scope']->id, true, 0, 'SYNTHETIC-INSPECTION-BLOCK', $f['actor']);
        }
        if ($reason === 'rights_unavailable') {
            RightsDeclaration::create(['track_id' => $f['track']->id,
                'provenance_reference' => 'SYNTHETIC-INSPECTION-RIGHTS', 'sample_disclosure' => 'Synthetic unresolved rights', 'status' => 'pending']);
        }
        if ($reason === 'asset_unavailable') {
            $asset = $f['media']['master_wav'];
            $path = Storage::disk($asset->disk)->path($asset->storage_path);
            // Preserve a proved provenance path so the finalizer's fresh byte digest observes this synthetic corruption.
            $this->app->instance(VerifiedMedia::class, new class($path) extends VerifiedMedia
            {
                public function __construct(private string $retainedPath) {}

                public function path(MediaAsset $asset): ?string
                {
                    return $this->retainedPath;
                }
            });
            $bytes = file_get_contents($path);
            $bytes[0] = $bytes[0] === 'X' ? 'Y' : 'X';
            chmod($path, 0600);
            try {
                file_put_contents($path, $bytes);
            } finally {
                chmod($path, 0440);
            }
        }
        if ($reason === 'inventory_unavailable') {
            // Synthetic retained observation: this tests historical graph interpretation, not a competing sale or MySQL race.
            $bindings = $f['original']['attempt']['inventory']['snapshot']['bindings'];
            $checks = ['scope_controls' => array_map(fn ($binding) => ['scope_id' => $binding['scope_id'],
                'scope_public_id' => $binding['scope_public_id'], 'control_version' => 0, 'blocked' => false], $bindings),
                'inventory_available' => false, 'assets_available' => true,
                'rights_controls' => array_map(fn ($line) => ['track_id' => $line['selection']['offer_snapshot']['product']['id'],
                    'retained_declaration_id' => $line['selection']['offer_snapshot']['rights']['id'],
                    'observed_declaration_id' => $line['selection']['offer_snapshot']['rights']['id'], 'available' => true], $f['original']['lines'])];
            $id = (string) Str::uuid();
            $evidence = app(FinalizationEvidence::class);
            $payload = $evidence->capture($f['order'], $f['original'], $f['payment'], $id, FinalizationPolicy::CONTRACT, now()->toImmutable(), $reason, $checks);
            [$cipher, $hash] = $evidence->encrypt($payload);
            $record = OrderFinalization::create(['public_id' => $id, 'order_id' => $f['order']->id, 'verified_payment_id' => $f['payment']->id,
                'order_attempt_id' => $f['order']->attempt()->sole()->id, 'mode' => 'test', 'outcome' => 'paid_exception', 'reason' => $reason,
                'policy_version' => FinalizationPolicy::CONTRACT['version'], 'confirmed_at' => $f['payment']->confirmed_at,
                'eligibility_cutoff' => $f['order']->attempt()->sole()->expires_at, 'finalized_at' => now(),
                'evidence_ciphertext' => $cipher, 'evidence_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION]);
            FulfillmentOutbox::create(['public_id' => (string) Str::uuid(), 'order_finalization_id' => $record->id, 'license_grant_id' => null,
                'effect_key' => 'exception', 'kind' => 'order_paid_exception_v1', 'state' => 'pending', 'created_at' => now(),
                'payload' => ['schema_version' => 1, 'finalization_id' => $id, 'grant_id' => null, 'evidence_hash' => $hash]]);
        } else {
            $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        }
        $record = OrderFinalization::where('order_id', $f['order']->id)->sole();
        $this->assertSame($reason, $record->reason);

        return $f + ['finalization' => $record];
    }

    private function corrupt(array $f, string $scenario): void
    {
        $table = match ($scenario) {
            'order_hash', 'order_semantics' => 'orders', 'payment_amount' => 'verified_payments',
            'missing_outbox', 'extra_outbox', 'outbox_payload' => 'fulfillment_outbox',
            'consumed_inventory' => 'inventory_reservations', 'consumed_promotion' => 'promotion_uses', default => 'order_finalizations'
        };
        $trigger = match ($scenario) {
            'extra_outbox' => 'fulfillment_outbox_valid_insert', 'missing_outbox' => 'fulfillment_outbox_immutable_delete',
            'consumed_inventory' => 'inventory_reservations_transition', 'consumed_promotion' => 'promotion_uses_guard_update',
            'unexpected_grant' => 'license_grants_valid_insert', default => $table.'_immutable_update'
        };
        // Direct corruption is exclusively in this disposable testing database.
        DB::unprepared('DROP TRIGGER '.$trigger);
        $record = $f['finalization'];
        if ($scenario === 'order_hash') {
            DB::table('orders')->where('id', $f['order']->id)->update(['payload_hash' => str_repeat('0', 64)]);
        }
        if ($scenario === 'order_semantics') {
            $payload = $f['original'];
            $payload['request']['buyer']['legalName'] = 'Changed synthetic buyer';
            $cipher = Crypt::encryptString(CanonicalJson::encode($payload));
            DB::table('orders')->where('id', $f['order']->id)->update(['payload_ciphertext' => $cipher, 'payload_hash' => hash('sha256', $cipher)]);
        }
        if ($scenario === 'payment_amount') {
            DB::table('verified_payments')->where('id', $f['payment']->id)->update(['amount_minor' => $f['payment']->amount_minor + 1]);
        }
        if ($scenario === 'finalization_hash') {
            DB::table('order_finalizations')->where('id', $record->id)->update(['evidence_hash' => str_repeat('0', 64)]);
        }
        if ($scenario === 'finalization_checks') {
            $payload = json_decode(Crypt::decryptString($record->evidence_ciphertext), true, 128, JSON_THROW_ON_ERROR);
            $payload['checks']['scope_controls'][0]['scope_id']++;
            $cipher = Crypt::encryptString(CanonicalJson::encode($payload));
            DB::table('order_finalizations')->where('id', $record->id)->update(['evidence_ciphertext' => $cipher, 'evidence_hash' => hash('sha256', $cipher)]);
        }
        if ($scenario === 'finalization_reason') {
            DB::table('order_finalizations')->where('id', $record->id)->update(['reason' => 'ATTACKER-SCRIPT-MARKER']);
        }
        if ($scenario === 'missing_outbox') {
            DB::table('fulfillment_outbox')->where('order_finalization_id', $record->id)->delete();
        }
        if ($scenario === 'extra_outbox') {
            $attributes = FulfillmentOutbox::sole()->getAttributes();
            unset($attributes['id']);
            $attributes['public_id'] = (string) Str::uuid();
            $attributes['effect_key'] = 'exception:extra';
            DB::table('fulfillment_outbox')->insert($attributes);
        }
        if ($scenario === 'outbox_payload') {
            $payload = FulfillmentOutbox::sole()->payload;
            $payload['evidence_hash'] = str_repeat('0', 64);
            DB::table('fulfillment_outbox')->where('order_finalization_id', $record->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        }
        if ($scenario === 'unexpected_grant') {
            LicenseGrant::create(['public_id' => (string) Str::uuid(), 'order_line_id' => $f['order']->lines()->sole()->id,
                'order_finalization_id' => $record->id, 'offer_revision_id' => $f['revision']->id,
                'license_version_id' => $f['revision']->license_version_id, 'rights_scope_id' => $f['scope']->id,
                'render_input_ciphertext' => 'SYNTHETIC-CORRUPTION', 'render_input_hash' => str_repeat('a', 64),
                'canonicalization_version' => CanonicalJson::VERSION, 'created_at' => now()]);
        }
        if ($scenario === 'consumed_inventory') {
            DB::table('inventory_reservations')->update(['state' => 'consumed', 'consumed_at' => now()]);
        }
        if ($scenario === 'consumed_promotion') {
            DB::table('promotion_uses')->update(['state' => 'consumed', 'consumed_at' => now()]);
        }
    }

    private function assertPrivateAbsent(string $serialized, array $f): void
    {
        foreach ([...array_values(OrderFixtures::buyer()), $f['order']->owner_key, $f['order']->payload_ciphertext,
            $f['payment']->provider_payment_intent_id, $f['payment']->account_id, $f['intent']->idempotency_key,
            $f['finalization']->evidence_ciphertext, $f['finalization']->evidence_hash, 'storage_path', 'claim_token'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
    }
}
