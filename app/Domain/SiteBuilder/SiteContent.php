<?php

namespace App\Domain\SiteBuilder;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationRevision;
use App\Domain\SiteBuilder\Models\SitePublicationSchedule;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/** Site changes do not write catalog, commercial or customer records. */
final class SiteContent
{
    /**
     * A schedule that the scheduler reaches later than this expires unpublished instead of surprising staff. The
     * schedule migration's transition trigger enforces the same 60 minutes; changing it needs a new migration.
     */
    public const SCHEDULE_GRACE_MINUTES = 60;

    public const SCHEDULE_MAX_DAYS = 365;

    public function create(array $content, string $label, User $actor): SiteRelease
    {
        return DB::transaction(function () use ($content, $label, $actor): SiteRelease {
            $actor = $this->actor($actor);
            // Images are pinned to the manifest of their ready row, locked, before the content (and its hash) is fixed.
            $images = app(SiteImageReferences::class);
            $content = SiteContentSchema::validate($images->pin($content));
            app(SiteRelatedTracks::class)->validateIdentities($content);
            Validator::make(['label' => $label], ['label' => ['required', 'string', 'max:120', 'not_regex:/[<>\x00-\x1F\x7F]/u']])->validate();
            $release = SiteRelease::create([
                'label' => $label, 'schema_version' => $content['schema_version'], 'content' => $content,
                'content_hash' => CanonicalJson::hash($content), 'canonicalization_version' => CanonicalJson::VERSION,
                'created_by' => $actor->id, 'created_at' => now(),
            ]);
            $images->index($release, $content);
            AuditEvent::record('site.release.created', $release, ['content_hash' => $release->content_hash, 'schema_version' => $release->schema_version]
                + (in_array($release->schema_version, [3, 4], true) ? ['images' => array_map(fn (array $reference): int => $reference['id'], SiteImageReferences::of($content))] : []), $actor->id);

            return $release;
        });
    }

    /** The expected schedule is the pending schedule the operator reviewed; null asserts none was pending. */
    public function publish(int $releaseId, int $expectedVersion, User $actor, ?int $expectedScheduleId = null): SitePublication
    {
        $this->verifyImageFiles($releaseId, $actor);

        return $this->activate($releaseId, $expectedVersion, $actor, 'publish', $expectedScheduleId);
    }

    public function rollback(int $releaseId, int $expectedVersion, User $actor, ?int $expectedScheduleId = null): SitePublication
    {
        $this->verifyImageFiles($releaseId, $actor);

        return $this->activate($releaseId, $expectedVersion, $actor, 'rollback', $expectedScheduleId);
    }

    public function current(): array
    {
        $publication = null;
        $reason = SiteContentUnavailable::MISSING;
        try {
            // History first: it is appended in the transaction that moves the pointer, so the pointer read next is never older than it.
            $published = SitePublicationRevision::query()->exists();
            // Capture the pointer once. Immutable snapshots and history keep this coherent if publication changes next.
            $publication = SitePublication::findOrFail(1);
            $reason = SiteContentUnavailable::PUBLICATION;
            $this->verifyPointer($publication, $published);
            if ($publication->active_release_id === null) {
                return SiteContentSchema::defaults();
            }
            $reason = SiteContentUnavailable::RELEASE;

            return $this->content(SiteRelease::findOrFail($publication->active_release_id));
        } catch (ValidationException|ModelNotFoundException $exception) {
            $this->reportUnavailable($publication, $reason);

            throw SiteContentUnavailable::because($reason, $exception instanceof ModelNotFoundException
                ? ['publication' => 'The published site content is missing.'] : $exception->errors());
        }
    }

    public function preview(int $id, User $actor): array
    {
        $this->actor($actor);

        return $this->content(SiteRelease::findOrFail($id));
    }

