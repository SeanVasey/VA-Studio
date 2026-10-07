<?php

namespace App\Domain\Commerce\ProductionPreparation;

use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1 as Check;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Retained staff report only; neither verified buyer identity nor proof of a buyer act. */
final class ProductionBuyerAssentObservations
{
    public const TABLE = 'production_buyer_assent_observations';

    public function review(string $packetId, User $actor): array
    {
        return $this->withPacket($packetId, $actor, function (array $packet, array $row) use ($actor): array {
            return $this->disclosure($packet, $row, $actor->id);
        });
    }

    public function retain(array $review, array $input, string $key, User $actor): array
    {
        Check::record($review, ['schema_version', 'purpose', 'observer_id', 'packet_id', 'packet_hash', 'seller', 'assent', 'selection', 'report_boundary', 'signature']);
        Check::require(is_string($review['packet_id']));
        $input = $this->input($input);
        $digest = PacketEvidence::key($key);

        return $this->withPacket($review['packet_id'], $actor, function (array $packet, array $packetRow, CurrentRows $reader, array &$expected) use ($review, $input, $digest, $actor): array {
            Check::require(CanonicalJson::encode($review) === CanonicalJson::encode($this->disclosure($packet, $packetRow, $actor->id)));
            $request = ['review' => $review, 'input' => $input];
            $rows = $reader->rows(self::TABLE, 'created_by = ? AND request_key = ?', [$actor->id, $digest], 2);
            Check::require(count($rows) <= 1);
            if ($rows !== []) {
                $body = $this->body($rows[0], $packetRow, $actor->id);
                Check::require(CanonicalJson::encode($body['request']['review']) === CanonicalJson::encode($review));
                Check::require(CanonicalJson::encode($body['request']) === CanonicalJson::encode($request));
                $expected['observation'] = $rows[0];

                return $body;
            }
            $body = ['schema_version' => 1, 'purpose' => 'staff_reported_production_buyer_assent', 'public_id' => (string) Str::uuid(),
                'observer_id' => $actor->id, 'packet_id' => $packetRow['id'], 'packet_hash' => $packetRow['payload_hash'],
                'created_at' => CarbonImmutable::now('UTC')->startOfSecond()->format('Y-m-d H:i:s'), 'request_key_digest' => $digest, 'request' => $request,
                'buyer_identity_verified' => false, 'buyer_act_verified' => false, 'purchase_bound' => false,
                'payable' => false, 'execution_allowed' => false, 'external_facts_verified' => false];
            $row = ['public_id' => $body['public_id'], 'production_track_preparation_packet_id' => $packetRow['id'],
                'created_by' => $actor->id, 'request_key' => $digest, 'created_at' => $body['created_at'], ...PacketEvidence::seal($body)];
            $row['id'] = DB::table(self::TABLE)->insertGetId($row);
            $expected['observation'] = $row;
            AuditEvent::create($this->audit($row));

            return json_decode(CanonicalJson::encode($body), true, 32, JSON_THROW_ON_ERROR);
        });
    }

    public function recover(string $packetId, string $key, User $actor): ?array
    {
        $digest = PacketEvidence::key($key);

        return $this->withPacket($packetId, $actor, function (array $packet, array $packetRow, CurrentRows $reader, array &$expected) use ($digest, $actor): ?array {
            $rows = $reader->rows(self::TABLE, 'created_by = ? AND request_key = ?', [$actor->id, $digest], 2);
            Check::require(count($rows) <= 1);
            if ($rows === []) {
                $expected['missing_key'] = $digest;

                return null;
            }
            $expected['observation'] = $rows[0];

            $body = $this->body($rows[0], $packetRow, $actor->id);
            Check::require(CanonicalJson::encode($body['request']['review']) === CanonicalJson::encode($this->disclosure($packet, $packetRow, $actor->id)));

            return $body;
        });
    }

    private function audit(array $row): array
    {
        return ['actor_id' => $row['created_by'], 'action' => 'commerce.production_preparation.buyer_report_retained',
            'subject_type' => self::class, 'subject_id' => $row['id'], 'context' => ['schema_version' => 1, 'evidence_hash' => $row['payload_hash'],
                'buyer_act_verified' => false, 'buyer_identity_verified' => false, 'execution_allowed' => false], 'created_at' => $row['created_at']];
    }

    private function disclosure(array $packet, array $row, int $actorId): array
    {
        $choices = $packet['capability']['machine']['choices'];
        $review = ['schema_version' => 1, 'purpose' => 'review_staff_reported_buyer_assent', 'observer_id' => $actorId,
            'packet_id' => $row['public_id'], 'packet_hash' => $row['payload_hash'], 'seller' => $choices['seller_identity'],
            'assent' => $choices['assent'], 'selection' => $packet['selection'],
            'report_boundary' => 'staff_report_only_requires_independent_verified_buyer_act_and_purchase_binding'];
        $review['signature'] = PacketEvidence::signature('production-buyer-report-review-v1', $review);

        return $review;
    }

