<?php

namespace App\Domain\Rights;

use Illuminate\Validation\ValidationException;

/** A literal allowlist substitution, never Blade, PHP, evaluation, or recursive templating. */
final class LicenseSourceVariables
{
    public function render(string $source, array $terms): string
    {
        // A policy bundle can contain 60 KB. Bound expansion before performing any substitution.
        // Historical schemas retain their existing repetition behavior.
        if (($terms['schema_version'] ?? null) === 4 && substr_count($source, '{{policy_texts}}') !== 1) {
            throw ValidationException::withMessages(['authored_source' => 'Include {{policy_texts}} exactly once to retain the complete policy bundle.']);
        }
        $values = app(LicenseTerms::class)->sourceValues($terms);
        $used = [];
        $rendered = preg_replace_callback('/\{\{([^{}]*)\}\}/u', function (array $match) use ($values, &$used) {
            $key = $match[1];
            if (! array_key_exists($key, $values)) {
                throw ValidationException::withMessages(['authored_source' => 'Unsupported source variable: '.$match[0].'. Use the exact variable shown beside the corresponding field.']);
            }
            $used[$key] = true;

            return $values[$key];
        }, $source);
        if ($rendered === null || str_contains($rendered, '{{') || str_contains($rendered, '}}')) {
            throw ValidationException::withMessages(['authored_source' => 'Source contains an incomplete or malformed variable.']);
        }
        $missing = array_diff(array_keys($values), array_keys($used));
        if ($missing !== []) {
            throw ValidationException::withMessages(['authored_source' => 'Include each terms variable in the source: '.implode(', ', array_map(fn ($key) => '{{'.$key.'}}', $missing)).'.']);
        }

        return $rendered;
    }
}
