<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipRows;
use App\Support\CanonicalJson;
use PDO;
use Throwable;
use WeakMap;

/** Fixed complete period closure. Retained history is data, never an effect permit. */
final class MemberActivationHistoryReader
{
    private const RAW_BYTES = 67108864;

    private const BATCH = 256;

    private const TABLES = [
        'period' => 'production_membership_paid_periods',
        'events' => 'production_membership_credit_events',
        'redemptions' => 'production_membership_redemptions',
        'origins' => 'production_member_origins',
        'artifacts' => 'production_member_artifacts',
        'activations' => 'production_member_activations',
        'profiles' => 'production_member_profiles',
        'definitions' => 'production_member_definitions',
        'plans' => 'production_membership_plan_versions',
    ];

    /** Fixed byte projections include every physical column; oversized values never become accepted truncations. */
    private const COLUMNS = [
        'period' => ['id' => 36, 'account_id' => 20, 'user_id' => 20, 'identity_origin_id' => 20,
            'plan_version_id' => 36, 'source_invoice_hash' => 64, 'invoice_graph_hash' => 64, 'owner_binding_hash' => 64,
            'allowance' => 10, 'period_start' => 19, 'period_end' => 19, 'credit_expires_at' => 19,
            'payload_ciphertext' => 65536, 'seal' => 64, 'created_at' => 19],
        'events' => ['id' => 36, 'period_id' => 36, 'sequence' => 10, 'kind' => 16, 'amount' => 10,
            'redemption_id' => 36, 'reservation_event_id' => 36, 'grant_origin_id' => 36, 'grant_receipt_hash' => 64,
            'grant_purpose' => 64, 'key_hash' => 64, 'request_hash' => 64, 'prior_seal' => 64, 'actor_binding_hash' => 64,
            'before_available' => 10, 'before_reserved' => 10, 'before_consumed' => 10, 'before_expired' => 10,
            'after_available' => 10, 'after_reserved' => 10, 'after_consumed' => 10, 'after_expired' => 10,
            'seal' => 64, 'created_at' => 19],
        'redemptions' => ['id' => 36, 'period_id' => 36, 'request_key_hash' => 64, 'owner_binding_hash' => 64,
            'credit_amount' => 10, 'selection_hash' => 64, 'license_manifest_hash' => 64, 'asset_manifest_hash' => 64,
            'original_terms_hash' => 64, 'intent_hash' => 64, 'honor_deadline' => 19,
            'payload_ciphertext' => 65536, 'seal' => 64, 'created_at' => 19],
        'origins' => ['id' => 36, 'redemption_id' => 36, 'definition_id' => 36, 'profile_id' => 36,
            'account_id' => 20, 'user_id' => 20, 'identity_origin_id' => 20, 'invoice_identity_hash' => 64,
            'owner_binding_hash' => 64, 'intent_hash' => 64, 'license_manifest_hash' => 64, 'asset_manifest_hash' => 64,
            'original_terms_hash' => 64, 'artifact_count' => 10, 'artifact_manifest_hash' => 64,
            'honor_deadline' => 19, 'provenance' => 32, 'payload_ciphertext' => 4194304, 'seal' => 64, 'created_at' => 19],
        'artifacts' => ['id' => 36, 'origin_id' => 36, 'ordinal' => 10, 'role' => 32, 'sha256' => 64,
            'bytes' => 20, 'storage_policy_hash' => 64, 'payload_ciphertext' => 262144, 'seal' => 64, 'created_at' => 19],
        'activations' => ['id' => 36, 'origin_id' => 36, 'redemption_id' => 36, 'artifact_manifest_hash' => 64,
            'readiness_receipt_hash' => 64, 'reservation_event_hash' => 64, 'purpose' => 64,
            'payload_ciphertext' => 524288, 'seal' => 64, 'created_at' => 19],
        'profiles' => ['id' => 36, 'profile_hash' => 64, 'original_terms_hash' => 64, 'implementation_hash' => 64,
            'font_manifest_hash' => 64, 'provenance' => 32, 'payload_ciphertext' => 1572864, 'seal' => 64, 'created_at' => 19],
        'definitions' => ['id' => 36, 'profile_id' => 36, 'definition_hash' => 64, 'original_terms_hash' => 64,
            'policy_hash' => 64, 'license_manifest_hash' => 64, 'asset_manifest_hash' => 64, 'retention_policy_hash' => 64,
            'family' => 64, 'purpose' => 64, 'version' => 10, 'provenance' => 32,
            'payload_ciphertext' => 1572864, 'seal' => 64, 'created_at' => 19],
        'plans' => ['id' => 36, 'policy_hash' => 64, 'original_terms_hash' => 64, 'provenance' => 32,
            'payload_ciphertext' => 524288, 'seal' => 64, 'created_at' => 19],
    ];

