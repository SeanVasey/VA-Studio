<?php

namespace Tests\Feature;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Listening\ListeningLibrary;
use App\Domain\Customers\Listening\SavedListeningLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class CustomerListeningLibraryTest extends TestCase
{
    use RefreshDatabase;

    private array $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->customer = CustomerFixtures::account();
    }

    public function test_favorites_and_ordered_named_playlists_persist_with_encrypted_private_names(): void
    {
        $a = QuoteFixtures::selection();
        $b = QuoteFixtures::selection();
        $empty = $this->read();
        $this->assertSame(0, $empty['version']);
        $this->assertDatabaseCount('customer_saved_tracks', 0);
        $saved = $this->change('save-track', 0, ['trackId' => (string) $a['track']->id]);
        $this->assertSame(1, $saved['version']);
        $created = $this->change('create-playlist', 1, ['name' => 'Private writing session']);
        $id = $created['playlists'][0]['id'];
        $this->change('add-playlist-track', 2, ['playlistId' => $id, 'trackId' => (string) $a['track']->id]);
        $added = $this->change('add-playlist-track', 3, ['playlistId' => $id, 'trackId' => (string) $b['track']->id]);
        $this->assertSame([(string) $a['track']->id, (string) $b['track']->id], array_column($added['playlists'][0]['tracks'], 'trackId'));
        $reordered = $this->change('reorder-playlist', 4, ['playlistId' => $id, 'trackIds' => [(string) $b['track']->id, (string) $a['track']->id]]);
        $this->assertSame([(string) $b['track']->id, (string) $a['track']->id], array_column($reordered['playlists'][0]['tracks'], 'trackId'));
        $this->change('rename-playlist', 5, ['playlistId' => $id, 'name' => 'Private revised name']);
        app(SaveTrackMetadata::class)->handle($a['track'], ['title' => 'Fresh public title', 'artist' => $a['track']->artist,
            'metadata_version' => $a['track']->fresh()->metadata_version], $a['actor']);
        $read = app(ListeningLibrary::class)->read(app(CustomerAccess::class)->principal($this->customer['user']), $this->customer['user']);
        $this->assertSame('Private revised name', $read['playlists'][0]['name']);
        $this->assertSame('Fresh public title', $read['favorites'][0]['track']['title']);
        $this->assertSame(6, $read['version']);
        $raw = DB::table('customer_saved_tracks')->sole();
        $this->assertStringNotContainsString('Private revised name', $raw->payload);
        $this->assertStringNotContainsString('favorites', $raw->payload);
        $this->assertSame(['title', 'artist', 'href'], array_keys($read['favorites'][0]['track']));
        foreach (['accountId', 'customer_account_id', 'ownerKey', 'accessVersion', 'credentialStamp', 'email', 'previewUrl'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($read, JSON_THROW_ON_ERROR));
        }
        $this->change('remove-playlist-track', 6, ['playlistId' => $id, 'trackId' => (string) $a['track']->id]);
        $this->change('remove-saved-track', 7, ['trackId' => (string) $a['track']->id]);
        $final = $this->change('delete-playlist', 8, ['playlistId' => $id]);
        $this->assertSame([], $final['favorites']);
        $this->assertSame([], $final['playlists']);
        $this->assertSame(9, $final['version']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_duplicates_are_noops_and_stale_mutations_cannot_overwrite_fresh_state(): void
    {
        $fixture = QuoteFixtures::selection();
        $track = ['trackId' => (string) $fixture['track']->id];
        $first = $this->change('save-track', 0, $track);
        $this->assertSame($first, $this->change('save-track', 1, $track));
        $this->refused(fn () => $this->change('remove-saved-track', 0, $track), 409);
        $created = $this->change('create-playlist', 1, ['name' => 'Playlist']);
        $item = ['playlistId' => $created['playlists'][0]['id']] + $track;
        $added = $this->change('add-playlist-track', 2, $item);
        $this->assertSame($added, $this->change('add-playlist-track', 3, $item));
        $this->refused(fn () => $this->change('reorder-playlist', 3, ['playlistId' => $item['playlistId'], 'trackIds' => [$track['trackId'], $track['trackId']]]), 422);
        $this->refused(fn () => $this->change('reorder-playlist', 3, ['playlistId' => $item['playlistId'], 'trackIds' => []]), 422);
        $this->assertSame($added, $this->read());
    }

    public function test_cross_account_playlist_ids_never_reveal_or_modify_foreign_state(): void
    {
        $created = $this->change('create-playlist', 0, ['name' => 'FOREIGN PRIVATE NAME']);
        $other = CustomerFixtures::account();
        $service = app(ListeningLibrary::class);
        $this->assertSame([], $service->read($other['principal'], $other['user'])['playlists']);
        foreach ([$created['playlists'][0]['id'], (string) Str::uuid()] as $id) {
            $this->refused(fn () => $service->change($other['principal'], $other['user'], ['action' => 'delete-playlist', 'version' => 0, 'playlistId' => $id]), 404);
        }
        $this->assertSame($created, $this->read());
        $this->assertDatabaseCount('customer_saved_tracks', 1);
        $this->expectException(CustomerAccessException::class);
        $service->read($this->customer['principal'], $other['user']);
    }

    #[DataProvider('accessChanges')]
    public function test_read_and_change_recheck_disabled_revoked_unverified_admin_and_credential_changed_access(string $state): void
    {
        $this->change('create-playlist', 0, ['name' => 'Retained']);
        $before = DB::table('customer_saved_tracks')->sole()->payload;
        match ($state) {
            'disabled' => config(['customer.test_accounts_enabled' => false]),
            'production' => $this->app->instance('env', 'production'),
            'revoked' => CustomerFixtures::withdraw($this->customer),
            'unverified' => $this->customer['user']->forceFill(['email_verified_at' => null])->save(),
            'admin' => $this->customer['user']->forceFill(['is_admin' => true])->save(),
            'credentials' => $this->customer['user']->forceFill(['password' => 'Synthetic changed credential'])->save(),
        };
        foreach ([fn () => $this->read(), fn () => $this->change('create-playlist', 1, ['name' => 'Forbidden'])] as $command) {
            try {
                $command();
                $this->fail('Stale access was accepted.');
            } catch (CustomerAccessException) {
                $this->assertSame($before, DB::table('customer_saved_tracks')->sole()->payload);
            }
        }
    }

    public static function accessChanges(): array
    {
        return array_map(fn ($state) => [$state], ['disabled', 'production', 'revoked', 'unverified', 'admin', 'credentials']);
    }

    #[DataProvider('unavailableStates')]
    public function test_fresh_availability_hides_withdrawn_or_unready_metadata_and_keeps_removal_usable(string $state): void
    {
        $fixture = QuoteFixtures::selection();
        $trackId = (string) $fixture['track']->id;
        $this->change('save-track', 0, ['trackId' => $trackId]);
        if ($state === 'withdrawn') {
            app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        } else {
            app(DeactivateOffer::class)->handle($fixture['offer'], $fixture['actor']);
        }
        $read = $this->read();
        $this->assertSame([['trackId' => $trackId, 'available' => false]], $read['favorites']);
        $this->assertStringNotContainsString($fixture['track']->title, json_encode($read));
        $this->assertStringNotContainsString('/tracks/', json_encode($read));
        $this->refused(fn () => $this->change('save-track', 1, ['trackId' => $trackId]), 404);
        $this->assertSame([], $this->change('remove-saved-track', 1, ['trackId' => $trackId])['favorites']);
    }

    public static function unavailableStates(): array
    {
        return [['withdrawn'], ['inactive_offer']];
    }

    public function test_unknown_draft_and_falsely_published_tracks_are_not_savable(): void
    {
        $draft = Track::create(['title' => 'PRIVATE DRAFT', 'slug' => 'private-draft']);
        $falsePublic = DB::table('tracks')->insertGetId(['title' => 'PRIVATE UNREADY', 'slug' => 'private-unready', 'published_slug' => 'private-unready', 'status' => 'published', 'published_at' => now()]);
        foreach ([(string) $draft->id, (string) $falsePublic, '999999999'] as $id) {
            $this->refused(fn () => $this->change('save-track', 0, ['trackId' => $id]), 404);
        }
        $this->assertDatabaseCount('customer_saved_tracks', 0);
    }

    public function test_favorite_playlist_and_per_playlist_track_bounds_fail_without_changing_retained_state(): void
    {
        $fixture = QuoteFixtures::selection();
        $id = (string) Str::uuid();
        $state = ['schema' => 1, 'accountId' => $this->customer['account']->id, 'version' => 1, 'favorites' => array_map('strval', range(1000, 1049)), 'playlists' => []];
        for ($i = 0; $i < 10; $i++) {
            $state['playlists'][] = ['id' => $i === 0 ? $id : (string) Str::uuid(), 'name' => 'List '.$i, 'trackIds' => array_map('strval', range(2000 + $i * 25, 2024 + $i * 25))];
        }
        SavedListeningLibrary::create(['customer_account_id' => $this->customer['account']->id, 'version' => 1, 'payload' => $state]);
        $before = DB::table('customer_saved_tracks')->sole()->payload;
        foreach ([['save-track', ['trackId' => (string) $fixture['track']->id]], ['create-playlist', ['name' => 'Eleventh']], ['add-playlist-track', ['playlistId' => $id, 'trackId' => (string) $fixture['track']->id]]] as [$action, $fields]) {
            $this->refused(fn () => $this->change($action, 1, $fields), 422);
            $this->assertSame($before, DB::table('customer_saved_tracks')->sole()->payload);
        }
        DB::enableQueryLog();
        $read = $this->read();
        $queries = array_values(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'from "tracks"')));
        DB::disableQueryLog();
        $this->assertCount(50, $read['favorites']);
        $this->assertCount(10, $read['playlists']);
        $this->assertCount(30, $queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('limit 10', $query['query']);
            $this->assertStringContainsString('"id" in', $query['query']);
            $this->assertStringNotContainsString('offset', $query['query']);
        }
    }

    #[DataProvider('invalidCommands')]
    public function test_invalid_commands_are_refused_without_creating_state(array $command): void
    {
        $this->refused(fn () => app(ListeningLibrary::class)->change($this->customer['principal'], $this->customer['user'], $command), 422);
        $this->assertDatabaseCount('customer_saved_tracks', 0);
    }

    public static function invalidCommands(): array
    {
        return [
            [['action' => 'create-playlist', 'version' => '0', 'name' => 'List']],
            [['action' => 'create-playlist', 'version' => 0, 'name' => ' List']],
            [['action' => 'create-playlist', 'version' => 0, 'name' => str_repeat('é', 81)]],
            [['action' => 'create-playlist', 'version' => 0, 'name' => "Private\nname"]],
            [['action' => 'create-playlist', 'version' => 0, 'name' => 'List', 'customer_account_id' => 2]],
            [['action' => 'save-track', 'version' => 0, 'trackId' => '01']],
            [['action' => 'save-track', 'version' => 0, 'trackId' => 1]],
            [['action' => 'save-track', 'version' => 0, 'trackId' => '1e2']],
            [['action' => 'unknown', 'version' => 0]],
        ];
    }

    public function test_corrupt_encrypted_state_fails_closed_and_is_never_replaced_by_empty_lists(): void
    {
        $this->change('create-playlist', 0, ['name' => 'Retained']);
        DB::table('customer_saved_tracks')->update(['payload' => 'PRIVATE CORRUPT CIPHERTEXT']);
        $this->refused(fn () => $this->read(), 503);
        $this->refused(fn () => $this->change('create-playlist', 1, ['name' => 'New']), 503);
        $this->assertSame('PRIVATE CORRUPT CIPHERTEXT', DB::table('customer_saved_tracks')->sole()->payload);
    }

    public function test_encrypted_state_is_bound_to_its_account_and_database_revision(): void
    {
        $this->change('create-playlist', 0, ['name' => 'PRIVATE retained name']);
        $other = CustomerFixtures::account();
        app(ListeningLibrary::class)->change($other['principal'], $other['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'Other list']);
        $ciphertext = DB::table('customer_saved_tracks')->where('customer_account_id', $this->customer['account']->id)->value('payload');
        DB::table('customer_saved_tracks')->where('customer_account_id', $other['account']->id)->update(['payload' => $ciphertext]);
        $this->refused(fn () => app(ListeningLibrary::class)->read($other['principal'], $other['user']), 503);
        DB::table('customer_saved_tracks')->where('customer_account_id', $this->customer['account']->id)->update(['version' => 2]);
        $this->refused(fn () => $this->read(), 503);
    }

    public function test_access_withdrawn_by_a_projection_callback_refuses_the_projection_and_rolls_back_the_save(): void
    {
        $fixture = QuoteFixtures::selection();
        $withdrew = false;
        DB::listen(function ($query) use (&$withdrew): void {
            if (! $withdrew && str_contains($query->sql, 'from "tracks"')) {
                $withdrew = true;
                CustomerFixtures::withdraw($this->customer);
            }
        });
        try {
            $this->change('save-track', 0, ['trackId' => (string) $fixture['track']->id]);
            $this->fail('Projection released after withdrawal.');
        } catch (CustomerAccessException) {
            $this->assertTrue($withdrew);
            $this->assertDatabaseCount('customer_saved_tracks', 0);
        }
    }

    private function read(): array
    {
        return app(ListeningLibrary::class)->read($this->customer['principal'], $this->customer['user']);
    }

    private function change(string $action, int $version, array $fields): array
    {
        return app(ListeningLibrary::class)->change($this->customer['principal'], $this->customer['user'], ['action' => $action, 'version' => $version] + $fields);
    }

    private function refused(callable $command, int $status): void
    {
        try {
            $command();
            $this->fail('Unsafe listening command was accepted.');
        } catch (ListeningException $error) {
            $this->assertSame($status, $error->status);
            $this->assertStringNotContainsString('PRIVATE', $error->getMessage());
        }
    }
}
