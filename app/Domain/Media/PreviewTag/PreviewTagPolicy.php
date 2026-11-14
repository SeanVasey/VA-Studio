<?php

namespace App\Domain\Media\PreviewTag;

use App\Domain\Catalog\Models\Track;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of the preview-tag setting (Sean, 2026-11-11): a store-wide default and a per-track override that decide
 * whether the PUBLIC preview carries the seller tag. Each command runs in one transaction that locks the acting staff user
 * (catalog authority and enrolled admin MFA re-read under that lock), then the track, then the setting row, checks the
 * expected revision, and records one audit event with the actor and the old and new values.
 *
 * It changes only which of two already-verified previews the public hears. It never touches a media row, an offer, a license, a
 * deliverable, a contract or the track itself, so it is allowed for draft and live tracks alike, needs no unpublishing and never
 * affects publication readiness: offer snapshots pin the tagged preview, which is never altered here (see {@see PreviewTagResolver}).
 * Because nothing is reprocessed, the switch is in effect on the next request.
 */
final class PreviewTagPolicy
{
    public const NO_UNTAGGED = 'This track has no verified untagged preview yet, so its preview cannot be switched off. A draft gains one when its master is processed again (php artisan vasey:media-retag-previews --add-untagged); a live track must be returned to draft first.';

    public function __construct(private readonly PreviewTagResolver $resolver) {}

    /**
     * @param  int  $expectedRevision  the revision the editor loaded (0 when no default has ever been saved)
     * @return array{tagged: bool, revision: int}
     */
    public function setDefault(bool $tagged, int $expectedRevision, User $actor): array
    {
        return DB::transaction(function () use ($tagged, $expectedRevision, $actor): array {
            $current = $this->lockActor($actor);
            $row = PreviewTagSetting::query()->where('scope_key', PreviewTagSetting::DEFAULT_KEY)->lockForUpdate()->first();
            $revision = $row?->revision ?? 0;
            $this->requireRevision($expectedRevision, $revision);
            $before = $row?->tagged ?? PreviewTagResolver::DEFAULT_TAGGED;
            if ($row !== null && $row->tagged === $tagged) {
                return ['tagged' => $tagged, 'revision' => $revision];
            }
            $row = $this->write($row, PreviewTagSetting::DEFAULT_KEY, null, $tagged, $current);
            AuditEvent::record('media.preview_tag.default_changed', $row, [
                'schema_version' => 1, 'scope' => 'default', 'track_id' => null, 'old' => $before, 'new' => $tagged,
                'effective_before' => $before, 'effective_after' => $tagged, 'revision_before' => $revision, 'revision_after' => $row->revision,
            ], $current->id);

            return ['tagged' => $tagged, 'revision' => $row->revision];
        });
    }

    /**
     * @param  ?bool  $tagged  true: always tagged; false: untagged (refused unless a verified untagged preview exists); null: follow the default
     * @return array{tagged: ?bool, revision: int, effective_tagged: bool}
     */
    public function setTrack(Track $track, ?bool $tagged, int $expectedRevision, User $actor): array
    {
        return DB::transaction(function () use ($track, $tagged, $expectedRevision, $actor): array {
            $current = $this->lockActor($actor);
            $locked = $track->exists ? Track::query()->lockForUpdate()->find($track->getKey()) : null;
            if ($locked === null) {
                $this->reject('This track is no longer available. Reload and choose a current track.');
            }
            $key = PreviewTagSetting::trackKey((int) $locked->id);
            $row = PreviewTagSetting::query()->where('scope_key', $key)->lockForUpdate()->first();
            $revision = $row?->revision ?? 0;
            $this->requireRevision($expectedRevision, $revision);
            $default = $this->resolver->defaultTagged();
            $before = $row?->tagged;
            if ($before === $tagged) {
                return ['tagged' => $tagged, 'revision' => $revision, 'effective_tagged' => $tagged ?? $default];
            }
            $untagged = $this->resolver->verifiedUntagged($locked);
            if ($tagged === false && $untagged === null) {
                $this->reject(self::NO_UNTAGGED);
            }
            $row = $this->write($row, $key, (int) $locked->id, $tagged, $current);
            AuditEvent::record('media.preview_tag.track_changed', $row, [
                'schema_version' => 1, 'scope' => 'track', 'track_id' => (int) $locked->id, 'old' => $before, 'new' => $tagged,
                'effective_before' => $before ?? $default, 'effective_after' => $tagged ?? $default,
                'untagged_available' => $untagged !== null, 'revision_before' => $revision, 'revision_after' => $row->revision,
            ], $current->id);

            return ['tagged' => $tagged, 'revision' => $row->revision, 'effective_tagged' => $tagged ?? $default];
        });
    }

    private function write(?PreviewTagSetting $row, string $key, ?int $trackId, ?bool $tagged, User $actor): PreviewTagSetting
    {
        if ($row !== null) {
            $row->forceFill(['tagged' => $tagged, 'revision' => $row->revision + 1, 'updated_by' => $actor->id])->save();

            return $row;
        }
        try {
            return PreviewTagSetting::query()->create(['scope_key' => $key, 'track_id' => $trackId, 'tagged' => $tagged, 'revision' => 1, 'updated_by' => $actor->id]);
        } catch (UniqueConstraintViolationException) {
            // Two first writes raced for the same key; the loser has not seen the winner's revision.
            $this->reject('This setting changed after you opened it. Reload it and apply your choice again.');
        }
    }

    private function requireRevision(int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            $this->reject('This setting changed after you opened it. Reload it and apply your choice again.');
        }
    }

    private function lockActor(User $actor): User
    {
        $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }

        return $current;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['tagged' => $message]);
    }
}