    /** @var WeakMap<MemberActivationHistorySnapshot, array> */
    private WeakMap $issued;

    public function __construct()
    {
        $this->issued = new WeakMap;
    }

    /**
     * `$consistentRead` (the customer index only, C4e review N1/N2): the current phases read with plain consistent
     * reads instead of FOR UPDATE, so a read of one owner's history never holds or waits for a row or gap lock. The
     * caller holds the owner's T23 lock, re-proves the same rows in the same snapshot and, after commit, in a fresh
     * committed frame; any difference refuses. Writers keep the default, locking capture.
     */
    public function capturePeriod(string $periodId, MembershipRows $rows, bool $consistentRead = false): MemberActivationHistorySnapshot
    {
        MemberGrantDocumentValues::id($periodId);
        $schema = MemberGrantCurrentSchema::pin($rows);
        $profile = MemberGrantCurrentSchema::table($schema, self::TABLES['profiles']);
        $prefix = substr($profile, 0, -strlen(self::TABLES['profiles'].'`'));
        $state = ['rows' => $rows, 'pdo' => $rows->identity(), 'schema' => $schema,
            'driver' => MemberGrantCurrentSchema::captureDriver($schema), 'periodId' => $periodId,
            'tables' => array_map(static fn (string $table): string => $prefix.$table.'`', self::TABLES),
            'frame' => null, 'pending' => false, 'consistent_read' => $consistentRead];
        $captured = self::read($state, false);
        $history = self::history($captured);
        MemberGrantCurrentSchema::assertCurrent($schema, $rows);
        $snapshot = new MemberActivationHistorySnapshot($history, $captured['scope']);
        $state['captured'] = $captured;
        $this->issued[$snapshot] = $state;

        return $snapshot;
    }

    public function currentCallbacks(MemberActivationHistorySnapshot $s, MembershipRows $rows): void
    {
        $state = $this->currentState($s, $rows);
        $rows->assertCurrent();
        MemberGrantCurrentSchema::assertCurrent($state['schema'], $rows);
        $actual = self::read($state, false);
        self::equal($state['captured'], $actual);
        self::history($actual);
    }

    /** Native captured state only. The owner performs every sibling callback before this phase. */
    public function currentRaw(MemberActivationHistorySnapshot $s, MembershipRows $rows): void
    {
        $state = $this->currentState($s, $rows);
        MemberGrantCurrentSchema::assertCurrent($state['schema'], $rows);
        self::equal($state['captured'], self::read($state, false));
        MemberGrantCurrentSchema::assertCurrent($state['schema'], $rows);
    }

    public function committedCallbacks(MemberActivationHistorySnapshot $s, IdentityCommittedFrame $f): void
    {
        $state = $this->state($s);
        MemberGrantException::require(! $state['pending'] && ($state['frame'] === null || $state['frame'] === $f), 'history_snapshot');
        $pending = [...$state, 'frame' => $f, 'pending' => true];
        $this->issued[$s] = $pending;
        try {
            $f->assertActive();
            MemberGrantCurrentSchema::assertCommitted($state['schema'], $f);
            $actual = self::read($state, true);
            self::equal($state['captured'], $actual);
            self::history($actual);
            // Callbacks cannot resurrect a retired registration or replace its exact frame.
            MemberGrantException::require(isset($this->issued[$s]) && $this->issued[$s] === $pending, 'history_snapshot');
            $this->issued[$s] = [...$pending, 'pending' => false];
        } catch (Throwable $error) {
            unset($this->issued[$s]);
            throw $error;
        }
    }

