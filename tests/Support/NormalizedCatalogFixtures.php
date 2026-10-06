<?php

namespace Tests\Support;

use App\Domain\Migration\CatalogOnboarding\NormalizedSourceSnapshot;
use App\Support\CanonicalJson;

/** Explicit synthetic normalized records: no claim about actual provider exports or account data. */
final class NormalizedCatalogFixtures
{
    public const ARTIFACT = "SYNTHETIC NORMALIZED CATALOG EVIDENCE ONLY\nsource-id,title,bpm\n001,SYNTHETIC Alpha,0095\n";

    public static function snapshot(int $count = 2): array
    {
        $records = [];
        for ($index = 1; $index <= $count; $index++) {
            $metadata = ['title' => 'SYNTHETIC Alpha '.$index, 'slug' => 'synthetic-import-'.$index,
                'artist' => 'SYNTHETIC artist', 'bpm' => 95, 'musical_key' => 'C minor', 'genre' => 'Synthetic',
                'mood' => null, 'tags' => ['Synthetic'], 'description' => "SYNTHETIC private note\nsecond line"];
            $records[] = self::seal(['source_id' => sprintf('%03d', $index), 'source_record_sha256' => '',
                'artifact_ids' => ['synthetic-raw'], 'raw_metadata' => array_replace($metadata, ['bpm' => '0095']),
                'metadata' => $metadata, 'visibility' => 'private', 'assets' => []]);
        }

        return ['schema_version' => 1, 'purpose' => NormalizedSourceSnapshot::SCHEMA, 'snapshot_id' => 'synthetic-source-snapshot',
            'source_system' => 'synthetic/provider:account', 'acquired_at' => '2026-10-06T12:00:00Z',
            'source_as_of' => '2026-10-06T11:00:00Z', 'acquisition_method' => 'synthetic_fixture',
            'operator_reference' => 'SYNTHETIC operator attestation only',
            'artifacts' => [['artifact_id' => 'synthetic-raw', 'relative_path' => 'raw/source.csv',
                'sha256' => hash('sha256', self::ARTIFACT), 'bytes' => strlen(self::ARTIFACT)]], 'records' => $records];
    }

    public static function seal(array $record): array
    {
        $payload = $record;
        unset($payload['source_id'], $payload['source_record_sha256']);
        $record['source_record_sha256'] = CanonicalJson::hash($payload);

        return $record;
    }
}
