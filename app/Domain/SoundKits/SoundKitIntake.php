<?php

namespace App\Domain\SoundKits;

use App\Domain\Media\MediaFailure;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Jobs\ProcessSoundKit;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SoundKitIntake
{
    public function handle(int $draftId, UploadedFile $upload, mixed $expectedVersion, User $actor): SoundKitRevision
    {
        $this->root();
        if (! (is_int($expectedVersion) || (is_string($expectedVersion) && preg_match('/\A[1-9][0-9]*\z/D', $expectedVersion)))
            || (string) (int) $expectedVersion !== (string) $expectedVersion || (int) $expectedVersion < 1) {
            throw ValidationException::withMessages(['upload' => 'Reload this kit before uploading.']);
        }
        $drafts = app(SoundKitDrafts::class);
        $profile = app(SoundKitArchiveProfile::class)->current();
        $path = $upload->getRealPath();
        $name = $upload->getClientOriginalName();
        if (! $upload->isValid() || ! is_string($path) || is_link($upload->getPathname()) || ! is_file($path)
            || ! mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 180 || ! preg_match('/\.zip\z/iD', $name)
            || preg_match('~[\\\\/\x00-\x1f\x7f]~', $name) || filesize($path) < 22 || filesize($path) > $profile['source_max_bytes']) {
            throw ValidationException::withMessages(['upload' => 'Choose a complete ZIP up to the configured private upload limit.']);
        }
        $hash = hash_file('sha256', $path);
        $size = filesize($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $profileHash = CanonicalJson::hash($profile);
        $command = CanonicalJson::hash(['actor' => (int) $actor->id, 'version' => (string) $expectedVersion,
            'name' => $name, 'sha256' => $hash, 'size' => $size, 'mime' => $mime, 'profile' => $profileHash]);
        [$draft, $existing] = DB::transaction(function () use ($drafts, $draftId, $actor, $expectedVersion, $command): array {
            $drafts->actor($actor);
            $draft = $drafts->lock($draftId);
            $existing = $draft->revisions()->where('command_sha256', $command)->lockForUpdate()->first();
            if ($existing === null) {
                $drafts->expected($draft, $expectedVersion);
            }

            return [$draft, $existing];
        });
        if ($existing !== null) {
            app(SoundKitFiles::class)->verify($existing->source_path, $existing->source_sha256, $existing->source_size_bytes);

            return $existing;
        }
        app(SoundKitArchive::class)->inspect($path, $mime, $profile);
        $source = app(SoundKitFiles::class)->preserve($path, $draft->public_id.'-'.$command, 'source.zip', $hash, $size);
        $revision = DB::transaction(function () use ($drafts, $draftId, $actor, $expectedVersion, $command, $name, $source, $hash, $size, $mime, $profile, $profileHash): SoundKitRevision {
            $actor = $drafts->actor($actor);
            $draft = $drafts->lock($draftId);
            $existing = $draft->revisions()->where('command_sha256', $command)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $drafts->expected($draft, $expectedVersion);
            if (! hash_equals($profileHash, CanonicalJson::hash(app(SoundKitArchiveProfile::class)->current()))) {
                throw new MediaFailure('profile_changed', 'The kit inspection profile changed. Reload before uploading.');
            }
            app(SoundKitFiles::class)->verify($source, $hash, $size);
            $number = (int) $draft->revisions()->max('number') + 1;
            $revision = SoundKitRevision::create(['public_id' => (string) Str::uuid(), 'sound_kit_draft_id' => $draft->id,
                'number' => $number, 'command_sha256' => $command, 'original_name' => $name, 'source_path' => $source,
                'source_sha256' => $hash, 'source_size_bytes' => $size, 'mime_type' => $mime, 'profile' => $profile,
                'profile_sha256' => $profileHash, 'description_snapshot' => $draft->only(['title', 'description', 'provenance']),
                'uploaded_by' => $actor->id, 'requested_by' => $actor->id, 'status' => 'quarantined', 'attempts' => 0]);
            $drafts->advance($draft);
            $draft->save();
            AuditEvent::record('sound_kit.revision.received', $revision, ['number' => $number, 'source_sha256' => $hash, 'size_bytes' => $size, 'profile_sha256' => $profileHash], $actor->id);

            return $revision;
        });
        // Own root commit has completed. Unknown commit outcomes retain the canonical source for replay.
        $this->dispatch($revision);

        return $revision->fresh();
    }

    public function retry(int $revisionId, User $actor): SoundKitRevision
    {
        $this->root();
        $hint = SoundKitRevision::findOrFail($revisionId);
        $revision = DB::transaction(function () use ($hint, $actor): SoundKitRevision {
            $drafts = app(SoundKitDrafts::class);
            $actor = $drafts->actor($actor);
            $drafts->lock($hint->sound_kit_draft_id);
            $revision = SoundKitRevision::query()->lockForUpdate()->findOrFail($hint->id);
            if ($revision->status === 'processing' && $revision->claimed_until?->isFuture()) {
                throw ValidationException::withMessages(['upload' => 'This revision is still being checked. Refresh its status shortly.']);
            }
            if (! in_array($revision->status, ['quarantined', 'processing'], true)) {
                throw ValidationException::withMessages(['upload' => 'This retained revision is terminal. Upload a new revision for a different result.']);
            }
            $revision->forceFill(['requested_by' => $actor->id, 'status' => 'quarantined', 'claim_token' => null, 'claimed_until' => null])->save();
            AuditEvent::record('sound_kit.revision.retry_requested', $revision, ['attempts' => $revision->attempts], $actor->id);

            return $revision;
        });
        $this->dispatch($revision);

        return $revision->fresh();
    }

    private function dispatch(SoundKitRevision $revision): void
    {
        try {
            ProcessSoundKit::dispatch($revision->id);
        } catch (Throwable $error) {
            report($error);
        } // Durable quarantine remains visible and retryable.
    }

    private function root(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Sound-kit intake and retry require their own root transaction.');
        }
    }
}