    /** No callback, crypto, frame getter, clock renewal or finish. */
    public function committedRaw(MemberActivationHistorySnapshot $s, IdentityCommittedFrame $f): void
    {
        $state = $this->state($s);
        MemberGrantException::require($state['frame'] === $f && ! $state['pending'], 'history_snapshot');
        MemberGrantCurrentSchema::assertCommitted($state['schema'], $f);
        self::equal($state['captured'], self::read($state, true));
        MemberGrantCurrentSchema::assertCommitted($state['schema'], $f);
    }

    public function retire(MemberActivationHistorySnapshot $s): void
    {
        unset($this->issued[$s]);
    }

    private function state(MemberActivationHistorySnapshot $s): array
    {
        MemberGrantException::require(isset($this->issued[$s]), 'history_snapshot');

        return $this->issued[$s];
    }

    private function currentState(MemberActivationHistorySnapshot $s, MembershipRows $rows): array
    {
        $state = $this->state($s);
        MemberGrantException::require($state['rows'] === $rows && $state['frame'] === null, 'history_snapshot');

        return $state;
    }

    private static function history(array $captured): MemberActivationHistory
    {
        $m = $captured['member'];

        return MemberActivationHistory::fromRows($m['origins'], $m['artifacts'], $m['activations'],
            $m['profiles'], $m['definitions'], $m['plans'], count($captured['scope']['redemptions']));
    }

