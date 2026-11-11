<?php

namespace App\Domain\SoundKits\Products;

use App\Domain\Media\AudioDerivatives;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\ScanEngines;
use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\SiteImageFiles;
use App\Domain\SiteBuilder\SiteImageManifest;
use App\Jobs\ProcessSoundKitDemo;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The cover art and demo audio of a sound-kit product (BL-53 follow-up, FP-007), and the rules that decide whether they may be
 * shown. Both are public assets with their own roles, entirely separate from the private kit archive: the cover is a ready
 * `release_cover` site image (the albums pipeline: scanned, metadata stripped, re-encoded to fixed sizes, manifest-hashed); the
 * demo is WAV audio the media pipeline scans and turns into the same seller-tagged MP3 preview and waveform a track gets
 * ({@see SoundKitDemoProcessor}). Neither can name the archive, and nothing here serves a byte.
 *
 * Every command runs in one transaction that locks the acting staff user (administer-catalog plus enrolled MFA, re-read under
 * the lock) and then the product, checks the expected revision and records one audit event. One MFA staff member may approve.
 * An approval stores a hash over the exact asset it approved (product, asset identity, content digest). The hash is recomputed
 * at every public read, so choosing or uploading a replacement voids it (the writer clears it too), and an asset swapped behind
 * the writer is simply not shown. As for tracks, media cannot be replaced while the kit is published: unpublish first.
 *
 * Both a cover and a demo are required to list a kit. A track needs a ready tagged preview and artwork to be published
 * (PublicationReadiness); a kit is an audio product bought by ear, so it needs the same two public assets.
 */
final class SoundKitProductMedia
{
    /** The cover art uses the existing square cover slot of the site image pipeline (profile site-image-v2). */
    public const COVER_SLOT = 'release_cover';

    /** A demo is a short taster, not the kit: 100 MiB of WAV and five minutes (a track master may be 200 MiB and 20 minutes). */
    public const DEMO_MAX_BYTES = 100 * 1024 * 1024;

    public const DEMO_MAX_SECONDS = 300;

    /** Kept equal to the list in AudioDerivatives::validateWav(). */
    public const WAV_MIME_TYPES = ['audio/x-wav', 'audio/wav', 'audio/vnd.wave'];

    /** What staff are told about a demo that failed for good, by failure code; any other code gets the generic text. */
    private const FAILED_MESSAGES = [
        'demo_too_long' => 'The demo is longer than 5 minutes (demo_too_long). Upload a shorter demo.',
        'invalid_wav' => 'The demo is not a complete WAV file (invalid_wav). Upload a new demo.',
        'unsupported_wav' => 'The demo is not 16/24/32-bit PCM or float audio (unsupported_wav). Upload a new demo.',
        'invalid_audio' => 'The demo is not one or two channels of valid, supported audio (invalid_audio). Upload a new demo.',
        'source_changed' => 'The stored upload changed after it was received (source_changed). Upload a new demo.',
    ];

