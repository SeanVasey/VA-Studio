<?php

namespace App\Domain\SiteBuilder;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationRevision;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Site changes do not write catalog, commercial or customer records. */
final class SiteContent
{
    public function create(array $content, string $label, User $actor): SiteRelease
    {
        return DB::transaction(function () use ($content, $label, $actor): SiteRelease {
            $actor = $this->actor($actor);
            $content = SiteContentSchema::validate($content);
            Validator::make(['label' => $label], ['label' => ['required', 'string', 'max:120', 'not_regex:/[<>\x00-\x1F\x7F]/u']])->validate();
            $release = SiteRelease::create([
                'label' => $label, 'schema_version' => $content['schema_version'], 'content' => $content,
                'content_hash' => CanonicalJson::hash($content), 'canonicalization_version' => CanonicalJson::VERSION,
                'created_by' => $actor->id, 'created_at' => now(),
            ]);
            AuditEvent::record('site.release.created', $release, ['content_hash' => $release->content_hash, 'schema_version' => $release->schema_version], $actor->id);

            return $release;
        });
    }

    public function publish(int $releaseId, int $expectedVersion, User $actor): SitePublication
    {
        return $this->activate($releaseId, $expectedVersion, $actor, 'publish');
    }

    public function rollback(int $releaseId, int $expectedVersion, User $actor): SitePublication
    {
        return $this->activate($releaseId, $expectedVersion, $actor, 'rollback');
    }

    public function current(): array
    {
        // Capture the pointer once. Immutable snapshots and history keep this coherent if publication changes next.
        $publication = SitePublication::findOrFail(1);
        $this->verifyPointer($publication);

        return $publication->active_release_id === null
            ? SiteContentSchema::defaults()
            : $this->content(SiteRelease::findOrFail($publication->active_release_id));
    }

    public function preview(int $id, User $actor): array
    {
        $this->actor($actor);

        return $this->content(SiteRelease::findOrFail($id));
    }

    private function activate(int $releaseId, int $expectedVersion, User $actor, string $operation): SitePublication
    {
        return DB::transaction(function () use ($releaseId, $expectedVersion, $actor, $operation): SitePublication {
            // Serialise every publish/rollback on one pre-existing row. Revision prevents ABA lost updates.
            $publication = SitePublication::query()->lockForUpdate()->findOrFail(1);
            $actor = $this->actor($actor);
            $this->verifyPointer($publication);
            if ($expectedVersion < 0 || $expectedVersion !== $publication->revision || $publication->revision >= 2147483646) {
                throw ValidationException::withMessages(['publication' => 'The published site changed. Refresh the release list before publishing or rolling back.']);
            }
            $release = SiteRelease::findOrFail($releaseId);
            $this->content($release);
            if ($publication->active_release_id === $releaseId) {
                throw ValidationException::withMessages(['publication' => 'This release is already active.']);
            }
            if ($operation === 'rollback' && ! SitePublicationRevision::where('release_id', $releaseId)->exists()) {
                throw ValidationException::withMessages(['publication' => 'Rollback requires a previously published release. Publish a draft to activate it for the first time.']);
            }
            if ($publication->revision === 0) {
                // Retain the exact pre-CMS content as rollback evidence in the same first-publication transaction.
                // The actor captures this existing baseline; they are not represented as its original author.
                $baselineContent = SiteContentSchema::defaults();
                $baseline = SiteRelease::create([
                    'label' => 'Original site content', 'schema_version' => 1, 'content' => $baselineContent,
                    'content_hash' => CanonicalJson::hash($baselineContent), 'canonicalization_version' => CanonicalJson::VERSION,
                    'created_by' => $actor->id, 'created_at' => now(),
                ]);
                SitePublicationRevision::create([
                    'revision' => 0, 'release_id' => $baseline->id, 'previous_release_id' => null,
                    'operation' => 'baseline', 'content_hash' => $baseline->content_hash, 'actor_id' => $actor->id, 'created_at' => now(),
                ]);
                AuditEvent::record('site.release.baseline_retained', $baseline, [
                    'content_hash' => $baseline->content_hash, 'publication_revision' => 0,
                ], $actor->id);
            }
            $previous = $publication->active_release_id;
            $revision = $publication->revision + 1;
            SitePublicationRevision::create([
                'revision' => $revision, 'release_id' => $releaseId, 'previous_release_id' => $previous,
                'operation' => $operation, 'content_hash' => $release->content_hash, 'actor_id' => $actor->id, 'created_at' => now(),
            ]);
            $publication->update(['active_release_id' => $releaseId, 'revision' => $revision, 'updated_at' => now()]);
            AuditEvent::record('site.release.'.$operation, $release, [
                'publication_revision' => $revision, 'previous_release_id' => $previous,
                'release_id' => $releaseId, 'content_hash' => $release->content_hash,
            ], $actor->id);

            return $publication;
        });
    }

    private function actor(User $actor): User
    {
        // Do not trust an actor instance retained by an editor before role/verification withdrawal.
        $current = $actor->exists ? User::find($actor->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog');

        return $current;
    }

    private function content(SiteRelease $release): array
    {
        $content = $release->content;
        if (! in_array($release->schema_version, [1, 2], true) || $release->canonicalization_version !== CanonicalJson::VERSION
            || ! is_array($content) || ($content['schema_version'] ?? null) !== $release->schema_version
            || ! hash_equals($release->content_hash, CanonicalJson::hash($content))) {
            throw ValidationException::withMessages(['publication' => 'The retained site release failed its integrity check.']);
        }

        return SiteContentSchema::validate($content);
    }

    private function verifyPointer(SitePublication $publication): void
    {
        if ($publication->revision === 0 && $publication->active_release_id === null) {
            return;
        }
        $history = SitePublicationRevision::where('revision', $publication->revision)->first();
        if ($history === null || $history->release_id !== $publication->active_release_id
            || ! hash_equals($history->content_hash, SiteRelease::findOrFail($history->release_id)->content_hash)) {
            throw ValidationException::withMessages(['publication' => 'The retained site publication failed its integrity check.']);
        }
    }
}
