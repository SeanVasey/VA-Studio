<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccess;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class CustomerListeningLibraryHttpTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function login(array $fixture): void
    {
        $principal = app(CustomerAccess::class)->principal($fixture['user']);
        $this->actingAs($fixture['user'], 'customer')->withSession(['_customer_access' => [
            'account_id' => $principal->accountId, 'access_version' => $principal->accessVersion,
            'credential_stamp' => $principal->credentialStamp,
        ]]);
    }

    public function test_real_customer_account_roundtrip_keeps_playlists_private_and_uses_current_version(): void
    {
        $fixture = CustomerFixtures::account();
        $this->login($fixture);
        $initial = $this->get('/account/listening-library')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertJsonCount(0, 'library.playlists')->json('library');
        $created = $this->postJson('/account/listening-library', ['action' => 'create-playlist',
            'version' => $initial['version'], 'name' => 'Private listening queue'])->assertOk()
            ->assertJsonPath('library.playlists.0.name', 'Private listening queue')->json('library');
        $this->assertGreaterThan($initial['version'], $created['version']);
        $this->postJson('/account/listening-library', ['action' => 'create-playlist',
            'version' => $initial['version'], 'name' => 'Lost-response retry'])->assertStatus(409);
        $this->get('/account/listening-library')->assertOk()->assertJsonCount(1, 'library.playlists');
        $this->login(CustomerFixtures::account());
        $this->get('/account/listening-library')->assertOk()->assertJsonCount(0, 'library.playlists')
            ->assertDontSee('Private listening queue', false)->assertDontSee($fixture['account']->owner_key, false);
    }

    public function test_guest_staff_and_withdrawn_customer_have_no_listening_access(): void
    {
        CustomerFixtures::configure();
        $fixture = CustomerFixtures::account();
        $this->get('/account/listening-library')->assertForbidden();
        $this->actingAs($fixture['user'], 'web')->get('/account/listening-library')->assertForbidden();
        $this->login($fixture);
        CustomerFixtures::withdraw($fixture);
        $this->postJson('/account/listening-library', ['action' => 'create-playlist', 'version' => 0,
            'name' => 'Refused private name'])->assertForbidden()->assertDontSee('Refused private name', false);
    }

    public static function boundaries(): array
    {
        return [
            ['query'], ['get-body'], ['range'], ['origin'], ['duplicate'], ['escaped-duplicate'],
            ['oversized'], ['wrong-type'], ['credential-change'], ['production'], ['csrf'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_privacy_request_and_session_boundaries_refuse_without_customer_state(string $case): void
    {
        $fixture = CustomerFixtures::account();
        $this->login($fixture);
        $body = ['action' => 'create-playlist', 'version' => 0, 'name' => 'PRIVATE-SENTINEL'];
        $response = match ($case) {
            'query' => $this->get('/account/listening-library?account_id='.$fixture['account']->id),
            'get-body' => $this->call('GET', '/account/listening-library', [], [], [], [], '{}'),
            'range' => $this->get('/account/listening-library', ['Range' => 'bytes=0-1']),
            'origin' => $this->postJson('/account/listening-library', $body, ['Origin' => 'https://foreign.invalid']),
            'duplicate' => $this->raw('{"action":"create-playlist","action":"delete-playlist","version":0,"name":"PRIVATE-SENTINEL"}'),
            'escaped-duplicate' => $this->raw('{"action":"create-playlist","\\u0061ction":"delete-playlist","version":0,"name":"PRIVATE-SENTINEL"}'),
            'oversized' => $this->raw(str_repeat('x', 4097)),
            'wrong-type' => $this->post('/account/listening-library', $body),
            'credential-change' => $this->afterCredentialChange($fixture, $body),
            'production' => $this->inProduction($body),
            'csrf' => $this->withoutCsrf($body),
        };
        $expected = match ($case) {
            'origin', 'credential-change', 'production' => 403,
            'oversized' => 413, 'wrong-type' => 415, 'csrf' => 419, default => 422,
        };
        $response->assertStatus($expected)->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDontSee('PRIVATE-SENTINEL', false)
            ->assertDontSee($fixture['user']->email, false)->assertDontSee($fixture['account']->owner_key, false);
        $this->app->detectEnvironment(fn () => 'testing');
    }

    private function raw(string $body)
    {
        return $this->call('POST', '/account/listening-library', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    }

    private function afterCredentialChange(array $fixture, array $body)
    {
        DB::table('users')->where('id', $fixture['user']->id)->update(['password' => 'changed-private-password']);

        return $this->postJson('/account/listening-library', $body);
    }

    private function inProduction(array $body)
    {
        $this->app->detectEnvironment(fn () => 'production');

        return $this->postJson('/account/listening-library', $body);
    }

    private function withoutCsrf(array $body)
    {
        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        return $this->postJson('/account/listening-library', $body);
    }
}
