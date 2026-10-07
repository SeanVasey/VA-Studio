<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Listening\ListeningLibrary;
use App\Domain\Customers\Listening\SavedListeningLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\ListeningNotesFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class CustomerListeningNotesTest extends TestCase
{
    use RefreshDatabase;

    private array $customer;

    protected function setUp(): void
    {
        parent::setUp();
        ListeningNotesFixtures::enablePromotion();
        $this->fakePrivateMediaStorage();
        $this->customer = CustomerFixtures::account();
    }

    private function change(string $action, int $version, array $fields = []): array
    {
        return app(ListeningLibrary::class)->change($this->customer['principal'], $this->customer['user'], ['action' => $action, 'version' => $version, ...$fields]);
    }

    private function read(): array
    {
        return app(ListeningLibrary::class)->read($this->customer['principal'], $this->customer['user']);
    }

    private function export(int $version): array
    {
        return app(ListeningLibrary::class)->export($this->customer['principal'], $this->customer['user'], $version);
    }

    private function refused(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Unsafe listening operation accepted.');
        } catch (ListeningException $error) {
            $this->assertSame($status, $error->status);
            $this->assertStringNotContainsString('PRIVATE', $error->getMessage());
        }
    }

    private function v1(array $favorites = [], array $playlists = []): SavedListeningLibrary
    {
        return SavedListeningLibrary::create(['customer_account_id' => $this->customer['account']->id, 'version' => 1,
            'payload' => ['schema' => 1, 'accountId' => $this->customer['account']->id, 'version' => 1, 'favorites' => $favorites, 'playlists' => $playlists]]);
    }

    public function test_actual_lyric_notes_persist_encrypted_and_private_export_contains_exact_own_inputs_only(): void
    {
        $selection = QuoteFixtures::selection();
        $id = (string) $selection['track']->id;
        $this->change('save-track', 0, ['trackId' => $id]);
        $body = "PRIVATE verse one\n\tVerse two, café 🎵\n";
        $note = $this->change('set-track-note', 1, ['trackId' => $id, 'body' => $body]);
        $this->assertSame(2, $note['listeningSchema']);
        $this->assertSame([['trackId' => $id, 'body' => $body]], $note['notes']);
        $raw = SavedListeningLibrary::sole()->getRawOriginal();
        $this->assertStringNotContainsString('PRIVATE', $raw['payload']);
        $this->assertSame(2, SavedListeningLibrary::sole()->payload['schema']);
        $this->assertSame($note, $this->read());
        $this->assertSame($note, $this->change('set-track-note', 2, ['trackId' => $id, 'body' => $body]));
        app(PublishTrack::class)->unpublish($selection['track'], $selection['actor']);
        $read = $this->read();
        $this->assertSame([['trackId' => $id, 'available' => false]], $read['favorites']);
        $this->assertSame($note['notes'], $read['notes']);
        $export = $this->export(2);
        $this->assertSame(['exportSchema' => 1, 'feature' => 'customer-listening-library', 'version' => 2,
            'favorites' => [$id], 'playlists' => [], 'notes' => [['trackId' => $id, 'body' => $body]]], $export);
        foreach (['accountId', 'customer_account_id', 'ownerKey', 'credentialStamp', 'accessVersion', 'artist', 'href', 'preview', 'email', $selection['track']->title] as $private) {
            $this->assertStringNotContainsString($private, json_encode($export, JSON_THROW_ON_ERROR));
        }
        $this->assertSame($raw, SavedListeningLibrary::sole()->getRawOriginal(), 'Export is read only.');
        $updated = $this->change('set-track-note', 2, ['trackId' => $id, 'body' => 'PRIVATE revision on unavailable reference']);
        $this->assertSame(3, $updated['version']);
        $deleted = $this->change('delete-track-note', 3, ['trackId' => $id]);
        $this->assertSame([], $deleted['notes']);
        $this->assertSame($deleted, $this->change('delete-track-note', 4, ['trackId' => $id]));
    }

    public function test_exact_v1_payload_reads_and_exports_without_rewrite_and_promotes_only_effective_mutation(): void
    {
        $id = (string) Str::uuid();
        $row = $this->v1(['999'], [['id' => $id, 'name' => 'PRIVATE V1 name', 'trackIds' => ['998']]]);
        $before = SavedListeningLibrary::sole()->getRawOriginal();
        $read = $this->read();
        $this->assertSame([], $read['notes']);
        $this->assertSame(2, $read['listeningSchema']);
        $this->assertSame([], $this->export(1)['notes']);
        $this->assertSame($read, $this->change('delete-track-note', 1, ['trackId' => '999']));
        $this->assertSame($read, $this->change('rename-playlist', 1, ['playlistId' => $id, 'name' => 'PRIVATE V1 name']));
        $this->assertSame($before, SavedListeningLibrary::sole()->getRawOriginal());
        $note = $this->change('set-track-note', 1, ['trackId' => '998', 'body' => 'PRIVATE V2 lyric']);
        $this->assertSame(2, $note['version']);
        $payload = SavedListeningLibrary::sole()->payload;
        $this->assertSame(['schema', 'accountId', 'version', 'favorites', 'playlists', 'notes'], array_keys($payload));
        $this->assertSame(2, $payload['schema']);
        $this->assertSame(['999'], $payload['favorites']);
        $this->assertSame($row->payload['playlists'], $payload['playlists']);
    }

    public function test_notes_require_existing_owned_references_without_allowing_private_catalog_discovery(): void
    {
        $selection = QuoteFixtures::selection();
        $draft = Track::create(['title' => 'PRIVATE unsaved draft', 'slug' => 'private-unsaved-draft']);
        $other = CustomerFixtures::account();
        app(ListeningLibrary::class)->change($other['principal'], $other['user'], ['action' => 'save-track', 'version' => 0, 'trackId' => (string) $selection['track']->id]);
        foreach ([(string) $selection['track']->id, (string) $draft->id, '99999999'] as $id) {
            foreach (['set-track-note', 'delete-track-note'] as $action) {
                $this->refused(fn () => $this->change($action, 0, ['trackId' => $id, ...($action === 'set-track-note' ? ['body' => 'PRIVATE probe'] : [])]), 404);
            }
        }
        $this->assertSame([], $this->export(0)['favorites']);
        $this->assertSame([], $this->export(0)['notes']);
        $this->assertDatabaseCount('customer_saved_tracks', 1);
        $this->expectException(CustomerAccessException::class);
        app(ListeningLibrary::class)->export($other['principal'], $this->customer['user'], 1);
    }

    public function test_removing_last_retained_reference_prunes_notes_but_shared_reference_preserves_them(): void
    {
        $list = (string) Str::uuid();
        $this->v1(['999'], [['id' => $list, 'name' => 'List', 'trackIds' => ['999']]]);
        $this->change('set-track-note', 1, ['trackId' => '999', 'body' => 'PRIVATE shared note']);
        $removed = $this->change('remove-saved-track', 2, ['trackId' => '999']);
        $this->assertCount(1, $removed['notes']);
        $deleted = $this->change('delete-playlist', 3, ['playlistId' => $list]);
        $this->assertSame([], $deleted['notes']);
        $this->assertSame([], $this->export(4)['notes']);
        $this->assertStringNotContainsString('PRIVATE shared note', json_encode(SavedListeningLibrary::sole()->payload));
    }

    public function test_clear_actually_removes_feature_contents_retains_monotonic_revision_and_blocks_stale_resurrection(): void
    {
        $this->v1(['999'], [['id' => (string) Str::uuid(), 'name' => 'PRIVATE playlist', 'trackIds' => ['998']]]);
        $this->change('set-track-note', 1, ['trackId' => '999', 'body' => 'PRIVATE lyric']);
        $this->refused(fn () => $this->change('clear-library', 1), 409);
        $cleared = $this->change('clear-library', 2);
        $this->assertSame(3, $cleared['version']);
        $this->assertSame([], $cleared['favorites']);
        $this->assertSame([], $cleared['playlists']);
        $this->assertSame([], $cleared['notes']);
        $this->assertDatabaseCount('customer_saved_tracks', 1);
        $this->assertStringNotContainsString('PRIVATE', json_encode(SavedListeningLibrary::sole()->payload));
        $this->refused(fn () => $this->change('set-track-note', 2, ['trackId' => '999', 'body' => 'PRIVATE stale resurrection']), 409);
        $this->refused(fn () => $this->export(2), 409);
        $this->assertSame(['exportSchema' => 1, 'feature' => 'customer-listening-library', 'version' => 3, 'favorites' => [], 'playlists' => [], 'notes' => []], $this->export(3));
        $this->assertSame(4, $this->change('clear-library', 3)['version'], 'Empty clear must still fence an earlier intent.');
    }

    public function test_clear_empty_absent_library_also_advances_and_leaves_empty_owned_row(): void
    {
        $this->assertSame(0, $this->export(0)['version']);
        $this->assertDatabaseCount('customer_saved_tracks', 0);
        $this->assertSame(1, $this->change('clear-library', 0)['version']);
        $this->refused(fn () => $this->change('create-playlist', 0, ['name' => 'PRIVATE old intent']), 409);
        $this->assertDatabaseCount('customer_saved_tracks', 1);
    }

    public static function invalidNotes(): array
    {
        return [[''], [" \n\t "], [str_repeat('a', 2001)], [str_repeat('é', 2001)], [str_repeat('🎵', 1001)],
            ["PRIVATE\r\nlyric"], ["PRIVATE\0lyric"], ["PRIVATE\u{202E}lyric"], ["PRIVATE\u{0085}lyric"], ["\u{00A0}\u{2028}"], [123], [null], ["\xFF"]];
    }

    #[DataProvider('invalidNotes')]
    public function test_note_character_byte_utf8_and_control_bounds_are_strict(mixed $body): void
    {
        $this->v1(['999']);
        $before = SavedListeningLibrary::sole()->getRawOriginal();
        $this->refused(fn () => $this->change('set-track-note', 1, ['trackId' => '999', 'body' => $body]), 422);
        $this->assertSame($before, SavedListeningLibrary::sole()->getRawOriginal());
    }

    public function test_maximum_utf8_and_count_bounds_are_accepted_without_exceeding_owned_note_capacity(): void
    {
        $ids = array_map('strval', range(1000, 1025));
        $this->v1($ids);
        $body = str_repeat('🎵', 1000);
        $this->change('set-track-note', 1, ['trackId' => $ids[0], 'body' => $body]);
        $this->assertSame(4000, strlen($this->export(2)['notes'][0]['body']));
        $state = SavedListeningLibrary::sole()->payload;
        $state['version'] = 3;
        $state['notes'] = array_map(fn ($id) => ['trackId' => $id, 'body' => 'PRIVATE note'], array_slice($ids, 0, 25));
        SavedListeningLibrary::sole()->fill(['version' => 3, 'payload' => $state])->save();
        $before = SavedListeningLibrary::sole()->getRawOriginal();
        $this->refused(fn () => $this->change('set-track-note', 3, ['trackId' => $ids[25], 'body' => 'PRIVATE 26th']), 422);
        $this->assertSame($before, SavedListeningLibrary::sole()->getRawOriginal());
        $this->assertCount(25, $this->read()['notes']);
        $this->assertCount(25, $this->export(3)['notes']);
    }

    public static function corruptShapes(): array
    {
        return [['v1-with-notes'], ['v2-missing-notes'], ['v2-unowned-note'], ['v2-duplicate-note'], ['v2-extra-note-field'], ['v2-invalid-body']];
    }

    #[DataProvider('corruptShapes')]
    public function test_malformed_v1_v2_payloads_are_refused_without_replacing_private_contents(string $shape): void
    {
        $row = $this->v1(['999']);
        $state = $row->payload;
        $state['version'] = 2;
        if ($shape === 'v1-with-notes') {
            $state['notes'] = [];
        } else {
            $state['schema'] = 2;
            if ($shape !== 'v2-missing-notes') {
                $note = ['trackId' => $shape === 'v2-unowned-note' ? '998' : '999', 'body' => $shape === 'v2-invalid-body' ? "PRIVATE\0bad" : 'PRIVATE retained'];
                $state['notes'] = $shape === 'v2-duplicate-note' ? [$note, $note] : [$note];
                if ($shape === 'v2-extra-note-field') {
                    $state['notes'][0]['private_sentinel'] = true;
                }
            }
        }
        $row->fill(['version' => 2, 'payload' => $state])->save();
        $before = SavedListeningLibrary::sole()->getRawOriginal();
        foreach ([fn () => $this->read(), fn () => $this->export(2), fn () => $this->change('clear-library', 2)] as $operation) {
            $this->refused($operation, 503);
            $this->assertSame($before, SavedListeningLibrary::sole()->getRawOriginal());
        }
    }
}
