<?php

namespace Tests\Support;

use App\Domain\Grants\Member\MemberActivationReadiness;
use App\Domain\Grants\Member\MemberActivationRecords;
use App\Domain\Grants\Member\MemberGrantDefinitionDocument;
use App\Domain\Grants\Member\MemberGrantProfileDocument;
use App\Domain\Grants\Member\MemberGrantRecords;
use App\Domain\Grants\Member\MemberLicenseAuthorizationRecords;
use App\Domain\Grants\Member\MemberOriginalArtifactManifest;
use App\Domain\Grants\Member\MemberOriginalSourceValues;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Support\CanonicalJson;

/** Synthetic authenticated codec values only. No live producer, DB scope, frame or authority. */
final class MemberActivationRecordValues
{
    public static function fixture(): array
    {
        $f = MemberLicenseAuthorizationValues::fixture();
        $s = $f['source']->document();
        $at = '2026-10-13 00:02:00';
        $p = MemberGrantRecords::profile(MemberGrantProfileDocument::from($s['profile']['document']), $s['profile']['id'],
            1, hash('sha256', 'NONBINDING author'), '2026-10-12 00:00:00');
        $def = MemberGrantRecords::definition(MemberGrantDefinitionDocument::from($s['definition']['document']), $s['definition']['id'],
            $s['profile']['id'], 1, hash('sha256', 'NONBINDING author'), '2026-10-12 00:00:00');
        $s['profile']['seal'] = $p['seal'];
        $s['definition']['seal'] = $def['seal'];
        $source = MemberOriginalSourceValues::from($s);
        $plan = ['id' => $f['policy']->planVersionId, 'policy_hash' => $f['policy']->policyHash,
            'original_terms_hash' => $f['policy']->originalTermsHash, 'provenance' => 'synthetic_rehearsal', 'created_at' => '2026-10-12 00:00:00'];
        $plan['payload_ciphertext'] = MembershipRecords::encrypt(['schema_version' => 'membership-policy-record-v1',
            'event' => 'policy_version_authored', 'plan_version_id' => $plan['id'], 'document' => $f['policy_document']->facts,
            'author_user_id' => 1, 'author_binding_hash' => hash('sha256', 'NONBINDING author'), 'created_at' => $plan['created_at']]);
        $plan['seal'] = MembershipRecords::seal($plan);
        $a = MemberLicenseAuthorizationRecords::create($source, $f['policy'], $f['actor'], $f['authorization_id'], $f['confirmed_at']);
        $originals = [['role' => 'license_pdf', 'sha256' => hash('sha256', 'NONBINDING synthetic codec artifact'), 'bytes' => 128,
            'storage_policy_hash' => CanonicalJson::hash($s['definition']['document']['storage_policy'])]];
        $m = ['schema_version' => 'member-original-artifact-manifest-v1', 'family' => $s['family'], 'purpose' => $s['purpose'],
            'origin_id' => $s['origin_id'], 'originals' => $originals];
        $manifest = new MemberOriginalArtifactManifest($s['origin_id'], CanonicalJson::hash($m), $originals);
        $r = MemberActivationReadiness::forSource($source, $a, $f['policy'], $manifest);
        $origin = MemberActivationRecords::origin($source, $a, $f['policy'], $manifest, 17, $f['actor'], $at);
        $artifact = MemberActivationRecords::artifact($origin, $f['policy'], self::id(20), $at);
        // Complete sealed event values isolate codec semantics. They do not assert an actual retained ledger.
        $consume = ['id' => self::id(21), 'period_id' => $s['period']['id'], 'sequence' => 3, 'kind' => 'consume', 'amount' => 1,
            'redemption_id' => $s['redemption']['id'], 'reservation_event_id' => $s['reserve_event']['id'],
            'grant_origin_id' => $origin['id'], 'grant_receipt_hash' => $r->hash(), 'grant_purpose' => $s['purpose'],
            'key_hash' => CanonicalJson::hash(['purpose' => 'membership-credit-consume-event-v1', 'redemption_id' => $s['redemption']['id']]),
            'request_hash' => CanonicalJson::hash(['purpose' => 'membership-credit-consume-v1', 'period_id' => $s['period']['id'],
                'period_seal' => $s['period']['seal'], 'redemption_id' => $s['redemption']['id'], 'redemption_seal' => $s['redemption']['seal'],
                'intent_hash' => $s['redemption']['intent_hash'], 'reservation_event_id' => $s['reserve_event']['id'],
                'reservation_event_seal' => $s['reserve_event']['seal'], 'grant_origin_id' => $origin['id'],
                'readiness_receipt_hash' => $r->hash(), 'authorization_intent_hash' => $a['intent_hash'], 'honor_deadline' => $s['redemption']['honor_deadline']]),
            'prior_seal' => $s['reserve_event']['seal'], 'actor_binding_hash' => CanonicalJson::hash($f['actor']),
            'before_available' => 2, 'before_reserved' => 1, 'before_consumed' => 0, 'before_expired' => 0,
            'after_available' => 2, 'after_reserved' => 0, 'after_consumed' => 1, 'after_expired' => 0, 'created_at' => $at];
        $consume['seal'] = MembershipPeriodRecords::seal(MembershipPeriodRecords::EVENTS, $consume);
        $activation = MemberActivationRecords::activation($origin, $artifact, $consume, $f['policy'], self::id(22), $f['actor'], $at);

        return [...$f, 'source' => $source, 'authorization' => $a, 'manifest' => $manifest, 'readiness' => $r,
            'origin' => $origin, 'artifact' => $artifact, 'consume' => $consume, 'activation' => $activation,
            'profile' => $p, 'definition' => $def, 'plan' => $plan, 'effect_at' => $at];
    }

    public static function id(int $id): string
    {
        return '00000000-0000-4000-8000-'.str_pad((string) $id, 12, '0', STR_PAD_LEFT);
    }
}
