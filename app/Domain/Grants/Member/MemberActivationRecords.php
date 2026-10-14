<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MemberGrantBinding;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicyBinding;
use App\Domain\Memberships\Production\MembershipValues;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Table-bound retained values. Encryption/authentication belongs before terminal raw admission. */
final class MemberActivationRecords
{
    public const ORIGINS = 'production_member_origins';

    public const ARTIFACTS = 'production_member_artifacts';

    public const ACTIVATIONS = 'production_member_activations';

    private const COLUMNS = [
        self::ORIGINS => ['id', 'redemption_id', 'definition_id', 'profile_id', 'account_id', 'user_id', 'identity_origin_id',
            'invoice_identity_hash', 'owner_binding_hash', 'intent_hash', 'license_manifest_hash', 'asset_manifest_hash',
            'original_terms_hash', 'artifact_count', 'artifact_manifest_hash', 'honor_deadline', 'provenance', 'created_at'],
        self::ARTIFACTS => ['id', 'origin_id', 'ordinal', 'role', 'sha256', 'bytes', 'storage_policy_hash', 'created_at'],
        self::ACTIVATIONS => ['id', 'origin_id', 'redemption_id', 'artifact_manifest_hash', 'readiness_receipt_hash',
            'reservation_event_hash', 'purpose', 'created_at'],
    ];

    private const VERSIONS = [self::ORIGINS => 'member-origin-record-v1', self::ARTIFACTS => 'member-original-artifact-record-v1',
        self::ACTIVATIONS => 'member-activation-record-v1'];

    private const PLAINTEXT = [self::ORIGINS => 2097152, self::ARTIFACTS => 65536, self::ACTIVATIONS => 262144];

    private const CIPHERTEXT = [self::ORIGINS => 4194304, self::ARTIFACTS => 262144, self::ACTIVATIONS => 524288];

    public static function readiness(array $value): array
    {
        return MemberActivationReadiness::from($value)->value();
    }

    public static function origin(MemberOriginalSourceValues $source, array $authorization, MembershipPolicyBinding $policy,
        MemberOriginalArtifactManifest $manifest, int $identityOriginId, array $actorBinding, string $effectAt): array
    {
        $s = $source->document();
        $m = self::manifest($manifest);
        MemberGrantException::require(CanonicalJson::hash($m) === $manifest->manifestHash, 'invalid_manifest');
        $safeManifest = self::manifestValue($m);
        $a = MemberLicenseAuthorizationRecords::verifyRetained($authorization, $source, $policy);
        $r = MemberActivationReadiness::forSource($source, $a, $policy, $safeManifest)->value();
        $actor = self::actor($actorBinding, $s['buyer_binding']);
        self::effectTime($effectAt, $s, $a);
        MemberGrantException::require($identityOriginId > 0, 'invalid_record');
        $o = ['schema_version' => 'member-origin-v1', 'family' => MemberGrantIntent::FAMILY, 'purpose' => MemberGrantIntent::PURPOSE,
            'provenance' => $s['provenance'], 'origin_id' => $s['origin_id'], 'source' => $s, 'render_source_hash' => $source->hash(),
            'artifact_manifest' => $m, 'readiness_commitment' => $r, 'authorization' => $a,
            'policy' => ['plan_version_id' => $policy->planVersionId, 'policy_hash' => $policy->policyHash,
                'consumption_terms_hash' => $r['consumption_terms_hash'], 'retention_policy_hash' => $r['retention_policy_hash']],
            'actor_binding' => $actor, 'created_at' => $effectAt];
        $row = self::originColumns($o, $identityOriginId);
        $row = self::write(self::ORIGINS, $o, $row);
        self::readOrigin($row, $policy);

        return $row;
    }

    public static function artifact(array $origin, MembershipPolicyBinding $policy, string $artifactId, string $effectAt): array
    {
        $o = self::readOrigin($origin, $policy);
        MemberGrantDocumentValues::id($artifactId);
        MemberGrantException::require($effectAt === $o['created_at'], 'invalid_record_time');
        $f = self::artifactDocument($o, $artifactId);
        $row = self::write(self::ARTIFACTS, $f, self::artifactColumns($f));
        self::readArtifact($row);

        return $row;
    }

