<?php

namespace App\Application\Media;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\MediaWriterActor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\PrivateMediaFiles;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\DeadlockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class IngestMediaUpload
{
    public const ROLES = ['master_wav' => 'WAV master', 'artwork' => 'Artwork (PNG or JPEG)', 'stems_zip' => 'Stems (WAV-only ZIP)'];

    public function handle(Track $track, mixed $upload, string $role, User $actor): MediaAsset
    {
        Gate::forUser($actor)->authorize('administer-catalog');
        if (! $track->exists || ! array_key_exists($role, self::ROLES)) {
            throw ValidationException::withMessages(['role' => 'Select an existing track and a supported upload role.']);
        }
        // Never accept a client-supplied path to an existing private object.
        if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
            throw ValidationException::withMessages(['upload' => 'Choose a newly uploaded file. Existing storage paths cannot be submitted.']);
        }
        $size = $upload->getSize();
        $limit = $role === 'artwork' ? 20 * 1024 * 1024 : 200 * 1024 * 1024;
        if (! is_int($size) || $size < 1 || $size > $limit) {
            throw ValidationException::withMessages(['upload' => $role === 'artwork' ? 'Artwork must be between 1 byte and 20 MiB.' : 'WAV masters and stems ZIPs must be between 1 byte and 200 MiB.']);
        }
        $source = $upload->getRealPath();
        if (! is_string($source) || ! is_file($source) || is_link($upload->getPathname())) {
            throw ValidationException::withMessages(['upload' => 'The temporary upload is no longer available. Upload it again.']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        $allowed = match ($role) {
            'artwork' => ['image/png', 'image/jpeg'],
            'stems_zip' => ['application/zip', 'application/x-zip'],
            default => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave', 'audio/wave'],
        };
        if (! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages(['upload' => 'File contents do not match the selected upload role.']);
        }
        app(PrivateMediaFiles::class)->root();
        $hash = hash_file('sha256', $source);
        $path = 'quarantine/'.Str::uuid().'/source.upload';
        $disk = Storage::disk('local');
        $stream = fopen($source, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read the temporary upload.');
        }
        try {
            if (! $disk->put($path, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('Cannot preserve the upload in private storage.');
            }
        } finally {
            fclose($stream);
        }
        $transactionLevel = DB::transactionLevel();
        $committing = false;
        try {
            if ($disk->size($path) !== $size || ! hash_equals($hash, hash_file('sha256', $disk->path($path)))) {
                throw new RuntimeException('Upload integrity verification failed.');
            }
            $name = basename(str_replace('\\', '/', $upload->getClientOriginalName()));
            $name = Str::limit(preg_replace('/[\x00-\x1F\x7F]/', '', $name), 240, '');

            return DB::transaction(function () use ($track, $role, $path, $name, $mime, $size, $hash, $actor, &$committing) {
                // Upload copying stays outside our mutation locks; authority is current at commit.
                $actor = app(MediaWriterActor::class)->authorize($actor);
                $track = Track::query()->lockForUpdate()->findOrFail($track->id);
                $asset = MediaAsset::create([
                    'track_id' => $track->id,
                    'role' => $role,
                    'disk' => 'local',
                    'storage_path' => $path,
                    'original_name' => $name ?: 'Uploaded media',
                    'mime_type' => $mime,
                    'size_bytes' => $size,
                    'sha256' => $hash,
                    'status' => 'quarantined',
                    'technical_metadata' => ['acquired_by' => $actor->id],
                ]);
                AuditEvent::record('media.upload.quarantined', $asset, ['role' => $role, 'sha256' => $hash, 'size_bytes' => $size], $actor->id);
                $committing = true;

                return $asset;
            });
        } catch (Throwable $exception) {
            // A commit error can follow a durable write. A failed rollback can leave rows
            // pending, as can Laravel's nested DeadlockException path, which skips rollback.
            if (! $committing && ! $exception instanceof DeadlockException && DB::transactionLevel() === $transactionLevel) {
                $disk->delete($path);
            }
            throw $exception;
        }
    }
}
