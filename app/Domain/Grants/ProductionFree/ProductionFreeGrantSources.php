<?php

namespace App\Domain\Grants\ProductionFree;

/**
 * Capability seam for current catalog readiness and exact private asset bytes. Nothing binds it by default;
 * root composes it over the current rights/publication/scanner/media proofs. Rehearsal tests bind a synthetic one.
 */
interface ProductionFreeGrantSources
{
    /**
     * Prove the exact sealed source manifest (license, track, rights references and asset hashes) is currently
     * publishable, cleared and scanned. Return a 64-hex proof digest; throw on any drift or missing fact.
     */
    public function prove(array $sourceManifest, array $assets): string;

    /**
     * Open the exact private bytes for one approved asset entry. The caller verifies size and SHA-256 before
     * the first byte leaves; an implementation must never return a public path.
     *
     * @return resource
     */
    public function open(array $asset);
}
