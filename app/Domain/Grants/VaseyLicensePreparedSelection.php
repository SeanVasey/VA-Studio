<?php

namespace App\Domain\Grants;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Contracts\RenderFit\LicenseTextFit;

/**
 * What a `vasey-license-pdf-v2` selection reads from files, read before the issuer's locked origin transaction
 * ({@see VaseyLicenseIssuance::prepare()}): the verified v2 profile and its hash, the renderer's input rule (the four
 * verified metric files) and the SHA-256 of the configured signature, read and checked against its pin. A null field means
 * that read failed; the width check over the same four verified metric files ({@see LicenseTextFit}) is read with them; the selection then keeps the family's existing profile, exactly as when the read failed inside it.
 */
final readonly class VaseyLicensePreparedSelection
{
    public function __construct(
        public ?array $profile = null,
        public ?string $profileSha256 = null,
        public ?ContractGlyphCoverage $coverage = null,
        public ?string $signatureSha256 = null,
        public ?LicenseTextFit $fit = null,
    ) {}
}
