<?php

namespace App\Domain\Commerce\ProductionPreparation;

use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1 as Check;
use App\Domain\Commerce\ProductionPolicy\Models\ProductionTrackCapabilityCandidate;
use App\Domain\Commerce\ProductionPolicy\ReadProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SourceCommitment;
use App\Domain\Commerce\ProductionPreparation\Models\ProductionTrackPreparationPacket;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable staff preparation only: no quote, customer, total, hold, order, payment or grant. */
final class ProductionTrackPreparationPackets
{
    public function review(ProductionTrackCapabilityCandidate $candidate, array $context, array $items, string $key, User $actor): array
    {
        $items = PreparationSelection::items($items);
        $digest = PacketEvidence::key($key);
        $publicId = (string) Str::uuid();
        $expected = null;

        return app(ReadProductionTrackCapabilities::class)->withLockedForAdapter($candidate, $context, $actor,
            function (array $capability, CurrentRows $reader) use ($items, $digest, $publicId, $actor, &$expected): array {
                $this->supported($capability);
                $graph = PreparationSelection::load($reader, $items);
                $selectors = PacketEvidence::selectors($reader, $actor->id, $digest, $publicId);
                Check::require($selectors['key'] === [] && $selectors['public'] === []);
                $authority = PacketAuthority::raw($reader, $actor->id);
                $expected = ['graph' => $graph, 'selectors' => $selectors];
                // These helpers may invoke application services; all evidence is
                // already captured and the terminal reader checks it afterward.
                PreparationSelection::interpret($graph, $items, CarbonImmutable::now('UTC'));
                $capture = ['schema_version' => 1, 'purpose' => 'review_production_track_preparation_packet',
                    'request' => ['actor_id' => $actor->id, 'candidate_id' => $capability['candidate_id'], 'context' => $capability['context'], 'items' => $items, 'key_digest' => $digest],
                    'public_id' => $publicId, 'authority_hash' => CanonicalJson::hash($authority), 'catalog_graph_hash' => CanonicalJson::hash($graph), 'capability' => $capability];
                $capture['signature'] = PacketEvidence::signature('production-packet-review-v1', $capture);

                return $capture;
            }, function (CurrentRows $reader) use ($items, $digest, $publicId, $actor, &$expected): void {
                $this->proveCurrent($reader, $items, $actor->id, $digest, $publicId, null, $expected);
            });
    }

    public function applyReviewed(array $capture, User $actor): ProductionTrackPreparationPacket
    {
        $this->verifyCapture($capture, $actor);
        $request = $capture['request'];
        // Lost responses and exact replays authenticate retained evidence even
        // after closure/catalog change. They never reassert current eligibility.
        $existing = $this->historical($actor, $request['key_digest'], null, true, CanonicalJson::hash($request));
        if ($existing !== null) {
            return $existing;
        }
        $candidate = new ProductionTrackCapabilityCandidate;
        $candidate->setRawAttributes(['id' => $request['candidate_id'], 'production_track_policy_draft_id' => $capture['capability']['source']['draft_id']], true);
        $candidate->exists = true;
        $expected = null;

        return app(ReadProductionTrackCapabilities::class)->withLockedForAdapter($candidate, $request['context'], $actor,
            function (array $capability, CurrentRows $reader) use ($capture, $request, $actor, &$expected): ProductionTrackPreparationPacket {
                $this->supported($capability);
                Check::require(CanonicalJson::encode($capability) === CanonicalJson::encode($capture['capability']));
                if (CanonicalJson::hash(PacketAuthority::raw($reader, $actor->id)) !== $capture['authority_hash']) {
                    throw new AuthorizationException;
                }
                $graph = PreparationSelection::load($reader, $request['items']);
                $selectors = PacketEvidence::selectors($reader, $actor->id, $request['key_digest'], $capture['public_id']);
                Check::require($selectors['key'] === [] && $selectors['public'] === [] && CanonicalJson::hash($graph) === $capture['catalog_graph_hash']);
                $expected = ['graph' => $graph, 'selectors' => $selectors];
                $at = CarbonImmutable::now('UTC')->startOfSecond();
                $selection = PreparationSelection::interpret($graph, $request['items'], $at);
                $body = ['schema_version' => 1, 'purpose' => PacketEvidence::PURPOSE, 'public_id' => $capture['public_id'], 'created_by' => $actor->id,
                    'created_at' => $at->format('Y-m-d H:i:s'), 'request' => $request, 'request_hash' => CanonicalJson::hash($request),
                    'capability' => $capability, 'catalog_graph' => $graph, 'catalog_graph_hash' => CanonicalJson::hash($graph), 'selection' => $selection];
                $row = ['public_id' => $body['public_id'], 'schema_version' => 1, 'production_track_policy_draft_id' => $capability['source']['draft_id'],
                    'production_track_capability_candidate_id' => $capability['candidate_id'], 'production_track_capability_approval_id' => $capability['approval_id'],
                    'request_key' => $request['key_digest'], 'request_hash' => $body['request_hash'], 'catalog_graph_hash' => $body['catalog_graph_hash'],
                    'advertised_subtotal_minor' => $selection['advertised_subtotal_minor'], 'line_count' => count($selection['lines']),
                    ...PacketEvidence::seal($body), 'created_by' => $actor->id, 'created_at' => $body['created_at']];
                $row['id'] = DB::table(PacketEvidence::PACKETS)->insertGetId($row);
                $lines = [];
                foreach ($selection['lines'] as $line) {
                    $lineRow = PacketEvidence::line($row['id'], $line);
                    $lineRow['id'] = DB::table(PacketEvidence::LINES)->insertGetId($lineRow);
                    $lines[] = $lineRow;
                }
                $audit = PacketEvidence::audit($row);
                $audit['id'] = AuditEvent::create($audit)->getKey();
                $expected['selectors'] = ['key' => [$row], 'public' => [$row], 'id' => [$row], 'lines' => $lines, 'audits' => [$audit]];
                $expected['id'] = $row['id'];

                return $this->model($row);
            }, function (CurrentRows $reader) use ($request, $capture, $actor, &$expected): void {
                $this->proveCurrent($reader, $request['items'], $actor->id, $request['key_digest'], $capture['public_id'], $expected['id'] ?? null, $expected);
            });
    }

