<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseVersion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * DD-31: the public license comparison is read from the license versions themselves, never typed into a site release.
 *
 * A version is listed while it is published and inside its offer availability window [effective_from, effective_until),
 * exactly as VerifiedLicense decides availability, and only when its terms are typed (schema 2 and later): each section's
 * lines are LicenseVersion::features(), the generator whose output the storefront cards and the offer snapshot carry.
 * Schema 1 versions hold reviewed free prose with no typed meaning, so they are not listed here. Nothing is authored,
 * stored or changed by this read; a successor version appears when it is published, with no site release edit.
 */
final class PublishedLicenseComparison
{
    /** Terms schemas whose public lines are generated from typed terms. */
    public const TYPED_SCHEMAS = [2, 3, 4, 5];

    /**
     * One entry per listed version, ordered by template and then version identity, so the order never depends on names or
     * on publication timing. The shape is the storefront's license tier shape.
     *
     * @return list<array{id: string, name: string, version: int|string, type: string, features: list<string>, requiredAssetRoles: list<string>}>
     */
    public function versions(?CarbonInterface $at = null): array
    {
        $at ??= now();
        $verified = app(VerifiedLicense::class);
        $listed = [];
        foreach ($this->candidates($at)->with('template')->get() as $version) {
            // The SQL window is only a prefilter: availability, the reviewed-evidence chain included, is VerifiedLicense's.
            if (! $verified->available($version, $at)) {
                continue;
            }
            $template = $version->template;
            $listed[] = [
                'id' => (string) $version->id, 'name' => $template->name, 'version' => $version->version, 'type' => $template->type,
                'features' => $version->features(), 'requiredAssetRoles' => $version->requiredAssetRoles(),
            ];
        }

        return $listed;
    }

    /**
     * Whether any version is a candidate, without verifying its evidence: the inexpensive check the site chrome uses to
     * decide whether to link the page. The page itself lists only verified versions and answers 404 when none remains.
     */
    public function offered(?CarbonInterface $at = null): bool
    {
        return $this->candidates($at ?? now())->exists();
    }

    /** @return Builder<LicenseVersion> */
    private function candidates(CarbonInterface $at): Builder
    {
        return LicenseVersion::query()
            ->where('status', 'published')->whereNotNull('published_at')
            ->whereIn('terms_schema_version', self::TYPED_SCHEMAS)
            ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn (Builder $query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->orderBy('license_template_id')->orderBy('id');
    }
}
