<?php

namespace App\Application\SiteBuilder;

use App\Domain\Media\MediaFailure;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\SiteImageInspection;
use App\Domain\SiteBuilder\SiteImageProblem;
use App\Domain\SiteBuilder\SiteImageSlot;
use App\Jobs\ProcessSiteImage;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Quarantines an uploaded site image with its provenance, then queues it for scanning and re-encoding. */
final class IngestSiteImage
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function handle(string $slot, mixed $upload, string $credit, bool $rightsConfirmed, User $actor): SiteImage
    {
        Gate::forUser($actor)->authorize('administer-catalog');
        if (! AdminMultiFactor::satisfiedBy($actor)) {
            throw new AuthorizationException('Multi-factor authentication is required.');
        }
        if (! SiteImageSlot::exists($slot)) {
            throw ValidationException::withMessages(['slot' => 'Choose where this image will be used.']);
        }
        $credit = trim($credit);
        if ($credit === '' || mb_strlen($credit) > 200 || preg_match('/[\x00-\x1F\x7F<>]/u', $credit) !== 0) {
            throw ValidationException::withMessages(['credit' => 'Enter the source or credit as plain text of up to 200 characters.']);
        }
        if (! $rightsConfirmed) {
            throw ValidationException::withMessages(['rights_confirmed' => 'Confirm that we have the rights to use this image on the site.']);
        }
        // Never accept a client-supplied path to an existing private object.
        if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
            throw ValidationException::withMessages(['upload' => 'Choose a newly uploaded file.']);
        }
        $size = $upload->getSize();
        if (! is_int($size) || $size < 12 || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['upload' => 'Site images must be up to 20 MiB.']);
        }
        $source = $upload->getRealPath();
        if (! is_string($source) || ! is_file($source) || is_link($upload->getPathname())) {
            throw ValidationException::withMessages(['upload' => 'The temporary upload is no longer available. Upload it again.']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['upload' => 'Upload a JPEG or PNG image.']);
        }
        // Header-only read for early feedback; processing re-measures the scanned copy with a bounded prober.
        $dimensions = @getimagesize($source);
        $limit = (int) config('media.max_artwork_dimension');
        if (! $dimensions || ($dimensions['mime'] ?? null) !== $mime || $dimensions[0] > $limit || $dimensions[1] > $limit) {
            throw ValidationException::withMessages(['upload' => "The image could not be read, or it is larger than {$limit} px on a side."]);
        }
        [$width, $height] = [(int) $dimensions[0], (int) $dimensions[1]];
        if (! SiteImageSlot::accepts($slot, $width, $height)) {
            throw ValidationException::withMessages(['upload' => "This image is {$width} × {$height} px. ".SiteImageSlot::requirement($slot)]);
        }
        // Refused uploads leave no row behind, while a failed row is kept for good, so catch what the headers show now.
        try {
            $problem = SiteImageInspection::headerProblem($source, $mime, $dimensions);
        } catch (MediaFailure) {
            $problem = 'invalid_image';
        }
        if ($problem !== null) {
            throw ValidationException::withMessages(['upload' => SiteImageProblem::describe($problem)]);
        }

        app(PrivateMediaFiles::class)->root();
        $hash = hash_file('sha256', $source);
        $path = 'site-images/quarantine/'.Str::uuid().'/source.upload';
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
        try {
            if ($disk->size($path) !== $size || ! hash_equals($hash, hash_file('sha256', $disk->path($path)))) {
                throw new RuntimeException('Upload integrity verification failed.');
            }
            $name = basename(str_replace('\\', '/', $upload->getClientOriginalName()));
            $name = Str::limit(preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '', 240, '');

            $image = DB::transaction(function () use ($slot, $path, $name, $hash, $size, $mime, $width, $height, $credit, $actor): SiteImage {
                $image = SiteImage::create([
                    'slot' => $slot, 'original_name' => $name !== '' ? $name : 'Uploaded image', 'source_path' => $path,
                    'source_sha256' => $hash, 'size_bytes' => $size, 'mime_type' => $mime, 'width' => $width, 'height' => $height,
                    'credit' => $credit, 'rights_confirmed_at' => now(), 'uploaded_by' => $actor->id,
                    // Explicit, so the returned model carries the state its immutability guard reads.
                    'status' => 'quarantined', 'attempts' => 0,
                ]);
                AuditEvent::record('site.image.uploaded', $image, ['slot' => $slot, 'sha256' => $hash, 'size_bytes' => $size, 'width' => $width, 'height' => $height], $actor->id);

                return $image;
            });
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
        // The row and its upload are committed, so a queue outage must not remove either: the image waits and staff retry it.
        // A caller's own transaction still holds the job back, so a worker never looks for a row that is not committed yet.
        $send = static function () use ($image): void {
            try {
                ProcessSiteImage::dispatch($image->id)->onQueue(config('media.queue'));
            } catch (Throwable $exception) {
                report($exception);
            }
        };
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);
        } else {
            $send();
        }

        return $image;
    }
}
