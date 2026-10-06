<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\ResumableMediaUploads;
use App\Http\Middleware\ResumableUploadPrivacy;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ResumableMediaUploadController
{
    private const FIELDS = ['id', 'trackId', 'role', 'originalName', 'sizeBytes', 'sha256', 'receivedBytes', 'chunkBytes', 'status', 'expiresAt', 'assetId', 'cleanupPending'];

    public function start(Request $request, ResumableMediaUploads $uploads): Response
    {
        $actor = $this->actor($request);
        $data = $this->body($request, ['trackId', 'role', 'sizeBytes', 'sha256', 'originalName']);
        if (! is_int($data['trackId']) || $data['trackId'] < 1 || ! is_int($data['sizeBytes']) || $data['sizeBytes'] < 1
            || ! is_string($data['role']) || ! is_string($data['sha256']) || ! is_string($data['originalName'])) {
            $this->invalid();
        }

        return $this->present($uploads->start(Track::findOrFail($data['trackId']), $data['role'], $data['sizeBytes'], $data['sha256'], $data['originalName'], $actor));
    }

    public function inspect(Request $request, string $upload, ResumableMediaUploads $uploads): Response
    {
        return $this->present($uploads->inspect($upload, $this->actor($request)));
    }

    public function append(Request $request, string $upload, ResumableMediaUploads $uploads): Response
    {
        $actor = $this->actor($request);
        $data = $request->request->all();
        $files = $request->allFiles();
        if (array_keys($data) !== ['offset'] || array_keys($files) !== ['chunk']
            || ! is_string($data['offset']) || preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D', $data['offset']) !== 1
            || ! $files['chunk'] instanceof UploadedFile || ! $files['chunk']->isValid()
            || $files['chunk']->getSize() < 1 || $files['chunk']->getSize() > 8 * 1024 * 1024) {
            $this->invalid();
        }

        return $this->present($uploads->append($upload, (int) $data['offset'], $files['chunk'], $actor));
    }

    public function complete(Request $request, string $upload, ResumableMediaUploads $uploads): Response
    {
        $actor = $this->actor($request);
        $this->body($request, []);
        $uploads->complete($upload, $actor);

        // Never serialize the MediaAsset model or expose a disk/path/part descriptor.
        return $this->present($uploads->inspect($upload, $actor));
    }

    public function cancel(Request $request, string $upload, ResumableMediaUploads $uploads): Response
    {
        $actor = $this->actor($request);
        $this->body($request, []);

        return $this->present($uploads->cancel($upload, $actor));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! Gate::forUser($actor)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($actor)) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    private function body(Request $request, array $fields): array
    {
        $raw = $request->attributes->get('_resumable_body');
        $object = is_string($raw) ? json_decode($raw, false, 3) : null;
        if (! $object instanceof \stdClass) {
            $this->invalid();
        }
        $data = get_object_vars($object);
        if (count($data) !== count($fields) || array_diff(array_keys($data), $fields)) {
            $this->invalid();
        }

        return $data;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['upload' => ['Invalid upload request.']]);
    }

    private function present(array $session): Response
    {
        return ResumableUploadPrivacy::protect(response()->json(['session' => array_intersect_key($session, array_flip(self::FIELDS))]));
    }
}
