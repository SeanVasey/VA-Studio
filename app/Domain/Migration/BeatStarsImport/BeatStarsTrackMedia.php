<?php

namespace App\Domain\Migration\BeatStarsImport;

use App\Application\Media\IngestMediaUpload;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\RecordingAssociation;
use App\Domain\Media\VerifiedMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

/**
 * Brings one draft track's listed files in through the ordinary media services: WAV master, artwork (high-resolution
 * first, then the standard file) and the stems ZIP enter as quarantined sources through `IngestMediaUpload`, are processed
 * by the same `MediaProcessor` the media worker runs (ClamAV, the approved seller tag, derivatives). The stems association is
 * left for staff to confirm.
 *
 * The listed preview MP3 is never ingested: the pipeline generates the tagged preview from the master, and an untagged
 * BeatStars preview must not become a public file. Nothing here creates a rights declaration, offer, license or
 * publication, and a missing or refused file is an outcome, never a substitute.
 *
 * Every step first looks at what an earlier run left: a ready derivative is kept, a quarantined source with the file's
 * exact SHA-256 is processed again instead of uploaded again.
 */
final class BeatStarsTrackMedia
{
    /** Source role => the derivative that proves it is attached. */
    public const DERIVATIVES = ['master_wav' => 'preview_tagged', 'artwork' => 'artwork', 'stems_zip' => 'stems_zip'];

    public function __construct(private readonly BeatStarsMediaFiles $files) {}

    /**
     * @param  array<string, string>  $listing  role => the export cell
     * @return array{attempted: bool, roles: array<string, array<string, mixed>>, stems_association: string}
     */
    public function attach(Track $track, array $listing, User $operator): array
    {
        $roles = [];
        $attempted = false;
        foreach (['master_wav', 'artwork', 'stems_zip'] as $role) {
            if ($this->ready($track, self::DERIVATIVES[$role]) !== null) {
                $roles[$role] = ['outcome' => 'already_attached'];

                continue;
            }
            $candidates = $role === 'artwork' ? ['high_res' => 'artwork_high_res', 'standard' => 'artwork'] : [$role => $role];
            $roles[$role] = $this->first($track, $candidates, $listing, $role, $operator, $attempted);
        }
        $roles['preview'] = $this->preview($track, $listing);

        return ['attempted' => $attempted, 'roles' => $roles, 'stems_association' => $this->association($track, $roles)];
    }

    /** What the pipeline and the files say about the preview, for the report. */
    private function preview(Track $track, array $listing): array
    {
        $listed = $this->files->resolve('preview_mp3', $listing['preview_mp3'] ?? '');

        return ['outcome' => $this->ready($track, 'preview_tagged') !== null ? 'generated_by_pipeline' : 'pending_master',
            'source_mp3' => $listed['state'] === 'refused' ? 'refused:'.$listed['code'] : $listed['state'], 'source_mp3_used' => false];
    }

    /**
     * Tries the candidate columns in order and stops at the first that ends ready. The first non-attached outcome is kept
     * when none does, with every candidate's own outcome beside it.
     *
     * @param  array<string, string>  $candidates  label => listing key
     */
    private function first(Track $track, array $candidates, array $listing, string $role, User $operator, bool &$attempted): array
    {
        $tried = [];
        foreach ($candidates as $label => $key) {
            $resolved = $this->files->resolve($key, $listing[$key] ?? '');
            if ($resolved['state'] !== 'ok') {
                $tried[$label] = match ($resolved['state']) {
                    'missing' => ['outcome' => 'media_missing'],
                    'refused' => ['outcome' => 'media_refused', 'code' => $resolved['code']],
                    default => ['outcome' => 'not_listed'],
                };

                continue;
            }
            $attempted = true;
            $outcome = $this->ingestAndProcess($track, $role, $resolved, $operator);
            $tried[$label] = $outcome;
            if ($outcome['outcome'] === 'attached') {
                return ['outcome' => 'attached'] + ($role === 'artwork' ? ['source' => $label, 'candidates' => $tried] : []);
            }
        }
        // A listed-but-missing file outranks "not listed" so the report names the real gap.
        foreach (['media_refused', 'media_missing', 'rejected', 'failed', 'not_listed'] as $rank) {
            foreach ($tried as $outcome) {
                if ($outcome['outcome'] === $rank) {
                    return $outcome + (count($tried) > 1 ? ['candidates' => $tried] : []);
                }
            }
        }

        return ['outcome' => 'not_listed'];
    }

