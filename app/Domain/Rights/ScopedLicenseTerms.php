<?php

namespace App\Domain\Rights;

use Illuminate\Validation\ValidationException;

/** Schema 3 extends the pinned v2 usage vocabulary with explicit licensed-use scope. */
final class ScopedLicenseTerms
{
    public function validate(array $terms): array
    {
        $this->keys($terms, ['schema_version', 'required_asset_roles', 'usage', 'permissions', 'credit', 'territory', 'duration'], 'structured_terms');
        if ($terms['schema_version'] !== 3) {
            $this->fail('structured_terms', 'License scope requires integer schema version 3.');
        }
        app(TypedLicenseTerms::class)->validate($this->usageTerms($terms));
        $territory = $terms['territory'];
        $mode = is_array($territory) ? ($territory['mode'] ?? null) : null;
        if (! in_array($mode, ['worldwide', 'countries'], true)) {
            $this->fail('structured_terms.territory.mode', 'Choose worldwide or selected countries.');
        }
        $this->keys($territory, $mode === 'countries' ? ['mode', 'country_codes'] : ['mode'], 'structured_terms.territory');
        if ($mode === 'countries') {
            $codes = $territory['country_codes'];
            if (! is_array($codes) || ! array_is_list($codes) || $codes === [] || count($codes) > count(LicenseTerritories::COUNTRIES)) {
                $this->fail('structured_terms.territory.country_codes', 'Choose at least one distinct supported country code.');
            }
            foreach ($codes as $code) {
                if (! is_string($code) || ! array_key_exists($code, LicenseTerritories::COUNTRIES)) {
                    $this->fail('structured_terms.territory.country_codes', 'Use an exact supported uppercase ISO 3166-1 alpha-2 code.');
                }
            }
            if (count(array_unique($codes)) !== count($codes)) {
                $this->fail('structured_terms.territory.country_codes', 'Country codes must be distinct.');
            }
        }
        $duration = $terms['duration'];
        $mode = is_array($duration) ? ($duration['mode'] ?? null) : null;
        if (! in_array($mode, ['perpetual', 'fixed_months'], true)) {
            $this->fail('structured_terms.duration.mode', 'Choose perpetual or a fixed number of calendar months.');
        }
        $this->keys($duration, $mode === 'fixed_months' ? ['mode', 'starts_at', 'months'] : ['mode', 'starts_at'], 'structured_terms.duration');
        if ($duration['starts_at'] !== 'grant') {
            $this->fail('structured_terms.duration.starts_at', 'License duration starts at the rights grant, not offer availability or a provisional quote.');
        }
        if ($mode === 'fixed_months' && (! is_int($duration['months']) || $duration['months'] < 1 || $duration['months'] > 1200)) {
            $this->fail('structured_terms.duration.months', 'Enter a whole number of calendar months from 1 to 1,200.');
        }

        return $terms;
    }

    public function statements(array $terms): array
    {
        $terms = $this->validate($terms);
        $statements = app(TypedLicenseTerms::class)->statements($this->usageTerms($terms));
        if ($terms['territory']['mode'] === 'worldwide') {
            $statements['territory'] = 'Territory: worldwide.';
        } else {
            $codes = $terms['territory']['country_codes'];
            sort($codes, SORT_STRING);
            $statements['territory'] = 'Territory: '.implode(', ', $codes).' (ISO 3166-1 alpha-2).';
        }
        $duration = $terms['duration'];
        $statements['duration'] = $duration['mode'] === 'perpetual'
            ? 'License duration: perpetual from the grant timestamp.'
            : 'License duration: '.$duration['months'].' calendar '.($duration['months'] === 1 ? 'month' : 'months').' from the grant timestamp (UTC; end-of-month clamping).';

        return $statements;
    }

    private function usageTerms(array $terms): array
    {
        // Deliberate projection onto the frozen v2 vocabulary, never mutation of a stored version.
        return array_replace(array_diff_key($terms, ['territory' => true, 'duration' => true]), ['schema_version' => 2]);
    }

    private function keys(mixed $value, array $expected, string $field): void
    {
        $keys = is_array($value) ? array_keys($value) : [];
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            $this->fail($field, 'Provide exactly the supported fields: '.implode(', ', $expected).'.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
