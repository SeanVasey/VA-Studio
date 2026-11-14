<?php

namespace Tests\Support;

use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SoundKits\Products\SoundKitDemo;
use App\Domain\SoundKits\Products\SoundKitDemoProcessor;
use App\Domain\SoundKits\Products\SoundKitProduct;
use App\Domain\SoundKits\Products\SoundKitProductMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/** Synthetic cover art and demo audio for sound-kit tests. The caller has bound the testing-only scanner (MediaFixtures::configure()). */
final class SoundKitMediaFixtures
{
    /** A processed square cover through the real site-image pipeline. */
    public static function cover(User $uploader): SiteImage
    {
        return SiteImageFixtures::ready(SoundKitProductMedia::COVER_SLOT, $uploader, name: 'synthetic-kit-cover');
    }

    /** Uploads a demo through the writer and processes it through the real pipeline (scan, tag, MP3). */
    public static function demo(SoundKitProduct $product, User $actor, ?string $wav = null): SoundKitDemo
    {
        $upload = UploadedFile::fake()->createWithContent('demo.wav', $wav ?? MediaFixtures::wav(1.2, 330));
        $demo = app(SoundKitProductMedia::class)->uploadDemo($product, $product->refresh()->revision, $upload, $actor);

        return app(SoundKitDemoProcessor::class)->handle($demo->id);
    }

    /** A cover and a demo, both chosen, processed and approved by $actor. */
    public static function approved(SoundKitProduct $product, User $actor): SoundKitProduct
    {
        $media = app(SoundKitProductMedia::class);
        $cover = self::cover($actor);
        $product = $media->chooseCover($product, $product->refresh()->revision, $cover->id, 'A synthetic test cover', $actor);
        $product = $media->approveCover($product, $product->revision, $actor);
        $demo = self::demo($product, $actor);
        if ($demo->status !== 'ready') {
            throw new \LogicException('The synthetic demo did not become ready: '.$demo->failure_code);
        }

        return $media->approveDemo($product->refresh(), $product->refresh()->revision, $actor);
    }
}
