<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\ProductDraft;
use App\Domain\Catalog\Models\ProductDraftMember;
use App\Domain\Catalog\Models\ProductDraftVersion;
use App\Domain\Catalog\Models\Track;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Append-only staff composition. Draft versions never authorize sale, publication or delivery. */
class ProductDrafts
{
    private const MAX_VERSION = 2147483647;

    public function save(?ProductDraft $draft, array $data, User $actor): ProductDraft
    {
        return DB::transaction(function () use ($draft, $data, $actor) {
            $currentActor = $this->actor($actor);
            if (array_diff(array_keys($data), ['kind', 'title', 'description', 'track_ids', 'version'])) {
                $this->reject('title', 'Only descriptive composition may be saved here.');
            }
            $current = $draft === null ? new ProductDraft : $this->draft((int) $draft->getKey());
            if ($current->exists) {
                $this->revision($current, $data['version'] ?? null);
                $before = $this->current($current);
            } else {
                if (array_key_exists('version', $data)) {
                    $this->reject('version', 'A new product draft cannot supply a revision.');
                }
                $before = null;
            }
            $kind = $data['kind'] ?? $current->kind;
            if (! in_array($kind, ['collection', 'album'], true) || ($current->exists && $kind !== $current->kind)) {
                $this->reject('kind', 'Choose collection or album when creating a draft. Its kind cannot change.');
            }
            $title = is_string($data['title'] ?? null) ? trim($data['title']) : null;
            $description = $data['description'] ?? '';
            $description = is_string($description) ? trim($description) : $description;
            $format = app(ProductDraftManifest::class);
            if (! $format->text($title, 180, true)) {
                $this->reject('title', 'Enter a title of up to 180 characters without control characters.');
            }
            if (! $format->text($description, 4000, false)) {
                $this->reject('description', 'Enter plain descriptive text of up to 4,000 characters.');
            }
            $ids = $this->ids($data['track_ids'] ?? null);
            $tracks = $this->sources($ids);
            $members = [];
            foreach ($ids as $id) {
                $track = $tracks[$id];
                if (! $format->text($track->title, 255, true) || $track->metadata_version < 0 || $track->publication_version < 0) {
                    $this->reject('track_ids', 'A selected track has invalid metadata. Review it before adding it.');
                }
                $members[] = ['track_id' => $id, 'title' => $track->title, 'metadata_version' => $track->metadata_version,
                    'publication_version' => $track->publication_version];
            }
            $manifest = $format->make($kind, $title, $description, $members);
            if ($before !== null && hash_equals($before->manifest_sha256, CanonicalJson::hash($manifest))) {
                return $current;
            }
            if (! $current->exists) {
                $current->fill(['kind' => $kind, 'title' => $title, 'version' => 0, 'created_by' => $currentActor->id])->save();
            }

            return $this->append($current, $manifest, $currentActor, $before);
        });
    }

    /** Using history makes another version; it never rewrites or silently refreshes retained metadata. */
    public function select(ProductDraft $draft, int $retainedVersionId, int $expectedVersion, User $actor): ProductDraft
    {
        return DB::transaction(function () use ($draft, $retainedVersionId, $expectedVersion, $actor) {
            $currentActor = $this->actor($actor);
            $current = $this->draft((int) $draft->getKey());
            $this->revision($current, $expectedVersion);
            $before = $this->current($current);
            $source = $current->versions()->whereKey($retainedVersionId)->lockForUpdate()->first();
            if ($source === null || $source->number > $current->version) {
                $this->reject('title', 'Choose a retained version belonging to this product draft.');
            }
            $manifest = app(ProductDraftManifest::class)->verified($source, $current->kind);
            $this->sources(array_column($manifest['members'], 'track_id'));
            if (hash_equals($before->manifest_sha256, $source->manifest_sha256)) {
                return $current;
            }

            return $this->append($current, $manifest, $currentActor, $before, $source);
        });
    }

    public function snapshot(int $id, User $actor): array
    {
        return DB::transaction(function () use ($id, $actor) {
            $this->actor($actor);
            $draft = $this->draft($id);
            $current = $this->current($draft);
            $manifest = app(ProductDraftManifest::class)->verified($current, $draft->kind);
            $history = $draft->versions()->orderByDesc('number')->lockForUpdate()->get()->map(function (ProductDraftVersion $version) use ($draft): array {
                $content = app(ProductDraftManifest::class)->verified($version, $draft->kind);

                return ['id' => (int) $version->id, 'number' => $version->number, 'title' => $content['title'],
                    'description' => $content['description'], 'members' => $content['members'], 'member_count' => count($content['members']),
                    'created_at' => $version->created_at?->toIso8601String(), 'manifest_sha256' => $version->manifest_sha256];
            })->all();

            return ['id' => (int) $draft->id, 'kind' => $draft->kind, 'version' => $draft->version,
                'title' => $manifest['title'], 'description' => $manifest['description'],
                'track_ids' => array_column($manifest['members'], 'track_id'), 'members' => $manifest['members'], 'history' => $history];
        });
    }

