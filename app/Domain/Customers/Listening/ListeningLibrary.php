<?php

namespace App\Domain\Customers\Listening;

use App\Domain\Catalog\PublicCatalog;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerPrincipal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Private bounded listening preferences; no purchase, grant, sharing or consent authority. */
final class ListeningLibrary
{
    public const FAVORITES = 50;

    public const PLAYLISTS = 10;

    public const PLAYLIST_TRACKS = 25;

    public const NOTES = 25;

    public const NOTE_CHARACTERS = 2000;

    public const NOTE_BYTES = 4000;

    // Keep the actual encrypted envelope inside the existing MySQL TEXT capacity.
    public const ENCRYPTED_PAYLOAD_BYTES = 60000;

    public function read(CustomerPrincipal $principal, User $actor): array
    {
        return DB::transaction(function () use ($principal, $actor): array {
            $evidence = new ListeningEvidence;
            app(CustomerAccess::class)->lock($principal, $principal->ownerKey, $actor);
            $row = SavedListeningLibrary::where('customer_account_id', $principal->accountId)->lockForUpdate()->first();
            $state = $this->state($row, $principal->accountId);
            $version = $row?->version ?? 0;

            return $this->project($principal, $state, $version, $evidence, $this->expected($row, $state, $version, $principal->accountId));
        });
    }

    public function change(CustomerPrincipal $principal, User $actor, array $command): array
    {
        return DB::transaction(function () use ($principal, $actor, $command): array {
            $evidence = new ListeningEvidence;
            // Fresh user/account locks serialize first creation and every bounded list update.
            app(CustomerAccess::class)->lock($principal, $principal->ownerKey, $actor);
            $this->validateCommand($command);
            $row = SavedListeningLibrary::where('customer_account_id', $principal->accountId)->lockForUpdate()->first();
            $version = $row?->version ?? 0;
            if ($command['version'] !== $version || $version >= 2147483646) {
                throw new ListeningException(409);
            }
            $state = $this->state($row, $principal->accountId);
            $next = $this->pruneNotes($this->apply($state, $command, $evidence));
            if ($next !== $state || $command['action'] === 'clear-library') {
                $row ??= new SavedListeningLibrary(['customer_account_id' => $principal->accountId]);
                $next = $this->v2($next);
                $next['version'] = ++$version;
                $row->fill(['version' => $version, 'payload' => $next]);
                $encrypted = $row->getAttributes()['payload'] ?? null;
                if (! is_string($encrypted) || strlen($encrypted) > self::ENCRYPTED_PAYLOAD_BYTES) {
                    throw new ListeningException;
                }
                $row->save();
            }

            return $this->project($principal, $next, $version, $evidence, $this->expected($row, $next, $version, $principal->accountId));
        }, 3);
    }

    /** Own feature inputs only: never include catalog, account or purchased-rights data. */
    public function export(CustomerPrincipal $principal, User $actor, int $expectedVersion): array
    {
        return DB::transaction(function () use ($principal, $actor, $expectedVersion): array {
            $evidence = new ListeningEvidence;
            app(CustomerAccess::class)->lock($principal, $principal->ownerKey, $actor);
            if ($expectedVersion < 0 || $expectedVersion > 2147483646) {
                throw new ListeningException;
            }
            $row = SavedListeningLibrary::where('customer_account_id', $principal->accountId)->lockForUpdate()->first();
            $version = $row?->version ?? 0;
            if ($expectedVersion !== $version) {
                throw new ListeningException(409);
            }
            $state = $this->state($row, $principal->accountId);
            $expected = $this->expected($row, $state, $version, $principal->accountId);
            $result = ['exportSchema' => 1, 'feature' => 'customer-listening-library', 'version' => $version,
                'favorites' => $state['favorites'], 'playlists' => $state['playlists'], 'notes' => $state['notes'] ?? []];
            app(CustomerAccess::class)->current($principal);
            $evidence->prove($principal, $expected);

            return $result;
        });
    }