    public static function activation(array $origin, array $artifact, array $consume, MembershipPolicyBinding $policy,
        string $activationId, array $actorBinding, string $effectAt): array
    {
        $o = self::readOrigin($origin, $policy);
        $f = self::readArtifact($artifact);
        self::equal($f, self::artifactDocument($o, $artifact['id']));
        $actor = self::actor($actorBinding, $o['source']['buyer_binding']);
        self::equal($actor, $o['actor_binding']);
        MemberGrantDocumentValues::id($activationId);
        MemberGrantException::require($effectAt === $o['created_at'], 'invalid_record_time');
        self::consume($o, $consume);
        $d = self::activationDocument($o, $origin, $artifact, $consume, $activationId);
        $row = self::write(self::ACTIVATIONS, $d, self::activationColumns($d));
        self::binding($origin, $artifact, $consume, $row, $policy);

        return $row;
    }

    public static function readOrigin(array $row, MembershipPolicyBinding $policy): array
    {
        $o = self::read(self::ORIGINS, $row);
        MemberGrantDocumentValues::keys($o, ['schema_version', 'family', 'purpose', 'provenance', 'origin_id', 'source',
            'render_source_hash', 'artifact_manifest', 'readiness_commitment', 'authorization', 'policy', 'actor_binding', 'created_at']);
        self::family($o, 'member-origin-v1');
        MemberGrantException::require(is_array($o['source']) && is_array($o['authorization']) && is_array($o['artifact_manifest'])
            && is_array($o['readiness_commitment']) && is_array($o['actor_binding']), 'tampered');
        $source = MemberOriginalSourceValues::from($o['source']);
        $s = $source->document();
        $manifest = self::manifestValue($o['artifact_manifest']);
        $r = MemberActivationReadiness::forSource($source, $o['authorization'], $policy, $manifest)->value();
        self::equal($o['readiness_commitment'], $r);
        self::equal($o['policy'], ['plan_version_id' => $policy->planVersionId, 'policy_hash' => $policy->policyHash,
            'consumption_terms_hash' => $r['consumption_terms_hash'], 'retention_policy_hash' => $r['retention_policy_hash']]);
        self::actor($o['actor_binding'], $s['buyer_binding']);
        self::effectTime($o['created_at'], $s, $o['authorization']);
        MemberGrantException::require($o['origin_id'] === $s['origin_id'] && $o['render_source_hash'] === $source->hash()
            && $o['provenance'] === $s['provenance'], 'tampered');
        self::equal(self::rowBinding(self::ORIGINS, $row), MemberGrantRecords::strings(self::originColumns($o, self::integer($row['identity_origin_id'], 1))));

        return $o;
    }

    public static function readArtifact(array $row): array
    {
        $f = self::read(self::ARTIFACTS, $row);
        MemberGrantDocumentValues::keys($f, ['schema_version', 'family', 'purpose', 'provenance', 'artifact_id', 'origin_id',
            'ordinal', 'role', 'sha256', 'bytes', 'storage_policy_hash', 'render_source_hash', 'profile_hash', 'derived_relative_path', 'created_at']);
        self::family($f, 'member-original-artifact-v1');
        foreach (['artifact_id', 'origin_id'] as $key) {
            MemberGrantDocumentValues::id($f[$key]);
        }
        foreach (['sha256', 'storage_policy_hash', 'render_source_hash', 'profile_hash'] as $key) {
            MemberGrantDocumentValues::hash($f[$key]);
        }
        MemberGrantDocumentValues::integer($f['bytes'], 32, MemberOriginalFiles::MAX_BYTES);
        self::utc($f['created_at']);
        MemberGrantException::require($f['ordinal'] === 0 && $f['role'] === 'license_pdf'
            && $f['derived_relative_path'] === self::path($f['provenance'], $f['origin_id'], $f['render_source_hash']), 'tampered');
        self::equal(self::rowBinding(self::ARTIFACTS, $row), MemberGrantRecords::strings(self::artifactColumns($f)));

        return $f;
    }