    private function ingestAndProcess(Track $track, string $role, array $resolved, User $operator): array
    {
        $hash = hash_file('sha256', $resolved['path']);
        if (! is_string($hash)) {
            return ['outcome' => 'media_refused', 'code' => 'unreadable'];
        }
        $source = MediaAsset::query()->where('track_id', $track->id)->whereNull('parent_asset_id')->where('role', $role)
            ->where('status', 'quarantined')->where('sha256', $hash)->latest('id')->first();
        if ($source === null) {
            try {
                $name = mb_substr(basename($resolved['path']), 0, 240);
                $source = app(IngestMediaUpload::class)->handle($track, new UploadedFile($resolved['path'], $name, null, UPLOAD_ERR_OK, true), $role, $operator);
            } catch (ValidationException $exception) {
                // The intake service's own wording is generic (size limit, content type); it never names a path.
                return ['outcome' => 'rejected', 'code' => 'intake_validation', 'detail' => implode(' ', $exception->validator->errors()->all())];
            }
            if (! $this->files->unchanged($resolved)) {
                return ['outcome' => 'failed', 'code' => 'source_changed_during_read'];
            }
        }
        try {
            $run = app(QueueMediaProcessing::class)->handle($source, $operator);
        } catch (ValidationException) {
            return ['outcome' => 'failed', 'code' => 'queue_refused'];
        }
        $code = $this->process($run);

        return $code === null ? ['outcome' => 'attached'] : ['outcome' => 'failed', 'code' => $code];
    }

    /**
     * Runs the queued processing request in this process with the service the media worker uses. Its claim token makes
     * the two exclusive: whichever claims first processes, and the other waits for that run's outcome. Returns the failure
     * code, or null when the run completed.
     */
    private function process(MediaProcessingRun $run): ?string
    {
        $run = $run->fresh();
        if ($run->status !== 'completed') {
            try {
                $run = app(MediaProcessor::class)->handle($run->id);
            } catch (MediaFailure $failure) {
                return $failure->failureCode;
            }
        }
        $deadline = now()->addSeconds((int) config('media.claim_lease_seconds'));
        while (in_array($run->status, ['queued', 'processing'], true) && now()->lt($deadline)) {
            Sleep::for(2)->seconds();
            $run = $run->fresh();
        }

        return $run->status === 'completed' ? null : ($run->failure_code ?? $run->status);
    }

    /**
     * Whether the stems are associated with the master and preview. The import never creates that association: it is a staff
     * verification that the stems are the same recording as the master, and it satisfies publication readiness, so Sean makes it
     * in the admin. The export row listing both files is evidence for him, not a confirmation.
     */
    private function association(Track $track, array $roles): string
    {
        $stems = $this->ready($track, 'stems_zip');
        if ($stems === null) {
            return ($roles['stems_zip']['outcome'] ?? '') === 'not_listed' ? 'not_applicable' : 'waiting_for_stems';
        }
        $preview = $this->ready($track, 'preview_tagged');
        if ($preview === null) {
            return 'waiting_for_master';
        }

        return app(RecordingAssociation::class)->matches($stems, $preview) ? 'associated' : 'unconfirmed';
    }

    private function ready(Track $track, string $output): ?MediaAsset
    {
        $asset = $track->assets()->where('role', $output)->where('status', 'ready')->latest('id')->first();

        return $asset !== null && app(VerifiedMedia::class)->available($asset) ? $asset : null;
    }
}
