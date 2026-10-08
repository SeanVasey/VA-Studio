<?php

namespace Tests\Support;

use App\Domain\Grants\Member\MemberGrantSchema;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDO;

/**
 * Synthetic structural 257 credit events for 258 schema tests only. Amounts, seals and hashes are
 * placeholders that satisfy the structural guards; they are not an award, an allowance, a policy fact
 * or a usable grant, and no producer authority stands behind them.
 */
final class MemberCreditEventFixtures
{
    /**
     * Profile, definition, plan, paid period (allowance 2), one redemption (credit 1), a pending origin and
     * its two artifacts. Every value is a synthetic placeholder, never a price, allowance or terms fact.
     */
    public static function graph(): array
    {
        self::testing();
        $profileId = (string) Str::uuid();
        $profile = ['id' => $profileId, 'profile_hash' => hash('sha256', $profileId), 'original_terms_hash' => str_repeat('d', 64),
            'implementation_hash' => str_repeat('e', 64), 'font_manifest_hash' => str_repeat('f', 64), 'provenance' => 'synthetic_rehearsal',
            'payload_ciphertext' => 'synthetic placeholder, not member terms/profile approval', 'seal' => hash('sha256', 'profile'.$profileId), 'created_at' => '2026-10-07 00:00:00'];
        self::insert(MemberGrantSchema::TABLES[0], $profile);
        $definitionId = (string) Str::uuid();
        self::insert(MemberGrantSchema::TABLES[1], ['id' => $definitionId, 'profile_id' => $profileId, 'definition_hash' => hash('sha256', $definitionId),
            'original_terms_hash' => $profile['original_terms_hash'], 'policy_hash' => str_repeat('a', 64), 'license_manifest_hash' => str_repeat('b', 64),
            'asset_manifest_hash' => str_repeat('c', 64), 'retention_policy_hash' => str_repeat('a', 64), 'family' => MemberGrantIntent::FAMILY,
            'purpose' => MemberGrantIntent::PURPOSE, 'version' => 1, 'provenance' => 'synthetic_rehearsal',
            'payload_ciphertext' => 'synthetic placeholder, not benefit or licensing facts', 'seal' => hash('sha256', 'definition'.$definitionId), 'created_at' => '2026-10-07 00:00:00']);
        $customer = CustomerFixtures::account();
        $planId = (string) Str::uuid();
        self::insert(MembershipSchema::TABLES[0], ['id' => $planId, 'policy_hash' => hash('sha256', $planId), 'original_terms_hash' => str_repeat('a', 64),
            'provenance' => 'synthetic_rehearsal', 'payload_ciphertext' => 'synthetic placeholder, not policy proof', 'seal' => hash('sha256', 'plan'.$planId), 'created_at' => '2026-10-07 00:00:00']);
        $periodId = (string) Str::uuid();
        $period = ['id' => $periodId, 'account_id' => $customer['account']->id, 'user_id' => $customer['user']->id, 'identity_origin_id' => 1, 'plan_version_id' => $planId,
            'source_invoice_hash' => hash('sha256', 'invoice'.$periodId), 'invoice_graph_hash' => hash('sha256', 'graph'.$periodId), 'owner_binding_hash' => hash('sha256', 'owner'.$periodId),
            'allowance' => 2, 'period_start' => '2026-10-07 00:00:00', 'period_end' => '2026-11-07 00:00:00', 'credit_expires_at' => null,
            'payload_ciphertext' => 'synthetic placeholder, not invoice proof', 'seal' => hash('sha256', 'period'.$periodId), 'created_at' => '2026-10-07 00:00:00'];
        self::insert(MembershipSchema::TABLES[1], $period);
        $redemption = self::redemption($period);
        $originId = (string) Str::uuid();
        $origin = ['id' => $originId, 'redemption_id' => $redemption['id'], 'definition_id' => $definitionId, 'profile_id' => $profileId,
            'account_id' => $period['account_id'], 'user_id' => $period['user_id'], 'identity_origin_id' => 1, 'invoice_identity_hash' => $period['source_invoice_hash'],
            'owner_binding_hash' => $redemption['owner_binding_hash'], 'intent_hash' => $redemption['intent_hash'], 'license_manifest_hash' => $redemption['license_manifest_hash'],
            'asset_manifest_hash' => $redemption['asset_manifest_hash'], 'original_terms_hash' => $redemption['original_terms_hash'], 'artifact_count' => 2,
            'artifact_manifest_hash' => str_repeat('f', 64), 'honor_deadline' => $redemption['honor_deadline'], 'provenance' => 'synthetic_rehearsal',
            'payload_ciphertext' => 'synthetic placeholder, not original buyer authority', 'seal' => hash('sha256', 'origin'.$originId), 'created_at' => '2026-10-07 00:00:02'];
        self::insert(MemberGrantSchema::TABLES[2], $origin);
        foreach (['member_contract', 'licensed_audio'] as $ordinal => $role) {
            $id = (string) Str::uuid();
            self::insert(MemberGrantSchema::TABLES[3], ['id' => $id, 'origin_id' => $originId, 'ordinal' => $ordinal, 'role' => $role, 'sha256' => hash('sha256', $id),
                'bytes' => 1, 'storage_policy_hash' => str_repeat('a', 64), 'payload_ciphertext' => 'synthetic placeholder, no private path or bytes present',
                'seal' => hash('sha256', 'artifact'.$id), 'created_at' => '2026-10-07 00:00:03']);
        }

        return ['period' => $period, 'redemption' => $redemption, 'origin' => $origin];
    }