    public static function readActivation(array $row): array
    {
        $d = self::read(self::ACTIVATIONS, $row);
        MemberGrantDocumentValues::keys($d, ['schema_version', 'family', 'purpose', 'provenance', 'activation_id', 'origin',
            'redemption', 'period', 'reservation_event', 'consume_event', 'definition', 'profile', 'render_source_hash',
            'artifact_manifest_hash', 'readiness_receipt_hash', 'authorization_document_hash', 'authorization_intent_hash',
            'consumption_terms_hash', 'retention_policy_hash', 'artifacts', 'actor_binding', 'activated_at']);
        self::family($d, 'member-license-activation-v1');
        MemberGrantDocumentValues::id($d['activation_id']);
        foreach (['origin', 'redemption', 'period', 'reservation_event', 'consume_event', 'definition', 'profile'] as $key) {
            $hash = in_array($key, ['definition', 'profile'], true) ? 'hash' : 'seal';
            MemberGrantDocumentValues::keys($d[$key], ['id', $hash]);
            MemberGrantDocumentValues::id($d[$key]['id']);
            MemberGrantDocumentValues::hash($d[$key][$hash]);
        }
        foreach (['render_source_hash', 'artifact_manifest_hash', 'readiness_receipt_hash', 'authorization_document_hash',
            'authorization_intent_hash', 'consumption_terms_hash', 'retention_policy_hash'] as $key) {
            MemberGrantDocumentValues::hash($d[$key]);
        }
        MemberGrantException::require(is_array($d['artifacts']) && array_is_list($d['artifacts']) && count($d['artifacts']) === 1, 'tampered');
        MemberGrantDocumentValues::keys($d['artifacts'][0], ['id', 'seal', 'ordinal', 'role']);
        MemberGrantDocumentValues::id($d['artifacts'][0]['id']);
        MemberGrantDocumentValues::hash($d['artifacts'][0]['seal']);
        MemberGrantException::require($d['artifacts'][0]['ordinal'] === 0 && $d['artifacts'][0]['role'] === 'license_pdf'
            && $d['origin']['id'] === $d['redemption']['id'], 'tampered');
        MemberGrantException::require(is_array($d['actor_binding']), 'tampered');
        MembershipValues::buyer($d['actor_binding']);
        self::utc($d['activated_at']);
        self::equal(self::rowBinding(self::ACTIVATIONS, $row), MemberGrantRecords::strings(self::activationColumns($d)));

        return $d;
    }

    public static function binding(array $origin, array $artifact, array $consume, array $activation, MembershipPolicyBinding $policy): MemberGrantBinding
    {
        $o = self::readOrigin($origin, $policy);
        $f = self::readArtifact($artifact);
        $d = self::readActivation($activation);
        self::equal($f, self::artifactDocument($o, $artifact['id']));
        self::consume($o, $consume);
        self::equal($d, self::activationDocument($o, $origin, $artifact, $consume, $activation['id']));
        $s = $o['source'];
        $r = $o['readiness_commitment'];

        return new MemberGrantBinding($origin['id'], $origin['redemption_id'], $s['invoice']['source_invoice_hash'],
            $r['definition_hash'], $r['profile_hash'], $s['selection']['license_manifest_hash'], $s['selection']['asset_manifest_hash'],
            $r['artifact_manifest_hash'], CanonicalJson::hash($d), $s['buyer_binding']);
    }

    private static function originColumns(array $o, int $identityOriginId): array
    {
        $s = $o['source'];

        return ['id' => $s['origin_id'], 'redemption_id' => $s['redemption']['id'], 'definition_id' => $s['definition']['id'],
            'profile_id' => $s['profile']['id'], 'account_id' => $s['buyer_binding']['account_id'], 'user_id' => $s['buyer_binding']['user_id'],
            'identity_origin_id' => $identityOriginId, 'invoice_identity_hash' => $s['invoice']['source_invoice_hash'],
            'owner_binding_hash' => CanonicalJson::hash($s['buyer_binding']), 'intent_hash' => $s['redemption']['intent_hash'],
            'license_manifest_hash' => $s['selection']['license_manifest_hash'], 'asset_manifest_hash' => $s['selection']['asset_manifest_hash'],
            'original_terms_hash' => $s['selection']['original_terms_hash'], 'artifact_count' => 1,
            'artifact_manifest_hash' => CanonicalJson::hash($o['artifact_manifest']), 'honor_deadline' => $s['redemption']['honor_deadline'],
            'provenance' => $s['provenance'], 'created_at' => $o['created_at']];
    }

