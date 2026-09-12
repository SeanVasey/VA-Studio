<?php

namespace App\Domain\Rights;

use Illuminate\Validation\ValidationException;

/** Schema 4 captures explicit economic declarations and their local, retained policy evidence. */
final class EconomicLicenseTerms
{
    public const OWNERSHIP = [
        'source_recording' => 'Licensed source recording ownership',
        'source_composition' => 'Licensed source composition ownership',
        'resulting_recording' => 'Resulting recording ownership',
        'resulting_composition' => 'Resulting composition ownership',
    ];

    public function validate(array $terms): array
    {
        $this->keys($terms, ['schema_version', 'required_asset_roles', 'usage', 'permissions', 'credit', 'territory', 'duration', 'ownership', 'publishing_income', 'recording_royalty', 'policies'], 'structured_terms');
        if ($terms['schema_version'] !== 4) {
            $this->fail('structured_terms', 'Economic policies require integer schema version 4.');
        }
        app(ScopedLicenseTerms::class)->validate($this->scopeTerms($terms));
        $policies = $terms['policies'];
        if (! is_array($policies) || ! array_is_list($policies) || count($policies) < 1 || count($policies) > 6) {
            $this->fail('structured_terms.policies', 'Retain between 1 and 6 referenced buyer-facing policies.');
        }
        $known = [];
        $bytes = 0;
        foreach ($policies as $index => $policy) {
            $field = 'structured_terms.policies.'.$index;
            $this->keys($policy, ['key', 'version', 'text'], $field);
            if (! is_string($policy['key']) || ! preg_match('/\A[a-z][a-z0-9-]{0,63}\z/', $policy['key'])) {
                $this->fail($field.'.key', 'Use a lowercase policy key, starting with a letter, up to 64 letters, digits or hyphens.');
            }
            if (isset($known[$policy['key']])) {
                $this->fail($field.'.key', 'Each policy key must be unique within this license version.');
            }
            if (! is_string($policy['version']) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/', $policy['version'])) {
                $this->fail($field.'.version', 'Provide an explicit policy version up to 32 letters, digits, dots, underscores or hyphens.');
            }
            $value = $policy['text'];
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || trim($value) === '' || strlen($value) > 20000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) || str_contains($value, '{{') || str_contains($value, '}}')) {
                $this->fail($field.'.text', 'Retain complete plain UTF-8 buyer-facing policy text, up to 20,000 bytes, without control characters or template variables.');
            }
            $bytes += strlen($value);
            $known[$policy['key']] = true;
        }
        if ($bytes > 60000) {
            $this->fail('structured_terms.policies', 'Combined retained policy text must not exceed 60,000 bytes.');
        }
        $used = [];
        $reference = function (array $declaration, string $field) use ($known, &$used): void {
            $key = $declaration['policy_key'];
            if (! is_string($key) || ! isset($known[$key])) {
                $this->fail($field.'.policy_key', 'Reference a policy key retained in this license version.');
            }
            $used[$key] = true;
        };
        $this->keys($terms['ownership'], array_keys(self::OWNERSHIP), 'structured_terms.ownership');
        foreach (self::OWNERSHIP as $subject => $label) {
            $field = 'structured_terms.ownership.'.$subject;
            $this->keys($terms['ownership'][$subject], ['policy_key'], $field);
            $reference($terms['ownership'][$subject], $field);
        }
        $publishing = $terms['publishing_income'];
        $mode = is_array($publishing) ? ($publishing['mode'] ?? null) : null;
        if (! in_array($mode, ['none', 'share'], true)) {
            $this->fail('structured_terms.publishing_income.mode', 'Choose no publishing-income entitlement under this license, or an explicit licensor share.');
        }
        $this->keys($publishing, $mode === 'share' ? ['mode', 'policy_key', 'licensor_bps'] : ['mode', 'policy_key'], 'structured_terms.publishing_income');
        if ($mode === 'share') {
            $this->basisPoints($publishing['licensor_bps'], 'structured_terms.publishing_income.licensor_bps');
        }
        $reference($publishing, 'structured_terms.publishing_income');
        $royalty = $terms['recording_royalty'];
        $mode = is_array($royalty) ? ($royalty['mode'] ?? null) : null;
        if (! in_array($mode, ['none', 'rate'], true)) {
            $this->fail('structured_terms.recording_royalty.mode', 'Choose no additional contractual recording royalty, or an explicit rate and receipts basis.');
        }
        $this->keys($royalty, $mode === 'rate' ? ['mode', 'policy_key', 'rate_bps', 'basis'] : ['mode', 'policy_key'], 'structured_terms.recording_royalty');
        if ($mode === 'rate') {
            $this->basisPoints($royalty['rate_bps'], 'structured_terms.recording_royalty.rate_bps');
            if (! in_array($royalty['basis'], ['gross_receipts', 'net_receipts'], true)) {
                $this->fail('structured_terms.recording_royalty.basis', 'Choose gross or net receipts. The retained policy must define receipts, deductions and accounting.');
            }
        }
        $reference($royalty, 'structured_terms.recording_royalty');
        if (array_diff_key($known, $used) !== []) {
            $this->fail('structured_terms.policies', 'Every retained policy must be referenced by an ownership or economic declaration.');
        }

        return $terms;
    }

    public function statements(array $terms): array
    {
        $terms = $this->validate($terms);
        $statements = app(ScopedLicenseTerms::class)->statements($this->scopeTerms($terms));
        $policies = array_column($terms['policies'], null, 'key');
        $reference = fn ($key) => 'policy '.$key.' @ '.$policies[$key]['version'];
        foreach (self::OWNERSHIP as $subject => $label) {
            $statements['ownership.'.$subject] = $label.': defined by retained '.$reference($terms['ownership'][$subject]['policy_key']).'.';
        }
        $publishing = $terms['publishing_income'];
        $statements['publishing_income'] = ($publishing['mode'] === 'none'
            ? 'Publishing income: this license provides no additional publishing-income entitlement to the licensor'
            : 'Publishing income: licensor share is '.$this->percent($publishing['licensor_bps']).'% of total publishing income attributable to the resulting composition')
            .', as defined by retained '.$reference($publishing['policy_key']).'.';
        $royalty = $terms['recording_royalty'];
        $statements['recording_royalty'] = ($royalty['mode'] === 'none'
            ? 'Recording royalty: no additional recording royalty is payable by the licensee to the licensor under this license'
            : 'Recording royalty: licensee pays licensor '.$this->percent($royalty['rate_bps']).'% of '.($royalty['basis'] === 'gross_receipts' ? 'gross' : 'net').' receipts from the resulting recording')
            .', as defined by retained '.$reference($royalty['policy_key']).'.';

        return $statements;
    }

    /** Complete policy bytes belong in the substituted source/evidence, not each storefront card. */
    public function sourceValues(array $terms): array
    {
        $values = $this->statements($terms);
        $policies = array_column($terms['policies'], null, 'key');
        ksort($policies, SORT_STRING);
        $values['policy_texts'] = implode("\n\n", array_map(fn ($policy) => 'Retained policy '.$policy['key'].' @ '.$policy['version'].'; SHA-256 '.hash('sha256', $policy['text'])."\n".$policy['text'], array_values($policies)));

        return $values;
    }

    private function scopeTerms(array $terms): array
    {
        return array_replace(array_diff_key($terms, ['ownership' => true, 'publishing_income' => true, 'recording_royalty' => true, 'policies' => true]), ['schema_version' => 3]);
    }

    private function percent(int $bps): string
    {
        return intdiv($bps, 100).'.'.str_pad((string) ($bps % 100), 2, '0', STR_PAD_LEFT);
    }

    private function basisPoints(mixed $value, string $field): void
    {
        if (! is_int($value) || $value < 1 || $value > 10000) {
            $this->fail($field, 'Enter integer basis points from 1 to 10,000 (100 basis points = 1%). Choose the explicit none mode for no entitlement or obligation.');
        }
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
