<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Track;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The visibility moves that narrow exposure or are staff-only: published to unlisted, any exposure to private, and between
 * draft and private. Anything that exposes a track (draft or unlisted to published, draft to unlisted, scheduling) goes through
 * PublishTrack or TrackPublicationSchedules, where every publication guard runs. Moving into private never runs a guard, so a
 * track that has stopped qualifying can always be withdrawn. Published to unlisted stays live, so the model's keep-qualifying
 * check still applies to it.
 */
final class ChangeTrackVisibility
{
    private const MOVES = [
        TrackVisibility::DRAFT => [TrackVisibility::PRIVATE],
        TrackVisibility::PRIVATE => [TrackVisibility::DRAFT],
        TrackVisibility::PUBLISHED => [TrackVisibility::UNLISTED, TrackVisibility::PRIVATE],
        TrackVisibility::UNLISTED => [TrackVisibility::PRIVATE],
    ];

    private const MAX_VERSION = 2147483647;

    /** @return list<string> the states this service can move a track in `$status` to */
    public static function targets(string $status): array
    {
        return self::MOVES[$status] ?? [];
    }

    /** The editor's versions fence the move exactly as for manual publication: any change since it opened refuses it. */
    public function handle(Track $track, User $actor, string $target, int $metadataVersion, int $publicationVersion): Track
    {
        return DB::transaction(function () use ($track, $actor, $target, $metadataVersion, $publicationVersion): Track {
            $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
            if ($current === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($current)->authorize('administer-catalog', [true]);
            if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
                throw new AuthorizationException('Admin multi-factor authentication is required.');
            }
            $locked = Track::query()->lockForUpdate()->findOrFail($track->getKey());
            if ($locked->metadata_version !== $metadataVersion || $locked->publication_version !== $publicationVersion) {
                $this->reject('This track changed since you opened it. Close and reopen the confirmation, then try again.');
            }
            if ($locked->publication_version >= self::MAX_VERSION) {
                $this->reject('This track cannot accept another publication revision.');
            }
            if (! in_array($target, self::targets($locked->status), true)) {
                $this->reject('A '.$locked->status.' track cannot become '.$target.' with this action.');
            }
            $from = $locked->status;
            $before = $locked->publication_version;
            $locked->update(['status' => $target, 'publication_version' => $before + 1]);
            AuditEvent::record('catalog.track.visibility_changed', $locked, [
                'schema_version' => 1, 'from_status' => $from, 'to_status' => $target, 'metadata_version' => $locked->metadata_version,
                'previous_publication_version' => $before, 'publication_version' => $locked->publication_version,
            ], $current->id);

            return $locked;
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['publication' => $message]);
    }
}
