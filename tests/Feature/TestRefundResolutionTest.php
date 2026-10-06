<?php

namespace Tests\Feature;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\TestPaymentExceptionEvent;
use App\Domain\Commerce\Models\TestRefundResolution;
use App\Domain\Commerce\Models\TestRefundResolutionRequest;
use App\Domain\Commerce\Operations\InspectRetainedTestPaymentException;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\RefundResolution\ResolveRefundedTestException;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFinancialFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\RefundResolutionFixtures as F;
use Tests\TestCase;

class TestRefundResolutionTest extends TestCase
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
        $this->financial->onInspect = F::fullRefund(...);
        $this->app->instance(StripeCheckoutGateway::class, $this->payments);
        $this->app->instance(StripePaymentGateway::class, $this->payments);
        $this->app->instance(StripeFinancialInspectionGateway::class, $this->financial);
    }

    private function resolve(array $f, ?string $key = null, int $sequence = 0): array
    {
        return app(ResolveRefundedTestException::class)->resolve($f['record']->public_id, $f['admin'], $key ?? (string) Str::uuid(), $sequence);
    }

    public static function promotions(): array
    {
        return ['inventory only' => [false], 'inventory and promotion' => [true]];
    }

    #[DataProvider('promotions')]
    public function test_full_refund_releases_only_pending_resources_and_preserves_original_money_and_rights(bool $promoted): void
    {
        $f = F::exception($this->payments, $promoted);
        $original = app(ReadOrder::class)->verify($f['order']);
        $before = FinalizationFixtures::retained();
        $service = app(ResolveRefundedTestException::class);
        $this->assertSame(['testOnly' => true, 'sequence' => 0, 'resolution' => null, 'history' => []], $service->review($f['record']->public_id, $f['admin']));
        $key = (string) Str::uuid();
        $result = $this->resolve($f, $key);
        $this->assertSame('released', $result['status']);
        $calls = [$this->payments->calls, $this->financial->calls];
        $this->travel(121)->seconds();
        $this->assertSame($result, $this->resolve($f, $key));
        $this->assertSame($calls, [$this->payments->calls, $this->financial->calls]);
        $this->assertSame(0, $this->financial->calls[0]['transaction_level']);
        $this->assertSame(1, TestRefundResolution::count());
        $this->assertSame(1, TestRefundResolutionRequest::count());
        $this->assertSame(2, TestPaymentExceptionEvent::count());
        $this->assertSame(1, AuditEvent::where('action', 'commerce.refund_resolution.released')->count());
        $this->assertDatabaseHas('inventory_reservations', ['id' => $f['order']->attempt()->sole()->inventory_reservation_id, 'state' => 'released', 'consumed_at' => null]);
        if ($promoted) {
            $this->assertDatabaseHas('promotion_uses', ['id' => $f['order']->attempt()->sole()->promotion_use_id, 'state' => 'released', 'consumed_at' => null]);
        }
        $after = FinalizationFixtures::retained();
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            unset($before[$table], $after[$table]);
        }
        $this->assertSame($before, $after);
        $this->assertSame($original, app(ReadOrder::class)->verify($f['order']));
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $detail = app(InspectRetainedTestPaymentException::class)->handle($f['record']->public_id, $f['admin']);
        $this->assertSame('released', $detail['inventoryState']);
        $this->assertSame($promoted ? 'released' : 'none', $detail['promotionState']);
        $this->assertSame('verified', $detail['inspectionStatus']);
        $review = $service->review($f['record']->public_id, $f['admin']);
        $this->assertSame($result['resolution'], $review['resolution']['publicId']);
        $this->assertSame(2, $review['sequence']);
        $projection = json_encode([$result, $review, $detail, TestRefundResolution::sole()], JSON_THROW_ON_ERROR);
        foreach (['pi_SYNTHETIC', 'ch_SYNTHETIC', 're_SYNTHETIC', 'private-financial', $f['order']->owner_key, TestRefundResolution::sole()->evidence_hash] as $private) {
            $this->assertStringNotContainsString($private, $projection);
        }
        config(['refund-resolution.enabled' => false]);
        $this->assertSame($original, app(ReadOrder::class)->verify($f['order']));
        Queue::assertNothingPushed();
    }

    public function test_two_complete_successful_refunds_must_sum_exactly_to_original_minor_units(): void
    {
        $f = F::exception($this->payments);
        $this->financial->onInspect = static function ($source) {
            $source = F::fullRefund($source);
            $source['refunds']['data'][0]['amount']--;
            $source['refunds']['data'][] = array_replace(PaymentFinancialFixtures::item('refund', 'succeeded', 1), ['id' => 're_SECOND']);

            return $source;
        };
        $this->assertSame('released', $this->resolve($f)['status']);
    }

    public static function unacceptable(): array
    {
        return array_combine($cases = ['none', 'partial', 'pending', 'requires_action', 'failed', 'canceled', 'wrong_sum', 'too_much', 'duplicate', 'foreign', 'changed_charge', 'refund_page', 'dispute_page', 'dispute', 'unavailable'], array_map(fn ($case) => [$case], $cases));
    }

    #[DataProvider('unacceptable')]
    public function test_incomplete_ambiguous_or_nonfinal_refunds_never_release_resources(string $case): void
    {
        $f = F::exception($this->payments);
        $before = FinalizationFixtures::retained();
        $this->financial->onInspect = static function ($source) use ($case) {
            if ($case === 'unavailable') {
                throw new RuntimeException('private provider error');
            }
            if ($case === 'none') {
                return $source;
            }
            $source = F::fullRefund($source);
            if ($case === 'partial') {
                foreach (['charge_before', 'charge_after'] as $key) {
                    $source[$key]['amount_refunded']--;
                    $source[$key]['refunded'] = false;
                }
                $source['refunds']['data'][0]['amount']--;
            }
            if (in_array($case, ['pending', 'requires_action', 'failed', 'canceled'])) {
                $source['refunds']['data'][0]['status'] = $case;
            }
            if ($case === 'wrong_sum') {
                $source['refunds']['data'][0]['amount']--;
            }
            if ($case === 'too_much') {
                $source['refunds']['data'][] = array_replace(PaymentFinancialFixtures::item('refund', 'succeeded', 1), ['id' => 're_EXTRA']);
            }
            if ($case === 'duplicate') {
                $source['refunds']['data'][] = $source['refunds']['data'][0];
            }
            if ($case === 'foreign') {
                $source['refunds']['data'][0]['payment_intent'] = 'pi_FOREIGN';
            }
            if ($case === 'changed_charge') {
                $source['charge_after']['amount_refunded']--;
            }
            if ($case === 'refund_page') {
                $source['refunds']['has_more'] = true;
            }
            if ($case === 'dispute_page') {
                $source['disputes']['has_more'] = true;
            }
            if ($case === 'dispute') {
                foreach (['charge_before', 'charge_after'] as $key) {
                    $source[$key]['disputed'] = true;
                }
                $source['disputes']['data'] = [PaymentFinancialFixtures::item('dispute', 'won', $source['payment']['amount'])];
            }

            return $source;
        };
        $key = (string) Str::uuid();
        $result = $this->resolve($f, $key);
        $this->assertContains($result['status'], ['not_refunded', 'attention', 'unavailable']);
        $calls = [$this->payments->calls, $this->financial->calls];
        $this->assertSame($result, $this->resolve($f, $key));
        $this->assertSame($calls, [$this->payments->calls, $this->financial->calls]);
        $this->assertSame(0, TestRefundResolution::count());
        $this->assertSame($before, FinalizationFixtures::retained());
    }

    public static function withdrawals(): array
    {
        return [['actor'], ['mfa'], ['account'], ['policy'], ['production']];
    }

    #[DataProvider('withdrawals')]
    public function test_current_authority_and_policy_are_checked_after_provider_io(string $case): void
    {
        $f = F::exception($this->payments);
        $before = FinalizationFixtures::retained();
        $this->financial->onInspect = function ($source) use ($case, $f) {
            match ($case) {
                'actor' => User::whereKey($f['admin']->id)->update(['is_admin' => false]),
                'mfa' => Filament::getPanel('admin')->multiFactorAuthentication([], isRequired: true),
                'account' => config(['payments.stripe.account_id' => 'acct_OTHER']),
                'policy' => config(['refund-resolution.enabled' => false]),
                'production' => $this->app->instance('env', 'production'),
            };

            return F::fullRefund($source);
        };
        try {
            $this->resolve($f);
            $this->fail('Withdrawn authority resolved.');
        } catch (AuthorizationException|ModelNotFoundException|RuntimeException) {
            $this->assertSame(0, TestRefundResolution::count());
            $this->assertSame($before, FinalizationFixtures::retained());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    #[DataProvider('withdrawals')]
    public function test_audit_callbacks_cannot_commit_resolution_after_withdrawal(string $case): void
    {
        $f = F::exception($this->payments);
        $before = FinalizationFixtures::retained();
        Event::listen('eloquent.created: '.AuditEvent::class, function ($event) use ($case, $f) {
            if ($event->action !== 'commerce.refund_resolution.released') {
                return;
            }
            match ($case) {
                'actor' => User::whereKey($f['admin']->id)->update(['is_admin' => false]),
                'mfa' => Filament::getPanel('admin')->multiFactorAuthentication([], isRequired: true),
                'account' => config(['payments.stripe.account_id' => 'acct_OTHER']),
                'policy' => config(['refund-resolution.enabled' => false]),
                'production' => $this->app->instance('env', 'production'),
            };
        });
        try {
            $this->resolve($f);
            $this->fail('Audit withdrawal committed a release.');
        } catch (AuthorizationException|ModelNotFoundException|RuntimeException) {
            $this->assertSame(0, TestRefundResolution::count());
            $this->assertSame(0, AuditEvent::where('action', 'commerce.refund_resolution.released')->count());
            $this->assertSame($before, FinalizationFixtures::retained());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public static function auditClockChanges(): array
    {
        return ['observation expires' => [0, 60], 'original request expires' => [61, 59], 'clock reverses' => [0, -1]];
    }

    #[DataProvider('auditClockChanges')]
    public function test_final_audit_clock_change_cannot_commit_an_expired_or_future_release(int $beforeInspection, int $duringAudit): void
    {
        $f = F::exception($this->payments, true);
        $original = app(ReadOrder::class)->verify($f['order']);
        $before = FinalizationFixtures::retained();
        Event::listen('eloquent.created: '.TestRefundResolutionRequest::class, function () use ($beforeInspection) {
            $this->travel($beforeInspection)->seconds();
        });
        Event::listen('eloquent.created: '.AuditEvent::class, function ($event) use ($duringAudit) {
            if ($event->action === 'commerce.refund_resolution.released') {
                $this->travel($duringAudit)->seconds();
            }
        });
        $key = (string) Str::uuid();
        try {
            $this->resolve($f, $key);
            $this->fail('Audit clock change committed an ineligible release.');
        } catch (RuntimeException) {
            $this->assertSame(0, TestRefundResolution::count());
            $this->assertSame(0, AuditEvent::where('action', 'commerce.refund_resolution.released')->count());
            $this->assertSame($before, FinalizationFixtures::retained());
            $this->assertSame($original, app(ReadOrder::class)->verify($f['order']));
        }
        $calls = [$this->payments->calls, $this->financial->calls];
        $this->assertSame('stale', $this->resolve($f, $key)['status']);
        $this->assertSame($calls, [$this->payments->calls, $this->financial->calls]);
    }

    public function test_review_disclosure_is_withheld_when_its_audit_withdraws_staff_authority(): void
    {
        $f = F::exception($this->payments);
        Event::listen('eloquent.created: '.AuditEvent::class, function ($event) use ($f) {
            if ($event->action === 'commerce.refund_resolution.reviewed') {
                User::whereKey($f['admin']->id)->update(['is_admin' => false]);
            }
        });
        $this->expectException(AuthorizationException::class);
        app(ResolveRefundedTestException::class)->review($f['record']->public_id, $f['admin']);
    }

    public function test_existing_request_cannot_change_review_sequence_or_actor_and_new_review_cannot_reuse_old_observation(): void
    {
        $f = F::exception($this->payments);
        $this->financial->onInspect = null;
        $key = (string) Str::uuid();
        $this->assertSame('not_refunded', $this->resolve($f, $key)['status']);
        $calls = [$this->payments->calls, $this->financial->calls];
        foreach ([$f + [], array_replace($f, ['admin' => LicenseFixtures::admin()])] as $index => $candidate) {
            try {
                $this->resolve($candidate, $key, $index === 0 ? 2 : 0);
                $this->fail('Changed request accepted.');
            } catch (RuntimeException) {
                $this->assertSame($calls, [$this->payments->calls, $this->financial->calls]);
            }
        }
        $this->financial->onInspect = F::fullRefund(...);
        $this->assertSame('released', $this->resolve($f, null, 2)['status']);
        $this->assertSame(2, count($this->financial->calls));
    }

    public function test_old_observed_result_cannot_be_used_after_the_freshness_deadline(): void
    {
        $f = F::exception($this->payments);
        $this->financial->onInspect = function ($source) {
            Event::listen('eloquent.created: '.TestPaymentExceptionEvent::class, function ($event) {
                if ($event->kind === 'reconciliation_observed') {
                    $this->travel(60)->seconds();
                }
            });

            return F::fullRefund($source);
        };
        $this->assertSame('stale', $this->resolve($f)['status']);
        $this->assertSame(0, TestRefundResolution::count());
    }

    public function test_original_resolution_request_expiry_prevents_late_provider_work_and_requires_a_new_review(): void
    {
        $f = F::exception($this->payments);
        $delay = true;
        Event::listen('eloquent.created: '.TestRefundResolutionRequest::class, function () use (&$delay) {
            if ($delay) {
                $this->travel(120)->seconds();
            }
        });
        $key = (string) Str::uuid();
        $this->assertSame('stale', $this->resolve($f, $key)['status']);
        $this->assertSame('stale', $this->resolve($f, $key)['status']);
        $this->assertSame([], $this->financial->calls);
        $this->assertSame(0, TestPaymentExceptionEvent::count());
        $delay = false;
        $this->assertSame('released', $this->resolve($f)['status']);
        $this->assertSame(2, TestRefundResolutionRequest::count());
    }

    public function test_resolution_audit_failure_rolls_back_both_resources_and_retained_effect_but_retry_uses_same_observation(): void
    {
        $f = F::exception($this->payments);
        $before = FinalizationFixtures::retained();
        $fail = true;
        Event::listen('eloquent.creating: '.AuditEvent::class, function ($event) use (&$fail) {
            if ($fail && $event->action === 'commerce.refund_resolution.released') {
                throw new RuntimeException('audit failed');
            }
        });
        $key = (string) Str::uuid();
        try {
            $this->resolve($f, $key);
            $this->fail('Missing audit committed.');
        } catch (RuntimeException) {
            $this->assertSame(0, TestRefundResolution::count());
            $this->assertSame($before, FinalizationFixtures::retained());
        }
        $calls = [$this->payments->calls, $this->financial->calls];
        $fail = false;
        $this->assertSame('released', $this->resolve($f, $key)['status']);
        $this->assertSame($calls, [$this->payments->calls, $this->financial->calls]);
    }

    public function test_paid_order_with_grants_is_never_a_refund_resolution_candidate(): void
    {
        $f = FinalizationFixtures::confirmed($this->payments, true, true);
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $f += ['record' => OrderFinalization::where('order_id', $f['order']->id)->sole(), 'admin' => LicenseFixtures::admin()];
        $before = FinalizationFixtures::retained();
        try {
            $this->resolve($f);
            $this->fail('Fulfilled payment accepted.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, FinalizationFixtures::retained());
        }
        $this->assertSame([], $this->financial->calls);
    }

    public static function corruptions(): array
    {
        return [['missing'], ['ciphertext'], ['event'], ['request'], ['request_time'], ['financial']];
    }

    #[DataProvider('corruptions')]
    public function test_privileged_retained_evidence_corruption_blocks_history_and_replay_without_provider_io(string $case): void
    {
        $f = F::exception($this->payments);
        $key = (string) Str::uuid();
        $this->assertSame('released', $this->resolve($f, $key)['status']);
        $calls = [$this->payments->calls, $this->financial->calls];
        // Deliberately model privileged corruption after proving the ordinary SQL guards elsewhere.
        // A damaged retained proof must never turn released resources into an accepted original graph.
        if ($case === 'missing') {
            DB::unprepared('DROP TRIGGER refund_resolution_delete');
            DB::table('test_refund_resolutions')->delete();
        } elseif ($case === 'financial') {
            DB::unprepared('DROP TRIGGER test_financial_observation_update');
            DB::table('test_payment_financial_observations')->update(['evidence_hash' => str_repeat('0', 64)]);
        } elseif (in_array($case, ['request', 'request_time'])) {
            DB::unprepared('DROP TRIGGER refund_request_update');
            DB::table('test_refund_resolution_requests')->update($case === 'request'
                ? ['expected_sequence' => 1] : ['created_at' => TestRefundResolutionRequest::sole()->created_at->subSecond()]);
        } else {
            DB::unprepared('DROP TRIGGER refund_resolution_update');
            DB::table('test_refund_resolutions')->update($case === 'ciphertext'
                ? ['evidence_hash' => str_repeat('0', 64)]
                : ['observed_event_id' => TestPaymentExceptionEvent::where('kind', 'reconciliation_requested')->sole()->id]);
        }
        try {
            app(ReadOrder::class)->verify($f['order']);
            $this->fail('Corrupt original graph accepted.');
        } catch (QuoteException $error) {
            $this->assertSame('ORDER_CHANGED', $error->errorCode);
        }
        try {
            $this->resolve($f, $key);
            $this->fail('Corrupt resolution replayed.');
        } catch (RuntimeException) {
            $this->assertSame($calls, [$this->payments->calls, $this->financial->calls]);
        }
    }
}
