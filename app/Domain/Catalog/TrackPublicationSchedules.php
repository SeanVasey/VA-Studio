<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\Models\TrackPublicationSchedule;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Scheduled track publication (FP-032, DD-39, SQ-15).
 *
 * Staff enter an America/Chicago wall time; the UTC instant is what is stored and compared. At that instant the
 * scheduler runs the same guards as a manual publication against current evidence, private files included
 * (PublishTrack::publishScheduled). A schedule whose guards fail is not published and not cancelled: it stays pending, the
 * reason is kept on the row and in the audit trail, and the scheduled command exits non-zero so the operational dashboard's
 * scheduled-task failure signal shows it. A schedule resolves exactly once, under the actor, track and schedule locks.
 */
final class TrackPublicationSchedules
{
    public const ZONE = 'America/Chicago';

    /** A typo such as 2062 for 2026 must not silently park a track for decades. */
    public const MAX_DAYS_AHEAD = 366;

    /** A blocked schedule is retried at most this often, because the guards hash private files. */
    public const RETRY_MINUTES = 5;

    private const MAX_VERSION = 2147483647;

    /**
     * Converts staff-entered America/Chicago wall time (`YYYY-MM-DDTHH:MM`) to the UTC instant to store. A local time that
     * does not exist (the spring-forward gap) or occurs twice (the fall-back hour) is refused rather than guessed.
     */
    public static function utcFromChicago(string $local): CarbonImmutable
    {
        $wall = null;
        if (preg_match('/\A(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})\z/D', trim($local), $parts) === 1) {
            $wall = $parts[1].' '.$parts[2];
        }
        $zone = new DateTimeZone(self::ZONE);
        $at = null;
        if ($wall !== null) {
            try {
                $at = CarbonImmutable::createFromFormat('!Y-m-d H:i', $wall, $zone) ?: null;
            } catch (Throwable) {
                $at = null;
            }
        }
        if ($at === null || $at->format('Y-m-d H:i') !== $wall) {
            // Either malformed, an impossible date, or a wall time inside the skipped spring-forward hour.
            throw ValidationException::withMessages(['publish_at' => 'Enter a date and time that exists in Central time, as YYYY-MM-DD HH:MM.']);
        }
        $utc = $at->utc();
        foreach ([-3600, 3600] as $shift) {
            if ($utc->addSeconds($shift)->setTimezone($zone)->format('Y-m-d H:i') === $wall) {
                throw ValidationException::withMessages(['publish_at' => 'That Central time happens twice when clocks go back. Choose a time before or after that hour.']);
            }
        }

        return $utc;
    }

    /** The stored UTC instant as Central wall time for staff, e.g. "2026-12-01 09:30 CST". */
    public static function describe(CarbonImmutable $utc): string
    {
        return $utc->setTimezone(self::ZONE)->format('Y-m-d H:i T');
    }

    /**
     * Schedules (or reschedules) a track. The versions come from the editor that opened the confirmation; any change since
     * then, metadata or publication, refuses the request. The guards are not required to pass now: they must pass at the
     * instant, and the caller may show {@see advisories()} so staff can fix problems early.
     */
    public function schedule(Track $track, User $actor, string $local, string $target, int $metadataVersion, int $publicationVersion): TrackPublicationSchedule
    {
        if (! in_array($target, TrackVisibility::TARGETS, true)) {
            $this->reject('target', 'Choose published or unlisted as the state to publish into.');
        }
        $utc = self::utcFromChicago($local);
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        if ($utc->lessThan($now->addMinute())) {
            $this->reject('publish_at', 'Choose a time at least one minute from now.');
        }
        if ($utc->greaterThan($now->addDays(self::MAX_DAYS_AHEAD))) {
            $this->reject('publish_at', 'Choose a time within the next '.self::MAX_DAYS_AHEAD.' days.');
        }
        $entry = substr($utc->setTimezone(self::ZONE)->format('Y-m-d H:i'), 0, 16);

        return DB::transaction(function () use ($track, $actor, $utc, $target, $metadataVersion, $publicationVersion, $entry): TrackPublicationSchedule {
            $current = $this->lockActor($actor);
            $locked = Track::query()->lockForUpdate()->findOrFail($track->getKey());
            $this->requireFence($locked, $metadataVersion, $publicationVersion);
            if (! in_array($locked->status, [TrackVisibility::DRAFT, TrackVisibility::SCHEDULED], true)) {
                $this->reject('publication', 'Only a draft track can be scheduled. Return it to draft first.');
            }
            // Read the clock again after any lock wait so the trigger's "in the future" rule and this decision agree.
            $created = CarbonImmutable::now('UTC')->startOfSecond();
            if ($utc->lessThan($created->addMinute())) {
                $this->reject('publish_at', 'Choose a time at least one minute from now.');
            }
            $previous = null;
            if ($locked->status === TrackVisibility::SCHEDULED) {
                $previous = TrackPublicationSchedule::query()->where('track_id', $locked->id)->where('state', 'pending')->lockForUpdate()->first();
                if ($previous === null) {
                    throw new LogicException('A scheduled track has no pending schedule.');
                }
                $previous->update(['state' => 'superseded', 'pending_slot' => null, 'outcome' => 'rescheduled',
                    'resolved_at' => $created, 'resolved_by' => $current->id]);
            }
            $schedule = TrackPublicationSchedule::create([
                'track_id' => $locked->id, 'target_status' => $target, 'publish_at' => $utc, 'entry_zone' => self::ZONE,
                'entry_local' => $entry, 'created_by' => $current->id, 'created_at' => $created, 'state' => 'pending', 'pending_slot' => 1,
            ]);
            $before = $locked->publication_version;
            $locked->update(['status' => TrackVisibility::SCHEDULED, 'publication_version' => $before + 1]);
            AuditEvent::record('catalog.track.scheduled', $locked, [
                'schema_version' => 1, 'schedule_id' => $schedule->id, 'target_status' => $target,
                'publish_at' => $utc->toIso8601ZuluString(), 'entry_zone' => self::ZONE, 'entry_local' => $entry,
                'replaced_schedule_id' => $previous?->id, 'metadata_version' => $locked->metadata_version,
                'previous_publication_version' => $before, 'publication_version' => $locked->publication_version,
            ], $current->id);

            return $schedule;
        });
    }