    public static function redemption(array $period): array
    {
        $id = (string) Str::uuid();
        $redemption = ['id' => $id, 'period_id' => $period['id'], 'request_key_hash' => hash('sha256', 'request'.$id), 'owner_binding_hash' => $period['owner_binding_hash'],
            'credit_amount' => 1, 'selection_hash' => str_repeat('a', 64), 'license_manifest_hash' => str_repeat('b', 64), 'asset_manifest_hash' => str_repeat('c', 64),
            'original_terms_hash' => str_repeat('d', 64), 'intent_hash' => hash('sha256', 'intent'.$id), 'honor_deadline' => '2026-10-08 00:00:00',
            'payload_ciphertext' => 'synthetic placeholder, not eligible license proof', 'seal' => hash('sha256', 'redemption'.$id), 'created_at' => '2026-10-07 00:00:01'];
        self::insert(MembershipSchema::TABLES[2], $redemption);

        return $redemption;
    }

    /** Structural activation row; readiness and reservation hashes are supplied by the caller. */
    public static function activation(array $origin, string $readinessHash, string $reservationSeal): array
    {
        $id = (string) Str::uuid();

        return ['id' => $id, 'origin_id' => $origin['id'], 'redemption_id' => $origin['redemption_id'], 'artifact_manifest_hash' => $origin['artifact_manifest_hash'],
            'readiness_receipt_hash' => $readinessHash, 'reservation_event_hash' => $reservationSeal, 'purpose' => MemberGrantIntent::PURPOSE,
            'payload_ciphertext' => 'synthetic placeholder, never a ready producer receipt', 'seal' => hash('sha256', 'activation'.$id), 'created_at' => '2026-10-07 00:00:04'];
    }

    /** Award the period's allowance, then reserve the redemption's credits. Returns [award, reserve]. */
    public static function reserve(string $redemptionId): array
    {
        self::testing();
        $redemption = self::row(MembershipSchema::TABLES[2], $redemptionId);
        $period = self::row(MembershipSchema::TABLES[1], $redemption['period_id']);
        $last = self::last($period['id']);
        $award = null;
        if ($last === null) {
            $award = self::event($period, 'award', null, null, (int) $period['allowance'], ['available' => (int) $period['allowance'], 'reserved' => 0, 'consumed' => 0, 'expired' => 0], $period['created_at']);
            self::insert(MembershipSchema::TABLES[3], $award);
            $last = $award;
        }
        $amount = (int) $redemption['credit_amount'];
        $reserve = self::event($period, 'reserve', $last, $redemption, $amount, ['available' => $last['after_available'] - $amount,
            'reserved' => $last['after_reserved'] + $amount, 'consumed' => $last['after_consumed'], 'expired' => $last['after_expired']], '2026-10-07 00:00:02');
        self::insert(MembershipSchema::TABLES[3], $reserve);

        return [$award, $reserve];
    }