    private function input(array $input): array
    {
        Check::record($input, ['buyer', 'reported_accepted', 'observation_reference']);
        Check::record($input['buyer'], ['legal_name', 'email']);
        $name = $input['buyer']['legal_name'];
        $email = $input['buyer']['email'];
        $reference = $input['observation_reference'];
        Check::require($input['reported_accepted'] === true && is_string($name) && trim($name) === $name && $name !== '' && strlen($name) <= 160
            && mb_check_encoding($name, 'UTF-8') && preg_match('/[\x00-\x1f\x7f]/u', $name) === 0
            && is_string($email) && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && Check::version($reference));

        return $input;
    }

    private function body(array $row, array $packetRow, int $actorId): array
    {
        try {
            Check::require($row['production_track_preparation_packet_id'] === $packetRow['id'] && $row['created_by'] === $actorId
                && $row['canonicalization_version'] === CanonicalJson::VERSION && strlen($row['payload_ciphertext']) <= 1048576
                && hash_equals($row['payload_hash'], hash('sha256', $row['payload_ciphertext'])));
            $plain = Crypt::decryptString($row['payload_ciphertext']);
            Check::require(strlen($plain) <= 524288);
            $body = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
            Check::record($body, ['schema_version', 'purpose', 'public_id', 'observer_id', 'packet_id', 'packet_hash', 'created_at', 'request_key_digest', 'request',
                'buyer_identity_verified', 'buyer_act_verified', 'purchase_bound', 'payable', 'execution_allowed', 'external_facts_verified']);
            Check::record($body['request'], ['review', 'input']);
            Check::require(CanonicalJson::encode($body) === $plain && $body['schema_version'] === 1 && $body['purpose'] === 'staff_reported_production_buyer_assent'
                && $body['public_id'] === $row['public_id'] && $body['observer_id'] === $actorId && $body['packet_id'] === $packetRow['id']
                && $body['packet_hash'] === $packetRow['payload_hash'] && $body['created_at'] === $row['created_at'] && $body['request_key_digest'] === $row['request_key']);
            foreach (['buyer_identity_verified', 'buyer_act_verified', 'purchase_bound', 'payable', 'execution_allowed', 'external_facts_verified'] as $field) {
                Check::require($body[$field] === false);
            }
            $this->input($body['request']['input']);

            return $body;
        } catch (Throwable) {
            Check::require(false);
        }
    }

    private function withPacket(string $publicId, User $actor, \Closure $work): mixed
    {
        Check::require(CapabilityHistory::uuid($publicId));
        $expected = [];

        return PacketAuthority::run($actor, function (CurrentRows $reader) use ($publicId, $work, &$expected): mixed {
            $rows = $reader->rows(PacketEvidence::PACKETS, 'public_id = ?', [$publicId], 2);
            Check::require(count($rows) === 1);
            $row = $rows[0];
            $expected = ['row' => $row, ...PacketEvidence::retained($reader, $row)];

            return $work($expected['body'], $row, $reader, $expected);
        }, function (CurrentRows $reader) use ($actor, &$expected): void {
            $row = $expected['row'];
            Check::require(CanonicalJson::encode(PacketEvidence::selectors($reader, $row['created_by'], $row['request_key'], $row['public_id'], $row['id'])) === CanonicalJson::encode($expected['selectors'])
                && CanonicalJson::encode(PacketEvidence::historyRaw($reader, $expected['history_raw'])) === CanonicalJson::encode($expected['history_raw']));
            if (isset($expected['observation'])) {
                $observation = $expected['observation'];
                $audits = $reader->audits(self::class, $observation['id'], 2);
                Check::require(count($audits) === 1);
                unset($audits[0]['id']);
                Check::require(CanonicalJson::encode($audits[0]) === CanonicalJson::encode($this->audit($observation)));
                foreach (['id = ?' => [$observation['id']], 'public_id = ?' => [$observation['public_id']], 'created_by = ? AND request_key = ?' => [$actor->id, $observation['request_key']]] as $where => $bindings) {
                    Check::require(CanonicalJson::encode($reader->rows(self::TABLE, $where, $bindings, 2)) === CanonicalJson::encode([$observation]));
                }
            } elseif (isset($expected['missing_key'])) {
                Check::require($reader->rows(self::TABLE, 'created_by = ? AND request_key = ?', [$actor->id, $expected['missing_key']], 2) === []);
            }
        });
    }
}
