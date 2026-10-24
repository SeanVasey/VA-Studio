<?php

namespace App\Domain\Migration\LegacyRedirects;

use RuntimeException;

/** A map that fails structural validation. It never serves a redirect. */
final class LegacyRedirectMapInvalid extends RuntimeException
{
    /** @param  list<string>  $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('The legacy redirect map is invalid: '.implode(' ', $problems));
    }
}
