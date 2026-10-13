<?php

namespace Tests\Unit\ProductionMemberGrants;

use App\Domain\Grants\Member\MemberActivationHistory;
use App\Domain\Grants\Member\MemberActivationRecords;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantRecords;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use ReflectionMethod;
use Tests\Support\MemberActivationRecordValues as Fixture;
use Tests\TestCase;

final class MemberActivationHistoryTest extends TestCase
{
    public function test_complete_authenticated_graph_is_detached_and_repeated_bijection_audits_agree(): void
    {
        $f = Fixture::fixture();
        $history = $this->history($f);
        $ids = [$f['consume']['id']];
        $history->assertComplete($ids);
        $history->assertComplete($ids);
        $original = $history->original($f['origin']['redemption_id']);
        $this->assertSame($f['source']->hash(), $original->source()->hash());
        $this->assertSame($f['activation']['id'], $original->identities()['activation_id']);
        $this->assertSame($f['artifact']['id'], $original->artifact()['artifact_id']);
        $row = $history->rows();
        $this->assertSame(MemberGrantRecords::strings($f['origin']), $row['origins'][0]);
        $row['origins'][0]['identity_origin_id'] = '999';
        $this->assertSame('17', $history->rows()['origins'][0]['identity_origin_id']);
        $this->assertSame(['origins', 'artifacts', 'activations', 'profiles', 'definitions', 'plans'], array_keys($history->rows()));
    }

    public function test_empty_history_is_a_value_and_refuses_a_declared_consume(): void
    {
        $empty = MemberActivationHistory::empty();
        $empty->assertComplete([]);
        $this->assertSame(array_fill_keys(['origins', 'artifacts', 'activations', 'profiles', 'definitions', 'plans'], []), $empty->rows());
        $this->expectException(MemberGrantException::class);
        $empty->assertComplete([Fixture::id(21)]);
    }

