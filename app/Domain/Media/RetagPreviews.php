<?php

namespace App\Domain\Media;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\TrackVisibility;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\Environment\TestEnvironment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Re-runs processing for draft tracks whose current tagged preview was mixed with a different seller tag than the one now
 * configured (`vasey:media-retag-previews`).
 *
 * `VerifiedMedia` judges a preview against the profile of the run that made it, so a preview made under an earlier tag
 * (on staging, the synthetic chime) stays valid after `MEDIA_TAG_PATH` / `MEDIA_TAG_SHA256` change. The pipeline already
 * handles this case: the tag is part of the processing fingerprint, so a master source processed again under the new
 * configuration gets a NEW run (a staff "Process / retry" does exactly that). This class only finds the stale tracks and
 * calls `QueueMediaProcessing` for each, which means validation, malware scans, the seller-tag checks, the claim lease and
 * the unique (source, fingerprint) run all apply unchanged. Nothing is copied, spliced or rewritten: old derivatives stay as
 * immutable evidence and simply stop being the track's latest preview.
 *
 * Only `draft` tracks are retagged. A live track (published or unlisted) is the existing "unpublish before replacing media"
 * rule; scheduled and private tracks are skipped as well, because the processor refreshes a track's duration and waveform
 * only while it is a draft. Skipped tracks are reported, never altered.
 */
final class RetagPreviews
{
    /** Where the plan puts a track whose preview is stale. */
    public const NEW = 'new';

    public const RETRY = 'retry';

    public const IN_FLIGHT = 'in_flight';

    /**
     * Local, testing or staging only, and only when the configured and the running environment agree. Production is
     * refused deliberately: it never held the synthetic tag, its tracks are live or about to be, and a later tag change
     * there is a per-track decision (unpublish, reprocess with the staff action, re-listen, republish) for Sean, not a batch.
     */
    public function admit(): void
    {
        $configured = config('app.env');
        if ($configured === 'production' || app()->environment('production') || app()->isProduction()) {
            throw new RetagPreviewsRefusal('Retagging previews in bulk is refused in production. Production never held the staging tag; if its approved tag ever changes, unpublish the track and use Media → Process / retry.');
        }
        if (! is_string($configured) || $configured !== app()->environment()) {
            throw new RetagPreviewsRefusal('The configured environment (APP_ENV) and the running environment differ, for example through --env; retagging previews refuses an ambiguous environment.');
        }
        if (! TestEnvironment::admitsTestCommerce()) {
            throw new RetagPreviewsRefusal('Retagging previews runs only when APP_ENV is local, testing or staging.');
        }
    }

    /** An existing staff account with catalog authority that satisfies the admin panel's MFA rule as it stands here. */
    public function actor(mixed $email): User
    {
        if (! is_string($email) || $email === '') {
            throw new RetagPreviewsRefusal('Give --actor=<email of an existing staff account>.');
        }
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            throw new RetagPreviewsRefusal('No staff account has that --actor email.');
        }
        if (! Gate::forUser($user)->allows('administer-catalog')) {
            throw new RetagPreviewsRefusal('The --actor account lacks catalog authority (an administrator with a verified email).');
        }
        if (! AdminMultiFactor::satisfiedBy($user)) {
            throw new RetagPreviewsRefusal("The --actor account does not satisfy the admin panel's MFA rule here. Sign in at /admin as that account and enroll the authenticator app first.");
        }

