<?php

namespace App\Domain\Albums;

use App\Domain\Albums\Models\AlbumRelease;
use App\Domain\Albums\Models\AlbumReleaseTrack;
use App\Domain\Media\ScanEngines;
use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\SiteImageFiles;
use App\Domain\SiteBuilder\SiteImageManifest;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of album releases. Every command runs in one transaction that locks the acting staff user and then the release,
 * and records exactly one audit event. A published release must always satisfy the publication rules, so saving one re-checks
 * them and a draft may be incomplete but never invalid. A release is catalog metadata: nothing here prices, sells or delivers.
 */
final class AlbumReleases
{
    public const SLOT = 'release_cover';

    private const FIELDS = ['title', 'artist_name', 'slug', 'release_type', 'release_date', 'description', 'artwork_image_id', 'artwork_alt', 'listening_links', 'tracks'];

    private const MAX_TRACKS = 99;

    private const MAX_TRACK_SECONDS = 21600;

    /**
     * @param  array<string, mixed>  $data  exactly the editable fields
     */
    public function save(?AlbumRelease $release, array $data, User $actor, ?int $expectedRevision = null): AlbumRelease
    {
        return DB::transaction(function () use ($release, $data, $actor, $expectedRevision): AlbumRelease {
            $current = $this->lockActor($actor);
            if (array_diff(array_keys($data), self::FIELDS) !== [] || array_diff(self::FIELDS, array_keys($data)) !== []) {
                $this->reject('title', 'The release form is incomplete. Reload and try again.');
            }
            $locked = $release?->exists ? AlbumRelease::query()->lockForUpdate()->find($release->getKey()) : new AlbumRelease;
            if ($locked === null) {
                $this->reject('title', 'This release is no longer available. Reload and choose a current release.');
            }
            if ($locked->exists && $expectedRevision !== null && $expectedRevision !== $locked->revision) {
                $this->reject('title', 'This release changed after you opened it. Reload it and apply your edits again.');
            }
            $after = $this->normalise($data, $locked);
            $before = $locked->exists ? $this->snapshot($locked) : null;
            $changed = array_values(array_filter(self::FIELDS, fn (string $field): bool => $before === null || $before[$field] !== $after[$field]));
            if ($changed === []) {
                return $locked;
            }
            $image = $after['artwork_image_id'] === null ? null : $this->coverImage($after['artwork_image_id']);
            if ($locked->is_published) {
                $this->requireReady($after, $image);
            }
            $locked->fill([
                'title' => $after['title'], 'artist_name' => $after['artist_name'], 'slug' => $after['slug'],
                'release_type' => $after['release_type'], 'release_date' => $after['release_date'], 'description' => $after['description'],
                'artwork_image_id' => $after['artwork_image_id'], 'artwork_alt' => $after['artwork_alt'],
                'artwork_manifest_sha256' => $image?->manifest_sha256, 'listening_links' => $after['listening_links'],
                'revision' => $locked->exists ? $locked->revision + 1 : 1,
            ]);
            $locked->save();
            $this->replaceTracks($locked, $after['tracks']);
            AuditEvent::record('albums.release.'.($before === null ? 'created' : 'updated'), $locked, [
                'schema_version' => 1, 'changed_fields' => $changed, 'published' => (bool) $locked->is_published, 'revision' => $locked->revision,
                'before_hash' => $before === null ? null : CanonicalJson::hash($before), 'after_hash' => CanonicalJson::hash($after),
            ], $current->id);

            return $locked->refresh();
        });
    }

    public function publish(AlbumRelease $release, int $expectedRevision, User $actor): AlbumRelease
    {
        return DB::transaction(function () use ($release, $expectedRevision, $actor): AlbumRelease {
            $current = $this->lockActor($actor);
            $locked = $this->lockRelease($release, $expectedRevision);
            if ($locked->is_published) {
                $this->reject('is_published', 'This release is already published.');
            }
            $after = $this->snapshot($locked);
            $image = $after['artwork_image_id'] === null ? null : $this->coverImage($after['artwork_image_id']);
            $this->requireReady($after, $image);
            $locked->forceFill(['is_published' => true, 'published_at' => now(), 'published_by' => $current->id,
                'artwork_manifest_sha256' => $image->manifest_sha256, 'revision' => $locked->revision + 1])->save();
            AuditEvent::record('albums.release.published', $locked, [
                'schema_version' => 1, 'revision' => $locked->revision, 'content_hash' => CanonicalJson::hash($after),
                'artwork_manifest_sha256' => $image->manifest_sha256,
            ], $current->id);

            return $locked->refresh();
        });
    }