    /** Chooses (or replaces) the cover art. Replacing voids the cover approval. Alt text is required for people who cannot see it. */
    public function chooseCover(SoundKitProduct $product, int $expectedRevision, mixed $imageId, mixed $alt, User $actor): SoundKitProduct
    {
        return DB::transaction(function () use ($product, $expectedRevision, $imageId, $alt, $actor): SoundKitProduct {
            $current = $this->lockActor($actor);
            $locked = $this->lockProduct($product, $expectedRevision);
            $this->refuseWhilePublished($locked, 'cover');
            $imageId = is_string($imageId) && ctype_digit($imageId) ? (int) $imageId : $imageId;
            if (! is_int($imageId) || $imageId < 1) {
                $this->reject('cover', 'Choose a ready image uploaded as release cover art.');
            }
            $image = SiteImage::query()->with('variants')->lockForUpdate()->find($imageId);
            if ($image === null || $image->slot !== self::COVER_SLOT || $image->status !== 'ready') {
                $this->reject('cover', 'Choose a ready image uploaded as release cover art.');
            }
            $image->setRelation('variants', $image->variants()->sharedLock()->get());
            if (($reason = $this->imageProblem($image)) !== null) {
                $this->reject('cover', $reason);
            }
            $alt = $this->alt($alt);
            $replaced = $locked->cover_image_id !== $image->id || ! is_string($locked->cover_manifest_sha256) || ! hash_equals($locked->cover_manifest_sha256, (string) $image->manifest_sha256);
            if (! $replaced && $locked->cover_alt === $alt) {
                return $locked;
            }
            $attributes = ['cover_image_id' => $image->id, 'cover_alt' => $alt, 'cover_manifest_sha256' => $image->manifest_sha256, 'revision' => $locked->revision + 1];
            if ($replaced) {
                $attributes += ['cover_approval_hash' => null, 'cover_approved_at' => null, 'cover_approved_by' => null];
            }
            $locked->forceFill($attributes)->save();
            AuditEvent::record('sound_kit_products.product.cover_chosen', $locked, [
                'schema_version' => 1, 'revision' => $locked->revision, 'image_id' => $image->id, 'manifest_sha256' => $image->manifest_sha256,
                'approval_voided' => $replaced, 'alt_hash' => CanonicalJson::hash(['alt' => $alt]),
            ], $current->id);

            return $locked->refresh();
        });
    }

    /** Approves the chosen cover: binds the approval to its exact image and manifest hash. */
    public function approveCover(SoundKitProduct $product, int $expectedRevision, User $actor): SoundKitProduct
    {
        return DB::transaction(function () use ($product, $expectedRevision, $actor): SoundKitProduct {
            $current = $this->lockActor($actor);
            $locked = $this->lockProduct($product, $expectedRevision);
            $image = $locked->cover_image_id === null ? null : SiteImage::query()->with('variants')->lockForUpdate()->find($locked->cover_image_id);
            if ($image !== null) {
                $image->setRelation('variants', $image->variants()->sharedLock()->get());
            }
            $problem = $this->coverProblem($locked, $image, verifyFiles: true, requireApproval: false);
            if ($problem !== null) {
                $this->reject('cover', $problem);
            }
            $hash = $this->coverBinding($locked);
            $locked->forceFill(['cover_approval_hash' => $hash, 'cover_approved_at' => now(), 'cover_approved_by' => $current->id, 'revision' => $locked->revision + 1])->save();
            AuditEvent::record('sound_kit_products.product.cover_approved', $locked, [
                'schema_version' => 1, 'revision' => $locked->revision, 'image_id' => $locked->cover_image_id, 'manifest_sha256' => $locked->cover_manifest_sha256, 'approval_hash' => $hash,
            ], $current->id);

            return $locked->refresh();
        });
    }