    private static function artifactDocument(array $o, string $artifactId): array
    {
        $s = $o['source'];
        $original = $o['artifact_manifest']['originals'][0];

        return ['schema_version' => 'member-original-artifact-v1', 'family' => MemberGrantIntent::FAMILY, 'purpose' => MemberGrantIntent::PURPOSE,
            'provenance' => $s['provenance'], 'artifact_id' => $artifactId, 'origin_id' => $s['origin_id'], 'ordinal' => 0, 'role' => 'license_pdf',
            'sha256' => $original['sha256'], 'bytes' => $original['bytes'], 'storage_policy_hash' => $original['storage_policy_hash'],
            'render_source_hash' => $o['render_source_hash'], 'profile_hash' => CanonicalJson::hash($s['profile']['document']['render_profile']),
            'derived_relative_path' => self::path($s['provenance'], $s['origin_id'], $o['render_source_hash']), 'created_at' => $o['created_at']];
    }

    private static function artifactColumns(array $f): array
    {
        return ['id' => $f['artifact_id'], 'origin_id' => $f['origin_id'], 'ordinal' => $f['ordinal'], 'role' => $f['role'],
            'sha256' => $f['sha256'], 'bytes' => $f['bytes'], 'storage_policy_hash' => $f['storage_policy_hash'], 'created_at' => $f['created_at']];
    }

    private static function activationDocument(array $o, array $origin, array $artifact, array $consume, string $activationId): array
    {
        $s = $o['source'];
        $r = $o['readiness_commitment'];

        return ['schema_version' => 'member-license-activation-v1', 'family' => MemberGrantIntent::FAMILY, 'purpose' => MemberGrantIntent::PURPOSE,
            'provenance' => $s['provenance'], 'activation_id' => $activationId, 'origin' => ['id' => $origin['id'], 'seal' => $origin['seal']],
            'redemption' => ['id' => $s['redemption']['id'], 'seal' => $s['redemption']['seal']], 'period' => $s['period'],
            'reservation_event' => $s['reserve_event'], 'consume_event' => ['id' => $consume['id'], 'seal' => $consume['seal']],
            'definition' => ['id' => $s['definition']['id'], 'hash' => $r['definition_hash']],
            'profile' => ['id' => $s['profile']['id'], 'hash' => $r['profile_hash']], 'render_source_hash' => $o['render_source_hash'],
            'artifact_manifest_hash' => $r['artifact_manifest_hash'], 'readiness_receipt_hash' => CanonicalJson::hash($r),
            'authorization_document_hash' => $r['authorization_document_hash'], 'authorization_intent_hash' => $r['authorization_intent_hash'],
            'consumption_terms_hash' => $r['consumption_terms_hash'], 'retention_policy_hash' => $r['retention_policy_hash'],
            'artifacts' => [['id' => $artifact['id'], 'seal' => $artifact['seal'], 'ordinal' => 0, 'role' => 'license_pdf']],
            'actor_binding' => $o['actor_binding'], 'activated_at' => $o['created_at']];
    }

    private static function activationColumns(array $d): array
    {
        return ['id' => $d['activation_id'], 'origin_id' => $d['origin']['id'], 'redemption_id' => $d['redemption']['id'],
            'artifact_manifest_hash' => $d['artifact_manifest_hash'], 'readiness_receipt_hash' => $d['readiness_receipt_hash'],
            'reservation_event_hash' => $d['reservation_event']['seal'], 'purpose' => $d['purpose'], 'created_at' => $d['activated_at']];
    }

    private static function consume(array $o, array $consume): void
    {
        MembershipPeriodRecords::verifyEvent($consume);
        $s = $o['source'];
        $r = $o['readiness_commitment'];
        $request = ['purpose' => 'membership-credit-consume-v1', 'period_id' => $s['period']['id'], 'period_seal' => $s['period']['seal'],
            'redemption_id' => $s['redemption']['id'], 'redemption_seal' => $s['redemption']['seal'], 'intent_hash' => $s['redemption']['intent_hash'],
            'reservation_event_id' => $s['reserve_event']['id'], 'reservation_event_seal' => $s['reserve_event']['seal'],
            'grant_origin_id' => $s['origin_id'], 'readiness_receipt_hash' => CanonicalJson::hash($r),
            'authorization_intent_hash' => $r['authorization_intent_hash'], 'honor_deadline' => $s['redemption']['honor_deadline']];
        MemberGrantException::require($consume['kind'] === 'consume' && $consume['period_id'] === $s['period']['id']
            && $consume['redemption_id'] === $s['redemption']['id'] && $consume['reservation_event_id'] === $s['reserve_event']['id']
            && $consume['grant_origin_id'] === $s['origin_id'] && $consume['grant_receipt_hash'] === CanonicalJson::hash($r)
            && $consume['grant_purpose'] === MemberGrantIntent::PURPOSE
            && self::integer($consume['amount'], 1) === $s['redemption']['credit_amount']
            && $consume['key_hash'] === CanonicalJson::hash(['purpose' => 'membership-credit-consume-event-v1', 'redemption_id' => $s['redemption']['id']])
            && $consume['request_hash'] === CanonicalJson::hash($request) && $consume['created_at'] === $o['created_at']
            && $consume['actor_binding_hash'] === CanonicalJson::hash($o['actor_binding']), 'invalid_member_consume');
    }

