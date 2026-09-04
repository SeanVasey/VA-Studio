<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseVersion;
use App\Support\CanonicalJson;

final class LicenseDiff
{
    public function between(LicenseVersion $older, LicenseVersion $newer): array
    {
        $before = $this->content($older);
        $after = $this->content($newer);
        $changes = [];
        foreach (['template', 'authored_source', 'structured_terms', 'effective_from', 'effective_until'] as $field) {
            if (CanonicalJson::encode($before[$field]) !== CanonicalJson::encode($after[$field])) {
                $changes[$field] = ['before' => $before[$field], 'after' => $after[$field]];
            }
        }

        return $changes;
    }

    private function content(LicenseVersion $version): array
    {
        // Historic terms may predate the supported schema; compare without reinterpreting them.
        return [
            'template' => $version->template()->firstOrFail()->only(['id', 'name', 'slug', 'type']),
            'authored_source' => $version->authored_source,
            'structured_terms' => $version->structured_terms,
            'effective_from' => $version->effective_from?->utc()->format('Y-m-d\TH:i:s\Z'),
            'effective_until' => $version->effective_until?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
