<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MembershipRows;
use PDO;

/** Fixed qualified predicates only; a reader is not source admission or write authority. */
final class MemberGrantRows
{
    private readonly PDO $pdo;

    private readonly string $driver;

    private readonly string $tablePrefix;

    public function __construct(MembershipRows $rows, private readonly MemberGrantSchemaSnapshot $schema)
    {
        $this->pdo = $rows->identity();
        $this->driver = MemberGrantCurrentSchema::captureDriver($schema);
        $profile = MemberGrantCurrentSchema::table($schema, MemberGrantRecords::PROFILES);
        MemberGrantException::require(str_ends_with($profile, MemberGrantRecords::PROFILES.'`'), 'schema');
        $this->tablePrefix = substr($profile, 0, -strlen(MemberGrantRecords::PROFILES.'`'));
    }

    public function profile(string $id, bool $committed = false): array
    {
        return $this->one(MemberGrantRecords::PROFILES, 'id = ?', [$id], $committed);
    }

    public function definition(string $id, bool $committed = false): array
    {
        return $this->one(MemberGrantRecords::DEFINITIONS, 'id = ?', [$id], $committed);
    }

    public function profileByHash(string $hash): array
    {
        return $this->one(MemberGrantRecords::PROFILES, 'profile_hash = ?', [$hash], false);
    }

    /** `$committed` true reads without a row lock, as every other reader here (append-only, trigger-immutable rows). */
    public function definitionByHash(string $hash, bool $committed = false): array
    {
        return $this->one(MemberGrantRecords::DEFINITIONS, 'definition_hash = ?', [$hash], $committed);
    }

    public function plan(string $id, bool $committed = false): array
    {
        return $this->one('production_membership_plan_versions', 'id = ?', [$id], $committed);
    }

    public function user(int $id, bool $committed = false): array
    {
        return $this->one('users', 'id = ?', [$id], $committed);
    }

    public function owner(array $expected, bool $committed = false): void
    {
        foreach (['user' => 'users', 'account' => 'customer_accounts', 'origin' => 'production_identity_origins',
            'challenge' => 'production_identity_challenges'] as $key => $table) {
            self::equal($expected[$key], $this->one($table, 'id = ?', [$expected[$key]['id']], $committed));
        }
        $history = $this->read('production_identity_verifications', 'origin_id = ?', [$expected['origin']['id']], 129, $committed);
        MemberGrantException::require(count($history) === count($expected['verification_history']), 'changed_owner');
        foreach ($history as $index => $entry) {
            self::equal($expected['verification_history'][$index], $entry);
        }
    }

    public function insertProfile(array $row): void
    {
        $this->insert(MemberGrantRecords::PROFILES, $row);
    }

    public function insertDefinition(array $row): void
    {
        $this->insert(MemberGrantRecords::DEFINITIONS, $row);
    }

    private function insert(string $table, array $row): void
    {
        $columns = match ($table) {
            MemberGrantRecords::PROFILES => ['id', 'profile_hash', 'original_terms_hash', 'implementation_hash', 'font_manifest_hash', 'provenance', 'payload_ciphertext', 'seal', 'created_at'],
            MemberGrantRecords::DEFINITIONS => ['id', 'profile_id', 'definition_hash', 'original_terms_hash', 'policy_hash', 'license_manifest_hash', 'asset_manifest_hash', 'retention_policy_hash', 'family', 'purpose', 'version', 'provenance', 'payload_ciphertext', 'seal', 'created_at'],
        };
        MemberGrantDocumentValues::keys($row, $columns);
        $statement = $this->pdo->prepare('INSERT INTO '.$this->tablePrefix.$table.'` ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')');
        $statement->execute(array_map(fn ($key) => $row[$key], $columns));
        MemberGrantException::require($statement->rowCount() === 1, 'ambiguous_effect');
    }

    private function one(string $table, string $where, array $bindings, bool $committed): array
    {
        $rows = $this->read($table, $where, $bindings, 2, $committed);
        MemberGrantException::require(count($rows) <= 1, 'ambiguous_source');

        return $rows[0] ?? [];
    }

    private function read(string $table, string $where, array $bindings, int $limit, bool $committed): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM '.$this->tablePrefix.$table.'` WHERE '.$where.' ORDER BY id LIMIT '.$limit
            .($this->driver === 'mysql' && ! $committed ? ' FOR UPDATE' : ''));
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function equal(array $expected, array $actual): void
    {
        MemberGrantException::require(MemberGrantRecords::strings($expected) === MemberGrantRecords::strings($actual), 'changed_facts');
    }
}
