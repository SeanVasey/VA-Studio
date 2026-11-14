<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MemberGrantBinding;
use App\Domain\Memberships\Production\MembershipInvoiceBinding;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyBinding;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Immutable authenticated history values; never a current read, source admission or writer permit. */
final readonly class MemberActivationHistory
{
    public const MAX_BYTES = 67108864;

    private const TABLES = ['origins' => MemberActivationRecords::ORIGINS, 'artifacts' => MemberActivationRecords::ARTIFACTS,
        'activations' => MemberActivationRecords::ACTIVATIONS, 'profiles' => MemberGrantRecords::PROFILES,
        'definitions' => MemberGrantRecords::DEFINITIONS, 'plans' => MembershipRecords::TABLE];

    private function __construct(private array $retained, private array $graphs) {}

    public static function empty(): self
    {
        return self::fromRows([], [], [], [], [], [], 0);
    }

    public static function fromRows(array $origins, array $artifacts, array $activations, array $profiles,
        array $definitions, array $plans, int $redemptionCount): self
    {
        MemberGrantException::require($redemptionCount >= 0 && $redemptionCount < MembershipPolicy::MAX_EVENTS, 'history_capacity');
        $sets = compact('origins', 'artifacts', 'activations', 'profiles', 'definitions', 'plans');
        $rawBytes = 0;
        $decodedBytes = 0;
        $rows = $documents = [];
        foreach ($sets as $kind => $list) {
            MemberGrantException::require(array_is_list($list) && count($list) <= $redemptionCount, 'history_capacity');
            $rows[$kind] = [];
            foreach ($list as $row) {
                MemberGrantException::require(is_array($row) && isset($row['id']) && is_string($row['id']), 'tampered');
                MemberGrantDocumentValues::id($row['id']);
                MemberGrantException::require(! isset($rows[$kind][$row['id']]), 'duplicate_history');
                // Scalar copies detach references before callbacks. Row-specific decoders still validate every column/type.
                foreach ($row as $value) {
                    MemberGrantException::require($value === null || is_string($value) || is_int($value), 'tampered');
                }
                $row = array_map(static fn ($value) => $value, $row);
                $cipherLimit = match ($kind) {
                    'origins' => 4194304, 'artifacts' => 262144, 'activations', 'plans' => 524288,
                    'profiles', 'definitions' => 1572864,
                };
                MemberGrantException::require(isset($row['payload_ciphertext']) && is_string($row['payload_ciphertext'])
                    && strlen($row['payload_ciphertext']) <= $cipherLimit, 'history_capacity');
                $rawBytes += strlen(CanonicalJson::encode(MemberGrantRecords::strings($row)));
                MemberGrantException::require($rawBytes <= self::MAX_BYTES, 'history_capacity');
                $rows[$kind][$row['id']] = $row;
            }
            ksort($rows[$kind], SORT_STRING);
        }
        // Authenticate support documents before interpreting a foreign key's retained plan identity.
        foreach ($rows['profiles'] as $id => $row) {
            $documents['profiles'][$id] = MemberGrantRecords::readProfile($row)->value();
            self::decoded($row, MemberGrantDocumentValues::MAX_BYTES + 2048, $decodedBytes);
        }
        foreach ($rows['definitions'] as $id => $row) {
            $documents['definitions'][$id] = MemberGrantRecords::readDefinition($row)->value();
            self::decoded($row, MemberGrantDocumentValues::MAX_BYTES + 2048, $decodedBytes);
        }
        foreach ($rows['plans'] as $id => $row) {
            $documents['plans'][$id] = MembershipPolicyVersions::document($row)->binding($id);
            self::decoded($row, 131072, $decodedBytes);
        }
        $artifactByOrigin = $activationByOrigin = [];
        foreach ($rows['artifacts'] as $id => $row) {
            $f = MemberActivationRecords::readArtifact($row);
            self::decoded($row, 65536, $decodedBytes);
            MemberGrantException::require(isset($rows['origins'][$row['origin_id']]) && ! isset($artifactByOrigin[$row['origin_id']]), 'surplus_history');
            $documents['artifacts'][$id] = $f;
            $artifactByOrigin[$row['origin_id']] = $id;
        }
        $consumes = [];
        foreach ($rows['activations'] as $id => $row) {
            $d = MemberActivationRecords::readActivation($row);
            self::decoded($row, 262144, $decodedBytes);
            MemberGrantException::require(isset($rows['origins'][$row['origin_id']]) && ! isset($activationByOrigin[$row['origin_id']])
                && ! isset($consumes[$d['consume_event']['id']]), 'surplus_history');
            $consumes[$d['consume_event']['id']] = true;
            $documents['activations'][$id] = $d;
            $activationByOrigin[$row['origin_id']] = $id;
        }
        $usedDefinitions = $usedProfiles = $usedPlans = $graphs = [];
        foreach ($rows['origins'] as $id => $row) {
            MemberGrantException::require(isset($row['definition_id'], $row['profile_id'], $row['redemption_id'])
                && isset($rows['definitions'][$row['definition_id']], $rows['profiles'][$row['profile_id']],
                    $artifactByOrigin[$id], $activationByOrigin[$id]) && ! isset($graphs[$row['redemption_id']]), 'incomplete_history');
            $definition = $documents['definitions'][$row['definition_id']];
            $profile = $documents['profiles'][$row['profile_id']];
            $planId = $definition['plan_version_id'];
            MemberGrantException::require(isset($documents['plans'][$planId]), 'incomplete_history');
            $policy = $documents['plans'][$planId];
            $o = MemberActivationRecords::readOrigin($row, $policy);
            self::decoded($row, 2097152, $decodedBytes);
            $s = $o['source'];
            self::equal($s['definition']['document'], $definition);
            self::equal($s['profile']['document'], $profile);
            MemberGrantException::require($rows['definitions'][$row['definition_id']]['profile_id'] === $row['profile_id']
                && $s['definition']['id'] === $row['definition_id'] && $s['profile']['id'] === $row['profile_id']
                && $s['definition']['seal'] === $rows['definitions'][$row['definition_id']]['seal']
                && $s['profile']['seal'] === $rows['profiles'][$row['profile_id']]['seal'], 'mislinked_history');
            $artifactId = $artifactByOrigin[$id];
            $activationId = $activationByOrigin[$id];
            $f = $documents['artifacts'][$artifactId];
            $d = $documents['activations'][$activationId];
            self::memberGraph($row, $o, $rows['artifacts'][$artifactId], $f, $rows['activations'][$activationId], $d);
            $usedDefinitions[$row['definition_id']] = $usedProfiles[$row['profile_id']] = $usedPlans[$planId] = true;
            $graphs[$row['redemption_id']] = ['origin' => $row, 'artifact' => $rows['artifacts'][$artifactId],
                'activation' => $rows['activations'][$activationId], 'o' => $o, 'f' => $f, 'd' => $d,
                'plan_id' => $planId];
        }
        foreach (['definitions' => $usedDefinitions, 'profiles' => $usedProfiles, 'plans' => $usedPlans] as $kind => $used) {
            $expected = array_keys($rows[$kind]);
            $actual = array_keys($used);
            sort($actual, SORT_STRING);
            MemberGrantException::require($actual === $expected, 'surplus_history');
        }
        $retained = [];
        foreach ($rows as $kind => $list) {
            $retained[$kind] = array_values(array_map(MemberGrantRecords::strings(...), $list));
        }

        return new self($retained, $graphs);
    }

    public function rows(): array
    {
        return $this->retained;
    }

    public function verifyConsume(array $period, array $redemption, array $reserve, array $consume,
        MembershipInvoiceBinding $invoice, MembershipPolicyBinding $policy, int $identityOriginId): MemberGrantBinding
    {
        MemberGrantException::require($policy->supports('consume') && $policy->consumptionTerms !== null
            && isset($redemption['id']) && isset($this->graphs[$redemption['id']]), 'unsupported_credit_history');
        $g = $this->graphs[$redemption['id']];
        $s = $g['o']['source'];
        $r = $g['o']['readiness_commitment'];
        $reservation = MembershipReservationRecords::verify($redemption, $period, $invoice, $policy);
        self::equal($s['selection'], $reservation['intent']['selection']);
        self::equal($s['invoice'], MembershipPeriodRecords::semantic($invoice));
        self::equal($s['buyer_binding'], $invoice->originalBuyerBinding);
        MemberGrantException::require($s['period']['id'] === $period['id'] && $s['period']['seal'] === $period['seal']
            && $s['redemption']['id'] === $redemption['id'] && $s['redemption']['seal'] === $redemption['seal']
            && $s['redemption']['intent_hash'] === $redemption['intent_hash']
            && (string) $s['redemption']['credit_amount'] === (string) $redemption['credit_amount']
            && $s['redemption']['created_at'] === $redemption['created_at'] && $s['redemption']['honor_deadline'] === $redemption['honor_deadline']
            && $s['reserve_event']['id'] === $reserve['id'] && $s['reserve_event']['seal'] === $reserve['seal']
            && $g['d']['consume_event']['id'] === $consume['id'] && $g['d']['consume_event']['seal'] === $consume['seal']
            && $g['plan_id'] === $policy->planVersionId && $s['invoice']['policy_hash'] === $policy->policyHash
            && (string) $g['origin']['identity_origin_id'] === (string) $identityOriginId
            && $identityOriginId > 0 && $consume['key_hash'] === MembershipReservationRecords::consumeKey($redemption['id'])
            && $consume['request_hash'] === MembershipReservationRecords::consumeRequest($period, $redemption, $reserve, $r), 'mislinked_history');

        return MemberActivationRecords::binding($g['origin'], $g['artifact'], $consume, $g['activation'], $policy);
    }

    public function assertComplete(array $consumeEventIds): void
    {
        MemberGrantException::require(array_is_list($consumeEventIds), 'incomplete_history');
        $actual = [];
        foreach ($consumeEventIds as $id) {
            MemberGrantDocumentValues::id($id);
            MemberGrantException::require(! isset($actual[$id]), 'duplicate_history');
            $actual[$id] = true;
        }
        $expected = [];
        foreach ($this->graphs as $g) {
            $expected[$g['d']['consume_event']['id']] = true;
        }
        ksort($actual, SORT_STRING);
        ksort($expected, SORT_STRING);
        MemberGrantException::require($actual === $expected, 'incomplete_history');
    }

    public function original(string $redemptionId): MemberActivatedOriginal
    {
        MemberGrantDocumentValues::id($redemptionId);
        MemberGrantException::require(isset($this->graphs[$redemptionId]), 'activation_absent');
        $g = $this->graphs[$redemptionId];

        return MemberActivatedOriginal::from($g['o']['source'], $g['f'], ['origin_id' => $g['origin']['id'],
            'redemption_id' => $redemptionId, 'definition_id' => $g['origin']['definition_id'],
            'consume_event_id' => $g['d']['consume_event']['id'], 'activation_id' => $g['activation']['id'],
            'artifact_manifest_hash' => $g['d']['artifact_manifest_hash'], 'activation_hash' => CanonicalJson::hash($g['d'])]);
    }

    private static function memberGraph(array $origin, array $o, array $artifact, array $f, array $activation, array $d): void
    {
        $s = $o['source'];
        $r = $o['readiness_commitment'];
        $m = $o['artifact_manifest']['originals'][0];
        MemberGrantException::require($f['origin_id'] === $origin['id'] && $f['artifact_id'] === $artifact['id']
            && $f['sha256'] === $m['sha256'] && $f['bytes'] === $m['bytes'] && $f['storage_policy_hash'] === $m['storage_policy_hash']
            && $f['render_source_hash'] === $o['render_source_hash']
            && $f['profile_hash'] === CanonicalJson::hash($s['profile']['document']['render_profile'])
            && $f['provenance'] === $o['provenance'] && $f['created_at'] === $o['created_at']
            && $d['activation_id'] === $activation['id'] && $d['origin'] === ['id' => $origin['id'], 'seal' => $origin['seal']]
            && $d['redemption'] === ['id' => $s['redemption']['id'], 'seal' => $s['redemption']['seal']]
            && $d['period'] === $s['period'] && $d['reservation_event'] === $s['reserve_event']
            && $d['definition'] === ['hash' => $r['definition_hash'], 'id' => $s['definition']['id']]
            && $d['profile'] === ['hash' => $r['profile_hash'], 'id' => $s['profile']['id']]
            && $d['render_source_hash'] === $o['render_source_hash'] && $d['artifact_manifest_hash'] === $r['artifact_manifest_hash']
            && $d['readiness_receipt_hash'] === CanonicalJson::hash($r) && $d['authorization_document_hash'] === $r['authorization_document_hash']
            && $d['authorization_intent_hash'] === $r['authorization_intent_hash']
            && $d['consumption_terms_hash'] === $r['consumption_terms_hash'] && $d['retention_policy_hash'] === $r['retention_policy_hash']
            && $d['provenance'] === $o['provenance'] && $d['activated_at'] === $o['created_at'], 'mislinked_history');
        self::equal($d['artifacts'], [['id' => $artifact['id'], 'seal' => $artifact['seal'], 'ordinal' => 0, 'role' => 'license_pdf']]);
        self::equal($d['actor_binding'], $o['actor_binding']);
    }

    private static function decoded(array $row, int $limit, int &$total): void
    {
        // Each unique authenticated record contributes exactly its canonical plaintext once to this budget.
        try {
            $plain = Crypt::decryptString($row['payload_ciphertext']);
        } catch (Throwable) {
            throw new MemberGrantException('tampered');
        }
        MemberGrantException::require(strlen($plain) <= $limit, 'history_capacity');
        $total += strlen($plain);
        MemberGrantException::require($total <= self::MAX_BYTES, 'history_capacity');
    }

    private static function equal(mixed $left, mixed $right): void
    {
        MemberGrantException::require(CanonicalJson::encode($left) === CanonicalJson::encode($right), 'mislinked_history');
    }
}
