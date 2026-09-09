<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PublicCatalog
{
    public const PAGE_SIZE = 12;
    public const SCAN_LIMIT = 48;

    public function page(Request $request): array
    {
        $input = validator($request->query(), [
            'q' => ['nullable', 'string', 'max:100'],
            'genre' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', Rule::in(['featured', 'tempo', 'title'])],
            'cursor' => ['nullable', 'string', 'max:4096'],
        ])->validate();
        $filters = ['q' => trim($input['q'] ?? ''), 'genre' => trim($input['genre'] ?? ''), 'sort' => $input['sort'] ?? 'featured'];
        $path = $request->routeIs('catalog.index') ? '/api/catalog' : '/';
        $cursor = $this->decodeCursor($input['cursor'] ?? null, $filters);
        $query = $this->query();
        foreach (preg_split('/\s+/u', mb_strtolower($filters['q']), -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(function (Builder $match) use ($pattern): void {
                foreach (['title', 'artist', 'genre', 'mood', 'musical_key', 'tags'] as $field) {
                    $match->orWhereRaw("LOWER($field) LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        if ($filters['genre'] !== '') {
            $query->where('genre', $filters['genre']);
        }
        $orders = match ($filters['sort']) {
            'tempo' => ['bpm' => 'asc', 'title' => 'asc', 'id' => 'asc'],
            'title' => ['title' => 'asc', 'id' => 'asc'],
            default => ['published_at' => 'desc', 'id' => 'desc'],
        };
        $backwards = $cursor?->pointsToPreviousItems() ?? false;
        foreach ($orders as $field => $direction) {
            $query->whereNotNull($field)->orderBy($field, $backwards ? ($direction === 'asc' ? 'desc' : 'asc') : $direction);
        }
        if ($cursor !== null) {
            $query->where(function (Builder $boundary) use ($orders, $cursor, $backwards): void {
                $prefix = [];
                foreach ($orders as $field => $direction) {
                    $boundary->orWhere(function (Builder $part) use ($prefix, $field, $direction, $cursor, $backwards): void {
                        foreach ($prefix as $equalField) {
                            $part->where($equalField, $cursor->parameter($equalField));
                        }
                        $greater = ($direction === 'asc') !== $backwards;
                        $part->where($field, $greater ? '>' : '<', $cursor->parameter($field));
                    });
                    $prefix[] = $field;
                }
            });
        }
        // Bound hydrated candidates and current-evidence checks. No offset or total query.
        $candidates = $query->limit(self::SCAN_LIMIT + 1)->get();
        $hasMore = $candidates->count() > self::SCAN_LIMIT;
        $candidates = $candidates->take(self::SCAN_LIMIT);
        $eligible = collect();
        $scanned = collect();
        foreach ($candidates as $track) {
            $scanned->push($track);
            if ($this->eligible($track)) {
                $eligible->push($track);
            }
            if ($eligible->count() === self::PAGE_SIZE) {
                break;
            }
        }
        $more = $scanned->count() < $candidates->count() || $hasMore;
        if ($backwards) {
            $eligible = $eligible->reverse()->values();
            $scanned = $scanned->reverse()->values();
        }
        $link = function (?Track $track, bool $next) use ($filters, $orders, $path): ?string {
            if (! $track) {
                return null;
            }
            $parameters = [];
            foreach ($orders as $field => $direction) {
                $parameters[$field] = $track->getRawOriginal($field);
            }
            $token = Crypt::encryptString(json_encode(['version' => 1, 'filters' => $filters, 'position' => (new Cursor($parameters, $next))->encode()], JSON_THROW_ON_ERROR));

            return $path.'?'.http_build_query($filters + ['cursor' => $token]);
        };

        return $this->project($eligible) + [
            'catalogPage' => [
                'filters' => $filters,
                'previousUrl' => ($backwards ? $more : $cursor !== null) ? $link($scanned->first(), false) : null,
                'nextUrl' => ($backwards ? $cursor !== null : $more) ? $link($scanned->last(), true) : null,
                'restartUrl' => $path.'?'.http_build_query($filters),
                'currentUrl' => $path.'?'.http_build_query($filters + (isset($input['cursor']) ? ['cursor' => $input['cursor']] : [])),
                'hasCursor' => $cursor !== null,
            ],
        ];
    }

    public function track(string $slug): array
    {
        $track = $this->query()->where('slug', $slug)->first();
        abort_unless($track && $this->eligible($track), 404);

        return $this->project(collect([$track]));
    }

    public function selections(array $ids): array
    {
        // At most ten public records; never treat absence from a catalog page as withdrawal.
        return $this->project($this->query()->whereIn('id', $ids)->limit(10)->get()->filter(fn (Track $track) => $this->eligible($track)));
    }

    private function query(): Builder
    {
        return Track::query()->where('status', 'published')->with('assets');
    }

    private function eligible(Track $track): bool
    {
        return app(PublicationReadiness::class)->blockers($track) === [];
    }

    private function decodeCursor(?string $token, array $filters): ?Cursor
    {
        if ($token === null) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
            if (($data['version'] ?? null) === 1 && ($data['filters'] ?? null) === $filters && is_string($data['position'] ?? null)) {
                $cursor = Cursor::fromEncoded($data['position']);
                if ($cursor !== null) {
                    return $cursor;
                }
            }
        } catch (Throwable) {
            // Encrypted, filter-bound positions do not reveal ineligible scan boundaries.
        }
        throw ValidationException::withMessages(['cursor' => 'This catalog position is no longer valid. Start from the catalog again.']);
    }

    private function project(Collection $tracks): array
    {
        $licenses = $tracks->flatMap(fn ($track) => $track->offers->where('is_active', true)->map(fn ($offer) => $offer->currentRevision->snapshot['license']))->unique('id');

        return [
            'tracks' => $tracks->map(function (Track $track) {
                $assets = $track->assets->where('status', 'ready')->sortByDesc('id');
                $preview = $assets->firstWhere('role', 'preview_tagged');

                return [
                    'id' => (string) $track->id, 'slug' => $track->slug, 'title' => $track->title, 'artist' => $track->artist,
                    'bpm' => $track->bpm, 'musicalKey' => $track->musical_key, 'genre' => $track->genre, 'mood' => $track->mood,
                    'durationSeconds' => $preview->technical_metadata['duration_seconds'], 'tags' => $track->tags ?? [], 'waveform' => $preview->technical_metadata['waveform'],
                    'artworkUrl' => route('media.public', $assets->firstWhere('role', 'artwork')->id),
                    'previewUrl' => route('media.public', $preview->id),
                    'shareUrl' => route('tracks.show', $track->slug),
                    'offers' => $track->offers->where('is_active', true)->map(function ($offer) {
                        $revision = $offer->currentRevision;
                        $license = $revision->snapshot['license'];

                        return [
                            'id' => (string) $offer->id, 'offerRevisionId' => (string) $revision->id, 'licenseVersionId' => (string) $license['id'], 'licenseName' => $license['name'],
                            'priceMinor' => $revision->price_minor, 'currency' => $revision->currency, 'deliverableRoles' => $license['required_asset_roles'],
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
            'licenseTiers' => $licenses->map(fn ($license) => [
                'id' => (string) $license['id'], 'name' => $license['name'], 'version' => $license['version'], 'type' => $license['type'],
                'features' => $license['features'], 'requiredAssetRoles' => $license['required_asset_roles'],
            ])->values()->all(),
        ];
    }
}
