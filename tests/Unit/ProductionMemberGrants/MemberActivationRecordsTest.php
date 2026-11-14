<?php

namespace Tests\Unit\ProductionMemberGrants;

use App\Domain\Grants\Member\MemberActivationReadiness;
use App\Domain\Grants\Member\MemberActivationRecords;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantRecords;
use App\Domain\Grants\Member\MemberLicenseAuthorizationRecords;
use App\Domain\Grants\Member\MemberOriginalArtifactManifest;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\MemberActivationRecordValues as Fixture;
use Tests\TestCase;

final class MemberActivationRecordsTest extends TestCase
{
    public function test_ordered_real_codecs_round_trip_and_keep_distinct_profile_hashes(): void
    {
        $f = Fixture::fixture();
        $o = MemberActivationRecords::readOrigin($f['origin'], $f['policy']);
        $a = MemberActivationRecords::readArtifact($f['artifact']);
        $d = MemberActivationRecords::readActivation($f['activation']);
        $binding = MemberActivationRecords::binding($f['origin'], $f['artifact'], $f['consume'], $f['activation'], $f['policy']);
        $this->assertSame($f['source']->hash(), $o['render_source_hash']);
        $this->assertSame($f['manifest']->manifestHash, $binding->originalArtifactHash);
        $this->assertSame(CanonicalJson::hash($d), $binding->activationHash);
        $this->assertSame($f['source']->document()['redemption']['id'], $binding->originId);
        $this->assertSame(CanonicalJson::hash($f['source']->document()['profile']['document']['render_profile']), $a['profile_hash']);
        $this->assertSame(CanonicalJson::hash($f['source']->document()['profile']['document']), $binding->profileHash);
        $this->assertNotSame($binding->profileHash, $a['profile_hash']);
        $this->assertSame('17', json_decode(Crypt::decryptString($f['origin']['payload_ciphertext']), true)['row_binding']['identity_origin_id']);
        $this->assertSame($f['readiness']->hash(), $f['consume']['grant_receipt_hash']);
    }

