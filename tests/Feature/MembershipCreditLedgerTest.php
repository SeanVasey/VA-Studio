<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Memberships\CreditLedger;
use App\Domain\Memberships\MembershipPlans;
use App\Domain\Memberships\Models\MembershipPlanVersion;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures as F;
use Tests\TestCase;

class MembershipCreditLedgerTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        $this->travelTo(now()->utc()->startOfSecond());
    }

    private function rows(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['membership_plans', 'membership_plan_versions', 'membership_credit_buckets', 'membership_credit_events', 'users', 'customer_accounts',
                'quotes', 'orders', 'license_grants', 'audit_events']);
    }

    private function refused(callable $operation, string $exception = ValidationException::class): void
    {
        $before = $this->rows();
        try {
            $operation();
            $this->fail('Invalid credit movement was accepted.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);
        }
        $this->assertSame($before, $this->rows());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function reserve(array $f, int $amount = 1, string $resource = 'synthetic:redemption_one', string $key = 'reserve_one'): array
    {
        return app(CreditLedger::class)->reserve($f['grant']['bucket_id'], $amount, $resource, $key, $f['principal'], $f['user']);
    }

    public function test_full_synthetic_renewal_redemption_release_and_reversal_preserve_partition_and_original_award(): void
    {
        $f = F::bucket();
        $ledger = app(CreditLedger::class);
        $this->assertSame(['available' => 3, 'reserved' => 0, 'consumed' => 0, 'expired' => 0], $f['grant']['balance']);
        $reserve = $this->reserve($f, 2);
        $this->assertSame(['available' => 1, 'reserved' => 2, 'consumed' => 0, 'expired' => 0], $reserve['balance']);
        $consumed = $ledger->consume($reserve['event_id'], 'consume_one', $f['principal'], $f['user']);
        $this->assertSame(['available' => 1, 'reserved' => 0, 'consumed' => 2, 'expired' => 0], $consumed['balance']);
        $reversed = $ledger->reverse($consumed['event_id'], 'reverse_one', $f['operator']);
        $this->assertSame(['available' => 3, 'reserved' => 0, 'consumed' => 0, 'expired' => 0], $reversed['balance']);
        $second = $this->reserve($f, 3, 'synthetic:redemption_two', 'reserve_two');
        $released = $ledger->release($second['event_id'], 'release_two', $f['principal'], $f['user']);
        $this->assertSame($f['grant']['balance'], $released['balance']);
        $current = $ledger->read($f['grant']['bucket_id'], $f['principal'], $f['user']);
        $this->assertSame($released['event_id'], $current['last_event_id']);
        $this->assertSame('synthetic_credit', $current['unit']);
        $this->assertSame(6, DB::table('membership_credit_events')->count());
        $this->assertSame(0, DB::table('license_grants')->count());
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('quotes')->count());
        $audit = AuditEvent::latest('id')->firstOrFail();
        $this->assertSame('membership.test_credit.release', $audit->action);
        $this->assertSame($f['user']->id, $audit->actor_id);
        $this->assertSame($released['event_id'], $audit->context['event_id']);
        $this->assertStringNotContainsString('synthetic:redemption_two', json_encode($audit->context));
        $this->assertStringNotContainsString($f['account']->owner_key, json_encode($current));
    }

    public function test_one_source_cannot_award_again_or_move_to_another_account_or_plan_version(): void
    {
        $f = F::bucket();
        $ledger = app(CreditLedger::class);
        $before = $this->rows();
        $this->assertSame($f['grant'], $ledger->grantSynthetic($f['version'], $f['account'], 'synthetic:invoice_fixture_one', $f['operator']));
        $this->assertSame($before, $this->rows());
        $other = CustomerFixtures::account();
        $this->refused(fn () => $ledger->grantSynthetic($f['version'], $other['account'], 'synthetic:invoice_fixture_one', $f['operator']));
        $command = app(MembershipPlans::class);
        $review = $command->reviewRevision($f['plan'], F::data(['allowance' => 9]), $f['operator']);
        $next = $command->applyReviewedRevision($review, $f['operator']);
        $nextVersion = MembershipPlanVersion::findOrFail($next['version_id']);
        $this->refused(fn () => $ledger->grantSynthetic($nextVersion, $f['account'], 'synthetic:invoice_fixture_one', $f['operator']));
        $second = $ledger->grantSynthetic($nextVersion, $f['account'], 'synthetic:invoice_fixture_two', $f['operator']);
        $this->assertSame(9, $second['amount']);
        $this->assertSame(3, $ledger->read($f['grant']['bucket_id'], $f['principal'], $f['user'])['balance']['available']);
    }

    public function test_every_actual_movement_replays_the_original_exact_result_without_effects(): void
    {
        $f = F::bucket();
        $ledger = app(CreditLedger::class);
        $reserve = $this->reserve($f);
        $before = $this->rows();
        $this->assertSame($reserve, $this->reserve($f));
        $this->assertSame($before, $this->rows());
        $consume = $ledger->consume($reserve['event_id'], 'consume', $f['principal'], $f['user']);
        $before = $this->rows();
        $this->assertSame($consume, $ledger->consume($reserve['event_id'], 'consume', $f['principal'], $f['user']));
        $this->assertSame($before, $this->rows());
        $reverse = $ledger->reverse($consume['event_id'], 'reverse', $f['operator']);
        $before = $this->rows();
        $this->assertSame($reverse, $ledger->reverse($consume['event_id'], 'reverse', $f['operator']));
        $this->assertSame($before, $this->rows());
        $other = $this->reserve($f, 1, 'synthetic:other', 'other');
        $release = $ledger->release($other['event_id'], 'release', $f['principal'], $f['user']);
        $before = $this->rows();
        $this->assertSame($release, $ledger->release($other['event_id'], 'release', $f['principal'], $f['user']));
        $this->assertSame($before, $this->rows());
    }

    public static function illegal(): array
    {
        return ['overspend' => ['overspend'], 'same resource' => ['resource'], 'key amount conflict' => ['amount-key'],
            'key resource conflict' => ['resource-key'], 'consume release conflict' => ['released-consume'],
            'release consume conflict' => ['consumed-release'], 'double consumption' => ['consume-twice'],
            'double release' => ['release-twice'], 'double reversal' => ['reverse-twice'], 'wrong event type' => ['event-type'],
            'unknown reservation' => ['unknown'], 'plain resource' => ['plain-resource'], 'plain grant source' => ['plain-source'],
            'empty key' => ['empty-key'], 'zero amount' => ['zero'], 'negative amount' => ['negative'], 'huge amount' => ['huge'],
            'float amount' => ['float'], 'numeric string amount' => ['string'], 'boolean amount' => ['boolean']];
    }

    #[DataProvider('illegal')]
    public function test_illegal_movements_have_no_partial_effect(string $case): void
    {
        $f = F::bucket();
        $ledger = app(CreditLedger::class);
        $reserved = $this->reserve($f, 2);
        if (in_array($case, ['released-consume', 'release-twice'], true)) {
            $ledger->release($reserved['event_id'], 'released', $f['principal'], $f['user']);
        }
        $consumed = null;
        if (in_array($case, ['consumed-release', 'consume-twice', 'reverse-twice'], true)) {
            $consumed = $ledger->consume($reserved['event_id'], 'consumed', $f['principal'], $f['user']);
        }
        if ($case === 'reverse-twice') {
            $ledger->reverse($consumed['event_id'], 'reversed', $f['operator']);
        }
        $this->refused(fn () => match ($case) {
            'overspend' => $this->reserve($f, 2, 'synthetic:other', 'other'),
            'resource' => $this->reserve($f, 1, 'synthetic:redemption_one', 'other'),
            'amount-key' => $this->reserve($f, 1),
            'resource-key' => $this->reserve($f, 2, 'synthetic:different'),
            'released-consume', 'consume-twice' => $ledger->consume($reserved['event_id'], 'new-consumption', $f['principal'], $f['user']),
            'consumed-release', 'release-twice' => $ledger->release($reserved['event_id'], 'new-release', $f['principal'], $f['user']),
            'reverse-twice' => $ledger->reverse($consumed['event_id'], 'new-reverse', $f['operator']),
            'event-type' => $ledger->consume($f['grant']['event_id'], 'wrong', $f['principal'], $f['user']),
            'unknown' => $ledger->consume(999999, 'wrong', $f['principal'], $f['user']),
            'plain-resource' => $this->reserve($f, 1, 'order:unverified_payment', 'other'),
            'plain-source' => $ledger->grantSynthetic($f['version'], $f['account'], 'in_unverified', $f['operator']),
            'empty-key' => $this->reserve($f, 1, 'synthetic:other', ''),
            'zero' => $this->reserve($f, 0), 'negative' => $this->reserve($f, -1), 'huge' => $this->reserve($f, 1000001),
            'float' => $ledger->reserve($f['grant']['bucket_id'], 1.9, 'synthetic:other', 'other', $f['principal'], $f['user']),
            'string' => $ledger->reserve($f['grant']['bucket_id'], '1', 'synthetic:other', 'other', $f['principal'], $f['user']),
            'boolean' => $ledger->reserve($f['grant']['bucket_id'], true, 'synthetic:other', 'other', $f['principal'], $f['user']),
        });
    }

    public function test_expiry_cannot_recycle_reserved_or_reversed_credits(): void
    {
        $f = F::bucket(['validity_seconds' => 10]);
        $ledger = app(CreditLedger::class);
        $reserved = $this->reserve($f);
        $second = $this->reserve($f, 1, 'synthetic:second', 'second');
        $consumed = $ledger->consume($second['event_id'], 'consume-second', $f['principal'], $f['user']);
        $this->travel(10)->seconds();
        $this->assertSame(0, $ledger->read($f['grant']['bucket_id'], $f['principal'], $f['user'])['spendable_credits']);
        $this->refused(fn () => $ledger->consume($reserved['event_id'], 'late-consume', $f['principal'], $f['user']));
        $this->refused(fn () => $this->reserve($f, 1, 'synthetic:late', 'late'));
        $expire = $ledger->expire($f['grant']['bucket_id'], 'expire', $f['operator']);
        $this->assertSame(['available' => 0, 'reserved' => 1, 'consumed' => 1, 'expired' => 1], $expire['balance']);
        $before = $this->rows();
        $this->assertSame($expire, $ledger->expire($f['grant']['bucket_id'], 'expire', $f['operator']));
        $this->assertSame($expire, $ledger->expire($f['grant']['bucket_id'], 'expire-noop', $f['operator']));
        $this->assertSame($before, $this->rows());
        $release = $ledger->release($reserved['event_id'], 'late-release', $f['principal'], $f['user']);
        $this->assertSame(['available' => 0, 'reserved' => 0, 'consumed' => 1, 'expired' => 2], $release['balance']);
        $reverse = $ledger->reverse($consumed['event_id'], 'late-reverse', $f['operator']);
        $this->assertSame(['available' => 0, 'reserved' => 0, 'consumed' => 0, 'expired' => 3], $reverse['balance']);
    }

    public function test_expiry_and_reversal_eligibility_are_frozen_explicit_policy(): void
    {
        $f = F::bucket(['validity_seconds' => null, 'reversal_allowed' => false]);
        $ledger = app(CreditLedger::class);
        $reserved = $this->reserve($f);
        $consume = $ledger->consume($reserved['event_id'], 'consume', $f['principal'], $f['user']);
        $this->refused(fn () => $ledger->reverse($consume['event_id'], 'reverse', $f['operator']));
        $this->travel(370)->days();
        $this->refused(fn () => $ledger->expire($f['grant']['bucket_id'], 'expire', $f['operator']));
        $this->assertSame(2, $ledger->read($f['grant']['bucket_id'], $f['principal'], $f['user'])['balance']['available']);
    }

    public function test_other_account_cannot_read_reserve_consume_release_or_substitute_an_event(): void
    {
        $f = F::bucket();
        $other = CustomerFixtures::account();
        $reserved = $this->reserve($f);
        $ledger = app(CreditLedger::class);
        foreach ([
            fn () => $ledger->read($f['grant']['bucket_id'], $other['principal'], $other['user']),
            fn () => $ledger->reserve($f['grant']['bucket_id'], 1, 'synthetic:other', 'other', $other['principal'], $other['user']),
            fn () => $ledger->consume($reserved['event_id'], 'other-consume', $other['principal'], $other['user']),
            fn () => $ledger->release($reserved['event_id'], 'other-release', $other['principal'], $other['user']),
        ] as $operation) {
            $this->refused($operation, AuthorizationException::class);
        }
    }

    public static function customerAuthority(): array
    {
        return ['account' => ['account'], 'password' => ['password'], 'email' => ['email'], 'admin' => ['admin']];
    }

    #[DataProvider('customerAuthority')]
    public function test_fresh_account_and_credential_authority_precedes_reads_writes_and_replays(string $withdraw): void
    {
        $f = F::bucket();
        $reserve = $this->reserve($f);
        match ($withdraw) {
            'account' => CustomerFixtures::withdraw($f),
            'password' => DB::table('users')->where('id', $f['user']->id)->update(['password' => Hash::make('Synthetic-new-credential')]),
            'email' => DB::table('users')->where('id', $f['user']->id)->update(['email_verified_at' => null]),
            'admin' => DB::table('users')->where('id', $f['user']->id)->update(['is_admin' => true]),
        };
        $ledger = app(CreditLedger::class);
        $this->refused(fn () => $this->reserve($f), CustomerAccessException::class);
        $this->refused(fn () => $ledger->consume($reserve['event_id'], 'consume', $f['principal'], $f['user']), CustomerAccessException::class);
        $this->refused(fn () => $ledger->read($f['grant']['bucket_id'], $f['principal'], $f['user']), CustomerAccessException::class);
    }

    public static function staffAuthority(): array
    {
        return ['role' => ['role'], 'email' => ['email'], 'MFA' => ['mfa']];
    }

    #[DataProvider('staffAuthority')]
    public function test_grant_replay_reversal_and_expiry_never_bypass_current_staff_authority(string $withdraw): void
    {
        $f = F::bucket(['validity_seconds' => 1]);
        $ledger = app(CreditLedger::class);
        $reserve = $this->reserve($f);
        $consume = $ledger->consume($reserve['event_id'], 'consume', $f['principal'], $f['user']);
        $this->travel(1)->seconds();
        DB::table('users')->where('id', $f['operator']->id)->update(match ($withdraw) {
            'role' => ['is_admin' => false], 'email' => ['email_verified_at' => null], default => ['app_authentication_secret' => null],
        });
        $this->refused(fn () => $ledger->grantSynthetic($f['version'], $f['account'], 'synthetic:invoice_fixture_one', $f['operator']), AuthorizationException::class);
        $this->refused(fn () => $ledger->reverse($consume['event_id'], 'reverse', $f['operator']), AuthorizationException::class);
        $this->refused(fn () => $ledger->expire($f['grant']['bucket_id'], 'expire', $f['operator']), AuthorizationException::class);
    }

    public function test_authority_withdrawal_by_credit_audit_observer_rolls_back_every_effect(): void
    {
        $f = F::bucket();
        AuditEvent::created(function (AuditEvent $event) use ($f): void {
            if ($event->action === 'membership.test_credit.reserve') {
                DB::table('customer_accounts')->where('id', $f['account']->id)->update(['active' => false, 'access_version' => 2, 'updated_at' => now()]);
            }
        });
        $this->refused(fn () => $this->reserve($f), CustomerAccessException::class);
    }

    public function test_modified_own_audit_context_refuses_a_credit_write(): void
    {
        $f = F::bucket();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'membership.test_credit.reserve') {
                $event->context = ['test_only' => true];
            }
        });
        $this->refused(fn () => $this->reserve($f));
    }

    public function test_final_user_retrieval_drift_is_caught_after_all_callbacks_and_rolls_back(): void
    {
        $f = F::bucket();
        $armed = false;
        AuditEvent::created(function (AuditEvent $event) use (&$armed): void {
            if ($event->action === 'membership.test_credit.reserve') {
                $armed = true;
            }
        });
        User::retrieved(function (User $user) use ($f, &$armed): void {
            if ($armed && $user->id === $f['user']->id) {
                $armed = false;
                DB::table('users')->where('id', $user->id)->update(['name' => 'Synthetic observer changed an earlier checked row']);
            }
        });
        $this->refused(fn () => $this->reserve($f));
    }

    public function test_disabled_foundation_refuses_all_credit_operations_and_no_op_reads(): void
    {
        $f = F::bucket();
        config(['memberships.test_mode_enabled' => false]);
        $this->refused(fn () => $this->reserve($f), AuthorizationException::class);
        $this->refused(fn () => app(CreditLedger::class)->read($f['grant']['bucket_id'], $f['principal'], $f['user']), AuthorizationException::class);
        $this->refused(fn () => app(CreditLedger::class)->grantSynthetic($f['version'], $f['account'], 'synthetic:invoice_fixture_one', $f['operator']), AuthorizationException::class);
    }

    public static function expiryCallbacks(): array
    {
        return ['reserve' => ['reserve'], 'consume' => ['consume'], 'release' => ['release'], 'reverse' => ['reverse']];
    }

    #[DataProvider('expiryCallbacks')]
    public function test_an_audit_callback_crossing_expiry_cannot_commit_predeadline_credit_eligibility(string $kind): void
    {
        $f = F::bucket(['validity_seconds' => 10]);
        $ledger = app(CreditLedger::class);
        $reserve = $kind === 'reserve' ? null : $this->reserve($f);
        $consume = $kind === 'reverse' ? $ledger->consume($reserve['event_id'], 'consume', $f['principal'], $f['user']) : null;
        AuditEvent::created(function (AuditEvent $event) use ($kind): void {
            if ($event->action === 'membership.test_credit.'.$kind) {
                $this->travel(10)->seconds();
            }
        });
        $this->refused(fn () => match ($kind) {
            'reserve' => $this->reserve($f),
            'consume' => $ledger->consume($reserve['event_id'], 'consume', $f['principal'], $f['user']),
            'release' => $ledger->release($reserve['event_id'], 'release', $f['principal'], $f['user']),
            'reverse' => $ledger->reverse($consume['event_id'], 'reverse', $f['operator']),
        });
    }

    public function test_a_ledger_command_never_adopts_an_outer_transaction(): void
    {
        $f = F::bucket();
        $before = $this->rows();
        DB::beginTransaction();
        try {
            try {
                $this->reserve($f);
                $this->fail('A nested credit write was admitted.');
            } catch (\LogicException) {
            }
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame($before, $this->rows());
        } finally {
            DB::rollBack();
        }
    }
}