    /** Request one future activation of an exact release against the publication revision the operator reviewed. */
    public function schedule(int $releaseId, CarbonImmutable $publishAt, int $expectedVersion, User $actor): SitePublicationSchedule
    {
        return DB::transaction(function () use ($releaseId, $publishAt, $expectedVersion, $actor): SitePublicationSchedule {
            // Same lock order as every publication path: singleton pointer first, then schedule rows.
            $publication = SitePublication::query()->lockForUpdate()->findOrFail(1);
            $actor = $this->actor($actor);
            $this->verifyPointer($publication);
            $this->expectRevision($publication, $expectedVersion, 'The published site changed. Refresh the release list before scheduling a publication.');
            $release = SiteRelease::findOrFail($releaseId);
            $this->content($release);
            if ($publication->active_release_id === $releaseId) {
                throw ValidationException::withMessages(['publication' => 'This release is already active.']);
            }
            $now = now()->toImmutable()->utc()->startOfSecond();
            $publishAt = $publishAt->utc();
            if ($publishAt->second !== 0 || $publishAt->microsecond !== 0) {
                throw ValidationException::withMessages(['publish_at' => 'Choose a whole minute (UTC).']);
            }
            if ($publishAt->lessThan($now->addMinute())) {
                throw ValidationException::withMessages(['publish_at' => 'Choose a time at least one minute from now (UTC).']);
            }
            if ($publishAt->greaterThan($now->addDays(self::SCHEDULE_MAX_DAYS))) {
                throw ValidationException::withMessages(['publish_at' => 'Choose a time within '.self::SCHEDULE_MAX_DAYS.' days.']);
            }
            if (SitePublicationSchedule::query()->where('state', 'pending')->lockForUpdate()->first() !== null) {
                throw ValidationException::withMessages(['publication' => 'Another release is already scheduled. Cancel that scheduled publication first.']);
            }
            $schedule = SitePublicationSchedule::create([
                'release_id' => $release->id, 'release_content_hash' => $release->content_hash, 'publish_at' => $publishAt,
                'expected_revision' => $publication->revision, 'created_by' => $actor->id, 'created_at' => $now,
                'state' => 'pending', 'pending_slot' => 1,
            ]);
            AuditEvent::record('site.schedule.created', $schedule, [
                'release_id' => $release->id, 'content_hash' => $release->content_hash,
                'publish_at' => $publishAt->toIso8601ZuluString(), 'expected_revision' => $publication->revision,
            ], $actor->id);

            return $schedule;
        });
    }

    public function cancelSchedule(int $scheduleId, User $actor): SitePublicationSchedule
    {
        return DB::transaction(function () use ($scheduleId, $actor): SitePublicationSchedule {
            SitePublication::query()->lockForUpdate()->findOrFail(1);
            $actor = $this->actor($actor);
            $schedule = SitePublicationSchedule::query()->lockForUpdate()->findOrFail($scheduleId);
            if ($schedule->state !== 'pending') {
                throw ValidationException::withMessages(['publication' => 'This scheduled publication was already resolved. Refresh the release list.']);
            }
            $this->resolveSchedule($schedule, 'cancelled', 'cancelled', $actor);

            return $schedule;
        });
    }

    /**
     * Trusted scheduler entry point with no public route. It reads the application clock, activates at most one due
     * schedule through the same guarded path as staff publication, and commits any fail-closed outcome.
     *
     * @return array{schedule_id: int, outcome: string, publication_revision?: int}|null
     */
    public function runDueSchedule(): ?array
    {
        foreach (DB::getConnections() as $connection) {
            // An outer transaction could have fixed an older REPEATABLE READ snapshot before the publication lock.
            if ($connection->transactionLevel() !== 0) {
                throw new LogicException('Scheduled publication runs outside database transactions.');
            }
        }
        if (auth()->check()) {
            // Audit attributes a missing actor to the signed-in user; scheduler decisions belong to no person.
            throw new LogicException('Scheduled publication runs without a signed-in user.');
        }
        // Unlocked probe only; the decision is repeated under the publication and schedule locks.
        $candidate = SitePublicationSchedule::query()->where('state', 'pending')
            ->where('publish_at', '<=', now()->toImmutable()->utc()->startOfSecond())->first(['id', 'release_id']);
        if ($candidate === null) {
            return null;
        }
        // Image files are hashed before the lock is taken. A schedule's release never changes, so this is the release decided below.
        $filesIntact = $this->imageFilesIntact($candidate->release_id);
        $candidate = $candidate->id;

        return DB::transaction(function () use ($candidate, $filesIntact): array {
            $publication = SitePublication::query()->lockForUpdate()->findOrFail(1);
            $schedule = SitePublicationSchedule::query()->lockForUpdate()->findOrFail($candidate);
            // Read the clock after any lock wait: lateness is judged at the moment of the decision.
            $now = now()->toImmutable()->utc()->startOfSecond();
            if ($schedule->state !== 'pending' || $schedule->publish_at->greaterThan($now)) {
                return ['schedule_id' => $schedule->id, 'outcome' => $schedule->state === 'pending' ? 'not_due' : 'already_resolved'];
            }
            if ($now->greaterThan($schedule->publish_at->addMinutes(self::SCHEDULE_GRACE_MINUTES))) {
                return $this->resolveSchedule($schedule, 'expired', 'grace_expired');
            }
            // The scheduling operator must still hold the role, with MFA enrolled where the admin panel requires it;
            // a withdrawn account cannot publish later.
            $actor = User::find($schedule->created_by);
            if ($actor === null || ! Gate::forUser($actor)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($actor)) {
                return $this->resolveSchedule($schedule, 'failed', 'actor_unauthorized');
            }
            $release = SiteRelease::find($schedule->release_id);
            try {
                $this->verifyPointer($publication);
                if ($release === null || ! hash_equals($schedule->release_content_hash, $release->content_hash) || ! $filesIntact) {
                    throw ValidationException::withMessages(['publication' => 'The scheduled release changed.']);
                }
                $this->content($release);
            } catch (ValidationException) {
                return $this->resolveSchedule($schedule, 'failed', 'integrity');
            }
            // Staff changes supersede schedules, so a moved pointer means code that predates scheduling published meanwhile.
            if ($publication->revision !== $schedule->expected_revision || $publication->revision >= 2147483646
                || $publication->active_release_id === $release->id) {
                return $this->resolveSchedule($schedule, 'failed', 'stale_revision');
            }
            $this->applyActivation($publication, $release, $actor, 'publish', ['schedule_id' => $schedule->id, 'trigger' => 'schedule']);
            $schedule->update([
                'state' => 'published', 'pending_slot' => null, 'outcome' => 'published', 'resolved_at' => $now,
                'publication_revision' => $publication->revision,
            ]);
            AuditEvent::record('site.schedule.published', $schedule, [
                'release_id' => $release->id, 'publication_revision' => $publication->revision,
                'publish_at' => $schedule->publish_at->toIso8601ZuluString(),
            ], $actor->id);

            return ['schedule_id' => $schedule->id, 'outcome' => 'published', 'publication_revision' => $publication->revision];
        });
    }