    private static function manifest(MemberOriginalArtifactManifest $manifest): array
    {
        return self::copy(['schema_version' => 'member-original-artifact-manifest-v1', 'family' => MemberGrantIntent::FAMILY,
            'purpose' => MemberGrantIntent::PURPOSE, 'origin_id' => $manifest->originId, 'originals' => $manifest->originals]);
    }

    private static function manifestValue(array $m): MemberOriginalArtifactManifest
    {
        MemberGrantDocumentValues::keys($m, ['schema_version', 'family', 'purpose', 'origin_id', 'originals']);
        MemberGrantException::require($m['schema_version'] === 'member-original-artifact-manifest-v1'
            && $m['family'] === MemberGrantIntent::FAMILY && $m['purpose'] === MemberGrantIntent::PURPOSE
            && is_array($m['originals']) && array_is_list($m['originals']) && count($m['originals']) === 1, 'invalid_manifest');
        $o = $m['originals'][0];
        MemberGrantDocumentValues::keys($o, ['role', 'sha256', 'bytes', 'storage_policy_hash']);
        // The legacy value constructor owns an intentional ordered entry API; canonical JSON has sorted keys.
        $originals = [['role' => $o['role'], 'sha256' => $o['sha256'], 'bytes' => $o['bytes'], 'storage_policy_hash' => $o['storage_policy_hash']]];

        return new MemberOriginalArtifactManifest($m['origin_id'], CanonicalJson::hash($m), $originals);
    }

    private static function write(string $table, array $document, array $row): array
    {
        $binding = self::rowBinding($table, $row);
        $plain = CanonicalJson::encode(['schema_version' => self::VERSIONS[$table], 'document' => $document, 'row_binding' => $binding]);
        MemberGrantException::require(strlen($plain) <= self::PLAINTEXT[$table], 'history_capacity');
        $row['payload_ciphertext'] = Crypt::encryptString($plain);
        MemberGrantException::require(strlen($row['payload_ciphertext']) <= self::CIPHERTEXT[$table], 'history_capacity');
        $row['seal'] = self::seal($table, $row, self::keys()[0]);

        return $row;
    }

    private static function read(string $table, array $row): array
    {
        MemberGrantDocumentValues::keys($row, [...self::COLUMNS[$table], 'payload_ciphertext', 'seal']);
        $binding = self::rowBinding($table, $row);
        MemberGrantDocumentValues::hash($row['seal']);
        MemberGrantException::require(is_string($row['payload_ciphertext']) && strlen($row['payload_ciphertext']) <= self::CIPHERTEXT[$table], 'history_capacity');
        $match = false;
        foreach (self::keys() as $key) {
            $match = hash_equals(self::seal($table, $row, $key), $row['seal']) || $match;
        }
        MemberGrantException::require($match, 'tampered');
        try {
            $plain = Crypt::decryptString($row['payload_ciphertext']);
            MemberGrantException::require(strlen($plain) <= self::PLAINTEXT[$table], 'history_capacity');
            $payload = json_decode($plain, true, 48, JSON_THROW_ON_ERROR);
            MemberGrantException::require(is_array($payload) && CanonicalJson::encode($payload) === $plain, 'tampered');
        } catch (MemberGrantException $error) {
            throw $error;
        } catch (Throwable) {
            throw new MemberGrantException('tampered');
        }
        MemberGrantDocumentValues::keys($payload, ['schema_version', 'document', 'row_binding']);
        MemberGrantException::require($payload['schema_version'] === self::VERSIONS[$table] && is_array($payload['document']), 'tampered');
        self::equal($payload['row_binding'], $binding);

        return $payload['document'];
    }

