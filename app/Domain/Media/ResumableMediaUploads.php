<?php

namespace App\Domain\Media;

use App\Application\Media\IngestMediaUpload;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaUploadSession;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Bounded private transport. Completion still uses the existing quarantine boundary. */
final class ResumableMediaUploads
{
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    public const MAX_ACTIVE = 4;

    public const LIFETIME_HOURS = 24;

    public function start(Track $track, string $role, int $sizeBytes, string $sha256, string $originalName, User $actor): array
    {
        $this->rootTransaction();
        if (! $track->exists || ! array_key_exists($role, IngestMediaUpload::ROLES)
            || $sizeBytes < 1 || $sizeBytes > ($role === 'artwork' ? 20 : 200) * 1024 * 1024
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $sha256)) {
            $this->invalid('Choose a supported file within the existing upload limits and its SHA-256 digest.');
        }
        $name = basename(str_replace('\\', '/', $originalName));
        $name = Str::limit(preg_replace('/[\x00-\x1F\x7F]/', '', $name), 240, '');
        if ($name === '' || ! mb_check_encoding($name, 'UTF-8')) {
            $this->invalid('Choose a file with a valid original name.');
        }

        return DB::transaction(function () use ($track, $role, $sizeBytes, $sha256, $name, $actor): array {
            $actor = $this->currentActor($actor);
            $track = Track::query()->lockForUpdate()->findOrFail($track->id);
            $existing = MediaUploadSession::where('actor_id', $actor->id)->where('track_id', $track->id)
                ->where('status', 'uploading')->orderBy('created_at')->lockForUpdate()->get();
            foreach ($existing as $candidate) {
                if ($candidate->role === $role && $candidate->size_bytes === $sizeBytes
                    && hash_equals($candidate->sha256, $sha256) && $candidate->original_name === $name) {
                    return $this->present($candidate);
                }
            }
            if (MediaUploadSession::where('actor_id', $actor->id)->whereNull('cleaned_at')->count() >= self::MAX_ACTIVE) {
                $this->invalid('Finish, cancel or recover cleanup for an existing upload before starting another.');
            }
            $session = MediaUploadSession::create(['public_id' => (string) Str::uuid(), 'actor_id' => $actor->id,
                'track_id' => $track->id, 'role' => $role, 'original_name' => $name, 'size_bytes' => $sizeBytes,
                'sha256' => $sha256, 'received_bytes' => 0, 'parts' => [], 'status' => 'uploading',
                'expires_at' => now()->addHours(self::LIFETIME_HOURS)]);
            AuditEvent::record('media.upload.session_started', $session, ['track_id' => $track->id,
                'role' => $role, 'size_bytes' => $sizeBytes, 'sha256' => $sha256], $actor->id);

            return $this->present($session);
        });
    }

    public function inspect(string $sessionId, User $actor): array
    {
        return $this->locked($sessionId, $actor, fn (MediaUploadSession $session): array => $this->present($session));
    }

    public function append(string $sessionId, int $offset, UploadedFile $chunk, User $actor): array
    {
        $this->rootTransaction();
        if ($offset < 0 || ! $chunk->isValid() || ! is_int($chunk->getSize())
            || $chunk->getSize() < 1 || $chunk->getSize() > self::CHUNK_BYTES
            || ! $chunk->getRealPath() || is_link($chunk->getPathname())) {
            $this->invalid('Choose a valid upload chunk of at most 8 MiB.');
        }
        $size = $chunk->getSize();
        $hash = hash_file('sha256', $chunk->getRealPath());

        return $this->locked($sessionId, $actor, function (MediaUploadSession $session) use ($offset, $chunk, $size, $hash): array {
            $this->uploading($session);
            if ($offset < $session->received_bytes) {
                foreach ($session->parts as $part) {
                    if ($part['offset'] === $offset && $part['size'] === $size && hash_equals($part['sha256'], $hash)) {
                        app(PrivateUploadParts::class)->verify($session->public_id, $part);

                        return $this->present($session);
                    }
                }
                $this->invalid('This upload offset already contains different bytes. Inspect the session before retrying.');
            }
            if ($offset !== $session->received_bytes || $size !== min(self::CHUNK_BYTES, $session->size_bytes - $offset)) {
                $this->invalid('Send the exact next complete chunk. Inspect the session to resume at its current offset.');
            }
            $part = app(PrivateUploadParts::class)->preserve($session->public_id, $chunk, $offset, $size, $hash);
            $session->forceFill(['parts' => [...$session->parts, $part], 'received_bytes' => $offset + $size])->save();
            // Bytes are retained if row/commit acknowledgement is uncertain. A retry
            // first checks the durable offset and never overwrites a referenced part.

            return $this->present($session);
        });
    }

    public function complete(string $sessionId, User $actor): MediaAsset
    {
        $asset = $this->locked($sessionId, $actor, function (MediaUploadSession $session, User $current, Track $track): MediaAsset {
            if ($session->status === 'completed') {
                $asset = MediaAsset::query()->findOrFail($session->asset_id);
                if ($asset->track_id !== $session->track_id || $asset->role !== $session->role
                    || (int) $asset->size_bytes !== $session->size_bytes || ! hash_equals($session->sha256, $asset->sha256)) {
                    throw new LogicException('Retained completed upload identity changed.');
                }

                return $asset;
            }
            $this->uploading($session);
            if ($session->received_bytes !== $session->size_bytes) {
                $this->invalid('This upload is incomplete. Resume its remaining bytes first.');
            }
            $source = app(PrivateUploadParts::class)->assemble($session->public_id, $session->parts, $session->size_bytes, $session->sha256);
            // Only our verified private spool crosses this internal boundary. No
            // request can supply a source path, choose test mode, or skip MIME checks.
            $file = new UploadedFile($source, $session->original_name, null, UPLOAD_ERR_OK, true);
            $asset = app(IngestMediaUpload::class)->handleResumable($track, $file, $session->role, $current, $session);
            $session->forceFill(['status' => 'completed', 'asset_id' => $asset->id])->save();
            AuditEvent::record('media.upload.session_completed', $session, ['asset_id' => $asset->id,
                'sha256' => $session->sha256, 'size_bytes' => $session->size_bytes], $current->id);
            // Keep both spool and copied quarantine on any uncertain outer outcome.
            // A fresh retry returns the same durable asset if the commit succeeded.

            return $asset;
        });
        // Only a positively acknowledged root commit (or its idempotent replay)
        // allows transport cleanup. The retained quarantine original is separate.
        $this->cleanup($sessionId, $actor, 'completed');

        return $asset;
    }

    public function cancel(string $sessionId, User $actor): array
    {
        $result = $this->locked($sessionId, $actor, function (MediaUploadSession $session, User $current): array {
            if ($session->status === 'completed') {
                $this->invalid('This upload is already a retained media asset and cannot be cancelled.');
            }
            if ($session->status !== 'cancelled') {
                $session->forceFill(['status' => 'cancelled'])->save();
                AuditEvent::record('media.upload.session_cancelled', $session, ['received_bytes' => $session->received_bytes], $current->id);
            }

            return $this->present($session);
        });
        // The terminal cancellation committed successfully. No writer can revive
        // this session; clean only transport parts, never quarantine or originals.
        $this->cleanup($sessionId, $actor, 'cancelled');

        $result['cleanupPending'] = false;

        return $result;
    }

    private function cleanup(string $sessionId, User $actor, string $terminalState): void
    {
        // The state already committed in the previous root transaction. Take the
        // same fences again so simultaneous completion/cancel replays cannot race
        // physical cleanup, and revalidate authority before opening private paths.
        $this->locked($sessionId, $actor, function (MediaUploadSession $session) use ($terminalState): void {
            if ($session->status !== $terminalState) {
                throw new LogicException('Upload cleanup requires its durable terminal state.');
            }
            if ($session->cleaned_at === null) {
                app(PrivateUploadParts::class)->remove($session->public_id);
                $session->forceFill(['cleaned_at' => now()])->save();
            }
        });
    }

    private function locked(string $sessionId, User $actor, callable $operation): mixed
    {
        $this->rootTransaction();
        if (! Str::isUuid($sessionId)) {
            throw new AuthorizationException('This upload session is unavailable.');
        }
        // This pre-transaction lookup only locates the immutable track fence. It
        // confers no authority; every identity is revalidated with current reads.
        $trackId = MediaUploadSession::where('public_id', $sessionId)->value('track_id');

        return DB::transaction(function () use ($sessionId, $trackId, $actor, $operation): mixed {
            $current = $this->currentActor($actor);
            $track = $trackId ? Track::query()->lockForUpdate()->find($trackId) : null;
            $session = MediaUploadSession::query()->where('public_id', $sessionId)->lockForUpdate()->first();
            if (! $track || ! $session || ! hash_equals($session->public_id, $sessionId)
                || $session->actor_id !== $current->id || $session->track_id !== $track->id) {
                throw new AuthorizationException('This upload session is unavailable.');
            }

            return $operation($session, $current, $track);
        });
    }

    private function uploading(MediaUploadSession $session): void
    {
        if ($session->status !== 'uploading' || $session->expires_at->lte(now())) {
            $this->invalid('This upload is no longer active. Start a new upload or inspect its completed result.');
        }
    }

    private function currentActor(User $actor): User
    {
        $current = app(MediaWriterActor::class)->authorize($actor);
        // HTTP admission may precede a lock wait. Re-read required enrollment
        // under the actor fence, even if this transaction has an older snapshot.
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException('This upload session is unavailable.');
        }

        return $current;
    }

    private function present(MediaUploadSession $session): array
    {
        return ['id' => $session->public_id, 'trackId' => $session->track_id, 'role' => $session->role,
            'originalName' => $session->original_name, 'sizeBytes' => $session->size_bytes, 'sha256' => $session->sha256,
            'receivedBytes' => $session->received_bytes, 'chunkBytes' => self::CHUNK_BYTES,
            'status' => $session->status === 'uploading' && $session->expires_at->lte(now()) ? 'expired' : $session->status,
            'expiresAt' => $session->expires_at->toIso8601String(), 'assetId' => $session->asset_id,
            'cleanupPending' => in_array($session->status, ['completed', 'cancelled'], true) && $session->cleaned_at === null];
    }

    private function rootTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Resumable upload operations require their own root transaction.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['upload' => $message]);
    }
}
