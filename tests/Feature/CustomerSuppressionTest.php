<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\Preferences\Models\ConsentEvent;
use App\Domain\Customers\Preferences\Models\ConsentState;
use App\Domain\Customers\Preferences\Suppression\Models\SuppressionAttempt;
use App\Domain\Customers\Preferences\Suppression\Models\SuppressionConfirmation;
use App\Domain\Customers\Preferences\Suppression\Models\SuppressionIntent;
use App\Domain\Customers\Preferences\Suppression\Models\SuppressionTarget;
use App\Domain\Customers\Preferences\Suppression\SuppressionDelivery;
use App\Domain\Customers\Preferences\Suppression\SuppressionReceipt;
use App\Domain\Customers\Preferences\Suppression\SuppressionRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\SuppressionFixtures;
use Tests\TestCase;

class CustomerSuppressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_default_off_withdrawal_is_atomic_encrypted_pending_and_repeated_intents_advance(): void
    {
        $c = CustomerFixtures::account();
        $s = new CustomerConsentPreferences;
        $this->assertSame('not_requested', $s->read($c['principal'], $c['user'])['purposes'][0]['suppression']['status']);
        foreach ([0, 1, 2] as $version) {
            $dto = $s->change($c['principal'], $c['user'], ConsentFixtures::withdraw($version));
            $this->assertSame($version + 1, $dto['purposes'][0]['version']);
            $this->assertSame(['status' => 'pending'], $dto['purposes'][0]['suppression']);
            foreach ([$c['user']->email, 'recipient', 'provider', 'accountId', 'ownerKey'] as $private) {
                $this->assertStringNotContainsString($private, json_encode($dto));
            }
        }
        $this->assertSame(3, SuppressionIntent::count());
        $this->assertSame(1, SuppressionTarget::count());
        $this->assertStringNotContainsString($c['user']->email, SuppressionTarget::sole()->recipient_ciphertext);
        $this->assertSame(['status' => 'pending'], (new SuppressionDelivery)->process($c['principal'], $c['user'], 3));
        $this->assertSame(0, SuppressionAttempt::count());
        $this->refuses(fn () => $s->change($c['principal'], $c['user'], ConsentFixtures::withdraw(2)), 409);
        $this->assertSame(3, ConsentEvent::count());
        $this->assertSame(3, SuppressionIntent::count());
    }

    public function test_intent_failure_rolls_back_consent_and_target_without_partial_choice(): void
    {
        $c = CustomerFixtures::account();
        SuppressionIntent::creating(fn () => throw new \RuntimeException('Synthetic storage refusal'));
        $this->refuses(fn () => (new CustomerConsentPreferences)->change($c['principal'], $c['user'], ConsentFixtures::withdraw()), 503);
        foreach ([ConsentEvent::class, ConsentState::class, SuppressionTarget::class, SuppressionIntent::class] as $class) {
            $this->assertSame(0, $class::count());
        }
    }

    public function test_single_positive_attempt_is_committed_before_adapter_and_is_never_sent_again(): void
    {
        $c = $this->withdraw();
        $adapter = new SuppressionFixtures;
        $committed = false;
        SuppressionAttempt::created(function () use (&$committed) {
            DB::afterCommit(function () use (&$committed) {
                $committed = DB::connection()->transactionLevel() === 0 && SuppressionAttempt::count() === 1;
            });
        });
        $adapter->onSuppress = function (SuppressionRequest $r) use (&$committed, $c) {
            $this->assertTrue($committed);
            $this->assertSame($c['user']->email, $r->recipient);
            $this->assertSame(1, DB::connection()->transactionLevel());

            return SuppressionFixtures::positive($r);
        };
        $service = new SuppressionDelivery($adapter);
        $this->assertSame(['status' => 'confirmed'], $service->process($c['principal'], $c['user'], 1));
        $this->assertSame(['status' => 'confirmed'], $service->process($c['principal'], $c['user'], 1));
        $this->assertSame(['status' => 'confirmed'], $service->reconcile($c['principal'], $c['user'], 1));
        $this->assertSame(1, $adapter->sent);
        $this->assertSame(0, $adapter->inspected);
        $this->assertSame(1, SuppressionConfirmation::count());
        $this->assertSame('confirmed', (new CustomerConsentPreferences)->read($c['principal'], $c['user'])['purposes'][0]['suppression']['status']);
        $this->assertStringNotContainsString('SYNTHETIC-POSITIVE-RECEIPT', SuppressionConfirmation::sole()->receipt_ciphertext);
    }

    #[DataProvider('ambiguousReceipts')]
    public function test_ambiguous_or_wrong_receipt_never_confirms_or_retries_even_after_repeated_withdrawal(string $kind): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        $a->onSuppress = fn (SuppressionRequest $r) => match ($kind) {
            'throw' => throw new \RuntimeException('Synthetic ambiguous transport'), 'null' => null,
            'operation' => new SuppressionReceipt('00000000-0000-4000-8000-000000000000', $r->recipientHmac, $r->bindingHash, $r->requestHash, 'SYNTHETIC'),
            'recipient' => new SuppressionReceipt($r->operationId, str_repeat('a', 64), $r->bindingHash, $r->requestHash, 'SYNTHETIC'),
            'binding' => new SuppressionReceipt($r->operationId, $r->recipientHmac, str_repeat('a', 64), $r->requestHash, 'SYNTHETIC'),
            'request' => new SuppressionReceipt($r->operationId, $r->recipientHmac, $r->bindingHash, str_repeat('a', 64), 'SYNTHETIC'),
            'empty' => new SuppressionReceipt($r->operationId, $r->recipientHmac, $r->bindingHash, $r->requestHash, ''),
        };
        $s = new SuppressionDelivery($a);
        $this->assertSame(['status' => 'unknown'], $s->process($c['principal'], $c['user'], 1));
        (new CustomerConsentPreferences)->change($c['principal'], $c['user'], ConsentFixtures::withdraw(1));
        $this->assertSame(['status' => 'unknown'], $s->process($c['principal'], $c['user'], 2));
        $this->assertSame(1, $a->sent);
        $this->assertSame(1, SuppressionAttempt::count());
        $this->assertSame(2, SuppressionIntent::count());
        $this->assertSame(0, SuppressionConfirmation::count());
        $a->onInspect = fn () => null;
        $this->assertSame(['status' => 'unknown'], $s->reconcile($c['principal'], $c['user'], 2));
        $a->onInspect = null;
        $this->assertSame(['status' => 'confirmed'], $s->reconcile($c['principal'], $c['user'], 2));
        $this->assertSame(2, $a->inspected);
        $this->assertSame(1, $a->sent);
    }

    public static function ambiguousReceipts(): array
    {
        return array_map(fn ($v) => [$v], ['throw', 'null', 'operation', 'recipient', 'binding', 'request', 'empty']);
    }

    public function test_default_unbound_adapter_cannot_send_even_under_synthetic_enabled_binding(): void
    {
        $c = $this->withdraw();
        new SuppressionFixtures;
        $this->assertSame(['status' => 'pending'], (new SuppressionDelivery)->process($c['principal'], $c['user'], 1));
        $this->assertSame(['status' => 'pending'], (new SuppressionDelivery)->reconcile($c['principal'], $c['user'], 1));
        $this->assertSame(0, SuppressionAttempt::count());
    }

    #[DataProvider('falsePolicies')]
    public function test_non_boolean_or_invalid_binding_cannot_authorize_transport(mixed $enabled, mixed $binding): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        if ($binding === 'valid') {
            $binding = config('customer-suppression.binding');
        }
        config(['customer-suppression' => ['enabled' => $enabled, 'binding' => $binding]]);
        $this->assertSame(['status' => 'pending'], (new SuppressionDelivery($a))->process($c['principal'], $c['user'], 1));
        $this->assertSame(0, $a->sent);
        $this->assertSame(0, SuppressionAttempt::count());
    }

    public static function falsePolicies(): array
    {
        return [[false, 'valid'], ['true', 'valid'], [1, 'valid'], [true, ['adapter' => 'synthetic', 'version' => 'v1', 'scope' => 'list', 'reviewReference' => '']], [true, ['adapter' => 'synthetic', 'version' => 'v1', 'scope' => 'list', 'reviewReference' => 'review', 'extra' => true]]];
    }

    public function test_nested_transaction_and_stale_version_refuse_before_attempt_or_adapter(): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        $s = new SuppressionDelivery($a);
        $this->refuses(fn () => $s->process($c['principal'], $c['user'], 0), 409);
        DB::transaction(fn () => $this->refuses(fn () => $s->process($c['principal'], $c['user'], 1), 503));
        $this->assertSame(0, SuppressionAttempt::count());
        $this->assertSame(0, $a->sent);
    }

    public function test_other_actor_and_revoked_account_cannot_claim_or_inspect(): void
    {
        $c = $this->withdraw();
        $other = CustomerFixtures::account();
        $a = new SuppressionFixtures;
        $s = new SuppressionDelivery($a);
        $this->accessRefuses(fn () => $s->process($c['principal'], $other['user'], 1));
        $this->accessRefuses(fn () => $s->reconcile($c['principal'], $other['user'], 1));
        CustomerFixtures::withdraw($c);
        $this->accessRefuses(fn () => $s->process($c['principal'], $c['user'], 1));
        $this->assertSame(0, SuppressionAttempt::count());
        $this->assertSame(0, $a->sent);
        $this->assertSame(0, $a->inspected);
    }

    public function test_credentials_changed_after_durable_claim_block_transport_and_retain_unknown(): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        SuppressionAttempt::created(function () use ($c) {
            DB::afterCommit(fn () => User::whereKey($c['user']->id)->update(['password' => 'synthetic-new-credential']));
        });
        $this->accessRefuses(fn () => (new SuppressionDelivery($a))->process($c['principal'], $c['user'], 1));
        $this->assertSame(1, SuppressionAttempt::count());
        $this->assertSame(0, SuppressionConfirmation::count());
        $this->assertSame(0, $a->sent);
    }

    public function test_late_credential_withdrawal_after_positive_receipt_cannot_confirm(): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        $a->onSuppress = function (SuppressionRequest $r) use ($c) {
            User::whereKey($c['user']->id)->update(['password' => 'synthetic-late-credential']);

            return SuppressionFixtures::positive($r);
        };
        $this->accessRefuses(fn () => (new SuppressionDelivery($a))->process($c['principal'], $c['user'], 1));
        $this->assertSame(1, SuppressionAttempt::count());
        $this->assertSame(0, SuppressionConfirmation::count());
        $this->assertSame(1, $a->sent);
        $this->assertSame('unknown', (new CustomerConsentPreferences)->read($c['principal'], $c['user'])['purposes'][0]['suppression']['status']);
    }

    public function test_terminal_adapter_policy_callback_cannot_release_confirmation(): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        $after = false;
        $a->onSuppress = function (SuppressionRequest $r) use (&$after) {
            $after = true;

            return SuppressionFixtures::positive($r);
        };
        $a->onBinding = function () use (&$after) {
            if ($after) {
                config(['customer-suppression.enabled' => false]);
            }
        };
        $this->refuses(fn () => (new SuppressionDelivery($a))->process($c['principal'], $c['user'], 1), 503);
        $this->assertSame(1, SuppressionAttempt::count());
        $this->assertSame(0, SuppressionConfirmation::count());
        $this->assertSame(1, $a->sent);
    }

    public function test_changed_provider_binding_never_resends_old_unknown_or_projects_old_confirmation(): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        $s = new SuppressionDelivery($a);
        $s->process($c['principal'], $c['user'], 1);
        config(['customer-suppression.binding.version' => 'synthetic-v2']);
        $this->assertSame('unknown', (new CustomerConsentPreferences)->read($c['principal'], $c['user'])['purposes'][0]['suppression']['status']);
        $this->assertSame(['status' => 'unknown'], $s->process($c['principal'], $c['user'], 1));
        $this->assertSame(['status' => 'unknown'], $s->reconcile($c['principal'], $c['user'], 1));
        $this->assertSame(1, $a->sent);
        $this->assertSame(0, $a->inspected);
        $this->assertSame(1, SuppressionAttempt::count());
    }

    public function test_grant_does_not_unsuppress_and_changed_recipient_never_reuses_other_target(): void
    {
        ConsentFixtures::configure();
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        $s = new SuppressionDelivery($a);
        $s->process($c['principal'], $c['user'], 1);
        $prefs = new CustomerConsentPreferences;
        $dto = $prefs->change($c['principal'], $c['user'], ConsentFixtures::grant(1));
        $this->assertSame('confirmed', $dto['purposes'][0]['suppression']['status']);
        $this->assertSame(1, $a->sent);
        User::whereKey($c['user']->id)->update(['email' => 'synthetic-changed@example.test']);
        $this->assertSame('not_requested', $prefs->read($c['principal'], $c['user'])['purposes'][0]['suppression']['status']);
        $prefs->change($c['principal'], $c['user'], ConsentFixtures::withdraw(2));
        $s->process($c['principal'], $c['user'], 3);
        $this->assertSame(2, SuppressionTarget::count());
        $this->assertSame(2, SuppressionAttempt::count());
        $this->assertSame(2, $a->sent);
    }

    public function test_saved_attempt_callback_cannot_replace_intended_operation_and_commit_transport(): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        SuppressionAttempt::creating(fn ($model) => $model->public_id = '00000000-0000-4000-8000-000000000000');
        $this->refuses(fn () => (new SuppressionDelivery($a))->process($c['principal'], $c['user'], 1), 503);
        $this->assertSame(0, SuppressionAttempt::count());
        $this->assertSame(0, $a->sent);
    }

    public function test_terminal_framework_query_withdrawal_cannot_release_positive_receipt(): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        $after = false;
        $fired = false;
        $a->onSuppress = function (SuppressionRequest $r) use (&$after) {
            $after = true;

            return SuppressionFixtures::positive($r);
        };
        DB::listen(function ($query) use (&$after, &$fired, $c) {
            if ($after && ! $fired && str_contains($query->sql, 'customer_accounts') && str_starts_with(strtolower($query->sql), 'select')) {
                $fired = true;
                User::whereKey($c['user']->id)->update(['password' => 'synthetic-terminal-credential']);
            }
        });
        $this->accessRefuses(fn () => (new SuppressionDelivery($a))->process($c['principal'], $c['user'], 1));
        $this->assertTrue($fired);
        $this->assertSame(1, SuppressionAttempt::count());
        $this->assertSame(0, SuppressionConfirmation::count());
        $this->assertSame(1, $a->sent);
    }

    public function test_customer_policy_disabled_by_adapter_cannot_release_receipt_or_private_status(): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        $a->onSuppress = function (SuppressionRequest $r) {
            config(['customer.test_accounts_enabled' => false]);

            return SuppressionFixtures::positive($r);
        };
        $this->accessRefuses(fn () => (new SuppressionDelivery($a))->process($c['principal'], $c['user'], 1));
        $this->assertSame(1, SuppressionAttempt::count());
        $this->assertSame(0, SuppressionConfirmation::count());
    }

    public function test_reconcile_uses_current_actor_and_credentials_and_never_sends(): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        $a->onSuppress = fn () => null;
        $s = new SuppressionDelivery($a);
        $s->process($c['principal'], $c['user'], 1);
        User::whereKey($c['user']->id)->update(['password' => 'synthetic-changed-for-inspection']);
        $this->accessRefuses(fn () => $s->reconcile($c['principal'], $c['user'], 1));
        $this->assertSame(0, $a->inspected);
        $this->assertSame(1, $a->sent);
        $this->assertSame(0, SuppressionConfirmation::count());
    }

    public function test_current_production_account_policy_stays_required_before_any_transport(): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        $this->app->instance('env', 'production');
        try {
            $this->accessRefuses(fn () => (new SuppressionDelivery($a))->process($c['principal'], $c['user'], 1));
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertSame(0, SuppressionAttempt::count());
        $this->assertSame(0, $a->sent);
    }

    public function test_native_and_sqlite_guards_retain_every_target_intent_attempt_and_receipt(): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        (new SuppressionDelivery($a))->process($c['principal'], $c['user'], 1);
        foreach (['customer_suppression_targets', 'customer_suppression_intents', 'customer_suppression_attempts', 'customer_suppression_confirmations'] as $table) {
            $before = (array) DB::table($table)->sole();
            foreach (['UPDATE `'.$table.'` SET id=id', 'DELETE FROM `'.$table.'`'] as $sql) {
                try {
                    DB::unprepared($sql);
                    $this->fail('Expected retained graph refusal');
                } catch (QueryException) {
                    $this->assertSame($before, (array) DB::table($table)->sole());
                }
            }
            try {
                DB::table($table)->insert($before);
                $this->fail('Expected retained duplicate refusal');
            } catch (QueryException) {
                $this->assertSame($before, (array) DB::table($table)->sole());
            }
        }
    }

    public function test_binding_resolver_failure_is_generic_and_never_claims_or_transports(): void
    {
        $c = $this->withdraw();
        $a = new SuppressionFixtures;
        $a->onBinding = fn () => throw new \RuntimeException('Synthetic private adapter detail');
        $this->refuses(fn () => (new SuppressionDelivery($a))->process($c['principal'], $c['user'], 1), 503);
        $this->assertSame(0, SuppressionAttempt::count());
        $this->assertSame(0, $a->sent);
    }

    private function withdraw(): array
    {
        $c = CustomerFixtures::account();
        (new CustomerConsentPreferences)->change($c['principal'], $c['user'], ConsentFixtures::withdraw());

        return $c;
    }

    private function refuses(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Expected suppression refusal');
        } catch (ConsentException $e) {
            $this->assertSame($status, $e->status);
        }
    }

    private function accessRefuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected current authority refusal');
        } catch (CustomerAccessException) {
            $this->assertTrue(true);
        }
    }
}
