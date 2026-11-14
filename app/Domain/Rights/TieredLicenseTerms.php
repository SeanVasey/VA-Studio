<?php

namespace App\Domain\Rights;

use Illuminate\Validation\ValidationException;

/**
 * Schema 5 keeps every v4 declaration and replaces only the usage vocabulary, so each of the
 * seller's tier limits has its own slot. Historical schemas 1 to 4 are never reinterpreted.
 */
final class TieredLicenseTerms
{
    public const USAGE = [
        'audio_releases' => 'Audio releases', 'copies_downloads' => 'Copies / downloads', 'audio_streams' => 'Audio streams',
        'monetized_video_streams' => 'Monetized video streams', 'non_monetized_video_streams' => 'Non-monetized video streams',
        'music_videos' => 'Monetized music videos', 'radio_stations' => 'Radio stations', 'free_downloads' => 'Free downloads',
        'for_profit_performances' => 'For-profit performances', 'not_for_profit_performances' => 'Not-for-profit performances',
    ];

    public function validate(array $terms): array
    {
        $this->keys($terms, ['schema_version', 'required_asset_roles', 'usage', 'permissions', 'credit', 'territory', 'duration', 'ownership', 'publishing_income', 'recording_royalty', 'policies'], 'structured_terms');
        if ($terms['schema_version'] !== 5) {
            $this->fail('structured_terms', 'Tier usage limits require integer schema version 5.');
        }
        app(EconomicLicenseTerms::class)->validate($this->economicTerms($terms));
        $this->keys($terms['usage'], array_keys(self::USAGE), 'structured_terms.usage');
        foreach (self::USAGE as $key => $label) {
            $value = $terms['usage'][$key];
            $field = 'structured_terms.usage.'.$key;
            $mode = is_array($value) ? ($value['mode'] ?? null) : null;
            if (! in_array($mode, ['prohibited', 'limited', 'unlimited'], true)) {
                $this->fail($field.'.mode', 'Choose prohibited, limited or unlimited for '.$label.'.');
            }
            $this->keys($value, $mode === 'limited' ? ['mode', 'limit'] : ['mode'], $field);
            if ($mode === 'limited' && (! is_int($value['limit']) || $value['limit'] < 1 || $value['limit'] > 2147483647)) {
                $this->fail($field.'.limit', 'A limited use needs an integer from 1 to 2,147,483,647. Use prohibited for no permission.');
            }
        }

        return $terms;
    }

    /** The v5 usage statements lead, in vocabulary order; every other statement is the unchanged v4 text. */
    public function statements(array $terms): array
    {
        $terms = $this->validate($terms);
        $statements = [];
        foreach (self::USAGE as $key => $label) {
            $use = $terms['usage'][$key];
            $value = match ($use['mode']) {
                'limited' => 'up to '.number_format($use['limit'], 0, '.', ','),
                'unlimited' => 'unlimited',
                'prohibited' => 'not permitted',
            };
            $statements['usage.'.$key] = $label.': '.$value.'.';
        }
        // The projected v4 usage is a placeholder; none of its statements may reach a v5 card or source.
        $economic = array_diff_key(app(EconomicLicenseTerms::class)->statements($this->economicTerms($terms)), $this->projectedUsageStatementKeys());

        return $statements + $economic;
    }

    /** Complete policy bytes belong in the substituted source/evidence, not each storefront card. */
    public function sourceValues(array $terms): array
    {
        $values = $this->statements($terms);
        $values['policy_texts'] = app(EconomicLicenseTerms::class)->sourceValues($this->economicTerms($terms))['policy_texts'];

        return $values;
    }

    /**
     * Deliberate projection onto the frozen v4 validator for every non-usage declaration. The v4 usage
     * slots are filled with a fixed placeholder that is validated but never rendered for schema 5.
     */
    private function economicTerms(array $terms): array
    {
        return array_replace($terms, ['schema_version' => 4, 'usage' => array_fill_keys(array_keys(TypedLicenseTerms::USAGE), ['mode' => 'prohibited'])]);
    }

    private function projectedUsageStatementKeys(): array
    {
        return array_fill_keys(array_map(fn (string $key) => 'usage.'.$key, array_keys(TypedLicenseTerms::USAGE)), true);
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