    public function read(string $publicId, User $actor): array
    {
        Check::require(CapabilityHistory::uuid($publicId));
        $body = $this->historical($actor, null, $publicId, false);
        Check::require(is_array($body));

        return $body;
    }

    public function recover(string $key, User $actor): ?array
    {
        return $this->historical($actor, PacketEvidence::key($key), null, false);
    }

    private function historical(User $actor, ?string $key, ?string $publicId, bool $asModel, ?string $requestHash = null): array|ProductionTrackPreparationPacket|null
    {
        $expected = null;

        return PacketAuthority::run($actor, function (CurrentRows $reader) use ($actor, $key, $publicId, $asModel, $requestHash, &$expected): array|ProductionTrackPreparationPacket|null {
            $rows = $key === null ? $reader->rows(PacketEvidence::PACKETS, 'public_id = ?', [$publicId], 2)
                : $reader->rows(PacketEvidence::PACKETS, 'created_by = ? AND request_key = ?', [$actor->id, $key], 2);
            Check::require(count($rows) <= 1);
            $expected = ['rows' => $rows];
            if ($rows === []) {
                return null;
            }
            $row = $rows[0];
            Check::require($requestHash === null || $requestHash === $row['request_hash']);
            $retained = PacketEvidence::retained($reader, $row);
            $expected += $retained;
            $expected['row'] = $row;

            return $asModel ? $this->model($row) : $retained['body'];
        }, function (CurrentRows $reader) use ($actor, $key, $publicId, &$expected): void {
            $rows = $key === null ? $reader->rows(PacketEvidence::PACKETS, 'public_id = ?', [$publicId], 2)
                : $reader->rows(PacketEvidence::PACKETS, 'created_by = ? AND request_key = ?', [$actor->id, $key], 2);
            Check::require(CanonicalJson::encode($rows) === CanonicalJson::encode($expected['rows']));
            if ($rows === []) {
                return;
            }
            $row = $expected['row'];
            Check::require(CanonicalJson::encode(PacketEvidence::selectors($reader, $row['created_by'], $row['request_key'], $row['public_id'], $row['id'])) === CanonicalJson::encode($expected['selectors'])
                && CanonicalJson::encode(PacketEvidence::historyRaw($reader, $expected['history_raw'])) === CanonicalJson::encode($expected['history_raw']));
        });
    }

    private function verifyCapture(array $capture, User $actor): void
    {
        Check::record($capture, ['schema_version', 'purpose', 'request', 'public_id', 'authority_hash', 'catalog_graph_hash', 'capability', 'signature']);
        Check::require($capture['schema_version'] === 1 && $capture['purpose'] === 'review_production_track_preparation_packet' && is_array($capture['request'])
            && CapabilityHistory::uuid($capture['public_id']) && SourceCommitment::hash($capture['signature']) && SourceCommitment::hash($capture['authority_hash'])
            && SourceCommitment::hash($capture['catalog_graph_hash']) && is_array($capture['capability']));
        $unsigned = $capture;
        unset($unsigned['signature']);
        Check::require(hash_equals(PacketEvidence::signature('production-packet-review-v1', $unsigned), $capture['signature']));
        $request = $capture['request'];
        Check::record($request, ['actor_id', 'candidate_id', 'context', 'items', 'key_digest']);
        if ($request['actor_id'] !== $actor->getKey()) {
            throw new AuthorizationException;
        }
        Check::require(is_int($request['candidate_id']) && $request['candidate_id'] > 0 && is_array($request['context']) && is_array($request['items'])
            && PreparationSelection::items($request['items']) === $request['items'] && SourceCommitment::hash($request['key_digest']));
    }

    private function supported(array $projection): void
    {
        Check::require($projection['context']['currency'] === 'USD' && $projection['context']['minor_unit_exponent'] === 2
            && $projection['execution_allowed'] === false && $projection['external_facts_verified'] === false);
    }

    /** Fixed captured-reader SQL, pure equality and time checks only. */
    private function proveCurrent(CurrentRows $reader, array $items, int $actorId, string $key, string $publicId, ?int $id, array $expected): void
    {
        $actual = PreparationSelection::load($reader, $items);
        Check::require(CanonicalJson::encode($actual) === CanonicalJson::encode($expected['graph'])
            && CanonicalJson::encode(PacketEvidence::selectors($reader, $actorId, $key, $publicId, $id)) === CanonicalJson::encode($expected['selectors']));
        PreparationSelection::effective($actual, CarbonImmutable::now('UTC'));
    }

    private function model(array $row): ProductionTrackPreparationPacket
    {
        $model = new ProductionTrackPreparationPacket;
        $model->setRawAttributes($row, true);
        $model->exists = true;

        return $model;
    }
}
