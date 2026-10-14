<?php

namespace Tests\Unit\ProductionMemberOriginals;

use App\Domain\Grants\Member\MemberGrantDefinitionDocument;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantProfileDocument;
use App\Domain\Grants\Member\MemberGrantRecords;
use Tests\Support\MemberGrantDocumentFixtures as Fixture;
use Tests\TestCase;

final class MemberGrantRecordsTest extends TestCase
{
    private function records(): array
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('M', 32)), 'app.previous_keys' => []]);
        $profile = MemberGrantProfileDocument::from(Fixture::profile());
        $definition = MemberGrantDefinitionDocument::from(Fixture::definition());
        $p = MemberGrantRecords::profile($profile, '00000000-0000-4000-8000-000000000001', 1, hash('sha256', 'synthetic author'), '2026-10-13 00:00:00');
        $d = MemberGrantRecords::definition($definition, '00000000-0000-4000-8000-000000000002', $p['id'], 1, hash('sha256', 'synthetic author'), $p['created_at']);

        return [$p, $d];
    }

    public function test_complete_profile_definition_envelopes_round_trip_and_join(): void
    {
        [$p, $d] = $this->records();
        $snapshot = MemberGrantRecords::snapshot($p, $d);
        self::assertSame($p['profile_hash'], $snapshot->profileDocument->hash());
        self::assertSame($d['definition_hash'], $snapshot->definitionDocument->hash());
        self::assertSame($p['id'], $snapshot->factsBinding->profileId);
        self::assertSame($d['seal'], $snapshot->definitionSeal);
        self::assertStringNotContainsString('NONBINDING', $d['payload_ciphertext']);
        self::assertNotSame(MemberGrantRecords::seal(MemberGrantRecords::PROFILES, $p), MemberGrantRecords::seal(MemberGrantRecords::DEFINITIONS, $p));
    }

    public function test_every_physical_retained_column_is_sealed(): void
    {
        [$p, $d] = $this->records();
        foreach ([MemberGrantRecords::PROFILES => $p, MemberGrantRecords::DEFINITIONS => $d] as $table => $original) {
            foreach ($original as $column => $value) {
                $changed = $original;
                $changed[$column] = is_int($value) ? $value + 1 : $value.'x';
                try {
                    $table === MemberGrantRecords::PROFILES ? MemberGrantRecords::readProfile($changed) : MemberGrantRecords::readDefinition($changed);
                    self::fail('Changed physical column admitted: '.$table.'.'.$column);
                } catch (MemberGrantException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_even_resealed_unknown_physical_columns_are_refused(): void
    {
        [$p] = $this->records();
        $p['unknown'] = 'not owned';
        $p['seal'] = MemberGrantRecords::seal(MemberGrantRecords::PROFILES, $p);
        $this->expectException(MemberGrantException::class);
        MemberGrantRecords::readProfile($p);
    }

    public function test_resealed_wrong_profile_join_cannot_replace_exact_authored_profile(): void
    {
        [$p, $d] = $this->records();
        $d['profile_id'] = '00000000-0000-4000-8000-000000000099';
        $d['seal'] = MemberGrantRecords::seal(MemberGrantRecords::DEFINITIONS, $d);
        $this->expectException(MemberGrantException::class);
        MemberGrantRecords::snapshot($p, $d);
    }
}