    /** Queries are derived from freshly read complete scope, never an expected-row list. */
    private static function read(array $state, bool $committed): array
    {
        $sets = array_fill_keys(array_keys(self::TABLES), []);
        $bytes = 0;
        self::collect($state, 'period', 'id = ?', [$state['periodId']], 1, $committed, $sets, $bytes);
        MemberGrantException::require(count($sets['period']) === 1, 'history_scope');
        $period = array_values($sets['period'])[0];
        self::collect($state, 'events', 'period_id = ?', [$period['id']], MembershipPolicy::MAX_EVENTS, $committed, $sets, $bytes);
        self::collect($state, 'redemptions', 'period_id = ?', [$period['id']], MembershipPolicy::MAX_EVENTS - 1, $committed, $sets, $bytes);
        $r = count($sets['redemptions']);
        $redemptionIds = array_keys($sets['redemptions']);

        // Every predicate is separately current in an effect transaction. In particular no IN (SELECT ...)
        // can read a stale RR snapshot behind a current outer query.
        self::byKeys($state, 'activations', 'redemption_id', $redemptionIds, $r, $committed, $sets, $bytes);
        self::collect($state, 'origins', 'invoice_identity_hash = ?', [$period['source_invoice_hash']], $r, $committed, $sets, $bytes);
        self::byKeys($state, 'origins', 'redemption_id', $redemptionIds, $r, $committed, $sets, $bytes);
        $declared = array_values(array_filter(array_column($sets['events'], 'grant_origin_id'), static fn ($id): bool => $id !== null));
        $activated = array_column($sets['activations'], 'origin_id');
        self::byKeys($state, 'origins', 'id', [...$redemptionIds, ...$declared, ...$activated], $r, $committed, $sets, $bytes);
        foreach ([...$declared, ...$activated] as $id) {
            MemberGrantException::require(isset($sets['origins'][$id]), 'history_scope');
        }
        foreach ($sets['origins'] as $origin) {
            MemberGrantException::require(isset($sets['redemptions'][$origin['redemption_id']])
                && $origin['id'] === $origin['redemption_id']
                && $origin['invoice_identity_hash'] === $period['source_invoice_hash']
                && $origin['account_id'] === $period['account_id'] && $origin['user_id'] === $period['user_id']
                && $origin['identity_origin_id'] === $period['identity_origin_id'], 'history_scope');
        }
        $originIds = array_keys($sets['origins']);
        // A historical foreign-key violation must not hide a pointer simply because its origin is
        // missing. These fixed candidates are known from this period; they never authorize adoption.
        $originCandidates = [...$redemptionIds, ...$declared, ...$activated, ...$originIds];
        self::byKeys($state, 'activations', 'origin_id', $originCandidates, $r, $committed, $sets, $bytes);
        self::byKeys($state, 'artifacts', 'origin_id', $originCandidates, $r, $committed, $sets, $bytes);
        foreach ($sets['artifacts'] as $artifact) {
            MemberGrantException::require(isset($sets['origins'][$artifact['origin_id']]), 'history_scope');
        }
        foreach ($sets['activations'] as $activation) {
            MemberGrantException::require(isset($sets['origins'][$activation['origin_id']])
                && isset($sets['redemptions'][$activation['redemption_id']])
                && $sets['origins'][$activation['origin_id']]['redemption_id'] === $activation['redemption_id'], 'history_scope');
        }
        self::byKeys($state, 'definitions', 'id', array_column($sets['origins'], 'definition_id'), $r, $committed, $sets, $bytes);
        self::byKeys($state, 'profiles', 'id', [...array_column($sets['origins'], 'profile_id'),
            ...array_column($sets['definitions'], 'profile_id')], $r, $committed, $sets, $bytes);
        foreach ($sets['origins'] as $origin) {
            MemberGrantException::require(isset($sets['definitions'][$origin['definition_id']])
                && isset($sets['profiles'][$origin['profile_id']]), 'history_scope');
        }
        foreach ($sets['definitions'] as $definition) {
            MemberGrantException::require(isset($sets['profiles'][$definition['profile_id']]), 'history_scope');
        }
        if ($originIds !== []) {
            self::byKeys($state, 'plans', 'policy_hash', array_column($sets['definitions'], 'policy_hash'), $r, $committed, $sets, $bytes);
            self::byKeys($state, 'plans', 'id', [$period['plan_version_id']], $r, $committed, $sets, $bytes);
            MemberGrantException::require(isset($sets['plans'][$period['plan_version_id']]) && count($sets['plans']) === 1, 'history_scope');
        }
        foreach ($sets as &$set) {
            ksort($set, SORT_STRING);
            $set = array_values($set);
        }
        unset($set);

        return ['scope' => ['period' => $sets['period'][0], 'events' => $sets['events'], 'redemptions' => $sets['redemptions']],
            'member' => ['origins' => $sets['origins'], 'artifacts' => $sets['artifacts'], 'activations' => $sets['activations'],
                'profiles' => $sets['profiles'], 'definitions' => $sets['definitions'], 'plans' => $sets['plans']]];
    }

    private static function byKeys(array $state, string $table, string $column, array $ids, int $bound,
        bool $committed, array &$sets, int &$bytes): void
    {
        $ids = array_values(array_unique($ids, SORT_STRING));
        sort($ids, SORT_STRING);
        foreach (array_chunk($ids, self::BATCH) as $batch) {
            self::collect($state, $table, '`'.$column.'` IN ('.implode(',', array_fill(0, count($batch), '?')).')',
                $batch, $bound, $committed, $sets, $bytes);
        }
    }

