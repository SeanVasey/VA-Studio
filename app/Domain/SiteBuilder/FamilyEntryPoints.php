<?php

namespace App\Domain\SiteBuilder;

use App\Domain\Memberships\MembershipPolicy;
use App\Domain\Services\Projects\ServiceProjectPolicy;
use InvalidArgumentException;

/**
 * Storefront entry points of the product families that are built default-off behind their own server-side policy
 * (BL-50, FP-001: no link to a product that is not available). A family's public paths and its header link exist only
 * while that policy is enabled; turning the policy on makes the link appear. Nothing here enables a family, and no
 * family is hidden for good: a family joins this list when it has a policy to read. Families without one yet
 * (merchandise, sound kits, offers, bundles) keep their current behaviour; today none of them is listed here. Collections
 * do have a storefront entry point (the `/collections` pages and navigation item, behind `collections.enabled`, BL-50 follow-up
 * in #304), but they read their own flag rather than this list, so they are not part of it yet.
 */
final class FamilyEntryPoints
{
    /**
     * Path prefixes, matched whole-segment ("/services" covers "/services/web-design" but not "/servicesx"), and the one
     * header link a family shows while enabled. The first matching family wins, so "service-projects" (the test
     * journey, read through `enabled()`) is listed before "services" (the public catalog pages, read through
     * `catalogEnabled()`); the slug "projects" is reserved for the former (ServicePublications::RESERVED_SLUGS).
     * The catalog has no header link of its own. Memberships have no public page; their entry point is the customer
     * library, which reads the same policy.
     */
    public const FAMILIES = [
        'service-projects' => ['paths' => ['/services/projects'], 'link' => ['label' => 'Service projects', 'href' => '/services/projects']],
        'services' => ['paths' => ['/services'], 'link' => null],
        'memberships' => ['paths' => ['/memberships', '/membership', '/production-memberships', '/account/membership-credits'], 'link' => null],
    ];

    public function enabled(string $family): bool
    {
        return match ($family) {
            'service-projects' => app(ServiceProjectPolicy::class)->enabled(),
            'services' => app(ServiceProjectPolicy::class)->catalogEnabled(),
            'memberships' => app(MembershipPolicy::class)->enabled(),
            default => throw new InvalidArgumentException('Unknown product family.'),
        };
    }

    /** The family whose path this is, or null; a query string, fragment, case or percent-encoding does not change it. */
    public function familyOf(string $href): ?string
    {
        $path = strtolower(rawurldecode((string) parse_url($href, PHP_URL_PATH)));
        $path = '/'.ltrim(preg_replace('#/{2,}#', '/', $path) ?? '', '/');
        foreach (self::FAMILIES as $family => $definition) {
            foreach ($definition['paths'] as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                    return $family;
                }
            }
        }

        return null;
    }

    /** False for a path into a family whose policy is off; every other path is left to its own checks. */
    public function admits(string $href): bool
    {
        $family = $this->familyOf($href);

        return $family === null || $this->enabled($family);
    }

    /** @return list<array{label: string, href: string}> header and footer links of the enabled families, in a fixed order */
    public function links(): array
    {
        $links = [];
        foreach (self::FAMILIES as $family => $definition) {
            if ($definition['link'] !== null && $this->enabled($family)) {
                $links[] = $definition['link'];
            }
        }

        return $links;
    }
}
