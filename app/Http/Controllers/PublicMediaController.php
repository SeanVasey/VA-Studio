<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\TrackVisibility;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\PreviewTag\PreviewTagResolver;
use App\Domain\Media\VerifiedMedia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PublicMediaController extends Controller
{
    public function __invoke(MediaAsset $asset): Response
    {
        abort_unless($asset->isPublicDerivative() && $asset->status === 'ready' && $asset->disk === 'local' && TrackVisibility::isLive($asset->track->status), 404);
        abort_unless(app(PublicationReadiness::class)->blockers($asset->track) === [], 404);
        abort_unless($asset->track->assets()->where('role', $asset->role)->where('status', 'ready')->latest('id')->value('id') === $asset->id, 404);
        if (in_array($asset->role, MediaAsset::PREVIEW_ROLES, true)) {
            // The preview setting is read on every request, so a switch is effective at once. A page that was loaded before the switch
            // and still names the other variant is sent to the current one instead of being served the variant that is now off.
            $served = app(PreviewTagResolver::class)->publicPreview($asset->track);
            abort_unless($served !== null, 404);
            if ($served->id !== $asset->id) {
                return redirect()->route('media.public', ['asset' => $served->id], 302)->header('Cache-Control', 'no-store');
            }
        }
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