    public function test_native_integer_and_canonical_pdo_decimal_rows_have_identical_meaning(): void
    {
        $f = Fixture::fixture();
        $normal = MemberActivationRecords::binding($f['origin'], $f['artifact'], $f['consume'], $f['activation'], $f['policy']);
        $strings = MemberActivationRecords::binding(MemberGrantRecords::strings($f['origin']), MemberGrantRecords::strings($f['artifact']),
            MemberGrantRecords::strings($f['consume']), MemberGrantRecords::strings($f['activation']), $f['policy']);
        $this->assertEquals($normal, $strings);
        foreach (['01', '+1', '1.0', '1e0', true, 1.0, PHP_INT_MAX.'0'] as $bad) {
            $row = $f['origin'];
            $row['account_id'] = $bad;
            try {
                MemberActivationRecords::readOrigin($row, $f['policy']);
                $this->fail('Noncanonical row integer admitted');
            } catch (MemberGrantException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_each_physical_column_and_exact_envelope_is_authenticated(): void
    {
        $f = Fixture::fixture();
        foreach (['origin', 'artifact', 'activation'] as $kind) {
            foreach ($f[$kind] as $column => $value) {
                $row = $f[$kind];
                $row[$column] = is_int($value) ? $value + 1 : $value.'x';
                try {
                    $this->read($kind, $row, $f);
                    $this->fail('Changed retained column admitted: '.$kind.'.'.$column);
                } catch (\Throwable $error) {
                    $this->assertNotInstanceOf(AssertionFailedError::class, $error);
                }
            }
            $row = $f[$kind];
            $payload = json_decode(Crypt::decryptString($row['payload_ciphertext']), true);
            $payload['extra'] = 'not owned';
            $row = $this->reseal($kind, $row, $payload);
            try {
                $this->read($kind, $row, $f);
                $this->fail('Resealed unknown envelope member admitted');
            } catch (MemberGrantException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_resealed_document_and_row_binding_changes_cannot_change_graph_meaning(): void
    {
        $f = Fixture::fixture();
        foreach (['binding', 'profile', 'path', 'source', 'authorization', 'activation'] as $change) {
            $kind = in_array($change, ['profile', 'path'], true) ? 'artifact' : ($change === 'activation' ? 'activation' : 'origin');
            $row = $f[$kind];
            $payload = json_decode(Crypt::decryptString($row['payload_ciphertext']), true);
            match ($change) {
                'binding' => $payload['row_binding']['identity_origin_id'] = 17,
                'profile' => $payload['document']['profile_hash'] = $f['readiness']->value()['profile_hash'],
                'path' => $payload['document']['derived_relative_path'] .= '/other',
                'source' => $payload['document']['source']['buyer_binding']['account_id']++,
                'authorization' => $payload['document']['authorization']['affirmed'] = false,
                'activation' => $payload['document']['consume_event']['seal'] = str_repeat('a', 64),
            };
            $row = $this->reseal($kind, $row, $payload);
            $changed = [...$f, $kind => $row];
            try {
                MemberActivationRecords::binding($changed['origin'], $changed['artifact'], $changed['consume'], $changed['activation'], $f['policy']);
                $this->fail('Resealed graph change admitted');
            } catch (MemberGrantException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_readiness_is_detached_and_independent_confirmations_keep_winner_evidence(): void
    {
        $f = Fixture::fixture();
        $v = $f['readiness']->value();
        $id = $v['definition_id'];
        $v['definition_id'] = &$id;
        $copy = MemberActivationReadiness::from($v);
        $before = $copy->hash();
        $id = Fixture::id(99);
        $this->assertSame($before, $copy->hash());
        $a = MemberLicenseAuthorizationRecords::create($f['source'], $f['policy'], $f['actor'], Fixture::id(98), '2026-10-13 00:01:01');
        $r = MemberActivationReadiness::forSource($f['source'], $a, $f['policy'], $f['manifest']);
        $this->assertSame($f['authorization']['intent_hash'], $a['intent_hash']);
        $this->assertNotSame($r->hash(), $f['readiness']->hash());
        $this->assertSame($f['authorization'], MemberActivationRecords::readOrigin($f['origin'], $f['policy'])['authorization']);
    }

    public function test_manifest_shape_is_not_a_verified_hash_and_expired_effect_refuses(): void
    {
        $f = Fixture::fixture();
        foreach (['manifest', 'honor', 'confirmation'] as $change) {
            $manifest = $change === 'manifest'
                ? new MemberOriginalArtifactManifest($f['manifest']->originId, str_repeat('a', 64), $f['manifest']->originals) : $f['manifest'];
            $at = match ($change) {
                'honor' => $f['source']->document()['redemption']['honor_deadline'],
                'confirmation' => '2026-10-13 00:00:59', default => $f['effect_at'],
            };
            try {
                MemberActivationRecords::origin($f['source'], $f['authorization'], $f['policy'], $manifest, 17, $f['actor'], $at);
                $this->fail('Unverified manifest or effect time admitted');
            } catch (MemberGrantException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_resealed_consume_must_match_the_exact_reserved_graph_and_effect_actor(): void
    {
        $f = Fixture::fixture();
        foreach (['amount', 'request_hash', 'actor_binding_hash', 'grant_receipt_hash', 'created_at', 'reservation_event_id'] as $field) {
            $c = $f['consume'];
            $c[$field] = match ($field) {
                'amount' => 2, 'created_at' => '2026-10-13 00:02:01',
                'reservation_event_id' => Fixture::id(99), default => str_repeat('b', 64)
            };
            $c['seal'] = MembershipPeriodRecords::seal(MembershipPeriodRecords::EVENTS, $c);
            try {
                MemberActivationRecords::activation($f['origin'], $f['artifact'], $c, $f['policy'], Fixture::id(22), $f['actor'], $f['effect_at']);
                $this->fail('Resealed foreign consume admitted');
            } catch (MemberGrantException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function read(string $kind, array $row, array $fixture): array
    {
        return match ($kind) {
            'origin' => MemberActivationRecords::readOrigin($row, $fixture['policy']),
            'artifact' => MemberActivationRecords::readArtifact($row), 'activation' => MemberActivationRecords::readActivation($row)
        };
    }

    private function reseal(string $kind, array $row, array $payload): array
    {
        $row['payload_ciphertext'] = Crypt::encryptString(CanonicalJson::encode($payload));
        unset($row['seal']);
        $table = match ($kind) {
            'origin' => MemberActivationRecords::ORIGINS, 'artifact' => MemberActivationRecords::ARTIFACTS,
            'activation' => MemberActivationRecords::ACTIVATIONS
        };
        $row['seal'] = hash_hmac('sha256', CanonicalJson::encode(['purpose' => 'member-activation-effect-record-v1',
            'table' => $table, 'row' => MemberGrantRecords::strings($row)]), config('app.key'));

        return $row;
    }
}