    public function pendingSchedule(): ?SitePublicationSchedule
    {
        return SitePublicationSchedule::query()->where('state', 'pending')->first();
    }

    /** @return Collection<int, SitePublicationSchedule> */
    public function recentSchedules(int $limit = 20): Collection
    {
        return SitePublicationSchedule::query()->orderByDesc('id')->limit(max(1, min($limit, 50)))->get();
    }

    private function activate(int $releaseId, int $expectedVersion, User $actor, string $operation, ?int $expectedScheduleId): SitePublication
    {
        return DB::transaction(function () use ($releaseId, $expectedVersion, $actor, $operation, $expectedScheduleId): SitePublication {
            // Serialise every publish/rollback on one pre-existing row. Revision prevents ABA lost updates.
            $publication = SitePublication::query()->lockForUpdate()->findOrFail(1);
            $actor = $this->actor($actor);
            $this->verifyPointer($publication);
            $this->expectRevision($publication, $expectedVersion);
            // Scheduling does not move the pointer, so the reviewed pending schedule is a separate expectation.
            $pending = SitePublicationSchedule::query()->where('state', 'pending')->lockForUpdate()->first();
            if ($pending?->id !== $expectedScheduleId) {
                throw ValidationException::withMessages(['publication' => 'The scheduled publication changed after you opened this confirmation. Refresh the release list before publishing or rolling back.']);
            }
            $release = SiteRelease::findOrFail($releaseId);
            $this->content($release);
            if ($publication->active_release_id === $releaseId) {
                throw ValidationException::withMessages(['publication' => 'This release is already active.']);
            }
            if ($operation === 'rollback' && ! SitePublicationRevision::where('release_id', $releaseId)->exists()) {
                throw ValidationException::withMessages(['publication' => 'Rollback requires a previously published release. Publish a draft to activate it for the first time.']);
            }
            $this->applyActivation($publication, $release, $actor, $operation);
            if ($pending !== null) {
                // The schedule was reviewed against the site this operator has just replaced; never apply it later.
                $pending->publication_revision = $publication->revision;
                $this->resolveSchedule($pending, 'superseded', 'manual_'.$operation, $actor, ['publication_revision' => $publication->revision]);
            }

            return $publication;
        });
    }