    /** Header-first reads prevent PDO's native buffering from materializing R large ciphertexts at once. */
    private static function collect(array $state, string $table, string $where, array $bindings, int $bound,
        bool $committed, array &$sets, int &$bytes): void
    {
        $member = ! in_array($table, ['period', 'events', 'redemptions'], true);
        $headers = self::query($state, $table, $where, $bindings, $bound + 1, $committed, false);
        MemberGrantException::require(count($headers) <= $bound, 'history_capacity');
        foreach ($headers as $header) {
            $id = $header['id'];
            MemberGrantException::require(is_string($id), 'history_scope');
            $prior = $sets[$table][$id] ?? null;
            if ($prior === null) {
                MemberGrantException::require(count($sets[$table]) < $bound, 'history_capacity');
            }
            if (isset(self::COLUMNS[$table]['payload_ciphertext'])) {
                $remaining = $member && $prior === null ? self::RAW_BYTES - $bytes : self::COLUMNS[$table]['payload_ciphertext'];
                MemberGrantException::require((int) $header['__payload_bytes'] <= $remaining, 'history_capacity');
                $full = self::query($state, $table, 'id = ?', [$id], 2, $committed, true, $remaining);
                MemberGrantException::require(count($full) === 1, 'changed_history');
                $row = $full[0];
                $observedHeader = $row;
                unset($observedHeader['payload_ciphertext']);
                self::equal($header, $observedHeader);
                unset($row['__payload_bytes']);
            } else {
                $row = $header;
            }
            if ($prior !== null) {
                self::equal($prior, $row);

                continue;
            }
            if ($member) {
                $bytes += strlen(CanonicalJson::encode($row));
                MemberGrantException::require($bytes <= self::RAW_BYTES, 'history_capacity');
            }
            $sets[$table][$id] = $row;
        }
    }

    private static function query(array $state, string $table, string $where, array $bindings, int $limit,
        bool $committed, bool $withPayload, ?int $remaining = null): array
    {
        $columns = [];
        $oversized = [];
        foreach (self::COLUMNS[$table] as $name => $cap) {
            $column = '`'.$name.'`';
            $length = $state['driver'] === 'mysql' ? 'OCTET_LENGTH('.$column.')' : 'length(CAST('.$column.' AS BLOB))';
            if ($name === 'payload_ciphertext') {
                $cap = min($cap, $remaining ?? $cap);
                $columns[] = $length.' AS `__payload_bytes`';
            }
            $oversized[] = '('.$column.' IS NOT NULL AND '.$length.' > '.$cap.')';
            if ($name !== 'payload_ciphertext' || $withPayload) {
                $columns[] = 'CASE WHEN '.$length.' <= '.$cap.' THEN '.$column.' ELSE NULL END AS '.$column;
            }
        }
        $columns[] = 'CASE WHEN '.implode(' OR ', $oversized).' THEN 1 ELSE 0 END AS `__oversized`';
        $statement = $state['pdo']->prepare('SELECT '.implode(', ', $columns).' FROM '.$state['tables'][$table]
            .' WHERE '.$where.' ORDER BY id LIMIT '.$limit
            .($state['driver'] === 'mysql' && ! $committed && ! ($state['consistent_read'] ?? false) ? ' FOR UPDATE' : ''));
        $statement->execute($bindings);
        $result = [];
        try {
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                MemberGrantException::require(in_array($row['__oversized'], [0, '0'], true), 'history_capacity');
                unset($row['__oversized']);
                $copy = [];
                foreach ($row as $key => $value) {
                    MemberGrantException::require($value === null || is_string($value) || is_int($value), 'history_scope');
                    $copy[$key] = $value === null ? null : (string) $value;
                }
                if (isset(self::COLUMNS[$table]['payload_ciphertext'])) {
                    MemberGrantException::require(isset($copy['__payload_bytes'])
                        && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $copy['__payload_bytes']) === 1, 'history_scope');
                }
                ksort($copy, SORT_STRING);
                $result[] = $copy;
            }
        } finally {
            $statement->closeCursor();
        }

        return $result;
    }

    private static function equal(array $expected, array $actual): void
    {
        MemberGrantException::require($expected === $actual, 'changed_history');
    }

    public function __clone(): void
    {
        throw new MemberGrantException('history_snapshot');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('history_snapshot');
    }
}
