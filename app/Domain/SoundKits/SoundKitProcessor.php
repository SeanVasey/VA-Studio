<?php

namespace App\Domain\SoundKits;

use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\MediaWorkflowBudget;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class SoundKitProcessor
{
    public const LEASE_SECONDS = 960;

    public const TRANSIENT = ['scanner_unavailable', 'scanner_signatures_stale', 'tool_unavailable', 'zip_unavailable', 'processor_timeout',
        'storage_failed', 'unsafe_storage', 'missing_source', 'processing_interrupted', 'authority_changed'];

    public function handle(int $id): SoundKitRevision
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Kit processing requires its own root transactions.');
        }
        [$revision, $token] = $this->claim($id);
        if ($token === null) {
            return $revision;
        }
        $files = app(PrivateMediaFiles::class);
        $workspace = null;
        $budget = app(MediaWorkflowBudget::class);
        $previous = $budget->enter();
        try {
            if (! hash_equals($revision->profile_sha256, CanonicalJson::hash($revision->profile))
                || ! hash_equals($revision->profile_sha256, CanonicalJson::hash(app(SoundKitArchiveProfile::class)->current()))) {
                throw new MediaFailure('profile_changed', 'Upload a new revision under the current kit inspection profile.');
            }
            $workspace = $files->workspace();
            $input = $workspace.'/source.zip';
            $snapshot = $files->snapshot($revision->source_path, $input, $revision->profile['source_max_bytes']);
            if ($snapshot['sha256'] !== $revision->source_sha256 || $snapshot['size_bytes'] !== $revision->source_size_bytes || $snapshot['mime_type'] !== $revision->mime_type) {
                throw new MediaFailure('source_changed', 'The private kit source no longer matches its retained identity.');
            }
            $archive = app(SoundKitArchive::class);
            $archive->inspect($input, $revision->mime_type, $revision->profile);
            $sourceScan = $this->scan($input);
            $built = $archive->build($input, $revision->mime_type, $revision->profile, $workspace, $this->scan(...));
            $hash = hash_file('sha256', $built['file']);
            $size = filesize($built['file']);
            $path = app(SoundKitFiles::class)->preserve($built['file'], $revision->public_id, 'samples.zip', $hash, $size);
            $manifest = app(SoundKitManifest::class)->make($revision, $built['manifest'], $hash, $size);
            $budget->assertRemaining();

            return $this->complete($revision, $token, $path, $hash, $size, $manifest,
                ['source_scan' => $sourceScan, 'archive_scan' => $built['archive_scan']]);
        } catch (MediaFailure $failure) {
            if ($failure->failureCode !== 'claim_lost') {
                $this->fail($revision, $token, $failure->failureCode);
            }
        } catch (AuthorizationException) {
            $this->fail($revision, $token, 'authority_changed');
        } catch (Throwable $error) {
            report($error);
            $this->fail($revision, $token, 'processing_interrupted');
        } finally {
            $budget->leave($previous);
            if ($workspace !== null) {
                $files->cleanup($workspace);
            }
            // Source and deterministic archive survive rollback or unknown commit acknowledgement.
        }

        return SoundKitRevision::findOrFail($id);
    }

    private function claim(int $id): array
    {
        $hint = SoundKitRevision::findOrFail($id);
        try {
            return DB::transaction(function () use ($hint): array {
                $drafts = app(SoundKitDrafts::class);
                $actor = User::find($hint->requested_by);
                if ($actor === null) {
                    throw new AuthorizationException;
                }
                $drafts->actor($actor);
                $drafts->lock($hint->sound_kit_draft_id);
                $revision = SoundKitRevision::query()->lockForUpdate()->findOrFail($hint->id);
                if ($revision->requested_by !== $hint->requested_by || ! in_array($revision->status, ['quarantined', 'processing'], true)
                    || ($revision->status === 'processing' && $revision->claimed_until?->isFuture())) {
                    return [$revision, null];
                }
                $token = (string) Str::uuid();
                $revision->forceFill(['status' => 'processing', 'claim_token' => $token, 'claimed_until' => now()->addSeconds(self::LEASE_SECONDS),
                    'attempts' => $revision->attempts + 1, 'failure_code' => null])->save();
                AuditEvent::record('sound_kit.revision.processing', $revision, ['attempts' => $revision->attempts], $actor->id);

                return [$revision, $token];
            });
        } catch (AuthorizationException) {
            // Nothing has been claimed or touched; another authorized staff member can request a retry.
            return [SoundKitRevision::findOrFail($id), null];
        }
    }

    private function complete(SoundKitRevision $hint, string $token, string $path, string $hash, int $size, array $manifest, array $evidence): SoundKitRevision
    {
        return DB::transaction(function () use ($hint, $token, $path, $hash, $size, $manifest, $evidence): SoundKitRevision {
            $drafts = app(SoundKitDrafts::class);
            $actor = User::find($hint->requested_by);
            if ($actor === null) {
                throw new AuthorizationException;
            }
            $drafts->actor($actor);
            $drafts->lock($hint->sound_kit_draft_id);
            $revision = SoundKitRevision::query()->lockForUpdate()->findOrFail($hint->id);
            if ($revision->status !== 'processing' || $revision->claim_token !== $token || $revision->requested_by !== $hint->requested_by) {
                throw new MediaFailure('claim_lost', 'Another worker owns this kit revision.');
            }
            app(MediaWorkflowBudget::class)->assertRemaining();
            if (! hash_equals($revision->profile_sha256, CanonicalJson::hash(app(SoundKitArchiveProfile::class)->current()))) {
                throw new MediaFailure('profile_changed', 'The inspection profile changed during verification. Upload a new revision.');
            }
            $revision->forceFill(['status' => 'ready', 'claim_token' => null, 'claimed_until' => null, 'failure_code' => null,
                'archive_path' => $path, 'archive_sha256' => $hash, 'archive_size_bytes' => $size, 'manifest' => $manifest,
                'manifest_sha256' => CanonicalJson::hash($manifest), 'evidence' => $evidence, 'verified_at' => now()])->save();
            app(SoundKitManifest::class)->verified($revision);
            AuditEvent::record('sound_kit.revision.verified', $revision, ['manifest_sha256' => $revision->manifest_sha256, 'members' => count($manifest['members'])], $actor->id);

            return $revision;
        });
    }

    private function fail(SoundKitRevision $hint, string $token, string $code): void
    {
        DB::transaction(function () use ($hint, $token, $code): void {
            app(SoundKitDrafts::class)->lock($hint->sound_kit_draft_id);
            $revision = SoundKitRevision::query()->lockForUpdate()->findOrFail($hint->id);
            if ($revision->status !== 'processing' || $revision->claim_token !== $token) {
                return;
            }
            $transient = in_array($code, self::TRANSIENT, true);
            $revision->forceFill(['status' => $transient ? 'quarantined' : 'failed', 'claim_token' => null, 'claimed_until' => null, 'failure_code' => $code])->save();
            AuditEvent::record('sound_kit.revision.'.($transient ? 'retry_pending' : 'failed'), $revision,
                ['failure_code' => $code, 'attempts' => $revision->attempts], $revision->requested_by);
        });
    }

    private function scan(string $path, ?int $seconds = null): array
    {
        $scanner = app(MalwareScanner::class);
        $scanner->boundBy($seconds);
        try {
            $scan = $scanner->scan($path);
        } finally {
            $scanner->boundBy(null);
        }
        SoundKitManifest::scan($scan, hash_file('sha256', $path));

        return $scan;
    }
}
