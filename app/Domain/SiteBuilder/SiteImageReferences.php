<?php

namespace App\Domain\SiteBuilder;

use App\Domain\Media\ScanEngines;
use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\Models\SiteReleaseImage;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The site images a v3 release shows (D-25). Each reference names a ready image of its slot and pins that image's manifest,
 * so a release can only ever show the exact bytes it was reviewed with.
 */
final class SiteImageReferences
{
    /** Each index slot and the reference's path under content.images. */
    public const PATHS = ['hero_desktop' => 'hero.desktop', 'hero_mobile' => 'hero.mobile', 'studio' => 'studio', 'share' => 'share'];

    private const DAMAGED = 'An image in the retained site release failed its integrity check.';

    /**
     * The references in validated content, keyed by slot; slots showing their built-in image are absent.
     *
     * @return array<string, array{id: mixed, manifest: mixed}>
     */
    public static function of(array $content): array
    {
        if (($content['schema_version'] ?? null) !== 3 || ! is_array($content['images'] ?? null)) {
            return [];
        }
        $references = [];
        foreach (self::PATHS as $slot => $path) {
            $reference = data_get($content['images'], $path);
            if (is_array($reference)) {
                $references[$slot] = ['id' => $reference['id'] ?? null, 'manifest' => $reference['manifest'] ?? null];
            }
        }

        return $references;
    }

    /**
     * Pins each referenced image's manifest into new content. The caller holds a transaction: the image rows are locked, so an image
     * still being processed is refused rather than read half-finished. A manifest the caller supplied must still match.
     */
    public function pin(array $content): array
    {
        foreach (self::of($content) as $slot => $reference) {
            $path = self::PATHS[$slot];
            $field = 'content.images.'.$path;
            $image = is_int($reference['id']) ? SiteImage::query()->whereKey($reference['id'])->lockForUpdate()->first() : null;
            // Locking reads see the latest commit. A plain read here could use a snapshot taken before this lock waited for the
            // image's completion, and find the image ready but none of its variants.
            $image?->setRelation('variants', $image->variants()->sharedLock()->get());
            if ($image === null || $image->slot !== $slot || ! $this->intact($image)) {
                throw ValidationException::withMessages([$field => 'Choose a ready image uploaded for this slot ('.SiteImageSlot::label($slot).').']);
            }
            if ($reference['manifest'] !== null && ! (is_string($reference['manifest']) && hash_equals($image->manifest_sha256, $reference['manifest']))) {
                throw ValidationException::withMessages([$field => 'This image is not the version you reviewed. Choose it again.']);
            }
            data_set($content, 'images.'.$path.'.manifest', $image->manifest_sha256);
        }

        return $content;
    }

    /** Records the release's references in the index, inside the release's own creation transaction. */
    public function index(SiteRelease $release, array $content): void
    {
        foreach (self::of($content) as $slot => $reference) {
            SiteReleaseImage::create(['site_release_id' => $release->id, 'slot' => $slot, 'site_image_id' => $reference['id'], 'created_at' => now()]);
        }
    }

    /**
     * Database-only checks for every read of a v3 release: each reference is a ready image of its slot whose recomputed manifest
     * matches the pinned one and whose scan evidence is accepted, and the index lists exactly these images.
     */
    public function verify(SiteRelease $release, array $content): void
    {
        $references = self::of($content);
        $indexed = SiteReleaseImage::query()->where('site_release_id', $release->id)->pluck('site_image_id', 'slot')
            ->map(fn (mixed $id): int => (int) $id)->all();
        $expected = array_map(fn (array $reference): mixed => $reference['id'], $references);
        ksort($indexed);
        ksort($expected);
        if ($indexed !== $expected) {
            throw ValidationException::withMessages(['publication' => self::DAMAGED]);
        }
        $images = $this->images($references);
        foreach ($references as $slot => $reference) {
            $image = $images->get($reference['id']);
            if ($image === null || $image->slot !== $slot || ! is_string($reference['manifest'])
                || ! hash_equals((string) $image->manifest_sha256, $reference['manifest']) || ! $this->intact($image)) {
                throw ValidationException::withMessages(['publication' => self::DAMAGED]);
            }
        }
    }

    /** Hashes every stored file of the referenced images. Publishing runs this before taking the publication lock. */
    public function verifyFiles(array $content): void
    {
        $files = app(SiteImageFiles::class);
        foreach ($this->images(self::of($content)) as $image) {
            foreach ($image->variants as $variant) {
                if ($files->verifiedBytes($variant->setRelation('image', $image)) === null) {
                    throw ValidationException::withMessages(['publication' => 'A stored file of an image in this release failed its integrity check. Restore it from a backup, or publish a release that uses another image.']);
                }
            }
        }
    }

    /** @return Collection<int, SiteImage> */
    private function images(array $references): Collection
    {
        $ids = array_values(array_filter(array_column($references, 'id'), 'is_int'));

        return $ids === [] ? new Collection : SiteImage::query()->with('variants')->whereKey($ids)->get()->keyBy('id');
    }

    private function intact(SiteImage $image): bool
    {
        $scan = $image->evidence['source_scan'] ?? [];

        return $image->status === 'ready' && SiteImageManifest::matches($image)
            && ($scan['status'] ?? null) === 'clean' && is_string($scan['sha256'] ?? null) && hash_equals($image->source_sha256, $scan['sha256'])
            && ScanEngines::accepted($scan['engine'] ?? null);
    }
}