    /** Withdraws the pending schedule and returns the track to draft. */
    public function cancel(Track $track, User $actor, int $metadataVersion, int $publicationVersion): Track
    {
        return DB::transaction(function () use ($track, $actor, $metadataVersion, $publicationVersion): Track {
            $current = $this->lockActor($actor);
            $locked = Track::query()->lockForUpdate()->findOrFail($track->getKey());
            $this->requireFence($locked, $metadataVersion, $publicationVersion);
            if ($locked->status !== TrackVisibility::SCHEDULED) {
                $this->reject('publication', 'This track is not scheduled.');
            }
            $schedule = TrackPublicationSchedule::query()->where('track_id', $locked->id)->where('state', 'pending')->lockForUpdate()->first();
            if ($schedule === null) {
                throw new LogicException('A scheduled track has no pending schedule.');
            }
            $schedule->update(['state' => 'cancelled', 'pending_slot' => null, 'outcome' => 'cancelled',
                'resolved_at' => CarbonImmutable::now('UTC')->startOfSecond(), 'resolved_by' => $current->id]);
            $before = $locked->publication_version;
            $locked->update(['status' => TrackVisibility::DRAFT, 'publication_version' => $before + 1]);
            AuditEvent::record('catalog.track.schedule_cancelled', $locked, [
                'schema_version' => 1, 'schedule_id' => $schedule->id, 'publish_at' => $schedule->publish_at->toIso8601ZuluString(),
                'metadata_version' => $locked->metadata_version, 'previous_publication_version' => $before,
                'publication_version' => $locked->publication_version,
            ], $current->id);

            return $locked;
        });
    }

    /** What would block publication right now; informational only, never a reason to refuse a schedule. */
    public function advisories(Track $track): array
    {
        return app(PublicationReadiness::class)->blockers($track);
    }

    /**
     * Scheduler entry point: activates every due schedule that is not waiting out its retry interval. Decisions come from
     * stored state and the clock, never from input.
     *
     * @return list<array{schedule_id: int, track_id: int, outcome: string, reason?: string}> outcome is published, blocked, error, not_due or already_resolved
     */
    public function runDue(int $limit = 25): array
    {
        foreach (DB::getConnections() as $connection) {
            // An outer transaction could have fixed an older REPEATABLE READ snapshot before the locks.
            if ($connection->transactionLevel() !== 0) {
                throw new LogicException('Scheduled track publication runs outside database transactions.');
            }
        }
        if (auth()->check()) {
            // Audit attributes a missing actor to the signed-in user; scheduler decisions belong to no person.
            throw new LogicException('Scheduled track publication runs without a signed-in user.');
        }
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $due = TrackPublicationSchedule::query()->where('state', 'pending')->where('publish_at', '<=', $now)
            ->orderBy('publish_at')->orderBy('id')->limit(max(1, $limit))->get(['id', 'track_id', 'created_by', 'last_attempt_at', 'last_failure_reason']);
        $results = [];
        foreach ($due as $probe) {
            if ($probe->last_attempt_at !== null && $probe->last_attempt_at->greaterThan($now->subMinutes(self::RETRY_MINUTES))) {
                // Waiting out the retry interval: still overdue, so the run keeps reporting it rather than flickering healthy.
                $results[] = ['schedule_id' => (int) $probe->id, 'track_id' => (int) $probe->track_id, 'outcome' => 'blocked',
                    'reason' => (string) $probe->last_failure_reason];

                continue;
            }
            try {
                $results[] = $this->activate($probe);
            } catch (Throwable $exception) {
                // One schedule's unexpected failure must not starve the ones behind it. The transaction rolled back, so it
                // stays pending and the next run retries; the private log keeps the details and the output only the class.
                try {
                    report($exception);
                } catch (Throwable) {
                    // A failing log must not replace the class-only result below.
                }
                $results[] = ['schedule_id' => (int) $probe->id, 'track_id' => (int) $probe->track_id, 'outcome' => 'error', 'reason' => class_basename($exception)];
            }
        }

        return $results;
    }

