<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\TestPaymentExceptionEvent;
use App\Domain\Commerce\Models\TestPaymentExceptionWork;
use App\Domain\Commerce\Models\TestPaymentFinancialObservation;
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
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures as F;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFinancialFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class TestPaymentFinancialObservationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private object $payments;

    private object $financial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        Queue::fake();
        $this->payments = PaymentFixtures::gateway();
        $this->financial = PaymentFinancialFixtures::gateway($this->payments);
        $this->app->instance(StripeCheckoutGateway::class, $this->payments);
        $this->app->instance(StripePaymentGateway::class, $this->payments);
        $this->app->instance(StripeFinancialInspectionGateway::class, $this->financial);
    }

    private function fixture(): array
    {
        $f = PaymentFixtures::started($this->payments, true, true);
        $this->travelTo($f['order']->attempt()->sole()->expires_at->addSecond());
        $f = F::confirm($f);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        Queue::fake();

        return $f + ['record' => OrderFinalization::where('order_id', $f['order']->id)->sole(), 'admin' => LicenseFixtures::admin()];
    }

    private function reconcile(array $f, ?string $key = null): array
    {
        return app(TestPaymentExceptionOperations::class)->reconcile($f['record']->public_id, $f['admin'], $key ?? (string) Str::uuid(), 0);
    }

    public static function refundAmounts(): array
    {
        return [['partial'], ['full']];
    }

    #[DataProvider('refundAmounts')]
    public function test_retained_refund_and_dispute_observations_preserve_originals_and_replay_without_io(string $kind): void
    {
        $f = $this->fixture();
        $before = F::retained();
        $amount = $kind === 'full' ? $f['payment']->amount_minor : 100;
        $this->financial->onInspect = function ($source) use ($amount): array {
            foreach (['charge_before', 'charge_after'] as $key) {
                $source[$key]['amount_refunded'] = $amount;
                $source[$key]['refunded'] = $amount === $source[$key]['amount'];
                $source[$key]['disputed'] = true;
            }
            $source['refunds']['data'] = [PaymentFinancialFixtures::item('refund', 'succeeded', $amount)];
            $source['disputes']['data'] = [PaymentFinancialFixtures::item('dispute', 'under_review', $amount)];

            return $source;
        };
        $key = (string) Str::uuid();
        $result = $this->reconcile($f, $key);
        $this->assertSame(['state' => 'observed', 'currency' => 'USD', 'refundedMinor' => $amount,
            'refundCount' => 1, 'refundStatuses' => ['succeeded'], 'disputeCount' => 1, 'disputeStatuses' => ['under_review']], $result['financialObservation']);
        $calls = [$this->payments->calls, $this->financial->calls];
        $this->assertSame($result, $this->reconcile($f, $key));
        $this->assertSame($calls, [$this->payments->calls, $this->financial->calls]);
        $this->assertSame(0, $this->financial->calls[0]['transaction_level']);
        $row = TestPaymentFinancialObservation::sole();
        $this->assertSame(TestPaymentExceptionEvent::where('kind', 'reconciliation_observed')->sole()->id, $row->test_payment_exception_event_id);
        $payload = app(CheckoutEvidence::class)->decrypt($row->evidence_ciphertext, $row->evidence_hash, $row->canonicalization_version);
        $this->assertSame($f['record']->public_id, $payload['finalization_id']);
        $this->assertSame($f['payment']->id, $payload['payment_id']);
        $this->assertStringNotContainsString('private-financial@example.test', json_encode($payload, JSON_THROW_ON_ERROR));
        $serialized = json_encode([$result, $row, AuditEvent::where('action', 'like', 'commerce.payment_exception.%')->pluck('context')], JSON_THROW_ON_ERROR);
        foreach (['private-financial', 'pi_SYNTHETIC', 'ch_SYNTHETIC', 'du_SYNTHETIC', 're_SYNTHETIC', $row->evidence_hash, $row->evidence_ciphertext] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
        $this->assertSame($before, F::retained());
        $this->assertSame('blocked', $result['fulfillment']);
        $this->assertSame(1, AuditEvent::where('action', 'commerce.payment_exception.reconciliation_observed')->count());
        Queue::assertNothingPushed();
    }

    public static function failedObservations(): array
    {
        return [['incomplete'], ['attention'], ['unavailable']];
    }

    #[DataProvider('failedObservations')]
    public function test_incomplete_conflicting_and_failed_reads_are_explicitly_unresolved_in_domain_and_admin(string $state): void
    {
        $f = $this->fixture();
        $before = F::retained();
        $this->financial->onInspect = function ($source) use ($state): array {
            if ($state === 'unavailable') {
                throw new RuntimeException('private-financial@example.test');
            }
            if ($state === 'incomplete') {
                $source['refunds']['has_more'] = true;
            }
            if ($state === 'attention') {
                $source['charge_after']['amount_refunded'] = 1;
            }

            return $source;
        };
        $result = $this->reconcile($f);
        $this->assertSame('confirmed', $result['outcome']);
        $this->assertSame(['state' => $state], $result['financialObservation']);
        $this->assertSame($state, TestPaymentFinancialObservation::sole()->state);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($f['admin']);
        Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('operationHistory', $f['record'])
            ->assertMountedActionModalSee(match ($state) {
                'incomplete' => 'more provider results exist', 'attention' => 'evidence needs attention', 'unavailable' => 'observation unavailable',
            })->assertMountedActionModalSee('Fulfillment remains blocked');
        $this->assertSame($before, F::retained());
        Queue::assertNothingPushed();
    }

    public function test_legacy_observation_without_sidecar_remains_not_inspected_on_replay(): void
    {
        $f = $this->fixture();
        $key = (string) Str::uuid();
        DB::transaction(function () use ($f, $key): void {
            $work = TestPaymentExceptionWork::create(['order_finalization_id' => $f['record']->id, 'sequence' => 0]);
            foreach (['reconciliation_requested', 'reconciliation_observed'] as $index => $kind) {
                TestPaymentExceptionEvent::create(['order_finalization_id' => $f['record']->id, 'sequence' => $index + 1,
                    'request_id' => $key, 'kind' => $kind, 'outcome' => $index === 0 ? 'pending' : 'confirmed',
                    'actor_id' => $f['admin']->id, 'observed_at' => $index === 0 ? null : now(), 'created_at' => now()]);
                $work->update(['sequence' => $index + 1]);
            }
        });
        $calls = $this->payments->calls;
        $this->assertSame('not_inspected', $this->reconcile($f, $key)['refundDisputeState']);
        $this->assertSame($calls, $this->payments->calls);
        $this->assertSame([], $this->financial->calls);
        $this->assertSame(0, TestPaymentFinancialObservation::count());
    }

    public static function authorityWithdrawals(): array
    {
        return [['actor'], ['account'], ['policy']];
    }

    #[DataProvider('authorityWithdrawals')]
    public function test_financial_io_cannot_commit_after_authority_account_or_policy_withdrawal(string $case): void
    {
        $f = $this->fixture();
        $before = F::retained();
        $this->financial->onInspect = function ($source) use ($f, $case): array {
            if ($case === 'actor') {
                User::whereKey($f['admin']->id)->update(['is_admin' => false]);
            }
            if ($case === 'account') {
                config(['payments.stripe.account_id' => 'acct_other']);
            }
            if ($case === 'policy') {
                config(['payments.stripe.processing_enabled' => false]);
            }

            return $source;
        };
        try {
            $this->reconcile($f);
            $this->fail('Withdrawn authority committed.');
        } catch (AuthorizationException|ModelNotFoundException|RuntimeException) {
            $this->assertSame(0, TestPaymentFinancialObservation::count());
            $this->assertSame(1, TestPaymentExceptionEvent::count());
            $this->assertSame($before, F::retained());
        }
    }

    public function test_reclaimed_claim_rejects_late_financial_worker_and_keeps_only_the_winner(): void
    {
        $f = $this->fixture();
        $key = (string) Str::uuid();
        $this->financial->onInspect = function ($source) use ($f, $key): array {
            $this->assertSame('busy', $this->reconcile($f, $key)['status']);
            $this->travel(TestPaymentExceptionOperations::LEASE_SECONDS)->seconds();
            $this->financial->onInspect = null;
            $this->assertSame('observed', $this->reconcile($f, $key)['refundDisputeState']);
            $source['refunds']['has_more'] = true;

            return $source;
        };
        $this->assertSame('stale', $this->reconcile($f, $key)['status']);
        $this->assertSame('observed', TestPaymentFinancialObservation::sole()->state);
        $this->assertSame(2, TestPaymentExceptionEvent::count());
        $this->assertNull(TestPaymentExceptionWork::sole()->claim_token);
    }

    public static function invalidStoredEvidence(): array
    {
        return [['binding'], ['expected'], ['hash'], ['write_failure']];
    }

    #[DataProvider('invalidStoredEvidence')]
    public function test_invalid_encrypted_evidence_or_sidecar_write_rolls_back_observed_event_audit_and_lease_clear(string $case): void
    {
        $f = $this->fixture();
        $before = F::retained();
        Event::listen('eloquent.creating: '.TestPaymentFinancialObservation::class, function ($row) use ($case): void {
            if ($case === 'write_failure') {
                throw new RuntimeException('private financial write failure');
            }
            if ($case === 'hash') {
                $row->evidence_hash = str_repeat('0', 64);

                return;
            }
            $codec = app(CheckoutEvidence::class);
            $payload = $codec->decrypt($row->evidence_ciphertext, $row->evidence_hash, $row->canonicalization_version);
            if ($case === 'binding') {
                $payload['event_id']++;
            }
            if ($case === 'expected') {
                unset($payload['observation']['expected']['payment']['metadata']);
            }
            [$row->evidence_ciphertext, $row->evidence_hash] = $codec->encrypt($payload);
        });
        try {
            $this->reconcile($f);
            $this->fail('Invalid financial evidence committed.');
        } catch (RuntimeException $error) {
            $this->assertStringNotContainsString('private financial', (string) $error);
            $this->assertSame(0, TestPaymentFinancialObservation::count());
            $this->assertSame(1, TestPaymentExceptionEvent::count());
            $this->assertSame(0, AuditEvent::where('action', 'commerce.payment_exception.reconciliation_observed')->count());
            $this->assertNotNull(TestPaymentExceptionWork::sole()->claim_token);
            $this->assertSame($before, F::retained());
        }
    }

    public function test_database_guards_prevent_update_delete_replacement_wrong_parent_and_populated_rollback(): void
    {
        $f = $this->fixture();
        $this->reconcile($f);
        $row = TestPaymentFinancialObservation::sole();
        $original = DB::table('test_payment_financial_observations')->get()->toJson();
        // A fresh valid parent isolates the state predicate from duplicate-event protection.
        $unusedEvent = DB::transaction(function () use ($f): TestPaymentExceptionEvent {
            $work = TestPaymentExceptionWork::sole();
            $event = TestPaymentExceptionEvent::create(['order_finalization_id' => $f['record']->id, 'sequence' => $work->sequence + 1,
                'request_id' => (string) Str::uuid(), 'kind' => 'reconciliation_observed', 'outcome' => 'confirmed',
                'actor_id' => $f['admin']->id, 'observed_at' => now(), 'created_at' => now()]);
            $work->update(['sequence' => $event->sequence]);

            return $event;
        });
        foreach (['update', 'delete', 'replace', 'upsert', 'wrong_parent', 'wrong_state'] as $operation) {
            try {
                $values = $row->getAttributes();
                $table = DB::table('test_payment_financial_observations');
                if ($operation === 'update') {
                    $table->update(['state' => 'attention']);
                }
                if ($operation === 'delete') {
                    $table->delete();
                }
                if ($operation === 'upsert') {
                    $table->upsert([$values], ['test_payment_exception_event_id'], ['state']);
                }
                if ($operation === 'replace') {
                    $columns = implode(',', array_keys($values));
                    $placeholders = implode(',', array_fill(0, count($values), '?'));
                    DB::statement('REPLACE INTO test_payment_financial_observations ('.$columns.') VALUES ('.$placeholders.')', array_values($values));
                }
                if ($operation === 'wrong_parent') {
                    unset($values['id']);
                    $values['test_payment_exception_event_id'] = TestPaymentExceptionEvent::where('kind', 'reconciliation_requested')->sole()->id;
                    $table->insert($values);
                }
                if ($operation === 'wrong_state') {
                    unset($values['id']);
                    $values['state'] = 'OBSERVED';
                    $values['test_payment_exception_event_id'] = $unusedEvent->id;
                    $table->insert($values);
                }
                $this->fail('Financial evidence mutation accepted.');
            } catch (QueryException) {
                $this->assertSame($original, DB::table('test_payment_financial_observations')->get()->toJson());
            }
        }
        $migration = require database_path('migrations/2026_10_06_000039_test_payment_financial_observations.php');
        try {
            $migration->down();
            $this->fail('Populated rollback accepted.');
        } catch (LogicException) {
            $this->assertSame($original, DB::table('test_payment_financial_observations')->get()->toJson());
        }
    }
}
