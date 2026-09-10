<?php

namespace App\Domain\Rights;

use Illuminate\Validation\ValidationException;

/** Version 2 vocabulary: explicit choices, no inferred permissions or production defaults. */
final class TypedLicenseTerms
{
    public const USAGE = [
        'audio_releases' => 'Audio releases', 'copies_downloads' => 'Copies / downloads',
        'monetized_streams' => 'Monetized streams', 'non_monetized_streams' => 'Non-monetized streams',
        'music_videos' => 'Music videos', 'live_performances' => 'Live performances', 'radio_stations' => 'Radio stations',
    ];

    public const PERMISSIONS = [
        'content_id' => 'Content ID registration', 'paid_advertising' => 'Paid advertising',
        'sublicensing' => 'Sublicensing', 'standalone_resale' => 'Standalone resale',
    ];

    public const DELIVERABLES = ['download_mp3' => 'MP3', 'master_wav' => 'WAV master', 'stems_zip' => 'Stems ZIP'];

    public function validate(array $terms): array
    {
        $this->keys($terms, ['schema_version', 'required_asset_roles', 'usage', 'permissions', 'credit'], 'structured_terms');
        if ($terms['schema_version'] !== 2) {
            $this->fail('structured_terms', 'Typed terms require integer schema version 2.');
        }
        $roles = $terms['required_asset_roles'];
        if (! is_array($roles) || ! array_is_list($roles) || $roles === [] || count($roles) > 3) {
            $this->fail('structured_terms.required_asset_roles', 'Choose one to three distinct deliverable roles.');
        }
        foreach ($roles as $role) {
            if (! is_string($role) || ! array_key_exists($role, self::DELIVERABLES)) {
                $this->fail('structured_terms.required_asset_roles', 'A deliverable role is unsupported.');
            }
        }
        if (count(array_unique($roles)) !== count($roles)) {
            $this->fail('structured_terms.required_asset_roles', 'Deliverable roles must be distinct.');
        }
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
        $this->keys($terms['permissions'], array_keys(self::PERMISSIONS), 'structured_terms.permissions');
        foreach (self::PERMISSIONS as $key => $label) {
            if (! in_array($terms['permissions'][$key], ['permitted', 'prohibited'], true)) {
                $this->fail('structured_terms.permissions.'.$key, 'Choose permitted or prohibited for '.$label.'.');
            }
        }
        $credit = $terms['credit'];
        $mode = is_array($credit) ? ($credit['mode'] ?? null) : null;
        if (! in_array($mode, ['required', 'not_required'], true)) {
            $this->fail('structured_terms.credit.mode', 'Choose whether producer credit is required.');
        }
        $this->keys($credit, $mode === 'required' ? ['mode', 'text'] : ['mode'], 'structured_terms.credit');
        if ($mode === 'required' && (! is_string($credit['text']) || ! mb_check_encoding($credit['text'], 'UTF-8')
            || trim($credit['text']) !== $credit['text'] || $credit['text'] === '' || mb_strlen($credit['text']) > 120
            || preg_match('/[\x00-\x1F\x7F]/', $credit['text']) || str_contains($credit['text'], '{{') || str_contains($credit['text'], '}}'))) {
            $this->fail('structured_terms.credit.text', 'Enter the approved credit as plain text, up to 120 characters, without template variables.');
        }

        return $terms;
    }

    /** Every card statement is also a required, exact source variable value. */
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
        foreach (self::PERMISSIONS as $key => $label) {
            $statements['permissions.'.$key] = $label.': '.($terms['permissions'][$key] === 'permitted' ? 'permitted' : 'not permitted').'.';
        }
        $statements['credit'] = $terms['credit']['mode'] === 'required'
            ? 'Producer credit required: '.$terms['credit']['text'] : 'Producer credit: not required.';
        // Semantic role order is pinned; JSON object key order never changes rendering.
        $roles = array_filter(array_keys(self::DELIVERABLES), fn ($role) => in_array($role, $terms['required_asset_roles'], true));
        $statements['deliverables'] = 'Deliverables: '.implode(', ', array_map(fn ($role) => self::DELIVERABLES[$role], $roles)).'.';

        return $statements;
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