    private static function rowBinding(string $table, array $row): array
    {
        $binding = [];
        foreach (self::COLUMNS[$table] as $column) {
            MemberGrantException::require(array_key_exists($column, $row), 'tampered');
            $value = $row[$column];
            if (in_array($column, ['account_id', 'user_id', 'identity_origin_id'], true)) {
                self::integer($value, 1);
            } elseif ($column === 'artifact_count') {
                MemberGrantException::require(self::integer($value, 1) === 1, 'tampered');
            } elseif ($column === 'ordinal') {
                MemberGrantException::require(self::integer($value, 0) === 0, 'tampered');
            } elseif ($column === 'bytes') {
                MemberGrantException::require(self::integer($value, 32) <= MemberOriginalFiles::MAX_BYTES, 'tampered');
            } elseif ($column === 'id' || str_ends_with($column, '_id')) {
                MemberGrantDocumentValues::id($value);
            } elseif (str_ends_with($column, '_hash') || $column === 'sha256') {
                MemberGrantDocumentValues::hash($value);
            } elseif (in_array($column, ['created_at', 'honor_deadline'], true)) {
                self::utc($value);
            } elseif ($column === 'provenance') {
                MemberGrantDocumentValues::provenance($value);
            } elseif ($column === 'role') {
                MemberGrantException::require($value === 'license_pdf', 'tampered');
            } elseif ($column === 'purpose') {
                MemberGrantException::require($value === MemberGrantIntent::PURPOSE, 'tampered');
            }
            MemberGrantException::require(is_int($value) || is_string($value), 'tampered');
            $binding[$column] = (string) $value;
        }
        ksort($binding, SORT_STRING);

        return $binding;
    }

    private static function seal(string $table, array $row, string $key): string
    {
        unset($row['seal']);

        return hash_hmac('sha256', CanonicalJson::encode(['purpose' => 'member-activation-effect-record-v1',
            'table' => $table, 'row' => MemberGrantRecords::strings($row)]), $key);
    }

    private static function keys(): array
    {
        $key = config('app.key');
        $previous = config('app.previous_keys', []);
        MemberGrantException::require(is_string($key) && strlen($key) >= 32 && is_array($previous), 'key_absent');
        foreach ($previous as $old) {
            MemberGrantException::require(is_string($old) && strlen($old) >= 32, 'key_absent');
        }

        return array_values(array_unique([$key, ...$previous]));
    }

    private static function integer(mixed $value, int $minimum): int
    {
        MemberGrantException::require(is_int($value) || (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1), 'tampered');
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => PHP_INT_MAX]]);
        MemberGrantException::require($number !== false, 'tampered');

        return $number;
    }

    private static function actor(array $actor, array $buyer): array
    {
        MembershipValues::buyer($actor);
        foreach (['account_id', 'user_id', 'origin_id', 'account_public_id', 'provenance'] as $key) {
            MemberGrantException::require($actor[$key] === $buyer[$key], 'owner_changed');
        }

        return self::copy($actor);
    }

    private static function effectTime(mixed $at, array $source, array $authorization): void
    {
        self::utc($at);
        MemberGrantException::require($at >= $authorization['confirmed_at'] && $at >= $source['redemption']['created_at']
            && $at < $source['redemption']['honor_deadline'], 'invalid_record_time');
    }

    private static function family(array $document, string $version): void
    {
        MemberGrantException::require($document['schema_version'] === $version && $document['family'] === MemberGrantIntent::FAMILY
            && $document['purpose'] === MemberGrantIntent::PURPOSE, 'tampered');
        MemberGrantDocumentValues::provenance($document['provenance']);
    }

    private static function utc(mixed $value): void
    {
        MemberGrantException::require(is_string($value), 'tampered');
        MembershipValues::utc($value);
    }

    private static function path(string $provenance, string $origin, string $sourceHash): string
    {
        return "contracts/production-member-v1/{$provenance}/{$origin}/{$sourceHash}/original.pdf";
    }

    private static function equal(mixed $left, mixed $right): void
    {
        MemberGrantException::require(CanonicalJson::encode($left) === CanonicalJson::encode($right), 'tampered');
    }

    private static function copy(array $value): array
    {
        return json_decode(CanonicalJson::encode($value), true, 48, JSON_THROW_ON_ERROR);
    }
}