    private function activate(TrackPublicationSchedule $probe): array
    {
        try {
            return DB::transaction(function () use ($probe): array {
                // Same order as manual publication: actor, track, then the schedule row.
                $actor = User::query()->lockForUpdate()->find($probe->created_by);
                $track = Track::query()->lockForUpdate()->findOrFail($probe->track_id);
                $schedule = TrackPublicationSchedule::query()->lockForUpdate()->findOrFail($probe->id);
                // Lateness and dueness are judged at the moment of the decision, after any lock wait.
                $now = CarbonImmutable::now('UTC')->startOfSecond();
                $result = ['schedule_id' => (int) $schedule->id, 'track_id' => (int) $track->id];
                if ($schedule->state !== 'pending') {
                    return $result + ['outcome' => 'already_resolved'];
                }
                if ($schedule->publish_at->greaterThan($now)) {
                    return $result + ['outcome' => 'not_due'];
                }
                // The scheduling operator must still hold the role with MFA; a withdrawn account cannot publish later.
                if ($actor === null) {
                    throw ValidationException::withMessages(['publication' => 'The operator who scheduled this track no longer exists. Reschedule it.']);
                }
                try {
                    Gate::forUser($actor)->authorize('administer-catalog', [true]);
                    $mfa = AdminMultiFactor::satisfiedBy($actor, lockForUpdate: true);
                } catch (AuthorizationException) {
                    $mfa = false;
                }
                if (! $mfa) {
                    throw ValidationException::withMessages(['publication' => 'The operator who scheduled this track can no longer publish. Reschedule it.']);
                }
                app(PublishTrack::class)->publishScheduled($track, $actor, $schedule->target_status, function (int $version) use ($schedule, $now): void {
                    $schedule->update(['state' => 'published', 'pending_slot' => null, 'outcome' => 'published',
                        'resolved_at' => $now, 'publication_version' => $version]);
                });

                return $result + ['outcome' => 'published'];
            });
        } catch (ValidationException $exception) {
            return $this->recordBlocked($probe, implode(' ', array_merge(...array_values($exception->errors()))));
        }
    }

    /** Keeps the schedule pending and records why, once per distinct reason in the audit trail. */
    private function recordBlocked(TrackPublicationSchedule $probe, string $reason): array
    {
        $reason = mb_substr($reason, 0, 1000);

        return DB::transaction(function () use ($probe, $reason): array {
            $schedule = TrackPublicationSchedule::query()->lockForUpdate()->findOrFail($probe->id);
            $result = ['schedule_id' => (int) $schedule->id, 'track_id' => (int) $schedule->track_id];
            if ($schedule->state !== 'pending') {
                return $result + ['outcome' => 'already_resolved'];
            }
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $first = $schedule->last_failure_reason !== $reason;
            $schedule->update(['attempts' => $schedule->attempts + 1, 'last_attempt_at' => $now, 'last_failure_at' => $now, 'last_failure_reason' => $reason]);
            if ($first) {
                AuditEvent::recordAttributed('catalog.track.schedule_blocked', $schedule, [
                    'schema_version' => 1, 'track_id' => $schedule->track_id, 'target_status' => $schedule->target_status,
                    'publish_at' => $schedule->publish_at->toIso8601ZuluString(), 'attempts' => $schedule->attempts, 'reason' => $reason,
                ], null);
            }

            return $result + ['outcome' => 'blocked', 'reason' => $reason];
        });
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

    private function requireFence(Track $locked, int $metadataVersion, int $publicationVersion): void
    {
        if ($locked->metadata_version !== $metadataVersion || $locked->publication_version !== $publicationVersion) {
            $this->reject('publication', 'This track changed since you opened it. Close and reopen the confirmation, then try again.');
        }
        if ($locked->publication_version >= self::MAX_VERSION) {
            $this->reject('publication', 'This track cannot accept another publication revision.');
        }
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