    private function validateCommand(array $command): void
    {
        $fields = match ($command['action'] ?? null) {
            'save-track', 'remove-saved-track' => ['trackId'],
            'create-playlist' => ['name'],
            'rename-playlist' => ['playlistId', 'name'],
            'delete-playlist' => ['playlistId'],
            'add-playlist-track', 'remove-playlist-track' => ['playlistId', 'trackId'],
            'reorder-playlist' => ['playlistId', 'trackIds'],
            'set-track-note' => ['trackId', 'body'],
            'delete-track-note' => ['trackId'],
            'clear-library' => [],
            default => throw new ListeningException,
        };
        if (count($command) !== count($fields) + 2 || array_diff(array_keys($command), ['action', 'version', ...$fields])
            || ! is_int($command['version'] ?? null) || $command['version'] < 0 || $command['version'] > 2147483646) {
            throw new ListeningException;
        }
        foreach ($fields as $field) {
            $value = $command[$field] ?? null;
            if ($field === 'trackId') {
                $this->id($value);
            } elseif ($field === 'playlistId') {
                if (! is_string($value) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $value) !== 1) {
                    throw new ListeningException;
                }
            } elseif ($field === 'name') {
                if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || trim($value) !== $value
                    || mb_strlen($value) < 1 || mb_strlen($value) > 80 || preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
                    throw new ListeningException;
                }
            } elseif ($field === 'body') {
                $this->note($value);
            } else {
                $this->ids($value, self::PLAYLIST_TRACKS);
            }
        }
    }

    private function apply(array $state, array $command, ListeningEvidence $evidence): array
    {
        $action = $command['action'];
        if ($action === 'clear-library') {
            return [...$state, 'schema' => 2, 'favorites' => [], 'playlists' => [], 'notes' => []];
        }
        if ($action === 'set-track-note' || $action === 'delete-track-note') {
            if (! in_array($command['trackId'], $this->references($state), true)) {
                throw new ListeningException(404);
            }
            $notes = $state['notes'] ?? [];
            $index = array_search($command['trackId'], array_column($notes, 'trackId'), true);
            if ($action === 'delete-track-note') {
                if ($index === false) {
                    return $state;
                }
                array_splice($notes, $index, 1);
            } else {
                if ($index !== false && $notes[$index]['body'] === $command['body']) {
                    return $state;
                }
                if ($index === false) {
                    if (count($notes) >= self::NOTES) {
                        throw new ListeningException;
                    }
                    $notes[] = ['trackId' => $command['trackId'], 'body' => $command['body']];
                } else {
                    $notes[$index]['body'] = $command['body'];
                }
            }

            return [...$this->v2($state), 'notes' => $notes];
        }
        if ($action === 'save-track' || $action === 'remove-saved-track') {
            if ($action === 'save-track') {
                $this->requirePublic($command['trackId'], $evidence);
                $state['favorites'] = $this->append($state['favorites'], $command['trackId'], self::FAVORITES);
            } else {
                $state['favorites'] = array_values(array_diff($state['favorites'], [$command['trackId']]));
            }

            return $state;
        }
        if ($action === 'create-playlist') {
            if (count($state['playlists']) >= self::PLAYLISTS) {
                throw new ListeningException;
            }
            $state['playlists'][] = ['id' => (string) Str::uuid(), 'name' => $command['name'], 'trackIds' => []];

            return $state;
        }
        $index = array_search($command['playlistId'], array_column($state['playlists'], 'id'), true);
        if ($index === false) {
            throw new ListeningException(404);
        }
        $playlist = $state['playlists'][$index];
        if ($action === 'delete-playlist') {
            array_splice($state['playlists'], $index, 1);
        } else {
            if ($action === 'rename-playlist') {
                $playlist['name'] = $command['name'];
            } elseif ($action === 'add-playlist-track') {
                $this->requirePublic($command['trackId'], $evidence);
                $playlist['trackIds'] = $this->append($playlist['trackIds'], $command['trackId'], self::PLAYLIST_TRACKS);
            } elseif ($action === 'remove-playlist-track') {
                $playlist['trackIds'] = array_values(array_diff($playlist['trackIds'], [$command['trackId']]));
            } else {
                if (array_diff($playlist['trackIds'], $command['trackIds']) || array_diff($command['trackIds'], $playlist['trackIds'])) {
                    throw new ListeningException;
                }
                $playlist['trackIds'] = $command['trackIds'];
            }
            $state['playlists'][$index] = $playlist;
        }

        return $state;
    }

    private function append(array $ids, string $id, int $limit): array
    {
        if (in_array($id, $ids, true)) {
            return $ids;
        }
        if (count($ids) >= $limit) {
            throw new ListeningException;
        }

        return [...$ids, $id];
    }

    private function requirePublic(string $id, ListeningEvidence $evidence): void
    {
        $evidence->capturePublic([(int) $id]);
        if (app(PublicCatalog::class)->selections([(int) $id])['tracks'] === []) {
            throw new ListeningException(404);
        }
    }

    private function state(?SavedListeningLibrary $row, int $accountId): array
    {
        if ($row === null) {
            return ['schema' => 1, 'accountId' => $accountId, 'version' => 0, 'favorites' => [], 'playlists' => []];
        }
        try {
            $state = $row->payload;
            $keys = ['schema', 'accountId', 'version', 'favorites', 'playlists'];
            if (is_array($state) && ($state['schema'] ?? null) === 2) {
                $keys[] = 'notes';
            }
            if (! is_array($state) || array_keys($state) !== $keys || ! in_array($state['schema'], [1, 2], true)
                || $state['accountId'] !== $accountId || $row->customer_account_id !== $accountId || $state['version'] !== $row->version
                || $row->version < 1 || $row->version > 2147483646) {
                throw new ListeningException;
            }
            $this->ids($state['favorites'], self::FAVORITES);
            if (! is_array($state['playlists']) || ! array_is_list($state['playlists']) || count($state['playlists']) > self::PLAYLISTS) {
                throw new ListeningException;
            }
            $ids = [];
            foreach ($state['playlists'] as $playlist) {
                if (! is_array($playlist) || array_keys($playlist) !== ['id', 'name', 'trackIds'] || in_array($playlist['id'], $ids, true)) {
                    throw new ListeningException;
                }
                $this->validateCommand(['action' => 'rename-playlist', 'version' => 0, 'playlistId' => $playlist['id'], 'name' => $playlist['name']]);
                $this->ids($playlist['trackIds'], self::PLAYLIST_TRACKS);
                $ids[] = $playlist['id'];
            }
            if ($state['schema'] === 2) {
                $notes = $state['notes'];
                if (! is_array($notes) || ! array_is_list($notes) || count($notes) > self::NOTES) {
                    throw new ListeningException;
                }
                $seen = [];
                $references = $this->references($state);
                foreach ($notes as $note) {
                    if (! is_array($note) || array_keys($note) !== ['trackId', 'body'] || in_array($note['trackId'], $seen, true)
                        || ! in_array($note['trackId'], $references, true)) {
                        throw new ListeningException;
                    }
                    $this->id($note['trackId']);
                    $this->note($note['body']);
                    $seen[] = $note['trackId'];
                }
            }

            return $state;
        } catch (Throwable) {
            throw new ListeningException(503);
        }
    }

    private function expected(?SavedListeningLibrary $row, array $state, int $version, int $accountId): ?array
    {
        // A saved callback may refresh this very instance to a different valid revision.
        // Bind the physical evidence to the local intended state, not a rebound model.
        if (($row?->version ?? 0) !== $version || $this->state($row, $accountId) !== $state) {
            throw new ListeningException(503);
        }

        return $row?->getRawOriginal();
    }

    private function v2(array $state): array
    {
        return [...$state, 'schema' => 2, 'notes' => $state['notes'] ?? []];
    }

    private function references(array $state): array
    {
        return array_values(array_unique([...$state['favorites'], ...array_merge([], ...array_column($state['playlists'], 'trackIds'))]));
    }

    private function pruneNotes(array $state): array
    {
        if ($state['schema'] === 2) {
            $references = $this->references($state);
            $state['notes'] = array_values(array_filter($state['notes'], fn ($note) => in_array($note['trackId'], $references, true)));
        }

        return $state;
    }

    private function note(mixed $body): void
    {
        if (! is_string($body) || ! mb_check_encoding($body, 'UTF-8') || trim($body) === '' || strlen($body) > self::NOTE_BYTES
            || mb_strlen($body) > self::NOTE_CHARACTERS || preg_match('/[\p{Cf}\x00-\x08\x0B-\x1F\x7F-\x9F]/u', $body)
            || preg_match('/[^\p{Z}\x09\x0A]/u', $body) !== 1) {
            throw new ListeningException;
        }
    }

    private function id(mixed $id): void
    {
        if (! is_string($id) || preg_match('/\A[1-9][0-9]{0,17}\z/D', $id) !== 1) {
            throw new ListeningException;
        }
    }

    private function ids(mixed $ids, int $limit): void
    {
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > $limit || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            throw new ListeningException;
        }
        foreach ($ids as $id) {
            $this->id($id);
        }
    }

    private function project(CustomerPrincipal $principal, array $state, int $version, ListeningEvidence $evidence, ?array $expectedRow): array
    {
        $ids = $state['favorites'];
        foreach ($state['playlists'] as $playlist) {
            $ids = [...$ids, ...$playlist['trackIds']];
        }
        $tracks = [];
        // A closed aggregate has at most 300 references. Never enumerate the catalog.
        foreach (array_chunk(array_values(array_unique($ids)), 10) as $chunk) {
            $evidence->capturePublic(array_map('intval', $chunk));
            foreach (app(PublicCatalog::class)->selections(array_map('intval', $chunk))['tracks'] as $track) {
                $tracks[$track['id']] = ['title' => $track['title'], 'artist' => $track['artist'], 'href' => route('tracks.show', $track['slug'], false)];
            }
        }
        $item = fn (string $id): array => ['trackId' => $id, 'available' => isset($tracks[$id])]
            + (isset($tracks[$id]) ? ['track' => $tracks[$id]] : []);
        $result = ['listeningSchema' => 2, 'version' => $version,
            'favorites' => array_map($item, $state['favorites']),
            'playlists' => array_map(fn ($playlist) => ['id' => $playlist['id'], 'name' => $playlist['name'], 'tracks' => array_map($item, $playlist['trackIds'])], $state['playlists']),
            'notes' => $state['notes'] ?? [],
            'limits' => ['favorites' => self::FAVORITES, 'playlists' => self::PLAYLISTS, 'playlistTracks' => self::PLAYLIST_TRACKS,
                'notes' => self::NOTES, 'noteCharacters' => self::NOTE_CHARACTERS, 'noteBytes' => self::NOTE_BYTES]];
        // Media/readiness callbacks may withdraw access; never release a stale projection or commit.
        app(CustomerAccess::class)->current($principal);
        $evidence->prove($principal, $expectedRow);

        return $result;
    }
}