    public function tracks(User $actor): array
    {
        return DB::transaction(function () use ($actor) {
            $this->actor($actor);

            // Labels are advisory. Saving obtains fresh ascending source locks and checks every identity.
            return Track::query()->orderBy('title')->orderBy('id')->get(['id', 'title'])
                ->mapWithKeys(fn (Track $track) => [(int) $track->id => $track->title.' (#'.$track->id.')'])->all();
        });
    }

    private function append(ProductDraft $draft, array $manifest, User $actor, ?ProductDraftVersion $before, ?ProductDraftVersion $source = null): ProductDraft
    {
        if ($draft->version < 0 || $draft->version >= self::MAX_VERSION) {
            $this->reject('title', 'This product draft cannot accept another version.');
        }
        $version = ProductDraftVersion::create(['product_draft_id' => $draft->id, 'number' => $draft->version + 1,
            'manifest' => $manifest, 'manifest_sha256' => CanonicalJson::hash($manifest), 'source_version_id' => $source?->id,
            'created_by' => $actor->id, 'created_at' => now()]);
        foreach ($manifest['members'] as $position => $member) {
            ProductDraftMember::create(['product_draft_version_id' => $version->id, 'position' => $position + 1, ...$member]);
        }
        $draft->fill(['title' => $manifest['title'], 'version' => $version->number])->save();
        AuditEvent::record('catalog.product_draft.'.($before === null ? 'created' : ($source === null ? 'version_saved' : 'version_selected')), $draft, [
            'schema_version' => 1, 'kind' => $draft->kind, 'version' => $version->number,
            'version_id' => (int) $version->id, 'source_version_id' => $source?->id, 'member_count' => count($manifest['members']),
            'canonicalization_version' => CanonicalJson::VERSION, 'before_hash' => $before?->manifest_sha256,
            'after_hash' => $version->manifest_sha256,
        ], $actor->id);

        return $draft;
    }

    private function current(ProductDraft $draft): ProductDraftVersion
    {
        $versions = $draft->versions()->orderByDesc('number')->lockForUpdate()->get();
        $current = $versions->first();
        if ($current === null || $draft->version < 1 || $draft->version > self::MAX_VERSION || $current->number !== $draft->version
            || $versions->count() !== $draft->version || $versions->last()->number !== 1) {
            $this->reject('title', 'This product draft has inconsistent version evidence. Preserve it for investigation.');
        }
        $manifest = app(ProductDraftManifest::class)->verified($current, $draft->kind);
        if ($manifest['title'] !== $draft->title) {
            $this->reject('title', 'This product draft has inconsistent descriptive evidence. Preserve it for investigation.');
        }

        return $current;
    }

    private function sources(array $ids): array
    {
        $sorted = $ids;
        sort($sorted, SORT_NUMERIC);
        $tracks = [];
        // Individual point locks make the source lock order independent of query planner choices.
        foreach ($sorted as $id) {
            $track = Track::query()->lockForUpdate()->find($id);
            if ($track === null) {
                $this->reject('track_ids', 'A selected track is no longer available. Review the members before saving.');
            }
            $tracks[$id] = $track;
        }

        return $tracks;
    }

    private function ids(mixed $ids): array
    {
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) < 1 || count($ids) > 100) {
            $this->reject('track_ids', 'Choose between 1 and 100 tracks in the intended order.');
        }
        $ids = array_map(fn ($id) => $this->positiveInteger($id, 'track_ids'), $ids);
        if (count(array_unique($ids)) !== count($ids)) {
            $this->reject('track_ids', 'Each track may appear only once in a product draft.');
        }

        return $ids;
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if (! (is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value)))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->reject($field, 'Choose a current positive integer identity.');
        }

        return (int) $value;
    }

    private function revision(ProductDraft $draft, mixed $revision): void
    {
        if ($this->positiveInteger($revision, 'version') !== $draft->version) {
            $this->reject('title', 'This product draft changed since you opened it. Close and reopen the editor before saving.');
        }
    }

    private function draft(int $id): ProductDraft
    {
        $draft = $id > 0 ? ProductDraft::query()->lockForUpdate()->find($id) : null;
        if ($draft === null) {
            $this->reject('title', 'This product draft is no longer available.');
        }

        return $draft;
    }

    private function actor(User $actor): User
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