    /** Terminal consume of a reserve event for one origin and readiness receipt (purpose fixed by 257). */
    public static function consume(array $reserve, string $originId, string $receiptHash, string $createdAt = '2026-10-07 00:00:03', ?string $redemptionId = null): array
    {
        self::testing();
        $period = self::row(MembershipSchema::TABLES[1], $reserve['period_id']);
        $redemption = self::row(MembershipSchema::TABLES[2], $redemptionId ?? $reserve['redemption_id']);
        $last = self::last($period['id']);
        $amount = (int) $reserve['amount'];
        $consume = self::event($period, 'consume', $last, $redemption, $amount, ['available' => $last['after_available'], 'reserved' => $last['after_reserved'] - $amount,
            'consumed' => $last['after_consumed'] + $amount, 'expired' => $last['after_expired']], $createdAt);
        $consume = [...$consume, 'reservation_event_id' => $reserve['id'], 'grant_origin_id' => $originId, 'grant_receipt_hash' => $receiptHash,
            'grant_purpose' => MemberGrantIntent::PURPOSE];
        self::insert(MembershipSchema::TABLES[3], $consume);

        return $consume;
    }

    public static function release(array $reserve): array
    {
        self::testing();
        $period = self::row(MembershipSchema::TABLES[1], $reserve['period_id']);
        $redemption = self::row(MembershipSchema::TABLES[2], $reserve['redemption_id']);
        $last = self::last($period['id']);
        $amount = (int) $reserve['amount'];
        $release = [...self::event($period, 'release', $last, $redemption, $amount, ['available' => $last['after_available'] + $amount,
            'reserved' => $last['after_reserved'] - $amount, 'consumed' => $last['after_consumed'], 'expired' => $last['after_expired']], '2026-10-07 00:00:03'),
            'reservation_event_id' => $reserve['id']];
        self::insert(MembershipSchema::TABLES[3], $release);

        return $release;
    }

    public static function insert(string $table, array $row): void
    {
        $schema = in_array($table, MemberGrantSchema::TABLES, true) ? new MemberGrantSchema : new MembershipSchema;
        $statement = DB::connection()->getPdo()->prepare('INSERT INTO '.$schema->table($table).' ('.implode(',', array_map(fn ($k) => '`'.$k.'`', array_keys($row)))
            .') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
    }

    private static function event(array $period, string $kind, ?array $previous, ?array $redemption, int $amount, array $after, string $createdAt): array
    {
        $id = (string) Str::uuid();
        $event = ['id' => $id, 'period_id' => $period['id'], 'sequence' => ($previous['sequence'] ?? 0) + 1, 'kind' => $kind, 'amount' => $amount,
            'redemption_id' => $redemption['id'] ?? null, 'reservation_event_id' => null, 'grant_origin_id' => null, 'grant_receipt_hash' => null,
            'grant_purpose' => null, 'key_hash' => hash('sha256', 'synthetic key '.$id), 'request_hash' => hash('sha256', 'synthetic request '.$id),
            'prior_seal' => $previous['seal'] ?? str_repeat('0', 64), 'actor_binding_hash' => hash('sha256', 'synthetic actor '.$id)];
        foreach (['available', 'reserved', 'consumed', 'expired'] as $balance) {
            $event['before_'.$balance] = (int) ($previous['after_'.$balance] ?? 0);
            $event['after_'.$balance] = $after[$balance];
        }

        return [...$event, 'seal' => hash('sha256', 'synthetic seal '.$id), 'created_at' => $createdAt];
    }

    private static function last(string $periodId): ?array
    {
        $statement = DB::connection()->getPdo()->prepare('SELECT * FROM '.(new MembershipSchema)->table(MembershipSchema::TABLES[3]).' WHERE period_id = ? ORDER BY sequence DESC LIMIT 1');
        $statement->execute([$periodId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::integers($row);
    }

    private static function row(string $table, string $id): array
    {
        $statement = DB::connection()->getPdo()->prepare('SELECT * FROM '.(new MembershipSchema)->table($table).' WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new LogicException('Synthetic fixture parent is absent.');
        }

        return self::integers($row);
    }

    private static function integers(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_string($value) && preg_match('/\A(sequence|amount|allowance|credit_amount|before_\w+|after_\w+)\z/D', $key) === 1) {
                $row[$key] = (int) $value;
            }
        }

        return $row;
    }

    private static function testing(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Synthetic credit events require the testing environment.');
        }
    }
}
