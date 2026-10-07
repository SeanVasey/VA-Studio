<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\Listening\ListeningLibrary;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\ConsentRuntime;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\Preferences\LocalConsentRuntime;
use App\Domain\Customers\Preferences\Models\ConsentEvent;
use App\Domain\Customers\Preferences\Models\ConsentPolicySnapshot;
use App\Domain\Customers\Preferences\Models\ConsentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\TestCase;

class CustomerConsentPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_default_never_creates_a_choice_or_invents_a_notice(): void
    {
        $customer = CustomerFixtures::account();
        $service = app(CustomerConsentPreferences::class);
        $this->assertSame(['schema' => 1, 'purposes' => [['purpose' => 'email_marketing', 'version' => 0, 'status' => 'unknown', 'notice' => null, 'canGrant' => false]]], $service->read($customer['principal'], $customer['user']));
        $this->assertSame(0, ConsentEvent::count());
        $this->assertSame(0, ConsentState::count());
        $this->assertSame(0, ConsentPolicySnapshot::count());
    }

    public function test_exact_affirmative_choice_persists_encrypted_server_recipient_and_original_notice(): void
    {
        ConsentFixtures::configure();
        $customer = CustomerFixtures::account();
        $service = app(CustomerConsentPreferences::class);
        $read = $service->read($customer['principal'], $customer['user']);
        $this->assertTrue($read['purposes'][0]['canGrant']);
        $this->assertSame('unknown', $read['purposes'][0]['status']);
        $granted = $service->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
        $this->assertSame('granted', $granted['purposes'][0]['status']);
        $this->assertSame(1, $granted['purposes'][0]['version']);
        $this->assertSame($granted, $service->read($customer['principal'], $customer['user']));
        $event = ConsentEvent::sole();
        $this->assertTrue($event->affirmative);
        $this->assertSame('first_party_customer', $event->source);
        $this->assertStringNotContainsString($customer['user']->email, $event->recipient_ciphertext);
        $serialized = json_encode($granted);
        foreach (['"email":', 'accountId', 'userId', 'recipient', 'ciphertext', $customer['account']->owner_key, $customer['user']->email] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
        $policy = ConsentPolicySnapshot::sole();
        $this->assertSame(ConsentFixtures::policy()['notice'], $policy->notice);
        $this->assertSame(ConsentFixtures::policy()['review_reference'], $policy->review_reference);
        $this->assertSame(hash('sha256', $policy->notice), $policy->notice_hash);
    }

    public function test_every_withdrawal_advances_revision_even_unknown_or_already_withdrawn_and_fences_stale_grants(): void
    {
        ConsentFixtures::configure();
        $customer = CustomerFixtures::account();
        $service = app(CustomerConsentPreferences::class);
        foreach ([0, 1, 2] as $version) {
            $result = $service->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw($version));
            $this->assertSame('withdrawn', $result['purposes'][0]['status']);
            $this->assertSame($version + 1, $result['purposes'][0]['version']);
        }
        $this->assertSame(3, ConsentEvent::count());
        $this->assertSame(0, ConsentPolicySnapshot::count());
        $before = ConsentState::sole()->getRawOriginal();
        $this->refuses(fn () => $service->change($customer['principal'], $customer['user'], ConsentFixtures::grant(2)), 409);
        $this->assertSame($before, ConsentState::sole()->getRawOriginal());
        $this->refuses(fn () => $service->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw(2)), 409);
    }

    public function test_changed_or_disabled_notice_never_infers_grant_and_withdrawal_remains_available(): void
    {
        ConsentFixtures::configure();
        $customer = CustomerFixtures::account();
        $service = app(CustomerConsentPreferences::class);
        $service->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
        config(['customer-preferences.email_marketing' => ConsentFixtures::policy('synthetic-notice-v2')]);
        $read = $service->read($customer['principal'], $customer['user']);
        $this->assertSame('unknown', $read['purposes'][0]['status']);
        $this->assertSame(1, $read['purposes'][0]['version']);
        config(['customer-preferences.test_grants_enabled' => false, 'customer-preferences.email_marketing' => null]);
        $withdrawn = $service->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw(1));
        $this->assertSame('withdrawn', $withdrawn['purposes'][0]['status']);
        $this->assertFalse($withdrawn['purposes'][0]['canGrant']);
        $this->assertNull($withdrawn['purposes'][0]['notice']);
        $this->assertSame(2, ConsentEvent::count());
        $this->assertSame(1, ConsentPolicySnapshot::count());
    }

    public function test_notice_version_cannot_be_reused_to_replace_original_copy_but_customer_can_withdraw(): void
    {
        ConsentFixtures::configure();
        $customer = CustomerFixtures::account();
        $service = app(CustomerConsentPreferences::class);
        $service->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
        $original = ConsentPolicySnapshot::sole()->getRawOriginal();
        $replacement = ConsentFixtures::policy();
        $replacement['notice'] .= ' Changed text.';
        config(['customer-preferences.email_marketing' => $replacement]);
        $this->assertFalse($service->read($customer['principal'], $customer['user'])['purposes'][0]['canGrant']);
        $this->refuses(fn () => $service->change($customer['principal'], $customer['user'], ConsentFixtures::grant(1)), 503);
        $this->assertSame($original, ConsentPolicySnapshot::sole()->getRawOriginal());
        $this->assertSame('withdrawn', $service->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw(1))['purposes'][0]['status']);
    }

    public function test_separate_accounts_have_independent_revisions_and_cannot_supply_another_owner_or_recipient(): void
    {
        ConsentFixtures::configure();
        $one = CustomerFixtures::account();
        $two = CustomerFixtures::account();
        $service = app(CustomerConsentPreferences::class);
        $service->change($one['principal'], $one['user'], ConsentFixtures::grant());
        $this->assertSame(0, $service->read($two['principal'], $two['user'])['purposes'][0]['version']);
        try {
            $service->change($one['principal'], $two['user'], ConsentFixtures::withdraw(1));
            $this->fail('A foreign actor cannot withdraw another account.');
        } catch (CustomerAccessException) {
            $this->assertSame(1, ConsentEvent::count());
        }
        $forged = ConsentFixtures::withdraw(1) + ['email' => $two['user']->email, 'accountId' => $two['account']->id];
        $this->refuses(fn () => $service->change($one['principal'], $one['user'], $forged), 422);
        $this->assertSame('granted', $service->read($one['principal'], $one['user'])['purposes'][0]['status']);
    }

    public static function badCommands(): array
    {
        return ['missing affirmative' => [['action' => 'grant-consent', 'version' => 0, 'purpose' => 'email_marketing', 'noticeVersion' => 'synthetic-notice-v1', 'noticeHash' => str_repeat('a', 64)]],
            'false affirmative' => [['affirmative' => false]], 'numeric affirmative' => [['affirmative' => 1]],
            'string version' => [['version' => '0']], 'negative version' => [['version' => -1]], 'float version' => [['version' => 0.0]],
            'foreign purpose' => [['purpose' => 'analytics']], 'old notice version' => [['noticeVersion' => 'older-v0']],
            'wrong hash' => [['noticeHash' => str_repeat('b', 64)]], 'extra recipient' => [['email' => 'synthetic@example.test']],
            'unknown action' => [['action' => 'import-consent']], 'extended notice field' => [['noticeText' => 'client invented']]];
    }

    #[DataProvider('badCommands')]
    public function test_untrusted_or_incomplete_consent_commands_refuse_without_any_capture(array $changes): void
    {
        ConsentFixtures::configure();
        $customer = CustomerFixtures::account();
        $command = array_replace(ConsentFixtures::grant(), $changes);
        if (! array_key_exists('affirmative', $changes) && isset($changes['noticeHash']) && $changes['noticeHash'] === str_repeat('a', 64)) {
            unset($command['affirmative']);
        }
        $this->refuses(fn () => app(CustomerConsentPreferences::class)->change($customer['principal'], $customer['user'], $command), 422);
        $this->assertSame(0, ConsentEvent::count());
        $this->assertSame(0, ConsentState::count());
        $this->assertSame(0, ConsentPolicySnapshot::count());
    }

    public function test_recipient_change_does_not_promote_old_capture_to_new_address(): void
    {
        ConsentFixtures::configure();
        $customer = CustomerFixtures::account();
        $service = app(CustomerConsentPreferences::class);
        $service->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
        DB::table('users')->where('id', $customer['user']->id)->update(['email' => 'changed-synthetic@example.test']);
        $this->assertSame('unknown', $service->read($customer['principal'], $customer['user'])['purposes'][0]['status']);
        $this->assertSame('withdrawn', $service->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw(1))['purposes'][0]['status']);
    }

    public static function malformedPolicies(): array
    {
        return ['missing policy' => [null], 'missing review' => [['review_reference' => '']], 'notice too long' => [['notice' => str_repeat('x', 2001)]],
            'invalid UTF8' => [['notice' => "invalid\xff"]], 'Unicode whitespace' => [['notice' => "\u{00a0}"]],
            'format controls' => [['notice' => "bad\u{202e}text"]], 'extended policy' => [['recipient' => 'untrusted@example.test']],
            'overlong version' => [['version' => str_repeat('v', 81)]]];
    }

    #[DataProvider('malformedPolicies')]
    public function test_missing_unreviewed_or_invalid_purpose_policy_cannot_create_opt_in_and_does_not_block_withdrawal(?array $changes): void
    {
        ConsentFixtures::configure();
        $command = ConsentFixtures::grant();
        config(['customer-preferences.email_marketing' => $changes === null ? null : array_replace(ConsentFixtures::policy(), $changes)]);
        $customer = CustomerFixtures::account();
        $service = app(CustomerConsentPreferences::class);
        $unknown = $service->read($customer['principal'], $customer['user']);
        $this->assertFalse($unknown['purposes'][0]['canGrant']);
        $this->assertNull($unknown['purposes'][0]['notice']);
        $this->refuses(fn () => $service->change($customer['principal'], $customer['user'], $command), 422);
        $this->assertSame(0, ConsentEvent::count());
        $this->assertSame('withdrawn', $service->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw())['purposes'][0]['status']);
    }

    public function test_actual_account_listening_and_order_preparation_do_not_infer_marketing_consent(): void
    {
        $customer = CustomerFixtures::account();
        app(ListeningLibrary::class)->change($customer['principal'], $customer['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC independent listening']);
        $order = CustomerFixtures::prepared($customer['user']);
        $this->assertTrue($order->exists);
        $this->assertSame(0, ConsentEvent::count());
        $this->assertSame(0, ConsentState::count());
        $this->assertSame('unknown', app(CustomerConsentPreferences::class)->read($customer['principal'], $customer['user'])['purposes'][0]['status']);
    }

    public function test_runtime_adapter_can_be_replaced_without_changing_domain_production_guards(): void
    {
        ConsentFixtures::configure();
        $customer = CustomerFixtures::account();
        $runtime = new class implements ConsentRuntime
        {
            public bool $enabled = false;

            public function grantsEnabled(): bool
            {
                return $this->enabled;
            }
        };
        $service = new CustomerConsentPreferences($runtime);
        $this->assertFalse($service->read($customer['principal'], $customer['user'])['purposes'][0]['canGrant']);
        $this->refuses(fn () => $service->change($customer['principal'], $customer['user'], ConsentFixtures::grant()), 422);
        $runtime->enabled = true;
        $this->assertSame('granted', $service->change($customer['principal'], $customer['user'], ConsentFixtures::grant())['purposes'][0]['status']);
        $environment = app()->environment();
        app()->instance('env', 'production');
        try {
            $this->assertFalse((new LocalConsentRuntime)->grantsEnabled());
            try {
                $service->read($customer['principal'], $customer['user']);
                $this->fail('Replacing the consent adapter cannot bypass current customer identity authority.');
            } catch (CustomerAccessException) {
                $this->assertSame(1, ConsentEvent::count());
            }
        } finally {
            app()->instance('env', $environment);
        }
    }

    private function refuses(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('A definite refusal was required.');
        } catch (ConsentException $error) {
            $this->assertSame($status, $error->status);
        }
    }
}
