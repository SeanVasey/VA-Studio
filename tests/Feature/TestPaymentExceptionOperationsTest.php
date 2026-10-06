<?php

namespace Tests\Feature;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\TestPaymentExceptionEvent;
use App\Domain\Commerce\Models\TestPaymentExceptionWork;
use App\Domain\Commerce\Operations\TestPaymentExceptionOperations;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Filament\Resources\TestPaymentExceptionResource\Pages\ListTestPaymentExceptions;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures as F;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFinancialFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class TestPaymentExceptionOperationsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        Queue::fake();
        $this->gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(StripeFinancialInspectionGateway::class, PaymentFinancialFixtures::gateway($this->gateway));
    }

    private function exception(): array
    {
        $f = PaymentFixtures::started($this->gateway, true, true);
        $this->travelTo($f['order']->attempt()->sole()->expires_at->addSecond());
        $f = F::confirm($f);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        Queue::fake();

        return $f + ['record' => OrderFinalization::where('order_id', $f['order']->id)->sole(), 'admin' => LicenseFixtures::admin()];
    }

    public function test_dispositions_are_append_only_idempotent_and_have_no_financial_effect(): void
    {
        $f = $this->exception();
        $before = F::retained();
        $calls = $this->gateway->calls;
        $service = app(TestPaymentExceptionOperations::class);
        $key = (string) Str::uuid();
        $first = $service->disposition($f['record']->public_id, $f['admin'], $key, 0, 'acknowledged');
        $this->assertSame($first, $service->disposition($f['record']->public_id, $f['admin'], $key, 0, 'acknowledged'));
        $this->assertSame(1, TestPaymentExceptionEvent::count());
        $next = $service->disposition($f['record']->public_id, $f['admin'], (string) Str::uuid(), 1, 'needs_review');
        $this->assertSame(2, $next['sequence']);
        $this->assertSame('blocked', $next['fulfillment']);
        $this->assertSame($before, F::retained());
        $this->assertSame($calls, $this->gateway->calls);
        Queue::assertNothingPushed();
        $this->assertSame(2, AuditEvent::where('action', 'commerce.payment_exception.disposition')->count());
        $this->assertSame(['acknowledged', 'needs_review'], TestPaymentExceptionEvent::orderBy('sequence')->pluck('outcome')->all());
    }

    public function test_reconciliation_records_only_a_minimized_current_observation_and_replay_performs_no_provider_io(): void
    {
        $f = $this->exception();
        $before = F::retained();
        $start = count($this->gateway->calls);
        $key = (string) Str::uuid();
        $result = app(TestPaymentExceptionOperations::class)->reconcile($f['record']->public_id, $f['admin'], $key, 0);
        $this->assertSame(['testOnly' => true, 'status' => 'reconciliation_observed', 'outcome' => 'confirmed', 'sequence' => 2,
            'observedAt' => now()->toIso8601ZuluString(), 'fulfillment' => 'blocked', 'refundDisputeState' => 'observed',
            'financialObservation' => ['state' => 'observed', 'currency' => 'USD', 'refundedMinor' => 0,
                'refundCount' => 0, 'refundStatuses' => [], 'disputeCount' => 0, 'disputeStatuses' => []]], $result);
        $calls = $this->gateway->calls;
        $this->assertSame(['account', 'retrieve', 'payment_intent'], array_column(array_slice($calls, $start), 'operation'));
        foreach (array_slice($calls, $start) as $call) {
            $this->assertSame(0, $call['transaction_level']);
        }
        $this->assertSame($result, app(TestPaymentExceptionOperations::class)->reconcile($f['record']->public_id, $f['admin'], $key, 0));
        $this->assertSame($calls, $this->gateway->calls);
        $this->assertSame($before, F::retained());
        Queue::assertNothingPushed();
        $this->assertNull(TestPaymentExceptionWork::sole()->claim_token);
        $serialized = json_encode([$result, TestPaymentExceptionEvent::all(), AuditEvent::where('action', 'like', 'commerce.payment_exception.%')->pluck('context')], JSON_THROW_ON_ERROR);
        foreach ([$f['payment']->provider_payment_intent_id, $f['intent']->request_hash, $f['intent']->account_id] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
    }

    public static function providerFailures(): array
    {
        return [['foreign_account', 'attention'], ['different_payment', 'attention'], ['amount', 'attention'], ['timeout', 'unavailable']];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_or_conflicting_evidence_cannot_change_the_terminal_exception(string $scenario, string $outcome): void
    {
        $f = $this->exception();
        $before = F::retained();
        if ($scenario === 'foreign_account') {
            $this->gateway->accountResponse['id'] = 'acct_FOREIGN';
        } elseif ($scenario === 'different_payment') {
            $this->gateway->session['payment_intent'] = 'pi_DIFFERENT';
            $this->gateway->payment['id'] = 'pi_DIFFERENT';
        } elseif ($scenario === 'amount') {
            $this->gateway->payment['amount']++;
        } else {
            $this->gateway->onRetrieve = fn () => throw new RuntimeException('private provider error');
        }
        $result = app(TestPaymentExceptionOperations::class)->reconcile($f['record']->public_id, $f['admin'], (string) Str::uuid(), 0);
        $this->assertSame($outcome, $result['outcome']);
        $this->assertSame($before, F::retained());
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        Queue::assertNothingPushed();
    }

    public function test_active_lease_refuses_duplicate_io_and_expired_claim_cannot_append_after_reclamation(): void
    {
        $f = $this->exception();
        $key = (string) Str::uuid();
        $service = app(TestPaymentExceptionOperations::class);
        $this->gateway->onRetrieve = function () use ($f, $key, $service): array {
            $busy = $service->reconcile($f['record']->public_id, $f['admin'], $key, 0);
            $this->assertSame('busy', $busy['status']);
            $this->travel(TestPaymentExceptionOperations::LEASE_SECONDS)->seconds();
            $this->gateway->onRetrieve = null;
            $replacement = $service->reconcile($f['record']->public_id, $f['admin'], $key, 0);
            $this->assertSame('confirmed', $replacement['outcome']);

            return $this->gateway->session;
        };
        $stale = $service->reconcile($f['record']->public_id, $f['admin'], $key, 0);
        $this->assertSame('stale', $stale['status']);
        $this->assertSame(2, TestPaymentExceptionEvent::count());
        $this->assertSame(1, TestPaymentExceptionEvent::where('kind', 'reconciliation_observed')->count());
    }

    public function test_revoked_persisted_authority_during_provider_io_cannot_append_an_observation(): void
    {
        $f = $this->exception();
        $this->gateway->onRetrieve = function () use ($f): array {
            User::whereKey($f['admin']->id)->update(['is_admin' => false]);

            return $this->gateway->session;
        };
        try {
            app(TestPaymentExceptionOperations::class)->reconcile($f['record']->public_id, $f['admin'], (string) Str::uuid(), 0);
            $this->fail('Withdrawn authority appended a result.');
        } catch (AuthorizationException) {
            $this->assertSame(1, TestPaymentExceptionEvent::count());
            $this->assertSame(0, TestPaymentExceptionEvent::where('kind', 'reconciliation_observed')->count());
        }
    }

    public function test_expired_request_cannot_reclaim_over_a_later_operator_disposition(): void
    {
        $f = $this->exception();
        $key = (string) Str::uuid();
        $service = app(TestPaymentExceptionOperations::class);
        $this->gateway->onRetrieve = function () use ($f, $service): array {
            $this->travel(TestPaymentExceptionOperations::LEASE_SECONDS)->seconds();
            $service->disposition($f['record']->public_id, $f['admin'], (string) Str::uuid(), 1, 'needs_review');

            return $this->gateway->session;
        };
        $this->assertSame('stale', $service->reconcile($f['record']->public_id, $f['admin'], $key, 0)['status']);
        $this->assertSame(2, TestPaymentExceptionEvent::count());
        $this->assertSame(0, TestPaymentExceptionEvent::where('kind', 'reconciliation_observed')->count());
        $this->expectException(RuntimeException::class);
        $service->reconcile($f['record']->public_id, $f['admin'], $key, 0);
    }

    public static function rejectedRequests(): array
    {
        return [['guest'], ['revoked'], ['unverified'], ['foreign_account'], ['sequence'], ['key_conflict'], ['invalid_disposition'], ['ambient_transaction']];
    }

    #[DataProvider('rejectedRequests')]
    public function test_invalid_or_unauthorized_dispositions_preserve_existing_history(string $scenario): void
    {
        $f = $this->exception();
        $service = app(TestPaymentExceptionOperations::class);
        $key = (string) Str::uuid();
        $service->disposition($f['record']->public_id, $f['admin'], $key, 0, 'acknowledged');
        $actor = $f['admin'];
        $sequence = 1;
        $disposition = 'needs_review';
        if ($scenario === 'guest') {
            $actor = null;
        }
        if ($scenario === 'revoked') {
            User::whereKey($actor->id)->update(['is_admin' => false]);
        }
        if ($scenario === 'unverified') {
            User::whereKey($actor->id)->update(['email_verified_at' => null]);
        }
        if ($scenario === 'foreign_account') {
            config(['payments.stripe.account_id' => 'acct_FOREIGN']);
        }
        if ($scenario === 'sequence') {
            $sequence = 0;
        }
        if ($scenario === 'invalid_disposition') {
            $disposition = 'resolved';
        }
        if ($scenario === 'ambient_transaction') {
            DB::beginTransaction();
        }
        try {
            $service->disposition($f['record']->public_id, $actor, $scenario === 'key_conflict' ? $key : (string) Str::uuid(), $sequence, $disposition);
            $this->fail('Invalid operation accepted.');
        } catch (AuthorizationException|ModelNotFoundException|RuntimeException) {
            $this->assertSame(1, TestPaymentExceptionEvent::count());
        } finally {
            if ($scenario === 'ambient_transaction') {
                DB::rollBack();
            }
        }
    }

    public function test_audit_failure_rolls_back_event_and_sequence(): void
    {
        $f = $this->exception();
        Event::listen('eloquent.creating: '.AuditEvent::class, fn () => throw new RuntimeException('private audit failure'));
        try {
            app(TestPaymentExceptionOperations::class)->disposition($f['record']->public_id, $f['admin'], (string) Str::uuid(), 0, 'acknowledged');
            $this->fail('Audit failure accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringNotContainsString('private audit failure', $error->getMessage());
            $this->assertSame(0, TestPaymentExceptionEvent::count());
            $this->assertSame(0, TestPaymentExceptionWork::count());
        }
    }

    public function test_raw_database_writes_cannot_rewrite_or_delete_history(): void
    {
        $f = $this->exception();
        app(TestPaymentExceptionOperations::class)->disposition($f['record']->public_id, $f['admin'], (string) Str::uuid(), 0, 'acknowledged');
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('test_payment_exception_events');
                $operation === 'update' ? $query->update(['outcome' => 'needs_review']) : $query->delete();
                $this->fail('Historical event changed.');
            } catch (QueryException) {
                $this->assertSame('acknowledged', TestPaymentExceptionEvent::sole()->outcome);
            }
        }
    }

    public function test_operator_can_record_a_review_and_inspect_minimized_history_in_the_actual_modal(): void
    {
        $f = $this->exception();
        $before = F::retained();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($f['admin']);
        Livewire::test(ListTestPaymentExceptions::class)
            ->mountTableAction('recordDisposition', $f['record'])
            ->setTableActionData(['disposition' => 'acknowledged'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();
        $this->assertSame('acknowledged', TestPaymentExceptionEvent::sole()->outcome);
        Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('operationHistory', $f['record'])
            ->assertMountedActionModalSee('Fulfillment remains blocked')
            ->assertMountedActionModalSee('acknowledged');
        $this->assertSame($before, F::retained());
    }

    public function test_operator_reconciliation_modal_preserves_exception_and_shows_latest_observation(): void
    {
        $f = $this->exception();
        $before = F::retained();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($f['admin']);
        Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('reconcilePayment', $f['record'])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('confirmed', TestPaymentExceptionEvent::where('kind', 'reconciliation_observed')->sole()->outcome);
        Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('operationHistory', $f['record'])
            ->assertMountedActionModalSee('confirmed')->assertMountedActionModalSee('refunds and disputes');
        $this->assertSame($before, F::retained());
    }

    public function test_stale_operator_modal_keeps_its_review_sequence_and_cannot_overwrite_another_disposition(): void
    {
        $f = $this->exception();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($f['admin']);
        $component = Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('recordDisposition', $f['record'])
            ->setTableActionData(['disposition' => 'acknowledged']);
        app(TestPaymentExceptionOperations::class)->disposition($f['record']->public_id, $f['admin'], (string) Str::uuid(), 0, 'needs_review');
        $component->callMountedTableAction()->assertNotified('Operational status was not confirmed');
        $this->assertSame(1, TestPaymentExceptionEvent::count());
        $this->assertSame('needs_review', TestPaymentExceptionEvent::sole()->outcome);
    }

    public function test_replay_rechecks_withdrawn_authority_and_disposition_survives_disabled_fresh_payment_policies(): void
    {
        $f = $this->exception();
        $service = app(TestPaymentExceptionOperations::class);
        $key = (string) Str::uuid();
        config(['payments.stripe.checkout_enabled' => false, 'payments.stripe.processing_enabled' => false, 'payments.stripe.finalization_enabled' => false]);
        $service->disposition($f['record']->public_id, $f['admin'], $key, 0, 'acknowledged');
        User::whereKey($f['admin']->id)->update(['is_admin' => false]);
        $this->expectException(AuthorizationException::class);
        $service->disposition($f['record']->public_id, $f['admin'], $key, 0, 'acknowledged');
    }

    public function test_review_is_bounded_current_and_audited_without_advancing_operation_sequence(): void
    {
        $f = $this->exception();
        $service = app(TestPaymentExceptionOperations::class);
        for ($sequence = 0; $sequence < 21; $sequence++) {
            $service->disposition($f['record']->public_id, $f['admin'], (string) Str::uuid(), $sequence, 'needs_review');
        }
        $review = $service->review($f['record']->public_id, $f['admin']);
        $this->assertSame(21, $review['sequence']);
        $this->assertCount(20, $review['history']);
        $this->assertSame(range(21, 2), array_column($review['history'], 'sequence'));
        $this->assertSame(21, TestPaymentExceptionEvent::count());
        $this->assertSame(1, AuditEvent::where('action', 'commerce.payment_exception.operations_reviewed')->count());
    }

    public function test_database_guards_reject_noncanonical_uuid_bytes_and_sequence_gaps(): void
    {
        $f = $this->exception();
        app(TestPaymentExceptionOperations::class)->disposition($f['record']->public_id, $f['admin'], (string) Str::uuid(), 0, 'acknowledged');
        $uuid = 'abcdef12-1234-4567-8123-abcdef123456';
        $bad = [$uuid."\0suffix", strtoupper($uuid), str_repeat('x', 36), 12345];
        if (DB::getDriverName() === 'sqlite') {
            $bad[] = DB::raw("CAST('{$uuid}' AS BLOB)");
        }
        foreach ($bad as $value) {
            foreach (['event', 'request', 'token'] as $target) {
                try {
                    if ($target === 'event') {
                        DB::table('test_payment_exception_events')->insert(['order_finalization_id' => $f['record']->id,
                            'sequence' => 2, 'request_id' => $value, 'kind' => 'disposition', 'outcome' => 'acknowledged',
                            'actor_id' => $f['admin']->id, 'created_at' => now()]);
                    } else {
                        DB::table('test_payment_exception_work')->update(['request_id' => $target === 'request' ? $value : $uuid,
                            'claim_token' => $target === 'token' ? $value : $uuid, 'lease_expires_at' => now()->addSeconds(120)]);
                    }
                    $this->fail('Malformed operation identity reached storage.');
                } catch (QueryException) {
                    $this->assertSame(1, TestPaymentExceptionEvent::count());
                    $this->assertNull(TestPaymentExceptionWork::sole()->claim_token);
                }
            }
        }
        try {
            DB::table('test_payment_exception_work')->update(['sequence' => 5]);
            $this->fail('Operation history skipped event sequences.');
        } catch (QueryException) {
            $this->assertSame(1, TestPaymentExceptionWork::sole()->sequence);
        }
        foreach (['acknowledged ', 'ACKNOWLEDGED'] as $outcome) {
            try {
                DB::table('test_payment_exception_events')->insert(['order_finalization_id' => $f['record']->id,
                    'sequence' => 2, 'request_id' => $uuid, 'kind' => 'disposition', 'outcome' => $outcome,
                    'actor_id' => $f['admin']->id, 'created_at' => now()]);
                $this->fail('Noncanonical operation status reached storage.');
            } catch (QueryException) {
                $this->assertSame(1, TestPaymentExceptionEvent::count());
            }
        }
    }
}