        return $user;
    }

    /**
     * The configured tag's SHA-256, once the configuration is usable: set, well formed, a readable regular private file
     * whose bytes hash to it, and not the synthetic staging placeholder (refused here in every environment, since the
     * point of a retag is to leave it). The processor still validates and scans the tag on every run.
     */
    public function configuredTag(): string
    {
        $path = config('media.tag_path');
        $sha = config('media.tag_sha256');
        if (! is_string($path) || $path === '' || ! is_string($sha) || ! preg_match('/\A[a-f0-9]{64}\z/D', $sha)) {
            throw new RetagPreviewsRefusal('MEDIA_TAG_PATH and a 64-character lowercase MEDIA_TAG_SHA256 must be configured first.');
        }
        try {
            $file = app(PrivateMediaFiles::class)->resolve($path);
        } catch (MediaFailure) {
            throw new RetagPreviewsRefusal('The configured seller tag file is missing or not a safe private file.');
        }
        $size = @filesize($file);
        $hash = is_int($size) && $size >= 12 && $size <= 16777216 ? @hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new RetagPreviewsRefusal('The configured seller tag file is unreadable or outside the allowed size.');
        }
        if (! hash_equals($sha, $hash)) {
            throw new RetagPreviewsRefusal('The configured seller tag does not match MEDIA_TAG_SHA256.');
        }
        if (StagingSellerTag::pathCarriesMarker($path) || StagingSellerTag::fileCarriesMarker($file)) {
            throw new RetagPreviewsRefusal('The configured seller tag is the synthetic staging placeholder. Install the approved seller tag first.');
        }

        return $sha;
    }

    /**
     * Read-only classification of every track's current tagged preview against the configured tag.
     *
     * @return array{tag_sha256: string, current: int, stale: list<array{track_id: int, slug: string, source_id: int, preview_id: int, state: string}>, skipped: array<string, list<int>>}
     */
    public function plan(string $tagSha256): array
    {
        $plan = ['tag_sha256' => $tagSha256, 'current' => 0, 'stale' => [], 'skipped' => ['live' => [], 'not_draft' => [], 'unverified' => [], 'unresolved' => [], 'superseded' => []]];
        $fingerprint = app(MediaProfile::class)->fingerprint(app(MediaProfile::class)->current('master_wav'));
        $latest = MediaAsset::query()->selectRaw('max(id)')->where('role', 'preview_tagged')->where('status', 'ready')->groupBy('track_id');
        MediaAsset::query()->whereIn('id', $latest)->orderBy('id')->each(function (MediaAsset $preview) use (&$plan, $tagSha256, $fingerprint): void {
            $track = Track::query()->find($preview->track_id);
            if ($track === null) {
                return;
            }
            // Database evidence only: no file is opened. A preview without intact evidence is a different problem, reported not repaired.
            if (app(VerifiedMedia::class)->evidence($preview) === null) {
                $plan['skipped']['unverified'][] = $track->id;

                return;
            }
            if (hash_equals((string) ($preview->technical_metadata['tag_sha256'] ?? ''), $tagSha256)) {
                $plan['current']++;

                return;
            }
            if (TrackVisibility::isLive($track->status)) {
                $plan['skipped']['live'][] = $track->id;

                return;
            }
            if ($track->status !== TrackVisibility::DRAFT) {
                $plan['skipped']['not_draft'][] = $track->id;

                return;
            }
            $source = MediaAsset::query()->find($preview->parent_asset_id);
            if ($source === null || $source->role !== 'master_wav' || $source->status !== 'processed' || $source->track_id !== $track->id) {
                $plan['skipped']['unverified'][] = $track->id;

                return;
            }
            // A newer master upload means the staff already replaced this recording: reprocessing the old one would make it the newest preview again.
            if ($this->newerMasterSourceExists($track->id, $source->id)) {
                $plan['skipped']['superseded'][] = $track->id;

                return;
            }
            $run = MediaProcessingRun::query()->where('source_asset_id', $source->id)->where('profile_fingerprint', $fingerprint)->first();
            $state = self::NEW;
            if ($run !== null) {
                $lease = now()->subSeconds((int) config('media.claim_lease_seconds'));
                if ($run->status === 'completed') {
                    $plan['skipped']['unresolved'][] = $track->id;

                    return;
                }
                if ($run->status === 'processing' ? $run->started_at?->gt($lease) : ($run->status === 'queued' && $run->updated_at?->gt($lease))) {
                    $state = self::IN_FLIGHT;
                } else {
                    $state = $run->status === 'failed' ? self::RETRY : self::NEW;
                }
            }
            $plan['stale'][] = ['track_id' => $track->id, 'slug' => (string) $track->slug, 'source_id' => $source->id, 'preview_id' => $preview->id, 'state' => $state];
        });

        return $plan;
    }

    /**
     * Queues a processing run for each stale draft track that has none in flight, through the unchanged
     * `QueueMediaProcessing` (staff authority re-read under lock, live-track refusal, one run per source and profile).
     * Repeating it queues nothing twice: a finished track is no longer stale and an in-flight run is left alone.
     *
     * Each entry is decided again inside its own transaction, under the locks `QueueMediaProcessing` takes (staff user,
     * source, track), because the plan was read earlier: a track that left draft, whose newest preview changed or that
     * received a newer master upload since is skipped with a reason, never reprocessed. A summary audit event is written
     * even when the run stops early (`stopped`), so what was already queued stays on record.
     *
     * @return array{queued: list<int>, in_flight: list<int>, refused: list<int>, skipped: array<int, string>}
     */
    public function queue(User $actor, array $plan): array
    {
        $result = ['queued' => [], 'in_flight' => [], 'refused' => [], 'skipped' => []];
        $finished = false;
        try {
            foreach ($plan['stale'] as $entry) {
                if ($entry['state'] === self::IN_FLIGHT) {
                    $result['in_flight'][] = $entry['track_id'];

                    continue;
                }
                try {
                    $outcome = $this->queueEntry($actor, $entry);
                } catch (AuthorizationException $denied) {
                    throw $denied; // Staff authority lost mid-run: stop, never continue under another identity.
                } catch (ValidationException|MediaFailure) {
                    $result['refused'][] = $entry['track_id'];

                    continue;
                }
                if (isset($outcome['run'])) {
                    $result['queued'][] = $outcome['run'];
                } else {
                    $result['skipped'][$entry['track_id']] = $outcome['skip'];
                }
            }
            $finished = true;
        } finally {
            AuditEvent::record('media.retag_previews.requested', $actor, [
                'tag_sha256' => $plan['tag_sha256'], 'queued_run_ids' => $result['queued'], 'in_flight_track_ids' => $result['in_flight'],
                'refused_track_ids' => $result['refused'], 'skipped_live_track_ids' => $plan['skipped']['live'],
                'skipped_superseded_track_ids' => $plan['skipped']['superseded'], 'skipped_after_plan' => $result['skipped'],
                'stopped' => ! $finished,
            ], $actor->id);
        }

        return $result;
    }

    /** @return array{run: int}|array{skip: string} */
    private function queueEntry(User $actor, array $entry): array
    {
        return DB::transaction(function () use ($actor, $entry): array {
            // Same order as QueueMediaProcessing (staff user, source, track), so concurrent staff actions cannot deadlock against this.
            if (! AdminMultiFactor::satisfiedBy($actor, lockForUpdate: true)) {
                throw new AuthorizationException;
            }
            $source = MediaAsset::query()->lockForUpdate()->find($entry['source_id']);
            $track = Track::query()->lockForUpdate()->find($entry['track_id']);
            if ($source === null || $track === null || $source->track_id !== $track->id) {
                return ['skip' => 'source_missing'];
            }
            if ($track->status !== TrackVisibility::DRAFT) {
                return ['skip' => 'no_longer_draft'];
            }
            $newest = MediaAsset::query()->where('track_id', $track->id)->where('role', 'preview_tagged')->where('status', 'ready')->max('id');
            if ((int) $newest !== $entry['preview_id']) {
                return ['skip' => 'preview_changed'];
            }
            if ($this->newerMasterSourceExists($track->id, $source->id)) {
                return ['skip' => 'superseded'];
            }

            return ['run' => app(QueueMediaProcessing::class)->handle($source, $actor)->id];
        });
    }

    /** Master uploads (not derived masters) are the only rows without a parent. */
    private function newerMasterSourceExists(int $trackId, int $sourceId): bool
    {
        return MediaAsset::query()->where('track_id', $trackId)->where('role', 'master_wav')->whereNull('parent_asset_id')->where('id', '>', $sourceId)->exists();
    }
}
