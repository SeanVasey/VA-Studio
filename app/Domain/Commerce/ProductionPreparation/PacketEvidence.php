<?php

namespace App\Domain\Commerce\ProductionPreparation;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyDraft;
use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1 as Check;
use App\Domain\Commerce\ProductionPolicy\PreparationContextV1;
use App\Domain\Commerce\ProductionPolicy\ProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SourceCommitment;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Authenticate retained artifacts; historical reads never reinstate sale eligibility. */
final class PacketEvidence
{
    public const PACKETS = 'production_track_preparation_packets';

    public const LINES = 'production_track_preparation_packet_lines';

    public const PURPOSE = 'production_track_catalog_preparation_packet';

    public static function signature(string $purpose, array $value): string
    {
        $key = config('app.key');
        Check::require(is_string($key) && $key !== '');

        return hash_hmac('sha256', $purpose.':'.CanonicalJson::encode($value), $key);
    }

    public static function key(string $key): string
    {
        Check::require(preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $key) === 1);

        return self::signature('production-preparation-request-key-v1', ['key' => $key]);
    }

    public static function seal(array $value): array
    {
        $canonical = CanonicalJson::encode($value);
        Check::require(strlen($canonical) <= 524288);
        $ciphertext = Crypt::encryptString($canonical);
        Check::require(strlen($ciphertext) <= 1048576);

        return ['payload_ciphertext' => $ciphertext, 'payload_hash' => hash('sha256', $ciphertext), 'canonicalization_version' => CanonicalJson::VERSION];
    }

    public static function selectors(CurrentRows $reader, int $actorId, string $key, string $publicId, ?int $id = null): array
    {
        $rows = ['key' => $reader->rows(self::PACKETS, 'created_by = ? AND request_key = ?', [$actorId, $key], 2),
            'public' => $reader->rows(self::PACKETS, 'public_id = ?', [$publicId], 2), 'id' => $id === null ? [] : $reader->rows(self::PACKETS, 'id = ?', [$id], 2)];
        Check::require(count($rows['key']) <= 1 && count($rows['public']) <= 1 && count($rows['id']) <= 1);
        $packet = $rows['key'][0] ?? $rows['public'][0] ?? $rows['id'][0] ?? null;
        $rows['lines'] = $packet === null ? [] : $reader->rows(self::LINES, 'production_track_preparation_packet_id = ?', [$packet['id']], 11);
        $rows['audits'] = $packet === null ? [] : $reader->audits(ProductionTrackPreparationPackets::class, $packet['id'], 2);

        return $rows;
    }

    public static function retained(CurrentRows $reader, array $row): array
    {
        try {
            Check::require($row['schema_version'] === 1 && CapabilityHistory::uuid($row['public_id']) && $row['canonicalization_version'] === CanonicalJson::VERSION
                && is_string($row['payload_ciphertext']) && strlen($row['payload_ciphertext']) <= 1048576
                && SourceCommitment::hash($row['payload_hash']) && $row['payload_hash'] === hash('sha256', $row['payload_ciphertext']));
            $canonical = Crypt::decryptString($row['payload_ciphertext']);
            Check::require(strlen($canonical) <= 524288);
            $body = json_decode($canonical, true, 32, JSON_THROW_ON_ERROR);
            Check::require(is_array($body) && CanonicalJson::encode($body) === $canonical);
            Check::record($body, ['schema_version', 'purpose', 'public_id', 'created_by', 'created_at', 'request', 'request_hash', 'capability', 'catalog_graph', 'catalog_graph_hash', 'selection']);
            Check::require($body['schema_version'] === 1 && $body['purpose'] === self::PURPOSE && $body['public_id'] === $row['public_id']
                && $body['created_by'] === $row['created_by'] && $body['created_at'] === $row['created_at'] && is_array($body['request'])
                && $body['request_hash'] === $row['request_hash'] && $body['request_hash'] === CanonicalJson::hash($body['request'])
                && $body['request']['actor_id'] === $row['created_by'] && $body['request']['key_digest'] === $row['request_key']
                && $body['catalog_graph_hash'] === $row['catalog_graph_hash'] && $body['catalog_graph_hash'] === CanonicalJson::hash($body['catalog_graph']));
            $capability = $body['capability'];
            $history = CapabilityHistory::load($reader, $row['production_track_policy_draft_id']);
            $candidate = CapabilityHistory::find($history['candidates'], $row['production_track_capability_candidate_id']);
            $approval = CapabilityHistory::find($history['approvals'], $row['production_track_capability_approval_id']);
            $candidateBody = $history['bodies'][$candidate['id']];
            $unsigned = $capability;
            unset($unsigned['signature']);
            Check::require($capability['signature'] === self::signature('production-preparation-projection-v1', $unsigned)
                && $capability['source'] === $candidateBody['source'] && $capability['candidate_id'] === $candidate['id']
                && $capability['candidate_public_id'] === $candidate['public_id'] && $capability['candidate_hash'] === $candidate['payload_hash']
                && $capability['approval_id'] === $approval['id'] && $capability['approval_hash'] === $approval['approval_hash']
                && $approval['production_track_capability_candidate_id'] === $candidate['id'] && $capability['generation'] === $candidate['generation']
                && $capability['machine_hash'] === CanonicalJson::hash($candidateBody['machine']) && $capability['machine'] === $candidateBody['machine']
                && $capability['execution_allowed'] === false && $capability['external_facts_verified'] === false
                && $body['request']['candidate_id'] === $candidate['id'] && $body['request']['context'] === $capability['context']);
            PreparationContextV1::requireMatches($capability['context'], $capability['machine']);
            Check::require($capability['context']['currency'] === 'USD' && $capability['context']['minor_unit_exponent'] === 2);
            $selection = $body['selection'];
            Check::require($selection['currency'] === 'USD' && $selection['minor_unit_exponent'] === 2 && $selection['tax_minor'] === null && $selection['total_minor'] === null
                && $selection['tax_state'] === 'unresolved' && $selection['assent_state'] === 'not_collected' && $selection['buyer_state'] === 'not_bound'
                && $selection['private_bytes_verified'] === false && $selection['payable'] === false && $selection['execution_allowed'] === false && $selection['external_facts_verified'] === false
                && $selection['advertised_subtotal_minor'] === $row['advertised_subtotal_minor'] && count($selection['lines']) === $row['line_count']
                && $selection['advertised_subtotal_minor'] === PreparationSelection::subtotal(array_column($selection['lines'], 'price_minor')));
            $items = PreparationSelection::items($body['request']['items']);
            Check::require($items === $body['request']['items'] && count($items) === count($selection['lines']));
            $selectors = self::selectors($reader, $row['created_by'], $row['request_key'], $row['public_id'], $row['id']);
            Check::require($selectors['key'] === [$row] && $selectors['public'] === [$row] && $selectors['id'] === [$row] && count($selectors['lines']) === $row['line_count']);
            foreach ($selection['lines'] as $position => $line) {
                $item = $items[$position];
                $expected = self::line($row['id'], $line);
                $actual = $selectors['lines'][$position];
                unset($actual['id']);
                Check::require(CanonicalJson::encode($expected) === CanonicalJson::encode($actual) && $line['position'] === $position + 1 && $line['track_id'] === $item['trackId']
                    && $line['offer_id'] === $item['offerId'] && $line['offer_revision_id'] === $item['offerRevisionId'] && $line['license_version_id'] === $item['licenseVersionId']
                    && $line['currency'] === 'USD' && $line['offer_snapshot_hash'] === CanonicalJson::hash($line['offer_snapshot']));
                $revision = PreparationSelection::one($body['catalog_graph']['revisions'], $line['offer_revision_id']);
                Check::require($revision['snapshot_hash'] === $line['offer_snapshot_hash'] && $revision['price_minor'] === $line['price_minor']);
            }
            Check::require(count($selectors['audits']) === 1);
            $audit = $selectors['audits'][0];
            unset($audit['id']);
            Check::require(CanonicalJson::encode($audit) === CanonicalJson::encode(self::audit($row)));

            return ['body' => $body, 'selectors' => $selectors, 'history_raw' => CapabilityHistory::raw($history)];
        } catch (Throwable) {
            Check::require(false);
        }
    }

    public static function line(int $packetId, array $line): array
    {
        return ['production_track_preparation_packet_id' => $packetId, ...array_intersect_key($line, array_flip(['position', 'track_id', 'offer_id', 'offer_revision_id', 'license_version_id', 'price_minor', 'offer_snapshot_hash']))];
    }

    /** Fixed raw history selectors; no decryption or application callbacks in terminal proof. */
    public static function historyRaw(CurrentRows $reader, array $expected): array
    {
        $sourceId = $expected['source']['draft']['id'];
        $versions = $reader->rows(SourceCommitment::VERSIONS, 'production_track_policy_draft_id = ?', [$sourceId], 257);
        $candidates = $reader->rows(CapabilityHistory::CANDIDATES, 'production_track_policy_draft_id = ?', [$sourceId], 257);
        $children = static function (string $table, string $field, array $ids) use ($reader): array {
            return $ids === [] ? [] : $reader->rows($table, $field.' IN ('.implode(',', array_fill(0, count($ids), '?')).')', $ids, 257);
        };

        return ['source' => ['draft' => $reader->one(SourceCommitment::DRAFTS, $sourceId), 'versions' => $versions,
            'reviews' => $children(SourceCommitment::REVIEWS, 'production_track_policy_version_id', array_column($versions, 'id')),
            'audits' => $reader->audits(ProductionTrackPolicyDraft::class, $sourceId, 513)],
            'candidates' => $candidates, 'approvals' => $children(CapabilityHistory::APPROVALS, 'production_track_capability_candidate_id', array_column($candidates, 'id')),
            'closures' => $children(CapabilityHistory::CLOSURES, 'production_track_capability_candidate_id', array_column($candidates, 'id')),
            'audits' => $reader->audits(ProductionTrackCapabilities::class, $sourceId, 769)];
    }

    public static function audit(array $row): array
    {
        return ['actor_id' => $row['created_by'], 'action' => 'commerce.production_preparation.packet_retained', 'subject_type' => ProductionTrackPreparationPackets::class,
            'subject_id' => $row['id'], 'context' => ['schema_version' => 1, 'evidence_hash' => $row['payload_hash'], 'request_hash' => $row['request_hash'],
                'execution_allowed' => false, 'payable' => false, 'external_facts_verified' => false], 'created_at' => $row['created_at']];
    }
}