    /**
     * Stores an uploaded WAV as the product's new demo and queues its processing. The new demo replaces the current one at once
     * and voids the demo approval; it is shown only once processed and approved.
     */
    public function uploadDemo(SoundKitProduct $product, int $expectedRevision, mixed $upload, User $actor): SoundKitDemo
    {
        $this->authorise($actor);
        // Never accept a client-supplied path to an existing private object.
        if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
            $this->reject('demo', 'Choose a newly uploaded file.');
        }
        $size = $upload->getSize();
        if (! is_int($size) || $size < 12 || $size > self::DEMO_MAX_BYTES) {
            $this->reject('demo', 'A demo must be a WAV file of up to 100 MiB.');
        }
        $source = $upload->getRealPath();
        if (! is_string($source) || ! is_file($source) || is_link($upload->getPathname())) {
            $this->reject('demo', 'The temporary upload is no longer available. Upload it again.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        // The same types AudioDerivatives::validateWav accepts, so nothing passes here only to fail there.
        if (! in_array($mime, self::WAV_MIME_TYPES, true)) {
            $this->reject('demo', 'Upload a WAV file. File contents do not match.');
        }
        // Cheap checks only: no media tool ever runs on an unscanned upload. Full validation (codec, channels, duration) is done by
        // the processor on the scanned copy.
        $header = (string) file_get_contents($source, false, null, 0, 12);
        if (strlen($header) < 12 || ! str_starts_with($header, 'RIFF') || substr($header, 8, 4) !== 'WAVE' || unpack('Vsize', substr($header, 4, 4))['size'] + 8 !== $size) {
            $this->reject('demo', 'The upload is not a complete RIFF/WAVE file.');
        }
        app(PrivateMediaFiles::class)->root();
        $hash = hash_file('sha256', $source);
        $path = 'sound-kit-demos/quarantine/'.Str::uuid().'/source.upload';
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
        $committing = false;
        try {
            if ($disk->size($path) !== $size || ! hash_equals($hash, hash_file('sha256', $disk->path($path)))) {
                throw new RuntimeException('Upload integrity verification failed.');
            }
            $name = Str::limit(preg_replace('/[\x00-\x1F\x7F]/', '', basename(str_replace('\\', '/', $upload->getClientOriginalName()))) ?? '', 240, '');
            $demo = DB::transaction(function () use ($product, $expectedRevision, $actor, $path, $name, $hash, $size, $mime, &$committing): SoundKitDemo {
                $current = $this->lockActor($actor);
                $locked = $this->lockProduct($product, $expectedRevision);
                $this->refuseWhilePublished($locked, 'demo');
                $demo = SoundKitDemo::create(['public_id' => (string) Str::uuid(), 'sound_kit_product_id' => $locked->id,
                    'original_name' => $name !== '' ? $name : 'Uploaded demo', 'source_path' => $path, 'source_sha256' => $hash, 'size_bytes' => $size,
                    'mime_type' => $mime, 'status' => 'quarantined', 'attempts' => 0, 'uploaded_by' => $current->id]);
                $locked->forceFill(['demo_id' => $demo->id, 'demo_approval_hash' => null, 'demo_approved_at' => null, 'demo_approved_by' => null,
                    'revision' => $locked->revision + 1])->save();
                AuditEvent::record('sound_kit_products.product.demo_uploaded', $locked, [
                    'schema_version' => 1, 'revision' => $locked->revision, 'demo_public_id' => $demo->public_id, 'sha256' => $hash, 'size_bytes' => $size,
                ], $current->id);
                $committing = true;

                return $demo;
            });
        } catch (Throwable $exception) {
            // A commit can report an error after the server applied it; once it has started the upload is kept, at worst as an orphan.
            if (! $committing) {
                $disk->delete($path);
            }
            throw $exception;
        }
        $this->dispatch($demo);

        return $demo;
    }

    /**
     * Queues processing again for the product's current demo when it stopped for a temporary reason (scanner, tools, storage or tag
     * configuration), or when a worker died holding it: a demo still `processing` once its claim lease has expired. A live lease is
     * refused, since a worker may still be finishing it. Same authority, MFA, expected product revision and audit as every command.
     * The product itself does not change, so the revision is not advanced.
     */
    public function retryDemo(SoundKitProduct $product, int $expectedRevision, User $actor): SoundKitDemo
    {
        $queued = DB::transaction(function () use ($product, $expectedRevision, $actor): SoundKitDemo {
            $current = $this->lockActor($actor);
            $locked = $this->lockProduct($product, $expectedRevision);
            $demo = $locked->demo_id === null ? null : SoundKitDemo::query()->lockForUpdate()->find($locked->demo_id);
            if ($demo === null || $demo->sound_kit_product_id !== $locked->id) {
                $this->reject('demo', 'This kit has no demo to retry.');
            }
            if (! self::retryable($demo)) {
                $this->reject('demo', $demo->status === 'processing' ? 'This demo is still being processed. Retry it if it is still stuck after its time allowance ends.' : 'This demo does not need a retry.');
            }
            AuditEvent::record('sound_kit_products.product.demo_retry_queued', $demo, [
                'schema_version' => 1, 'revision' => $locked->revision, 'failure_code' => $demo->failure_code ?? 'processing_interrupted', 'from_status' => $demo->status,
            ], $current->id);

            return $demo;
        });
        $this->dispatch($queued);

        return $queued;
    }

    /** True for a demo waiting on a fixable cause, or stuck in `processing` after its claim lease ran out (the processor reclaims it). */
    public static function retryable(SoundKitDemo $demo): bool
    {
        if ($demo->status === 'quarantined') {
            return $demo->failure_code !== null;
        }

        return $demo->status === 'processing' && ($demo->claimed_until === null || $demo->claimed_until->isPast());
    }

    /** Approves the product's current demo: binds the approval to its exact source and prepared preview. */
    public function approveDemo(SoundKitProduct $product, int $expectedRevision, User $actor): SoundKitProduct
    {
        return DB::transaction(function () use ($product, $expectedRevision, $actor): SoundKitProduct {
            $current = $this->lockActor($actor);
            $locked = $this->lockProduct($product, $expectedRevision);
            $demo = $locked->demo_id === null ? null : SoundKitDemo::query()->lockForUpdate()->find($locked->demo_id);
            $problem = $this->demoProblem($locked, $demo, verifyFiles: true, requireApproval: false);
            if ($problem !== null) {
                $this->reject('demo', $problem);
            }
            $hash = $this->demoBinding($locked, $demo);
            $locked->forceFill(['demo_approval_hash' => $hash, 'demo_approved_at' => now(), 'demo_approved_by' => $current->id, 'revision' => $locked->revision + 1])->save();
            AuditEvent::record('sound_kit_products.product.demo_approved', $locked, [
                'schema_version' => 1, 'revision' => $locked->revision, 'demo_public_id' => $demo->public_id, 'preview_sha256' => $demo->preview_sha256, 'approval_hash' => $hash,
            ], $current->id);

            return $locked->refresh();
        });
    }

    /**
     * What stops the product's media from being shown, keyed `cover` and `demo`. Empty means both are ready and approved. Reads
     * only. With `$verifyFiles` every stored file is read and hashed (publication); public reads rely on the immutable rows.
     *
     * @return array<string, string>
     */
    public function problems(SoundKitProduct $product, bool $verifyFiles): array
    {
        $problems = [];
        $image = $product->cover_image_id === null ? null : SiteImage::query()->with('variants')->find($product->cover_image_id);
        if (($cover = $this->coverProblem($product, $image, $verifyFiles, requireApproval: true)) !== null) {
            $problems['cover'] = $cover;
        }
        $demo = $product->demo_id === null ? null : SoundKitDemo::query()->find($product->demo_id);
        if (($demoProblem = $this->demoProblem($product, $demo, $verifyFiles, requireApproval: true)) !== null) {
            $problems['demo'] = $demoProblem;
        }

        return $problems;
    }

    /** The product's approved, intact cover image, or null. Callers have already established that the kit is listed. */
    public function cover(SoundKitProduct $product): ?SiteImage
    {
        $image = $product->cover_image_id === null ? null : SiteImage::query()->with('variants')->find($product->cover_image_id);

        return $this->coverProblem($product, $image, verifyFiles: false, requireApproval: true) === null ? $image : null;
    }

    /** The product's approved, ready demo, or null. */
    public function demo(SoundKitProduct $product): ?SoundKitDemo
    {
        $demo = $product->demo_id === null ? null : SoundKitDemo::query()->find($product->demo_id);

        return $this->demoProblem($product, $demo, verifyFiles: false, requireApproval: true) === null ? $demo : null;
    }

    public function coverBinding(SoundKitProduct $product): string
    {
        return CanonicalJson::hash(['schema' => 'sound-kit-cover-approval-v1', 'product' => $product->public_id, 'image_id' => $product->cover_image_id,
            'manifest_sha256' => $product->cover_manifest_sha256]);
    }

    public function demoBinding(SoundKitProduct $product, SoundKitDemo $demo): string
    {
        return CanonicalJson::hash(['schema' => 'sound-kit-demo-approval-v1', 'product' => $product->public_id, 'demo' => $demo->public_id,
            'source_sha256' => $demo->source_sha256, 'preview_sha256' => $demo->preview_sha256]);
    }

    private function coverProblem(SoundKitProduct $product, ?SiteImage $image, bool $verifyFiles, bool $requireApproval): ?string
    {
        if ($product->cover_image_id === null) {
            return 'Choose the cover art before publishing.';
        }
        if ($image === null || $image->slot !== self::COVER_SLOT || ! is_string($product->cover_manifest_sha256) || ! is_string($image->manifest_sha256)
            || ! hash_equals($product->cover_manifest_sha256, $image->manifest_sha256)) {
            return 'The chosen cover art is not available. Choose it again.';
        }
        if (($reason = $this->imageProblem($image, $verifyFiles)) !== null) {
            return $reason;
        }
        if (trim((string) $product->cover_alt) === '') {
            return 'Describe the cover art for people who cannot see it before publishing.';
        }
        if ($requireApproval && ($product->cover_approval_hash === null || $product->cover_approved_at === null || $product->cover_approved_by === null
            || ! hash_equals($product->cover_approval_hash, $this->coverBinding($product)))) {
            return 'Approve the chosen cover art.';
        }

        return null;
    }

    /** Why the image cannot be shown publicly, or null when it is a ready, scanned, intact cover (with every file intact when asked). */
    private function imageProblem(SiteImage $image, bool $verifyFiles = true): ?string
    {
        $scan = $image->evidence['source_scan'] ?? [];
        if ($image->slot !== self::COVER_SLOT || $image->status !== 'ready' || ! SiteImageManifest::matches($image)
            || ($scan['status'] ?? null) !== 'clean' || ! is_string($scan['sha256'] ?? null) || ! hash_equals($image->source_sha256, $scan['sha256'])
            || ($verifyFiles && ! ScanEngines::accepted($scan['engine'] ?? null))) {
            return 'The chosen cover art is not ready. Check its status under Site images.';
        }
        if ($verifyFiles) {
            $files = app(SiteImageFiles::class);
            foreach ($image->variants as $variant) {
                if ($files->verifiedBytes($variant->setRelation('image', $image)) === null) {
                    return 'A file of the chosen cover art is missing or damaged. Upload it again.';
                }
            }
        }

        return null;
    }

    private function demoProblem(SoundKitProduct $product, ?SoundKitDemo $demo, bool $verifyFiles, bool $requireApproval): ?string
    {
        if ($product->demo_id === null) {
            return 'Upload the demo audio before publishing.';
        }
        if ($demo === null || $demo->sound_kit_product_id !== $product->id) {
            return 'The demo audio is not available. Upload it again.';
        }
        if ($demo->status === 'failed') {
            return self::FAILED_MESSAGES[$demo->failure_code] ?? 'The demo could not be prepared ('.($demo->failure_code ?? 'unknown').'). Upload a new demo.';
        }
        if ($demo->status !== 'ready') {
            if ($demo->failure_code === 'tool_unavailable') {
                return 'The demo is waiting: the media tools are unavailable on the server (tool_unavailable). Retry it once an operator has restored them; the upload is not at fault.';
            }

            return 'The demo is still being prepared'.($demo->failure_code !== null ? ' ('.$demo->failure_code.'); retry it once the cause is fixed' : '').'.';
        }
        $scan = $demo->evidence['source_scan'] ?? [];
        $tagScan = $demo->evidence['tag_scan'] ?? [];
        // Whether the scan engine counts as evidence is decided where the asset is approved and the kit published (as for the kit
        // archive and album covers); the immutable rows are not re-judged against it on every page view.
        if (($scan['status'] ?? null) !== 'clean' || ! is_string($scan['sha256'] ?? null) || ! hash_equals($demo->source_sha256, $scan['sha256'])
            || ($tagScan['status'] ?? null) !== 'clean' || ! is_string($tagScan['sha256'] ?? null) || ! hash_equals((string) ($demo->evidence['tag_sha256'] ?? ''), $tagScan['sha256'])
            || ($verifyFiles && (! ScanEngines::accepted($scan['engine'] ?? null) || ! ScanEngines::accepted($tagScan['engine'] ?? null)))
            || ! is_string($demo->preview_sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $demo->preview_sha256) !== 1 || ! self::previewPathValid($demo->preview_path)
            || ! is_int($demo->duration_seconds) || $demo->duration_seconds < 1 || ! is_array($demo->waveform) || $demo->waveform === []) {
            return 'The demo audio has no clean scan and preview evidence. Upload it again.';
        }
        if ($verifyFiles && self::previewPath($demo) === null) {
            return 'The stored demo preview is missing or damaged. Upload it again.';
        }
        if ($requireApproval && ($product->demo_approval_hash === null || $product->demo_approved_at === null || $product->demo_approved_by === null
            || ! hash_equals($product->demo_approval_hash, $this->demoBinding($product, $demo)))) {
            return 'Approve the demo audio.';
        }

        return null;
    }

    public static function previewPathValid(mixed $path): bool
    {
        return is_string($path) && preg_match('~\Asound-kit-demos/revisions/[a-f0-9-]{36}/preview\.mp3\z~D', $path) === 1;
    }

    /** The prepared preview's private file path, only when its size and SHA-256 still match the retained evidence. */
    public static function previewPath(SoundKitDemo $demo): ?string
    {
        try {
            if (! self::previewPathValid($demo->preview_path) || ! is_string($demo->preview_sha256) || ! is_int($demo->preview_size_bytes) || $demo->preview_size_bytes < 1) {
                return null;
            }
            $path = app(PrivateMediaFiles::class)->resolve($demo->preview_path);
            clearstatcache(true, $path);

            return filesize($path) === $demo->preview_size_bytes && hash_equals($demo->preview_sha256, (string) hash_file('sha256', $path)) ? $path : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function dispatch(SoundKitDemo $demo): void
    {
        // The row and its upload are committed, so a queue outage must not remove either: the demo waits and staff retry it.
        $send = static function () use ($demo): void {
            try {
                ProcessSoundKitDemo::dispatch($demo->id)->onQueue(config('media.queue'));
            } catch (Throwable $exception) {
                report($exception);
            }
        };
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);
        } else {
            $send();
        }
    }

    private function refuseWhilePublished(SoundKitProduct $product, string $field): void
    {
        if ($product->is_published) {
            $this->reject($field, 'Unpublish the kit before changing its '.($field === 'cover' ? 'cover art' : 'demo audio').'.');
        }
    }

    private function alt(mixed $value): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->reject('cover_alt', 'Enter plain text.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F<>]/u', $value) === 1) {
            $this->reject('cover_alt', 'Describe the cover art in 1 to 200 characters of plain text on one line.');
        }

        return $value;
    }

    private function lockProduct(SoundKitProduct $product, int $expectedRevision): SoundKitProduct
    {
        $locked = $product->exists ? SoundKitProduct::query()->lockForUpdate()->find($product->getKey()) : null;
        if ($locked === null) {
            $this->reject('title', 'This kit product is no longer available. Reload and choose a current product.');
        }
        if ($locked->revision !== $expectedRevision) {
            $this->reject('title', 'This kit product changed after you opened it. Reload it and review it again.');
        }

        return $locked;
    }

    /** Cheap authority check before a whole-file copy; the transaction re-reads it under a lock. */
    private function authorise(User $actor): void
    {
        $current = $actor->exists ? User::query()->find($actor->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog');
        if (! AdminMultiFactor::satisfiedBy($current)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }
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

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