    public function test_each_missing_or_surplus_graph_set_refuses_without_adopting_it(): void
    {
        $f = Fixture::fixture();
        foreach (['origins', 'artifacts', 'activations', 'profiles', 'definitions', 'plans'] as $kind) {
            $rows = $this->rows($f);
            $rows[$kind] = [];
            try {
                $this->from($rows, 1);
                $this->fail('Incomplete graph admitted: '.$kind);
            } catch (MemberGrantException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['profiles', 'definitions', 'plans'] as $kind) {
            $rows = array_fill_keys(array_keys($this->rows($f)), []);
            $rows[$kind] = $this->rows($f)[$kind];
            try {
                $this->from($rows, 1);
                $this->fail('Surplus support admitted');
            } catch (MemberGrantException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_duplicate_physical_rows_and_row_count_overflow_refuse(): void
    {
        $f = Fixture::fixture();
        foreach (array_keys($this->rows($f)) as $kind) {
            $rows = $this->rows($f);
            $rows[$kind][] = $rows[$kind][0];
            try {
                $this->from($rows, 2);
                $this->fail('Duplicate physical identity admitted');
            } catch (MemberGrantException $error) {
                $this->assertSame('duplicate_history', $error->reason);
            }
        }
        $this->expectException(MemberGrantException::class);
        $this->from($this->rows($f), 0);
    }

    public function test_complete_consume_set_is_exact_and_immutable_between_calls(): void
    {
        $f = Fixture::fixture();
        $h = $this->history($f);
        foreach ([[], [Fixture::id(99)], [$f['consume']['id'], $f['consume']['id']]] as $ids) {
            try {
                $h->assertComplete($ids);
                $this->fail('Wrong complete consume set admitted');
            } catch (MemberGrantException) {
                $this->addToAssertionCount(1);
            }
            $h->assertComplete([$f['consume']['id']]);
            $this->assertSame($f['origin']['id'], $h->original($f['origin']['id'])->identities()['origin_id']);
        }
    }

    public function test_resealed_crosslinks_cannot_use_a_different_artifact_or_activation(): void
    {
        $f = Fixture::fixture();
        foreach (['artifact_origin', 'definition', 'profile', 'consume', 'origin_seal', 'artifact_seal'] as $change) {
            $rows = $this->rows($f);
            $kind = $change === 'artifact_origin' ? 'artifacts' : 'activations';
            $row = $rows[$kind][0];
            $p = json_decode(Crypt::decryptString($row['payload_ciphertext']), true);
            match ($change) {
                'artifact_origin' => $p['document']['origin_id'] = Fixture::id(99),
                'definition' => $p['document']['definition']['id'] = Fixture::id(99),
                'profile' => $p['document']['profile']['hash'] = str_repeat('a', 64),
                'consume' => $p['document']['consume_event']['id'] = Fixture::id(99),
                'origin_seal' => $p['document']['origin']['seal'] = str_repeat('a', 64),
                'artifact_seal' => $p['document']['artifacts'][0]['seal'] = str_repeat('a', 64),
            };
            if ($change === 'artifact_origin') {
                $row['origin_id'] = Fixture::id(99);
                $p['row_binding']['origin_id'] = Fixture::id(99);
                $p['document']['derived_relative_path'] = str_replace($f['origin']['id'], Fixture::id(99), $p['document']['derived_relative_path']);
            }
            $row['payload_ciphertext'] = Crypt::encryptString(CanonicalJson::encode($p));
            unset($row['seal']);
            $row['seal'] = hash_hmac('sha256', CanonicalJson::encode(['purpose' => 'member-activation-effect-record-v1',
                'table' => $kind === 'artifacts' ? MemberActivationRecords::ARTIFACTS : MemberActivationRecords::ACTIVATIONS,
                'row' => MemberGrantRecords::strings($row)]), config('app.key'));
            $rows[$kind][0] = $row;
            try {
                $h = $this->from($rows, 1);
                // A D consume locator needs actual 257 linkage; assertComplete closes that independent scope here.
                $h->assertComplete([$f['consume']['id']]);
                $this->fail('Resealed crosslink admitted');
            } catch (MemberGrantException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_raw_budget_refuses_before_decrypting_unbounded_aggregate_rows(): void
    {
        $f = Fixture::fixture();
        $rows = array_fill_keys(array_keys($this->rows($f)), []);
        $body = str_repeat('x', 4194304);
        for ($i = 100; $i < 116; $i++) {
            $rows['origins'][] = [...$f['origin'], 'id' => Fixture::id($i), 'payload_ciphertext' => $body];
        }
        try {
            $this->from($rows, 16);
            $this->fail('Raw member history overflow admitted');
        } catch (MemberGrantException $error) {
            $this->assertSame('history_capacity', $error->reason);
        }
    }

    public function test_authenticated_plaintext_budget_has_an_exact_nonrenewing_boundary(): void
    {
        $f = Fixture::fixture();
        $method = new ReflectionMethod(MemberActivationHistory::class, 'decoded');
        $size = strlen(Crypt::decryptString($f['origin']['payload_ciphertext']));
        $used = MemberActivationHistory::MAX_BYTES - $size;
        $args = [$f['origin'], 2097152, &$used];
        $method->invokeArgs(null, $args);
        $this->assertSame(MemberActivationHistory::MAX_BYTES, $used);
        try {
            $method->invokeArgs(null, $args);
            $this->fail('Decoded history accumulator overflow admitted');
        } catch (MemberGrantException $error) {
            $this->assertSame('history_capacity', $error->reason);
        }
    }

    private function rows(array $f): array
    {
        return ['origins' => [$f['origin']], 'artifacts' => [$f['artifact']], 'activations' => [$f['activation']],
            'profiles' => [$f['profile']], 'definitions' => [$f['definition']], 'plans' => [$f['plan']]];
    }

    private function history(array $f): MemberActivationHistory
    {
        return $this->from($this->rows($f), 1);
    }

    private function from(array $rows, int $count): MemberActivationHistory
    {
        return MemberActivationHistory::fromRows($rows['origins'], $rows['artifacts'], $rows['activations'],
            $rows['profiles'], $rows['definitions'], $rows['plans'], $count);
    }
}
