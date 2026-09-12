<?php

namespace App\Domain\Rights;

/** Stable buyer-facing projection of a verified, frozen license snapshot. */
final class LicenseDisclosure
{
    public function fromSnapshot(array $license): array
    {
        $source = $license['authored_source'];
        $terms = $license['structured_terms'];

        return [
            'licenseVersionId' => (string) $license['id'],
            'name' => $license['name'], 'version' => $license['version'], 'type' => $license['type'],
            'features' => $license['features'], 'deliverableRoles' => $license['required_asset_roles'],
            'termsText' => $terms['schema_version'] === 1 ? $source : app(LicenseSourceVariables::class)->render($source, $terms),
        ];
    }
}
