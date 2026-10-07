<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\Listening\SavedListeningLibrary;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class CustomerListeningExportHttpTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function login(array $fixture): void
    {
        \Tests\Support\ListeningNotesFixtures::enablePromotion();
        $principal = app(CustomerAccess::class)->principal($fixture['user']);
        $this->actingAs($fixture['user'], 'customer')->withSession(['_customer_access' => [
            'account_id' => $principal->accountId, 'access_version' => $principal->accessVersion,
            'credential_stamp' => $principal->credentialStamp,
        ]]);
    }

    private function raw(string $body, string $path = '/account/listening-library/export')
    {
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    }

    public function test_actual_note_export_clear_flow_accepts_the_full_escaped_unicode_bound_and_keeps_export_read_only(): void
    {
        $fixture = CustomerFixtures::account();
        $this->login($fixture);
        SavedListeningLibrary::create(['customer_account_id' => $fixture['account']->id, 'version' => 1,
            'payload' => ['schema' => 1, 'accountId' => $fixture['account']->id, 'version' => 1,
                'favorites' => ['999'], 'playlists' => []]]);
        $note = str_repeat('é', 2000);
        $raw = json_encode(['action' => 'set-track-note', 'version' => 1, 'trackId' => '999', 'body' => $note], JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(4096, strlen($raw));
        $this->assertLessThanOrEqual(16384, strlen($raw));
        $this->raw($raw, '/account/listening-library')->assertOk()->assertJsonPath('library.version', 2)
            ->assertJsonPath('library.notes.0.body', $note)->assertJsonPath('library.favorites.0.available', false);
        $before = SavedListeningLibrary::sole()->getRawOriginal();
        $this->assertStringNotContainsString($note, $before['payload']);
        $export = $this->postJson('/account/listening-library/export', ['version' => 2])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->json('export');
        $this->assertSame(['exportSchema' => 1, 'feature' => 'customer-listening-library', 'version' => 2,
            'favorites' => ['999'], 'playlists' => [], 'notes' => [['trackId' => '999', 'body' => $note]]], $export);
        $this->assertSame($before, SavedListeningLibrary::sole()->getRawOriginal());
        $this->postJson('/account/listening-library', ['action' => 'clear-library', 'version' => 2])->assertOk()
            ->assertJsonPath('library.version', 3)->assertJsonCount(0, 'library.notes')->assertJsonCount(0, 'library.favorites');
        $this->postJson('/account/listening-library/export', ['version' => 2])->assertStatus(409)->assertDontSee($note, false);
        $this->postJson('/account/listening-library', ['action' => 'set-track-note', 'version' => 2,
            'trackId' => '999', 'body' => 'PRIVATE stale revival'])->assertStatus(409);
        $this->postJson('/account/listening-library/export', ['version' => 3])->assertOk()->assertExactJson(['export' => [
            'exportSchema' => 1, 'feature' => 'customer-listening-library', 'version' => 3,
            'favorites' => [], 'playlists' => [], 'notes' => [],
        ]]);
        $this->assertDatabaseCount('customer_saved_tracks', 1);
    }

    public static function malformedExports(): array
    {
        return [
            ['{}'], ['[]'], ['null'], ['{"version":"0"}'], ['{"version":0.0}'], ['{"version":true}'],
            ['{"version":-1}'], ['{"version":2147483647}'], ['{"version":0,"accountId":"PRIVATE"}'],
            ['{"version":0,"version":0}'], ['{"version":0,"\\u0076ersion":0}'],
            ['{"version":{"version":0}}'], ['{"version":0,'],
        ];
    }

    #[DataProvider('malformedExports')]
    public function test_export_refuses_ambiguous_or_client_authority_fields_without_writes_or_private_echo(string $raw): void
    {
        $fixture = CustomerFixtures::account();
        $this->login($fixture);
        $this->raw($raw)->assertStatus(422)->assertJsonPath('code', 'CUSTOMER_LISTENING_UNAVAILABLE')
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('PRIVATE', false)
            ->assertDontSee($fixture['user']->email, false)->assertDontSee($fixture['account']->owner_key, false);
        $this->assertDatabaseCount('customer_saved_tracks', 0);
    }

    public function test_export_requires_current_owner_and_never_reuses_a_previous_account_library(): void
    {
        $fixture = CustomerFixtures::account();
        $this->login($fixture);
        $this->postJson('/account/listening-library', ['action' => 'create-playlist', 'version' => 0,
            'name' => 'PRIVATE original owner'])->assertOk();
        $this->login(CustomerFixtures::account());
        $this->postJson('/account/listening-library/export', ['version' => 0])->assertOk()
            ->assertJsonCount(0, 'export.playlists')->assertDontSee('PRIVATE original owner', false);
        $this->login($fixture);
        DB::table('users')->where('id', $fixture['user']->id)->update(['password' => 'changed-private-password']);
        $this->postJson('/account/listening-library/export', ['version' => 1])->assertForbidden()
            ->assertDontSee('PRIVATE original owner', false)->assertDontSee($fixture['user']->email, false);
    }

    public function test_export_has_real_csrf_and_all_body_limits_are_scoped_to_the_listening_endpoints(): void
    {
        $fixture = CustomerFixtures::account();
        $this->login($fixture);
        $this->raw(str_repeat('x', 16385))->assertStatus(413);
        $this->raw(str_repeat('x', 4097), '/account/identity/request')->assertStatus(413);
        $this->get('/account/listening-library/export?PRIVATE=probe')->assertStatus(422)
            ->assertDontSee('PRIVATE', false)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->postJson('/account/listening-library/export', ['version' => 0])->assertStatus(419)
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('code', 'CUSTOMER_LISTENING_UNAVAILABLE');
        $this->assertDatabaseCount('customer_saved_tracks', 0);
    }
}
