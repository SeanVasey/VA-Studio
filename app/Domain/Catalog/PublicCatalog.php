<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\Inventory\SelectionInventory;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\PreviewTag\PreviewTagResolver;
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

    /** Listed (published) tracks only. Callers that point at a track for others, such as legacy redirects, use this. */
    public function track(string $slug): array
    {
        return $this->project(collect([$this->publishedTrack($slug, false)]));
    }

    /**
     * A track page by its own URL: the projection plus whether the track is unlisted, so the page can be served `noindex`
     * and without structured data. Draft, scheduled and private tracks are not found here.
     *
     * @return array{projection: array, unlisted: bool}
     */
    public function direct(string $slug): array
    {
        $track = $this->publishedTrack($slug, true);

        return ['projection' => $this->project(collect([$track])), 'unlisted' => $track->status === TrackVisibility::UNLISTED];
    }

    /** Internal model only. HTTP embeds must explicitly project public text and route IDs. */
    public function preview(string $slug): MediaAsset
    {
        $track = $this->publishedTrack($slug);
        // The preview the setting selects (tagged or its verified untagged sibling); eligibility above already proved the tagged identity.
        $preview = app(PreviewTagResolver::class)->publicPreview($track, $track->assets);
        abort_unless($preview, 404);
        $preview->setRelation('track', $track);

        return $preview;
    }

    /**
     * By its own URL, preview or license link. With `$direct`, unlisted tracks are found too; either way the same
     * eligibility checks apply.
     */
    private function publishedTrack(string $slug, bool $direct = true): Track
    {
        $track = ($direct ? $this->directQuery() : $this->query())->where('slug', $slug)->first();
        abort_unless($track && $this->eligible($track), 404);

        return $track;
    }

    public function license(string $slug, string $revisionId): array
    {
        $track = $this->publishedTrack($slug);
        $offer = $track->offers->first(fn ($offer) => $offer->is_active && (string) $offer->currentRevision?->id === $revisionId);
        abort_unless($offer, 404);

        return app(PublicLicenseDisclosure::class)->fromRevision($offer->currentRevision);
    }

    public function selections(array $ids): array
    {
        // At most ten public records; never treat absence from a catalog page as withdrawal.
        return $this->project($this->query()->whereIn('id', $ids)->limit(10)->get()->filter(fn (Track $track) => $this->eligible($track)));
    }

    /**
     * Browse cards for the given track IDs, in the order given, for grouping pages such as collections (BL-54). Only listed
     * (published) tracks that pass the same eligibility check as the catalog are returned; a draft, scheduled, private, unlisted
     * or withdrawn track is dropped silently. The card names the track and links to its own page: it carries no offer, price or
     * license, because every track is licensed individually there.
     *
     * @param  list<int>  $ids  at most 100
     * @return list<array<string, mixed>>
     */
    public function browseCards(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $tracks = $this->query()->whereIn('id', array_slice($ids, 0, 100))->get()->filter(fn (Track $track) => $this->eligible($track))->keyBy('id');
        $cards = [];
        foreach ($ids as $id) {
            $track = $tracks->get($id);
            if ($track === null) {
                continue;
            }
            $assets = $track->assets->where('status', 'ready')->sortByDesc('id');
            $preview = $assets->firstWhere('role', 'preview_tagged');
            $cards[] = [
                'id' => (string) $track->id, 'slug' => $track->slug, 'path' => route('tracks.show', $track->slug, false),
                'title' => $track->title, 'artist' => $track->artist, 'bpm' => $track->bpm, 'musicalKey' => $track->musical_key,
                'genre' => $track->genre, 'durationSeconds' => $preview->technical_metadata['duration_seconds'],
                'artworkUrl' => route('media.public', $assets->firstWhere('role', 'artwork')->id),
            ];
        }

        return $cards;
    }

    /** Ordered editorial summaries only; private previews omit destinations at the server boundary. */
    public function relatedLinks(array $ids, bool $includeHref = true): array
    {
        if (! array_is_list($ids) || count($ids) > 6) {
            throw ValidationException::withMessages(['related_track_ids' => 'Choose up to six distinct tracks using positive integer IDs.']);
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw ValidationException::withMessages(['related_track_ids' => 'Choose up to six distinct tracks using positive integer IDs.']);
            }
        }
        if (count(array_unique($ids, SORT_STRING)) !== count($ids)) {
            throw ValidationException::withMessages(['related_track_ids' => 'Choose up to six distinct tracks using positive integer IDs.']);
        }
        if ($ids === []) {
            return [];
        }
        $tracks = $this->query()->whereIn('id', $ids)->get()->filter(fn (Track $track) => $this->eligible($track))->keyBy('id');
        $links = [];
        foreach ($ids as $id) {
            if (($track = $tracks->get($id)) !== null) {
                $links[] = ['title' => $track->title, 'artist' => $track->artist]
                    + ($includeHref ? ['href' => route('tracks.show', $track->slug, false)] : []);
            }
        }

        return $links;
    }

    /** Current eligible choices for the authorized staff editor; bound candidate hydration and evidence checks. */
    public function relatedChoices(string $search = ''): array
    {
        validator(['search' => $search], ['search' => ['string', 'max:100']])->validate();
        $query = $this->query();
        if (($search = trim($search)) !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(fn (Builder $match) => $match
                ->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(artist) LIKE ? ESCAPE '!'", [$pattern]));
        }

        return $query->orderBy('title')->orderBy('artist')->orderBy('id')->limit(self::SCAN_LIMIT)->get()
            ->filter(fn (Track $track) => $this->eligible($track))
            ->mapWithKeys(fn (Track $track): array => [$track->id => $track->title.' · '.$track->artist])->all();
    }

    /** Every listing, search, filter, related-track and ID lookup is published only: an unlisted track is never discoverable. */
    private function query(): Builder
    {
        return Track::query()->where('status', TrackVisibility::PUBLISHED)->with('assets');
    }

    private function directQuery(): Builder
    {
        return Track::query()->whereIn('status', TrackVisibility::LIVE)->with('assets');
    }

    private function eligible(Track $track): bool
    {
        // Listing evidence: a private deliverable is not hashed per public request; checkout and delivery hash it again.
        if (app(PublicationReadiness::class)->listingBlockers($track) !== []) {
            return false;
        }
        $track->setRelation('offers', $track->offers->filter(fn ($offer) => app(SelectionInventory::class)->available($offer->current_revision_id)));

        return $track->offers->isNotEmpty();
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
                // Duration and waveform are the tagged identity's (the untagged variant carries identical ones); only the URL follows the setting.
                $preview = $assets->firstWhere('role', 'preview_tagged');
                $served = app(PreviewTagResolver::class)->publicPreview($track, $track->assets) ?? $preview;

                return [
                    'id' => (string) $track->id, 'slug' => $track->slug, 'title' => $track->title, 'artist' => $track->artist,
                    'bpm' => $track->bpm, 'musicalKey' => $track->musical_key, 'genre' => $track->genre, 'mood' => $track->mood,
                    'durationSeconds' => $preview->technical_metadata['duration_seconds'], 'tags' => $track->tags ?? [], 'waveform' => $preview->technical_metadata['waveform'],
                    'artworkUrl' => route('media.public', $assets->firstWhere('role', 'artwork')->id),
                    'previewUrl' => route('media.public', $served->id),
                    'shareUrl' => route('tracks.show', $track->slug),
                    'offers' => $track->offers->where('is_active', true)->map(function ($offer) use ($track) {
                        $revision = $offer->currentRevision;
                        $license = $revision->snapshot['license'];

                        return [
                            'id' => (string) $offer->id, 'offerRevisionId' => (string) $revision->id, 'licenseVersionId' => (string) $license['id'], 'licenseName' => $license['name'],
                            'priceMinor' => $revision->price_minor, 'currency' => $revision->currency, 'deliverableRoles' => $license['required_asset_roles'],
                            'licenseUrl' => route('tracks.license', ['slug' => $track->slug, 'revision' => $revision->id]),
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