    public function unpublish(AlbumRelease $release, int $expectedRevision, User $actor): AlbumRelease
    {
        return DB::transaction(function () use ($release, $expectedRevision, $actor): AlbumRelease {
            $current = $this->lockActor($actor);
            $locked = $this->lockRelease($release, $expectedRevision);
            if (! $locked->is_published) {
                $this->reject('is_published', 'This release is not published.');
            }
            $locked->forceFill(['is_published' => false, 'revision' => $locked->revision + 1])->save();
            AuditEvent::record('albums.release.unpublished', $locked, [
                'schema_version' => 1, 'revision' => $locked->revision, 'content_hash' => CanonicalJson::hash($this->snapshot($locked)),
            ], $current->id);

            return $locked->refresh();
        });
    }

    /**
     * What stops a release from being published, keyed by form field. Empty means ready. Reads only; it takes no locks.
     *
     * @return array<string, string>
     */
    public function blockers(AlbumRelease $release): array
    {
        $snapshot = $this->snapshot($release);
        $image = $snapshot['artwork_image_id'] === null ? null : SiteImage::query()->with('variants')->find($snapshot['artwork_image_id']);

        return $this->readinessProblems($snapshot, $image);
    }

    /** @param  array<string, mixed>  $after */
    private function requireReady(array $after, ?SiteImage $image): void
    {
        $problems = $this->readinessProblems($after, $image);
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /**
     * @param  array<string, mixed>  $release  a normalised snapshot
     * @return array<string, string>
     */
    private function readinessProblems(array $release, ?SiteImage $image): array
    {
        $problems = [];
        if ($release['artwork_image_id'] === null) {
            $problems['artwork_image_id'] = 'Choose the cover art before publishing.';
        } elseif ($image === null || ($reason = $this->artworkProblem($image)) !== null) {
            $problems['artwork_image_id'] = $reason ?? 'The chosen cover art is not available.';
        }
        if ($release['artwork_alt'] === null) {
            $problems['artwork_alt'] = 'Describe the cover art for people who cannot see it before publishing.';
        }
        if ($release['tracks'] === []) {
            $problems['tracks'] = 'Add at least one track before publishing.';
        }
        if ($release['listening_links'] === []) {
            $problems['listening_links'] = 'Add at least one listening link before publishing.';
        }

        return $problems;
    }

    /** Why the image cannot be shown publicly, or null when it is a ready, scanned, intact cover with every file present. */
    private function artworkProblem(SiteImage $image): ?string
    {
        if ($image->slot !== self::SLOT) {
            return 'Choose an image uploaded as release cover art.';
        }
        $scan = $image->evidence['source_scan'] ?? [];
        if ($image->status !== 'ready' || ! SiteImageManifest::matches($image)
            || ($scan['status'] ?? null) !== 'clean' || ! is_string($scan['sha256'] ?? null) || ! hash_equals($image->source_sha256, $scan['sha256'])
            || ! ScanEngines::accepted($scan['engine'] ?? null)) {
            return 'The chosen cover art is not ready. Check its status under Site images.';
        }
        $files = app(SiteImageFiles::class);
        foreach ($image->variants as $variant) {
            if ($files->verifiedBytes($variant->setRelation('image', $image)) === null) {
                return 'A file of the chosen cover art is missing or damaged. Upload it again.';
            }
        }

        return null;
    }

    private function coverImage(int $id): SiteImage
    {
        $image = SiteImage::query()->with('variants')->lockForUpdate()->find($id);
        if ($image === null || $image->slot !== self::SLOT || $image->status !== 'ready') {
            $this->reject('artwork_image_id', 'Choose a ready image uploaded as release cover art.');
        }
        $image->setRelation('variants', $image->variants()->sharedLock()->get());

        return $image;
    }

    private function lockRelease(AlbumRelease $release, int $expectedRevision): AlbumRelease
    {
        $locked = $release->exists ? AlbumRelease::query()->lockForUpdate()->find($release->getKey()) : null;
        if ($locked === null) {
            $this->reject('title', 'This release is no longer available. Reload and choose a current release.');
        }
        if ($locked->revision !== $expectedRevision) {
            $this->reject('title', 'This release changed after you opened it. Reload it and review it again.');
        }

        return $locked;
    }

    /** @return array<string, mixed> */
    private function snapshot(AlbumRelease $release): array
    {
        return [
            'title' => $release->title, 'artist_name' => $release->artist_name, 'slug' => $release->slug,
            'release_type' => $release->release_type->value, 'release_date' => $release->release_date->format('Y-m-d'),
            'description' => $release->description, 'artwork_image_id' => $release->artwork_image_id, 'artwork_alt' => $release->artwork_alt,
            'listening_links' => array_values($release->listening_links ?? []),
            'tracks' => $release->tracks()->get()->map(fn (AlbumReleaseTrack $track): array => ['title' => $track->title, 'duration_seconds' => $track->duration_seconds])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data, AlbumRelease $existing): array
    {
        $type = $data['release_type'] instanceof ReleaseType ? $data['release_type']->value : $data['release_type'];
        if (! is_string($type) || ! in_array($type, ReleaseType::values(), true)) {
            $this->reject('release_type', 'Choose album, EP or single.');
        }
        $slug = $data['slug'];
        if (! is_string($slug) || strlen($slug) > 80 || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) !== 1) {
            $this->reject('slug', 'Use lowercase letters, numbers and single hyphens, up to 80 characters.');
        }
        $taken = AlbumRelease::query()->where('slug', $slug)->when($existing->exists, fn ($query) => $query->whereKeyNot($existing->getKey()))->lockForUpdate()->exists();
        if ($taken) {
            $this->reject('slug', 'Another release already uses this web address.');
        }
        $date = $data['release_date'];
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;
        $parsed = is_string($date) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $date, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 1950 && (int) $m[1] <= 2100;
        if (! $parsed) {
            $this->reject('release_date', 'Enter a real release date.');
        }
        $imageId = $data['artwork_image_id'];
        if ($imageId !== null) {
            $imageId = is_string($imageId) && ctype_digit($imageId) ? (int) $imageId : $imageId;
            if (! is_int($imageId) || $imageId < 1) {
                $this->reject('artwork_image_id', 'Choose a ready image uploaded as release cover art.');
            }
        }

        return [
            'title' => $this->line($data['title'], 'title', 160), 'artist_name' => $this->line($data['artist_name'], 'artist_name', 120),
            'slug' => $slug, 'release_type' => $type, 'release_date' => $date,
            'description' => $this->block($data['description']),
            'artwork_image_id' => $imageId === null ? null : (int) $imageId,
            'artwork_alt' => $this->line($data['artwork_alt'], 'artwork_alt', 200, true),
            'listening_links' => $this->links($data['listening_links']),
            'tracks' => $this->tracks($data['tracks']),
        ];
    }

    /** @return list<array{platform: string, url: string}> */
    private function links(mixed $links): array
    {
        if (! is_array($links) || ! array_is_list($links) || count($links) > count(ListeningPlatforms::options())) {
            $this->reject('listening_links', 'Add up to one link per platform.');
        }
        $clean = [];
        $seen = [];
        foreach ($links as $link) {
            if (! is_array($link) || array_diff(array_keys($link), ['platform', 'url']) !== [] || ! array_key_exists('platform', $link) || ! array_key_exists('url', $link)) {
                $this->reject('listening_links', 'Each link needs a platform and an address.');
            }
            $url = is_string($link['url']) ? trim($link['url']) : $link['url'];
            if (($problem = ListeningPlatforms::problem($link['platform'], $url)) !== null) {
                $this->reject('listening_links', $problem);
            }
            if (isset($seen[$link['platform']])) {
                $this->reject('listening_links', 'Use each platform once.');
            }
            $seen[$link['platform']] = true;
            $clean[] = ['platform' => $link['platform'], 'url' => $url];
        }

        return $clean;
    }

    /** @return list<array{title: string, duration_seconds: int}> */
    private function tracks(mixed $tracks): array
    {
        if (! is_array($tracks) || ! array_is_list($tracks) || count($tracks) > self::MAX_TRACKS) {
            $this->reject('tracks', 'Add up to '.self::MAX_TRACKS.' tracks.');
        }
        $clean = [];
        foreach ($tracks as $track) {
            if (! is_array($track) || array_diff(array_keys($track), ['title', 'duration_seconds']) !== [] || ! array_key_exists('title', $track) || ! array_key_exists('duration_seconds', $track)) {
                $this->reject('tracks', 'Each track needs a title and a length.');
            }
            $seconds = $track['duration_seconds'];
            if (! is_int($seconds) || $seconds < 1 || $seconds > self::MAX_TRACK_SECONDS) {
                $this->reject('tracks', 'Each track length must be between 0:01 and 6:00:00.');
            }
            $clean[] = ['title' => $this->line($track['title'], 'tracks', 160), 'duration_seconds' => $seconds];
        }

        return $clean;
    }

    /** @param  list<array{title: string, duration_seconds: int}>  $tracks */
    private function replaceTracks(AlbumRelease $release, array $tracks): void
    {
        $release->tracks()->delete();
        foreach ($tracks as $index => $track) {
            AlbumReleaseTrack::create(['album_release_id' => $release->id, 'position' => $index + 1] + $track);
        }
    }

    private function line(mixed $value, string $field, int $max, bool $nullable = false): ?string
    {
        if ($nullable && ($value === null || (is_string($value) && trim($value) === ''))) {
            return null;
        }
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->reject($field, 'Enter plain text.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F<>]/u', $value) === 1) {
            $this->reject($field, 'Enter 1 to '.$max.' characters of plain text on one line.');
        }

        return $value;
    }

    private function block(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->reject('description', 'Enter plain text.');
        }
        $value = trim(str_replace("\r\n", "\n", $value));
        if (mb_strlen($value) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/u', $value) === 1) {
            $this->reject('description', 'Enter up to 2000 characters of plain text.');
        }

        return $value;
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

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
