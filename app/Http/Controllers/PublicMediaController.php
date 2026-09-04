<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\VerifiedMedia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicMediaController extends Controller
{
    public function __invoke(MediaAsset $asset): BinaryFileResponse
    {
        abort_unless($asset->isPublicDerivative() && $asset->status === 'ready' && $asset->disk === 'local' && $asset->track->status === 'published', 404);
        abort_unless(app(PublicationReadiness::class)->blockers($asset->track) === [], 404);
        abort_unless($asset->track->assets()->where('role', $asset->role)->where('status', 'ready')->latest('id')->value('id') === $asset->id, 404);
        $path = app(VerifiedMedia::class)->path($asset);
        abort_unless($path !== null, 404);

        return response()->file($path, [
            'Content-Type' => $asset->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function operator(MediaAsset $asset): BinaryFileResponse
    {
        abort_unless($asset->isPublicDerivative(), 404);
        $path = app(VerifiedMedia::class)->path($asset);
        abort_unless($path !== null, 404);

        return response()->file($path, [
            'Content-Type' => $asset->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ])->setPrivate();
    }
}
