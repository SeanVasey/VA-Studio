<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

final class CustomerCommunicationPreferencesHttpTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const PATH = '/account/communication-preferences';

    private function login(array $fixture): void
    {
        $this->postJson('/account/sign-in', ['email' => $fixture['user']->email, 'password' => CustomerFixtures::PASSWORD])->assertOk();
    }

    public function test_real_signed_in_choice_keeps_unknown_distinct_and_fences_stale_grants_after_withdrawal(): void
    {
        $fixture = CustomerFixtures::account();
        ConsentFixtures::configure();
        $this->login($fixture);
        $this->get(self::PATH)->assertOk()->assertJsonPath('preferences.purposes.0.status', 'unknown')
            ->assertJsonPath('preferences.purposes.0.version', 0)->assertJsonPath('preferences.purposes.0.canGrant', true)
            ->assertDontSee($fixture['user']->email, false)->assertDontSee($fixture['account']->owner_key, false);
        $this->postJson(self::PATH, ConsentFixtures::grant())->assertOk()->assertJsonPath('preferences.purposes.0.status', 'granted')
            ->assertJsonPath('preferences.purposes.0.version', 1);
        config(['customer-preferences.test_grants_enabled' => false, 'customer-preferences.email_marketing' => null]);
        $this->postJson(self::PATH, ConsentFixtures::withdraw(1))->assertOk()->assertJsonPath('preferences.purposes.0.status', 'withdrawn')
            ->assertJsonPath('preferences.purposes.0.version', 2)->assertJsonPath('preferences.purposes.0.canGrant', false);
        ConsentFixtures::configure();
        $this->postJson(self::PATH, ConsentFixtures::grant(1))->assertStatus(409)->assertJsonPath('code', 'CUSTOMER_PREFERENCES_UNAVAILABLE');
        $this->assertSame(2, DB::table('customer_consent_events')->count());
        $this->assertSame(2, (int) DB::table('customer_consent_states')->value('revision'));
        $this->postJson(self::PATH, ConsentFixtures::withdraw(2))->assertOk()->assertJsonPath('preferences.purposes.0.version', 3);
        $other = CustomerFixtures::account();
        $this->login($other);
        $this->get(self::PATH)->assertOk()->assertJsonPath('preferences.purposes.0.status', 'unknown')->assertJsonPath('preferences.purposes.0.version', 0);
    }

    public function test_current_password_withdrawal_refuses_even_with_a_cached_authenticated_guard_and_retains_consent_history(): void
    {
        $fixture = CustomerFixtures::account();
        ConsentFixtures::configure();
        $this->login($fixture);
        $this->postJson(self::PATH, ConsentFixtures::grant())->assertOk();
        $before = DB::table('customer_consent_events')->get()->all();
        DB::table('users')->where('id', $fixture['user']->id)->update(['password' => 'SYNTHETIC withdrawn credential']);
        $this->get(self::PATH)->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson(self::PATH, ConsentFixtures::withdraw(1))->assertForbidden()->assertDontSee($fixture['user']->email, false);
        $this->assertEquals($before, DB::table('customer_consent_events')->get()->all());
    }

    public static function malformed(): array
    {
        return [
            ['{"action":"withdraw-consent","action":"grant-consent","version":0,"purpose":"email_marketing"}', '', [], 422],
            ['{"action":"withdraw-consent","\\u0061ction":"withdraw-consent","version":0,"purpose":"email_marketing"}', '', [], 422],
            ['{"action":"withdraw-consent","version":0,"purpose":"email_marketing","recipient":"PRIVATE-RECIPIENT"}', '', [], 422],
            ['{"action":"withdraw-consent","version":"0","purpose":"email_marketing"}', '', [], 422],
            ['{"action":"withdraw-consent","version":false,"purpose":"email_marketing"}', '', [], 422],
            ['{"action":"withdraw-consent","version":0,"purpose":{}}', '', [], 422],
            ['[]', '', [], 422],
            ['{}', '', [], 422],
            [str_repeat('x', 4097), '', [], 413],
            ['{}', '?recipient=PRIVATE-RECIPIENT', [], 422],
            ['{}', '', ['Origin' => 'https://foreign.invalid'], 403],
            ['{}', '', ['Range' => 'bytes=0-1'], 422],
            ['{}', '', ['X-HTTP-Method-Override' => 'DELETE'], 422],
        ];
    }

    #[DataProvider('malformed')]
    public function test_closed_private_http_input_cannot_create_or_echo_consent(string $raw, string $suffix, array $headers, int $status): void
    {
        $fixture = CustomerFixtures::account();
        ConsentFixtures::configure();
        $this->login($fixture);
        $this->call('POST', self::PATH.$suffix, [], [], [], ['CONTENT_TYPE' => 'application/json', ...$this->transformHeadersToServerVars($headers)], $raw)
            ->assertStatus($status)->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertDontSee('PRIVATE-RECIPIENT', false)->assertDontSee($fixture['user']->email, false)->assertDontSee($fixture['account']->owner_key, false);
        $this->assertSame(0, DB::table('customer_consent_events')->count());
    }

    public function test_actual_web_csrf_and_get_body_errors_remain_private_and_write_nothing(): void
    {
        $fixture = CustomerFixtures::account();
        $this->login($fixture);
        $this->call('GET', self::PATH, [], [], [], [], '{}')->assertStatus(422)->assertJsonPath('code', 'CUSTOMER_PREFERENCES_UNAVAILABLE');
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->postJson(self::PATH, ConsentFixtures::withdraw())->assertStatus(419)->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('code', 'CUSTOMER_PREFERENCES_UNAVAILABLE')->assertDontSee($fixture['user']->email, false);
        $this->assertSame(0, DB::table('customer_consent_events')->count());
    }
}
