<?php

namespace App\Domain\Grants\Member;

use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Authenticated complete member-only row envelopes. These helpers issue values, never admission. */
final class MemberGrantRecords
{
    public const PROFILES = 'production_member_profiles';

    public const DEFINITIONS = 'production_member_definitions';

    private const PROFILE_COLUMNS = ['id', 'profile_hash', 'original_terms_hash', 'implementation_hash', 'font_manifest_hash', 'provenance', 'payload_ciphertext', 'seal', 'created_at'];

    private const DEFINITION_COLUMNS = ['id', 'profile_id', 'definition_hash', 'original_terms_hash', 'policy_hash', 'license_manifest_hash', 'asset_manifest_hash', 'retention_policy_hash', 'family', 'purpose', 'version', 'provenance', 'payload_ciphertext', 'seal', 'created_at'];

    public static function profile(MemberGrantProfileDocument $document, string $id, int $author, string $authorHash, string $created): array
    {
        $value = $document->value();
        $row = ['id' => $id, 'profile_hash' => $document->hash(), 'original_terms_hash' => CanonicalJson::hash($value['original_terms']),
            'implementation_hash' => CanonicalJson::hash($value['render_profile']['implementation']),
            'font_manifest_hash' => CanonicalJson::hash($value['render_profile']['base']['assets']['assets']),
            'provenance' => $value['provenance'], 'created_at' => $created];
        $row['payload_ciphertext'] = self::encrypt(self::payload('profile', $id, $value, $author, $authorHash, $created));
        $row['seal'] = self::seal(self::PROFILES, $row);
        self::readProfile($row);

        return $row;
    }

    public static function definition(MemberGrantDefinitionDocument $document, string $id, string $profileId,
        int $author, string $authorHash, string $created): array
    {
        $value = $document->value();
        $row = ['id' => $id, 'profile_id' => $profileId, 'definition_hash' => $document->hash(),
            'original_terms_hash' => $value['original_terms_hash'], 'policy_hash' => $value['policy_hash'],
            'license_manifest_hash' => CanonicalJson::hash($value['license_manifest']), 'asset_manifest_hash' => CanonicalJson::hash($value['asset_manifest']),
            'retention_policy_hash' => CanonicalJson::hash($value['retention_policy']), 'family' => $value['family'], 'purpose' => $value['purpose'],
            'version' => $value['version'], 'provenance' => $value['provenance'], 'created_at' => $created];
        $payload = self::payload('definition', $id, $value, $author, $authorHash, $created);
        $payload['profile_id'] = $profileId;
        $row['payload_ciphertext'] = self::encrypt($payload);
        $row['seal'] = self::seal(self::DEFINITIONS, $row);
        self::readDefinition($row);

        return $row;
    }

    private static function payload(string $kind, string $id, array $document, int $author, string $authorHash, string $created): array
    {
        return ['schema_version' => 'member-'.$kind.'-record-v1', 'event' => $kind.'_authored', $kind.'_id' => $id,
            'document' => $document, 'author_user_id' => $author, 'author_binding_hash' => $authorHash, 'created_at' => $created];
    }

    private static function encrypt(array $payload): string
    {
        $plain = CanonicalJson::encode($payload);
        MemberGrantException::require(strlen($plain) <= MemberGrantDocumentValues::MAX_BYTES + 2048, 'invalid_document');

        return Crypt::encryptString($plain);
    }

    public static function seal(string $table, array $row, ?string $key = null): string
    {
        MemberGrantException::require(in_array($table, [self::PROFILES, self::DEFINITIONS], true), 'tampered');
        unset($row['seal']);

        return hash_hmac('sha256', CanonicalJson::encode(['purpose' => 'member-original-authored-record-v1', 'table' => $table,
            'row' => self::strings($row)]), $key ?? self::keys()[0]);
    }

    public static function readProfile(array $row): MemberGrantProfileDocument
    {
        $payload = self::read(self::PROFILES, $row, 'profile');
        $document = MemberGrantProfileDocument::from($payload['document']);
        $value = $document->value();
        MemberGrantException::require($document->hash() === $row['profile_hash']
            && CanonicalJson::hash($value['original_terms']) === $row['original_terms_hash']
            && CanonicalJson::hash($value['render_profile']['implementation']) === $row['implementation_hash']
            && CanonicalJson::hash($value['render_profile']['base']['assets']['assets']) === $row['font_manifest_hash']
            && $value['provenance'] === $row['provenance'], 'tampered');

        return $document;
    }

