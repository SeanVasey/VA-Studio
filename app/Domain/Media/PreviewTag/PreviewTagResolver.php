<?php

namespace App\Domain\Media\PreviewTag;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\VerifiedMedia;
use Illuminate\Support\Collection;

/**
 * The read side of the preview-tag setting: which ready preview the public hears for a track right now.
 *
 * Effective setting = the track's override, else the store default, else tagged. The tagged preview is the preview identity
 * (offer snapshots, readiness and recording bindings pin it); the untagged preview is its sibling from the SAME processing
 * run. The untagged one is chosen only when the setting is off AND that sibling exists and passes the same verified-media
 * check as every public file. Anything else falls back to the tagged preview, never to nothing, and never to any other
 * role: the full-quality delivery MP3 and the master are not previews and have no path through here.
 *
 * Reads only. The decision is made per request from the database, so a switch is effective on the next request with no
 * reprocessing; the only delay is a browser or CDN keeping a file it already fetched (see docs/media-processing.md).
 */
final class PreviewTagResolver
{
    /** Tagged is the default Sean chose on 2026-11-11 until staff set another. */
    public const DEFAULT_TAGGED = true;

    public function defaultTagged(): bool
    {
        return $this->settings(null)['default'];
    }

    /** The track's own setting: true, false, or null when it follows the default. */
    public function override(int $trackId): ?bool
    {
        return $this->settings($trackId)['override'];
    }

    /** The revision an editor must send back: the default's when `$trackId` is null, else that track's. 0 when nothing was ever saved. */
    public function revision(?int $trackId = null): int
    {
        return (int) PreviewTagSetting::query()->where('scope_key', $trackId === null ? PreviewTagSetting::DEFAULT_KEY : PreviewTagSetting::trackKey($trackId))->value('revision');
    }

    /**
     * What staff see for one track, from database records only (no file is opened): the setting, the effective choice, whether the
     * current preview identity recorded an untagged sibling, and what the public hears as a result.
     *
     * @return array{override: ?bool, default: bool, effective_tagged: bool, untagged_recorded: bool, public: string, summary: string}
     */
    public function describe(Track $track): array
    {
        $settings = $this->settings((int) $track->id);
        $effective = $settings['override'] ?? $settings['default'];
        $anchor = $this->anchor($track);
        $recorded = $anchor !== null && $this->sibling($anchor) !== null;
        $public = $anchor === null ? 'none' : (! $effective && $recorded ? 'untagged' : 'tagged');
        $source = $settings['override'] === null ? 'store default' : 'this track';
        $summary = match (true) {
            $anchor === null => 'No preview yet',
            $public === 'untagged' => 'Public hears: untagged ('.$source.')',
            ! $effective => 'Public hears: tagged (untagged requested by '.$source.' but no untagged variant exists)',
            default => 'Public hears: tagged ('.$source.')',
        };

        return ['override' => $settings['override'], 'default' => $settings['default'], 'effective_tagged' => $effective,
            'untagged_recorded' => $recorded, 'public' => $public, 'summary' => $summary.($anchor === null ? '' : ($recorded ? '; untagged variant exists' : '; no untagged variant'))];
    }

    public function effectiveTagged(int $trackId): bool
    {
        $settings = $this->settings($trackId);

        return $settings['override'] ?? $settings['default'];
    }

    /** The current preview identity: the newest ready tagged preview. Not checked against its file. */
    public function anchor(Track $track, ?Collection $assets = null): ?MediaAsset
    {
        return $assets !== null
            ? $assets->where('status', 'ready')->where('role', 'preview_tagged')->sortByDesc('id')->first()
            : $track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->first();
    }

    /** The untagged sibling recorded for this tagged preview (same run, same master), if any. Not checked against its file. */
    public function sibling(MediaAsset $anchor, ?Collection $assets = null): ?MediaAsset
    {
        if (! $anchor->processing_run_id) {
            return null;
        }
        $candidates = $assets !== null
            ? $assets->where('status', 'ready')->where('role', 'preview_untagged')->sortByDesc('id')
            : MediaAsset::query()->where('track_id', $anchor->track_id)->where('role', 'preview_untagged')->where('status', 'ready')->orderByDesc('id')->get();

        return $candidates->first(fn (MediaAsset $item): bool => $item->track_id === $anchor->track_id
            && $item->processing_run_id === $anchor->processing_run_id && $item->parent_asset_id === $anchor->parent_asset_id);
    }

    /** The verified untagged preview of the track's current identity, or null: what staff need before a track can be switched off. */
    public function verifiedUntagged(Track $track): ?MediaAsset
    {
        $anchor = $this->anchor($track);
        $sibling = $anchor === null ? null : $this->sibling($anchor);

        return $sibling !== null && app(VerifiedMedia::class)->available($sibling) ? $sibling : null;
    }

    /**
     * The preview the public is served: the tagged identity, or its verified untagged sibling when the setting is off.
     *
     * @param  ?Collection<int, MediaAsset>  $assets  the track's already loaded assets, when the caller has them
     */
    public function publicPreview(Track $track, ?Collection $assets = null): ?MediaAsset
    {
        $anchor = $this->anchor($track, $assets);
        if ($anchor === null || $this->effectiveTagged((int) $track->id)) {
            return $anchor;
        }
        $sibling = $this->sibling($anchor, $assets);

        return $sibling !== null && app(VerifiedMedia::class)->available($sibling) ? $sibling : $anchor;
    }

    /**
     * Recorded (database-only) coverage of the untagged variant, for the staff page: tracks with a current tagged preview, and how
     * many of those runs also recorded an untagged one.
     *
     * @return array{previewed: int, with_untagged: int}
     */
    public function coverage(): array
    {
        $anchors = MediaAsset::query()->whereIn('id', MediaAsset::query()->selectRaw('max(id)')->where('role', 'preview_tagged')->where('status', 'ready')->groupBy('track_id'))
            ->get(['id', 'processing_run_id']);
        $runs = $anchors->pluck('processing_run_id')->filter()->all();
        $covered = $runs === [] ? [] : MediaAsset::query()->where('role', 'preview_untagged')->where('status', 'ready')->whereIn('processing_run_id', $runs)->pluck('processing_run_id')->unique()->all();

        return ['previewed' => $anchors->count(), 'with_untagged' => count(array_intersect($runs, $covered))];
    }

    /** @return array{default: bool, override: ?bool} */
    private function settings(?int $trackId): array
    {
        $keys = [PreviewTagSetting::DEFAULT_KEY, ...($trackId === null ? [] : [PreviewTagSetting::trackKey($trackId)])];
        $rows = PreviewTagSetting::query()->whereIn('scope_key', $keys)->get()->keyBy('scope_key');
        $default = $rows->get(PreviewTagSetting::DEFAULT_KEY)?->tagged;

        return ['default' => $default ?? self::DEFAULT_TAGGED,
            'override' => $trackId === null ? null : $rows->get(PreviewTagSetting::trackKey($trackId))?->tagged];
    }
}
