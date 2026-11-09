<?php

namespace App\Domain\Grants;

use App\Domain\Contracts\Production\ContractLicenseConstants;

/**
 * The contract text a license version prints under `vasey-license-pdf-v2`: its `{NAME}` template and its two license
 * constants. Increment 1 of the production contract profile (authoring Sean's six templates as license versions) is what
 * supplies them; until then nothing in the application binds this contract, so {@see VaseyLicenseIssuance} never selects
 * v2. Tests bind a synthetic, nonbinding source. The answer is frozen into the origin at selection, so a later change here
 * never changes an issued or retried original.
 */
interface VaseyLicenseContractSource
{
    /**
     * @param  array  $license  The line's frozen license snapshot (`source.license`: id, version, source and submission hashes).
     * @return array{template: string, constants: ContractLicenseConstants}|null null when this license version has no v2 contract text.
     */
    public function contract(array $license): ?array;
}