    public static function readDefinition(array $row): MemberGrantDefinitionDocument
    {
        $payload = self::read(self::DEFINITIONS, $row, 'definition');
        MemberGrantDocumentValues::id($row['profile_id']);
        MemberGrantException::require($payload['profile_id'] === $row['profile_id'] && in_array($row['version'], [1, '1'], true), 'tampered');
        $document = MemberGrantDefinitionDocument::from($payload['document']);
        $value = $document->value();
        MemberGrantException::require($document->hash() === $row['definition_hash'] && $value['original_terms_hash'] === $row['original_terms_hash']
            && $value['policy_hash'] === $row['policy_hash'] && CanonicalJson::hash($value['license_manifest']) === $row['license_manifest_hash']
            && CanonicalJson::hash($value['asset_manifest']) === $row['asset_manifest_hash'] && CanonicalJson::hash($value['retention_policy']) === $row['retention_policy_hash']
            && $value['family'] === $row['family'] && $value['purpose'] === $row['purpose'] && $value['provenance'] === $row['provenance'], 'tampered');

        return $document;
    }

    private static function read(string $table, array $row, string $kind): array
    {
        MemberGrantDocumentValues::keys($row, $table === self::PROFILES ? self::PROFILE_COLUMNS : self::DEFINITION_COLUMNS);
        MemberGrantDocumentValues::id($row['id']);
        MemberGrantDocumentValues::hash($row['seal']);
        self::utc($row['created_at']);
        MemberGrantException::require(is_string($row['payload_ciphertext']) && strlen($row['payload_ciphertext']) <= 1572864, 'tampered');
        $match = false;
        foreach (self::keys() as $key) {
            $match = hash_equals(self::seal($table, $row, $key), $row['seal']) || $match;
        }
        MemberGrantException::require($match, 'tampered');
        try {
            $plain = Crypt::decryptString($row['payload_ciphertext']);
            MemberGrantException::require(strlen($plain) <= MemberGrantDocumentValues::MAX_BYTES + 2048, 'tampered');
            $payload = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
            MemberGrantException::require(is_array($payload) && CanonicalJson::encode($payload) === $plain, 'tampered');
        } catch (Throwable) {
            throw new MemberGrantException('tampered');
        }
        $keys = ['schema_version', 'event', $kind.'_id', 'document', 'author_user_id', 'author_binding_hash', 'created_at'];
        MemberGrantDocumentValues::keys($payload, $kind === 'definition' ? [...$keys, 'profile_id'] : $keys);
        MemberGrantException::require($payload['schema_version'] === 'member-'.$kind.'-record-v1' && $payload['event'] === $kind.'_authored'
            && $payload[$kind.'_id'] === $row['id'] && $payload['created_at'] === $row['created_at'] && is_array($payload['document']), 'tampered');
        MemberGrantDocumentValues::integer($payload['author_user_id']);
        MemberGrantDocumentValues::hash($payload['author_binding_hash']);

        return $payload;
    }

    public static function snapshot(array $profile, array $definition): MemberGrantFactsSnapshot
    {
        $profileDocument = self::readProfile($profile);
        $definitionDocument = self::readDefinition($definition);
        $p = $profileDocument->value();
        $d = $definitionDocument->value();
        MemberGrantException::require($definition['profile_id'] === $profile['id'] && $d['profile_hash'] === $profileDocument->hash()
            && $definition['original_terms_hash'] === $profile['original_terms_hash'] && $p['provenance'] === $d['provenance']
            && CanonicalJson::encode($p['original_terms']) === CanonicalJson::encode(MemberGrantDocumentValues::termsProjection($d['license_manifest'])), 'conflicting_facts');
        $profileBinding = new MemberGrantProfileBinding($profile['id'], $profile['profile_hash'], $profile['original_terms_hash'],
            $profile['implementation_hash'], $profile['font_manifest_hash'], $profile['provenance']);
        $factsBinding = new MemberGrantFactsBinding($definition['id'], $definition['definition_hash'], $profile['id'], $profile['profile_hash'],
            $definition['original_terms_hash'], $definition['policy_hash'], $definition['license_manifest_hash'], $definition['asset_manifest_hash'],
            $definition['retention_policy_hash'], $definition['provenance']);

        return new MemberGrantFactsSnapshot($profileDocument, $definitionDocument, $factsBinding, $profileBinding, $profile['seal'], $definition['seal']);
    }

    public static function utc(mixed $value): void
    {
        MemberGrantException::require(is_string($value) && strlen($value) === 19, 'tampered');
        MemberGrantDocumentValues::iso(str_replace(' ', 'T', $value).'Z');
    }

    public static function strings(array $row): array
    {
        ksort($row, SORT_STRING);
        foreach ($row as $value) {
            MemberGrantException::require($value === null || is_string($value) || is_int($value) || is_bool($value), 'tampered');
        }

        return array_map(fn ($value): ?string => $value === null ? null : (string) $value, $row);
    }

    private static function keys(): array
    {
        $current = config('app.key');
        $previous = config('app.previous_keys', []);
        MemberGrantException::require(is_string($current) && strlen($current) >= 32 && is_array($previous), 'key_absent');
        foreach ($previous as $key) {
            MemberGrantException::require(is_string($key) && strlen($key) >= 32, 'key_absent');
        }

        return array_values(array_unique([$current, ...$previous]));
    }
}
