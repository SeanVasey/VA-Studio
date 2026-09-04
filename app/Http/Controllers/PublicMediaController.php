<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicMediaController extends Controller
{
    public function __invoke(MediaAsset $asset): BinaryFileResponse
    {
        abort_unless($asset->isPublicDerivative() && $asset->status === 'ready' && $asset->disk === 'local' && $asset->track->status === 'published', 404);
        abort_unless(app(PublicationReadiness::class)->blockers($asset->track) === [], 404);
        abort_unless(Storage::disk('local')->exists($asset->storage_path), 404);

        return response()->file(Storage::disk('local')->path($asset->storage_path), [
            'Content-Type' => $asset->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