    /** Caller holds the singleton lock and has verified the actor, pointer, revision and release. */
    private function applyActivation(SitePublication $publication, SiteRelease $release, User $actor, string $operation, array $auditContext = []): void
    {
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
            ] + $auditContext, $actor->id);
        }
        $previous = $publication->active_release_id;
        $revision = $publication->revision + 1;
        SitePublicationRevision::create([
            'revision' => $revision, 'release_id' => $release->id, 'previous_release_id' => $previous,
            'operation' => $operation, 'content_hash' => $release->content_hash, 'actor_id' => $actor->id, 'created_at' => now(),
        ]);
        $publication->update(['active_release_id' => $release->id, 'revision' => $revision, 'updated_at' => now()]);
        AuditEvent::record('site.release.'.$operation, $release, [
            'publication_revision' => $revision, 'previous_release_id' => $previous,
            'release_id' => $release->id, 'content_hash' => $release->content_hash,
        ] + $auditContext, $actor->id);
    }

    /** Caller holds the singleton and schedule locks. A missing resolver means the scheduler decided, not a person. */
    private function resolveSchedule(SitePublicationSchedule $schedule, string $state, string $outcome, ?User $resolver = null, array $context = []): array
    {
        $schedule->update([
            'state' => $state, 'pending_slot' => null, 'outcome' => $outcome,
            'resolved_at' => now()->toImmutable()->utc()->startOfSecond(), 'resolved_by' => $resolver?->id,
        ]);
        AuditEvent::record('site.schedule.'.$state, $schedule, [
            'release_id' => $schedule->release_id, 'outcome' => $outcome, 'publish_at' => $schedule->publish_at->toIso8601ZuluString(),
        ] + $context, $resolver?->id);

        return ['schedule_id' => $schedule->id, 'outcome' => $outcome];
    }

    private function expectRevision(SitePublication $publication, int $expectedVersion,
        string $message = 'The published site changed. Refresh the release list before publishing or rolling back.'): void
    {
        if ($expectedVersion < 0 || $expectedVersion !== $publication->revision || $publication->revision >= 2147483646) {
            throw ValidationException::withMessages(['publication' => $message]);
        }
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

    /** Every request fails closed; the operator log records the outage at most once a minute while the cache works, and on every request when it does not. */
    private function reportUnavailable(?SitePublication $publication, string $reason): void
    {
        try {
            try {
                $first = Cache::add('site-content:unavailable-reported', true, 60);
            } catch (Throwable) {
                // A failing cache only removes the once-a-minute limit; it must not silence the report.
                $first = true;
            }
            if ($first) {
                Log::critical('Published site content is unavailable.', [
                    'reason' => $reason, 'revision' => $publication?->revision, 'release_id' => $publication?->active_release_id,
                ]);
            }
        } catch (Throwable) {
            // Reporting must never replace the fail-closed response.
        }
    }

    /** Staff authority first, then every stored image file, all before the publication lock is taken. */
    private function verifyImageFiles(int $releaseId, User $actor): void
    {
        $this->actor($actor);
        $release = SiteRelease::find($releaseId);
        if (in_array($release?->schema_version, [3, 4], true) && is_array($release->content)) {
            app(SiteImageReferences::class)->verifyFiles($release->content);
        }
    }

    private function imageFilesIntact(int $releaseId): bool
    {
        $release = SiteRelease::find($releaseId);
        if (! in_array($release?->schema_version, [3, 4], true) || ! is_array($release->content)) {
            return true;
        }
        try {
            app(SiteImageReferences::class)->verifyFiles($release->content);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    private function content(SiteRelease $release): array
    {
        $content = $release->content;
        if (! in_array($release->schema_version, [1, 2, 3, 4], true) || $release->canonicalization_version !== CanonicalJson::VERSION
            || ! is_array($content) || ($content['schema_version'] ?? null) !== $release->schema_version
            || ! hash_equals($release->content_hash, CanonicalJson::hash($content))) {
            throw ValidationException::withMessages(['publication' => 'The retained site release failed its integrity check.']);
        }
        $content = SiteContentSchema::validate($content);
        if (in_array($release->schema_version, [3, 4], true)) {
            app(SiteImageReferences::class)->verify($release, $content);
        }

        return $content;
    }

    /**
     * @param  bool|null  $published  Whether publication history existed before the pointer was read. Callers holding the publication
     *                                lock omit it; an unlocked reader must read history first so a first publication in between
     *                                cannot pair the old pointer with the new history.
     */
    private function verifyPointer(SitePublication $publication, ?bool $published = null): void
    {
        // Only a site that has never been published may show code defaults. A seed row re-created after publication fails instead.
        if ($publication->revision === 0 && $publication->active_release_id === null && ! ($published ?? SitePublicationRevision::query()->exists())) {
            return;
        }
        $history = SitePublicationRevision::where('revision', $publication->revision)->first();
        // A release missing behind its history (a restore with foreign-key checks off) is the same integrity failure, not a 404.
        $release = $history === null ? null : SiteRelease::find($history->release_id);
        if ($history === null || $release === null || $history->release_id !== $publication->active_release_id
            || ! hash_equals($history->content_hash, $release->content_hash)) {
            throw ValidationException::withMessages(['publication' => 'The retained site publication failed its integrity check.']);
        }
    }
}
