<?php

namespace App\Domain\Rights;

use Illuminate\Validation\ValidationException;

final class LicenseTerms
{
    public const SCHEMA_VERSION = 2;

    public const ASSET_ROLES = ['download_mp3', 'master_wav', 'stems_zip'];

    public function validate(array $terms): array
    {
        if (($terms['schema_version'] ?? null) === 2) {
            return app(TypedLicenseTerms::class)->validate($terms);
        }
        $keys = array_keys($terms);
        sort($keys);
        // Preserve the historical v1 validator independently of the current authoring schema.
        if ($keys !== ['features', 'required_asset_roles', 'schema_version'] || ($terms['schema_version'] ?? null) !== 1) {
            $this->fail('Terms require only schema_version (integer 1), features and required_asset_roles.');
        }
        $features = $terms['features'];
        if (! is_array($features) || ! array_is_list($features) || count($features) < 1 || count($features) > 20) {
            $this->fail('Provide between 1 and 20 feature summaries.');
        }
        foreach ($features as $feature) {
            if (! is_string($feature) || ! mb_check_encoding($feature, 'UTF-8') || trim($feature) !== $feature || $feature === '' || mb_strlen($feature) > 240 || preg_match('/[\x00-\x1F\x7F]/', $feature)) {
                $this->fail('Feature summaries must be nonempty plain strings up to 240 characters without control characters or outer whitespace.');
            }
        }
        if (count(array_unique($features, SORT_STRING)) !== count($features)) {
            $this->fail('Feature summaries must be distinct.');
        }
        $roles = $terms['required_asset_roles'];
        if (! is_array($roles) || ! array_is_list($roles) || count($roles) < 1 || count($roles) > count(self::ASSET_ROLES)) {
            $this->fail('Select between 1 and 3 distinct delivery roles.');
        }
        foreach ($roles as $role) {
            if (! is_string($role) || ! in_array($role, self::ASSET_ROLES, true)) {
                $this->fail('A required delivery role is unsupported.');
            }
        }
        if (count(array_unique($roles, SORT_STRING)) !== count($roles)) {
            $this->fail('Required delivery roles must be distinct.');
        }

        // Feature prose is reviewed alongside source. This schema does not interpret legal meaning.
        return $terms;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['structured_terms' => $message]);
    }
}
