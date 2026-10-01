<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Track;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Public destinations only. This read creates no publication, review token or delivery authority. */
final class ReadTrackSharing
{
    public function handle(int $trackId, ?User $actor): array
    {
        // A caller-owned REPEATABLE READ snapshot can predate the persisted authority withdrawal.
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                $this->unavailable();
            }
        }
        // Denied callers do not resolve or lock the supplied catalog locator.
        $this->authorize($actor?->exists ? User::find($actor->getKey()) : null);

        return DB::transaction(function () use ($trackId, $actor): array {
            // Lock both identities before normal Gate/catalog reads establish a MySQL snapshot.
            $current = $actor?->exists ? User::whereKey($actor->getKey())->lockForUpdate()->first() : null;
            $track = $trackId > 0 ? Track::whereKey($trackId)->lockForUpdate()->first() : null;
            $this->authorize($current);
            if ($track === null || ! is_string($track->slug) || strlen($track->slug) > 255
                || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $track->slug) !== 1) {
                $this->unavailable();
            }
            try {
                // A retained table row's status is insufficient: reuse the ordinary public boundary.
                $preview = app(PublicCatalog::class)->preview($track->slug);
            } catch (HttpExceptionInterface $exception) {
                if ($exception->getStatusCode() !== 404) {
                    throw $exception;
                }
                $this->unavailable();
            }
            if ($preview->track->id !== $track->id) {
                $this->unavailable();
            }
            $origin = $this->origin();
            $trackUrl = $origin.route('tracks.show', ['slug' => $track->slug], false);
            $embedUrl = $origin.route('embeds.show', ['slug' => $track->slug], false);
            $embedCode = '<iframe src="'.htmlspecialchars($embedUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                .'" title="VASEY.AUDIO tagged track preview" loading="lazy" referrerpolicy="no-referrer"'
                .' style="width:100%;max-width:720px;height:300px;border:0"></iframe>';

            return ['title' => $preview->track->title, 'trackUrl' => $trackUrl, 'embedUrl' => $embedUrl, 'embedCode' => $embedCode];
        });
    }

    private function authorize(?User $actor): void
    {
        if ($actor === null || ! Gate::forUser($actor)->allows('administer-catalog')
            || ! AdminMultiFactor::satisfiedBy($actor)) {
            throw new AuthorizationException;
        }
    }

    private function origin(): string
    {
        $value = config('app.url');
        if (! is_string($value) || $value === '' || strlen($value) > 2048
            || preg_match('/[\x00-\x20\x7F\\\\]/', $value) !== 0
            || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $this->unavailable();
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            $this->unavailable();
        }

        return rtrim($value, '/');
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['sharing' => 'Public sharing is unavailable for this track. Refresh the catalog or check the configured store origin.']);
    }
}
