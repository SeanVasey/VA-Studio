<?php

namespace App\Domain\Customers\ProductionFeatures\Listening;

use App\Domain\Customers\Listening\ListeningException;
use Illuminate\Support\Str;
use Throwable;

/** New production-only copy of reviewed pure rules from main9f ListeningLibrary.
 * No account authority, model access, publication decision or legacy storage adoption.
 */
final class ProductionListeningState
{
    public const FAVORITES = 50;

    public const PLAYLISTS = 10;

    public const PLAYLIST_TRACKS = 25;

    public const NOTES = 25;

    public const NOTE_CHARACTERS = 2000;

    public const NOTE_BYTES = 4000;

    // Keep the actual encrypted envelope inside the existing MySQL TEXT capacity.
    public const ENCRYPTED_PAYLOAD_BYTES = 60000;

    public function empty(int $accountId): array
    {
        return ['schema' => 1, 'accountId' => $accountId, 'version' => 0, 'favorites' => [], 'playlists' => []];
    }

    public function validateCommand(array $command): void
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

    public function apply(array $state, array $command, callable $publicTrack, bool $promotionEnabled): array
    {
        $action = $command['action'];
        if ($action === 'clear-library') {
            return [...$state, 'favorites' => [], 'playlists' => [], ...($state['schema'] === 2 ? ['notes' => []] : [])];
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
                if ($state['schema'] === 1 && ! $promotionEnabled) {
                    throw new ListeningException(503);
                }
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
                $this->requirePublic($command['trackId'], $publicTrack);
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
                $this->requirePublic($command['trackId'], $publicTrack);
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

    private function requirePublic(string $id, callable $publicTrack): void
    {
        if (! $publicTrack($id)) {
            throw new ListeningException(404);
        }
    }

    public function state(mixed $state, int $accountId, int $version): array
    {
        try {
            $keys = ['schema', 'accountId', 'version', 'favorites', 'playlists'];
            if (is_array($state) && ($state['schema'] ?? null) === 2) {
                $keys[] = 'notes';
            }
            if (! is_array($state) || array_keys($state) !== $keys || ! in_array($state['schema'], [1, 2], true)
                || $state['accountId'] !== $accountId || $state['version'] !== $version
                || $accountId < 1 || $version < 0 || $version > 2147483646) {
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

            if ($version === 0 && ($state['favorites'] !== [] || $state['playlists'] !== [] || ($state['notes'] ?? []) !== [])) {
                throw new ListeningException(503);
            }

            return $state;
        } catch (Throwable) {
            throw new ListeningException(503);
        }
    }

    private function v2(array $state): array
    {
        return [...$state, 'schema' => 2, 'notes' => $state['notes'] ?? []];
    }

    public function references(array $state): array
    {
        return array_values(array_unique([...$state['favorites'], ...array_merge([], ...array_column($state['playlists'], 'trackIds'))]));
    }

    public function pruneNotes(array $state): array
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
}
