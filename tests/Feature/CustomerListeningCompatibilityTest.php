<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Listening\ListeningLibrary;
use App\Domain\Customers\Listening\SavedListeningLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\PreviousListeningV1;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

require_once __DIR__.'/../Support/PreviousListeningV1.php';

class CustomerListeningCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private array $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->customer = CustomerFixtures::account();
    }

    private function enable(): void
    {
        config(['customer-listening.v2_promotion_enabled' => true,
            'customer-listening.v2_rollout_review_reference' => 'SYNTHETIC stopped-upgrade test fixture']);
    }

    private function change(string $action, int $version, array $fields = []): array
    {
        return app(ListeningLibrary::class)->change($this->customer['principal'], $this->customer['user'], ['action' => $action, 'version' => $version, ...$fields]);
    }

    private function previous(): array
    {
        return PreviousListeningV1::reader()->read($this->customer['principal'], $this->customer['user']);
    }

    private function v1(array $favorites = ['999'], array $playlists = []): void
    {
        SavedListeningLibrary::create(['customer_account_id' => $this->customer['account']->id, 'version' => 1,
            'payload' => ['schema' => 1, 'accountId' => $this->customer['account']->id, 'version' => 1, 'favorites' => $favorites, 'playlists' => $playlists]]);
    }

    public static function ordinary(): array
    {
        $cases = [];
        foreach (['save-track', 'remove-saved-track', 'create-playlist', 'rename-playlist', 'delete-playlist', 'add-playlist-track', 'remove-playlist-track', 'reorder-playlist', 'clear-library'] as $action) {
            foreach ([false, true] as $enabled) {
                $cases[$action.($enabled ? ' safe promotion configured' : ' default-off')] = [$action, $enabled];
            }
        }

        return $cases;
    }

    #[DataProvider('ordinary')]
    public function test_all_effective_ordinary_v1_writes_remain_readable_by_actual_prior_reader(string $action, bool $enabled): void
    {
        if ($enabled) {
            $this->enable();
        }
        $id = (string) QuoteFixtures::selection()['track']->id;
        $playlist = (string) Str::uuid();
        $this->v1(['999'], [['id' => $playlist, 'name' => 'PRIVATE retained V1 list', 'trackIds' => ['999', '998']]]);
        $this->assertSame(1, $this->previous()['listeningSchema']);
        $fields = match ($action) {
            'save-track' => ['trackId' => $id], 'remove-saved-track' => ['trackId' => '999'],
            'create-playlist' => ['name' => 'PRIVATE next V1 playlist'], 'rename-playlist' => ['playlistId' => $playlist, 'name' => 'PRIVATE renamed V1 list'],
            'delete-playlist' => ['playlistId' => $playlist], 'add-playlist-track' => ['playlistId' => $playlist, 'trackId' => $id],
            'remove-playlist-track' => ['playlistId' => $playlist, 'trackId' => '999'], 'reorder-playlist' => ['playlistId' => $playlist, 'trackIds' => ['998', '999']],
            'clear-library' => [],
        };
        $result = $this->change($action, 1, $fields);
        try {
            $previous = $this->previous();
        } catch (ListeningException $error) {
            $this->fail('The actual prior reader rejected an ordinary V1 write with status '.$error->status.'.');
        }
        $payload = SavedListeningLibrary::sole()->payload;
        $this->assertSame(1, $payload['schema']);
        $this->assertSame(['schema', 'accountId', 'version', 'favorites', 'playlists'], array_keys($payload));
        $this->assertSame(2, $result['version']);
        $this->assertSame(2, $previous['version']);
        $this->assertSame($result['favorites'], $previous['favorites']);
        $this->assertSame($result['playlists'], $previous['playlists']);
        if (! $enabled) {
            $this->assertSame($result, $previous, 'Disabled promotion must project the actual original closed V1 API.');
        }
    }

    public function test_new_rows_and_empty_clear_stay_v1_and_stale_old_writer_cannot_resurrect_contents(): void
    {
        $first = $this->change('create-playlist', 0, ['name' => 'PRIVATE new V1 list']);
        $this->assertSame(1, SavedListeningLibrary::sole()->payload['schema']);
        $this->assertSame($first, $this->previous());
        $cleared = $this->change('clear-library', 1);
        $this->assertSame(2, $cleared['version']);
        $this->assertSame($cleared, $this->previous());
        $this->assertSame(1, SavedListeningLibrary::sole()->payload['schema']);
        try {
            PreviousListeningV1::reader()->change($this->customer['principal'], $this->customer['user'], ['action' => 'create-playlist', 'version' => 1, 'name' => 'PRIVATE stale writer']);
            $this->fail('Old stale writer resurrected cleared data.');
        } catch (ListeningException $error) {
            $this->assertSame(409, $error->status);
        }
        $this->assertSame(3, $this->change('clear-library', 2)['version']);
        $this->assertSame([], $this->previous()['playlists']);
    }

    public function test_old_and_new_ordinary_writers_can_alternate_against_retained_v1_ciphertext(): void
    {
        $first = PreviousListeningV1::reader()->change($this->customer['principal'], $this->customer['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'PRIVATE old writer']);
        $list = $first['playlists'][0]['id'];
        $new = $this->change('rename-playlist', 1, ['playlistId' => $list, 'name' => 'PRIVATE new writer']);
        try {
            $this->assertSame($new, $this->previous());
        } catch (ListeningException $error) {
            $this->fail('The actual old writer cannot resume after the new ordinary writer; status '.$error->status.'.');
        }
        $old = PreviousListeningV1::reader()->change($this->customer['principal'], $this->customer['user'], ['action' => 'rename-playlist', 'version' => 2, 'playlistId' => $list, 'name' => 'PRIVATE old writer again']);
        $this->assertSame($old, app(ListeningLibrary::class)->read($this->customer['principal'], $this->customer['user']));
        $this->assertSame(1, SavedListeningLibrary::sole()->payload['schema']);
    }

    public function test_v1_read_export_and_noops_preserve_exact_encrypted_row_bytes_with_promotion_enabled_or_disabled(): void
    {
        $list = (string) Str::uuid();
        $this->v1(['999'], [['id' => $list, 'name' => 'PRIVATE untouched', 'trackIds' => ['999']]]);
        $before = SavedListeningLibrary::sole()->getRawOriginal();
        foreach ([false, true] as $enabled) {
            $enabled ? $this->enable() : config(['customer-listening.v2_promotion_enabled' => false]);
            app(ListeningLibrary::class)->read($this->customer['principal'], $this->customer['user']);
            app(ListeningLibrary::class)->export($this->customer['principal'], $this->customer['user'], 1);
            $this->change('rename-playlist', 1, ['playlistId' => $list, 'name' => 'PRIVATE untouched']);
            $this->change('reorder-playlist', 1, ['playlistId' => $list, 'trackIds' => ['999']]);
            $this->change('remove-saved-track', 1, ['trackId' => '998']);
            $this->change('delete-track-note', 1, ['trackId' => '999']);
            $this->assertSame($before, SavedListeningLibrary::sole()->getRawOriginal());
            $this->assertSame(1, $this->previous()['version']);
        }
    }

    public static function unsafeRollouts(): array
    {
        return ['default-off' => [false, null], 'flag without review' => [true, null], 'string flag' => ['true', 'SYNTHETIC review'],
            'blank review' => [true, ' '], 'unicode blank review' => [true, "\u{00A0}"], 'oversize review' => [true, str_repeat('x', 201)], 'control review' => [true, "SYNTHETIC\nreview"], 'invalid UTF8 review' => [true, "\xFF"]];
    }

    #[DataProvider('unsafeRollouts')]
    public function test_v1_note_promotion_requires_strict_flag_and_explicit_safe_rollout_reference(mixed $flag, mixed $reference): void
    {
        config(['customer-listening.v2_promotion_enabled' => $flag, 'customer-listening.v2_rollout_review_reference' => $reference]);
        $this->v1();
        $before = SavedListeningLibrary::sole()->getRawOriginal();
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/\A(?:insert into|update) ["`]?customer_saved_tracks\b/i', $query->sql)) {
                $writes++;
            }
        });
        try {
            $this->change('set-track-note', 1, ['trackId' => '999', 'body' => 'PRIVATE forbidden promotion']);
            $this->fail('V2 promotion escaped its stopped-upgrade gate.');
        } catch (ListeningException $error) {
            $this->assertSame(503, $error->status);
        }
        $this->assertSame(0, $writes);
        $this->assertSame($before, SavedListeningLibrary::sole()->getRawOriginal());
        $this->assertSame(1, $this->previous()['listeningSchema']);
    }

    public static function lateRollouts(): array
    {
        return ['terminal query disables flag' => ['query', false], 'terminal query changes review' => ['query', true],
            'terminal container disables flag' => ['container', false], 'terminal container changes review' => ['container', true]];
    }

    #[DataProvider('lateRollouts')]
    public function test_terminal_callbacks_cannot_commit_promotion_under_a_changed_rollout(string $boundary, bool $reference): void
    {
        $this->enable();
        $this->v1();
        $before = SavedListeningLibrary::sole()->getRawOriginal();
        $fired = false;
        $change = function () use (&$fired, $reference): void {
            $fired = true;
            config($reference ? ['customer-listening.v2_rollout_review_reference' => 'SYNTHETIC changed rollout'] : ['customer-listening.v2_promotion_enabled' => false]);
        };
        if ($boundary === 'query') {
            $seen = 0;
            DB::listen(function ($query) use (&$seen, &$fired, $change): void {
                if (! $fired && preg_match('/\bfrom ["`]customer_accounts["`]/i', $query->sql) && ++$seen === 2) {
                    $change();
                }
            });
        } else {
            $seen = 0;
            $this->app->resolving(CustomerAccessPolicy::class, function () use (&$seen, $change): void {
                if (++$seen === 3) {
                    $change();
                }
            });
        }
        try {
            $this->change('set-track-note', 1, ['trackId' => '999', 'body' => 'PRIVATE attempted promotion']);
            $this->fail('Late rollout withdrawal committed V2.');
        } catch (ListeningException $error) {
            $this->assertSame(503, $error->status);
        }
        $this->assertTrue($fired);
        $this->assertSame($before, SavedListeningLibrary::sole()->getRawOriginal());
        $this->assertSame(1, $this->previous()['version']);
    }

    public function test_explicit_promotion_and_retained_v2_notes_work_after_disable_but_actual_old_reader_refuses_v2(): void
    {
        $this->v1();
        $this->enable();
        $note = $this->change('set-track-note', 1, ['trackId' => '999', 'body' => 'PRIVATE reviewed note']);
        $this->assertSame(2, SavedListeningLibrary::sole()->payload['schema']);
        $this->assertSame(2, $note['version']);
        try {
            $this->previous();
            $this->fail('The actual V1-only reader must not be claimed compatible with V2.');
        } catch (ListeningException $error) {
            $this->assertSame(503, $error->status);
        }
        config(['customer-listening.v2_promotion_enabled' => false, 'customer-listening.v2_rollout_review_reference' => null]);
        $this->assertSame($note, app(ListeningLibrary::class)->read($this->customer['principal'], $this->customer['user']));
        $edited = $this->change('set-track-note', 2, ['trackId' => '999', 'body' => 'PRIVATE retained V2 edit']);
        $this->assertSame($edited['notes'], app(ListeningLibrary::class)->export($this->customer['principal'], $this->customer['user'], 3)['notes']);
        $this->assertSame([], $this->change('delete-track-note', 3, ['trackId' => '999'])['notes']);
        $this->assertSame(2, SavedListeningLibrary::sole()->payload['schema']);
        $this->assertSame(5, $this->change('clear-library', 4)['version']);
        $this->assertSame(2, SavedListeningLibrary::sole()->payload['schema'], 'Retained V2 is never silently downgraded.');
    }
}
