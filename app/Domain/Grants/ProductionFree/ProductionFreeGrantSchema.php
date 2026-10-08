<?php

namespace App\Domain\Grants\ProductionFree;

use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;

/**
 * Atomic append-only DDL for family 256 with strict empty-prefix recovery. It never adopts or repairs retained
 * unguarded rows, never drops evidence and refuses drifted parents, foreign names and external dependents.
 */
final class ProductionFreeGrantSchema
{
    public const FAMILY = 'production-free-origin-v1';

    public const PURPOSE = 'production-free-license-grant-v1';

    public const PROFILE = 'production-free-grant-pdf-v1';

    public const PROVENANCES = ['synthetic_rehearsal', 'verified_production'];

    public const ROLES = ['contract', 'master_wav', 'download_mp3', 'stems_zip'];

    public const TABLES = ['production_free_definitions', 'production_free_reviews', 'production_free_availability',
        'production_free_origins', 'production_free_document_work', 'production_free_originals',
        'production_free_revocations', 'production_free_authorizations', 'production_free_redemptions'];

    /** Observed permanent parent columns (preparation 5bfdd4f0) the guards read; driver => [type, not null]. */
    private const PARENTS = [
        'users' => [
            'id' => ['sqlite' => ['INTEGER', 1], 'mysql' => ['bigint unsigned', 'NO']],
            'is_admin' => ['sqlite' => ['tinyint(1)', 1], 'mysql' => ['tinyint(1)', 'NO']],
            'email_verified_at' => ['sqlite' => ['datetime', 0], 'mysql' => ['timestamp', 'YES']],
        ],
        'customer_accounts' => [
            'id' => ['sqlite' => ['INTEGER', 1], 'mysql' => ['bigint unsigned', 'NO']],
            'user_id' => ['sqlite' => ['INTEGER', 1], 'mysql' => ['bigint unsigned', 'NO']],
            'active' => ['sqlite' => ['tinyint(1)', 1], 'mysql' => ['tinyint(1)', 'NO']],
        ],
    ];

    public function table(string $logical): string
    {
        ProductionFreeGrantException::require(in_array($logical, self::TABLES, true), 'schema');
        $name = DB::connection()->getTablePrefix().$logical;
        ProductionFreeGrantException::require(strlen($name) <= 52 && preg_match('/\A[a-zA-Z0-9_]+\z/D', $name) === 1, 'schema');

        return DB::getDriverName() === 'sqlite' ? 'main."'.$name.'"' : '`'.$name.'`';
    }

    public function assertOwned(PDO $pdo): void
    {
        ProductionFreeGrantException::require(DB::connection()->getPdo() === $pdo, 'changed_connection');
        $this->up(false);
    }

    public function up(bool $ddl = true): void
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        ProductionFreeGrantException::require(in_array($driver, ['sqlite', 'mysql'], true), 'schema');
        $this->parentFloor($pdo, $driver);
        $this->assertNamespace($pdo, $driver);
        $this->assertNoExternalDependents($pdo, $driver);
        $plans = [];
        $prefixEnded = false;
        foreach (self::TABLES as $logical) {
            $table = $this->table($logical);
            $name = DB::connection()->getTablePrefix().$logical;
            $definition = $this->definition($logical, $driver);
            $create = 'CREATE TABLE '.$table.' ('.$definition['sql'].')'.($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '');
            $guards = $this->guards($logical, $driver, $name, $table);
            if ($driver === 'sqlite') {
                $statement = $pdo->prepare('SELECT name, tbl_name FROM sqlite_temp_master');
                $statement->execute();
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $object) {
                    ProductionFreeGrantException::require(! in_array(strtolower($object['name']), array_map(strtolower(...), [$name, ...array_keys($guards)]), true) && strtolower($object['tbl_name']) !== strtolower($name), 'shadowed_schema');
                }
                $statement = $pdo->prepare('SELECT name, type, sql FROM main.sqlite_master WHERE lower(name) = lower(?)');
                $statement->execute([$name]);
                $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                ProductionFreeGrantException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['name'] === $name), 'schema_namespace');
                $actual = $objects[0] ?? false;
                if ($actual !== false) {
                    ProductionFreeGrantException::require($actual['type'] === 'table' && $this->sql($actual['sql']) === $this->sql(str_replace('main.', '', $create)), 'schema');
                    $indexes = $pdo->query('PRAGMA main.index_list("'.$name.'")')->fetchAll(PDO::FETCH_ASSOC);
                    ProductionFreeGrantException::require(count($indexes) === 1 + count($definition['unique']), 'schema');
                }
                $statement = $pdo->prepare("SELECT name FROM main.sqlite_master WHERE type = 'trigger' AND tbl_name = ?");
                $statement->execute([$name]);
                ProductionFreeGrantException::require(array_diff($statement->fetchAll(PDO::FETCH_COLUMN), array_keys($guards)) === [], 'schema');
            } else {
                $statement = $pdo->prepare('SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND lower(TABLE_NAME) = lower(?)');
                $statement->execute([$name]);
                $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                ProductionFreeGrantException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['TABLE_NAME'] === $name), 'schema_namespace');
                $actual = $objects[0] ?? false;
                if ($actual !== false) {
                    unset($actual['TABLE_NAME']);
                }
                // SHOW also resolves a local temporary table when no durable table exists.
                try {
                    $shown = $pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
                    ProductionFreeGrantException::require(! str_contains(strtoupper($shown[1]), 'TEMPORARY'), 'shadowed_schema');
                } catch (PDOException $error) {
                    if ($actual !== false || $error->getCode() !== '42S02') {
                        throw $error;
                    }
                }
                if ($actual !== false) {
                    ProductionFreeGrantException::require($actual === ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_bin'], 'schema');
                    $this->mysqlDefinition($pdo, $name, $definition);
                }
                $statement = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND EVENT_OBJECT_TABLE = ?');
                $statement->execute([$name]);
                ProductionFreeGrantException::require(array_diff($statement->fetchAll(PDO::FETCH_COLUMN), array_keys($guards)) === [], 'schema');
            }
            ProductionFreeGrantException::require(! ($prefixEnded && $actual !== false), 'schema_prefix');
            $prefixEnded = $prefixEnded || $actual === false;
            $missing = [];
            foreach ($guards as $guard => $expected) {
                if ($driver === 'sqlite') {
                    $statement = $pdo->prepare('SELECT name, type, tbl_name, sql FROM main.sqlite_master WHERE lower(name) = lower(?)');
                    $statement->execute([$guard]);
                    $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                    ProductionFreeGrantException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['name'] === $guard), 'schema_namespace');
                    $row = $objects[0] ?? false;
                    if ($row !== false) {
                        ProductionFreeGrantException::require($row['type'] === 'trigger' && $row['tbl_name'] === $name && $this->sql($row['sql']) === $this->sql($expected['sql']), 'schema');
                    }
                } else {
                    $statement = $pdo->prepare('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND lower(TRIGGER_NAME) = lower(?)');
                    $statement->execute([$guard]);
                    $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                    ProductionFreeGrantException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['TRIGGER_NAME'] === $guard), 'schema_namespace');
                    $row = $objects[0] ?? false;
                    if ($row !== false) {
                        ProductionFreeGrantException::require($row['EVENT_OBJECT_TABLE'] === $name && $row['EVENT_MANIPULATION'] === $expected['event'] && $row['ACTION_TIMING'] === 'BEFORE' && $this->sql($row['ACTION_STATEMENT']) === $this->sql($expected['body']), 'schema');
                    }
                }
                ProductionFreeGrantException::require(! ($prefixEnded && $row !== false), 'schema_prefix');
                $prefixEnded = $prefixEnded || $row === false;
                if ($row === false) {
                    $missing[] = $expected['sql'];
                }
            }
            if ($actual !== false && $missing !== []) {
                ProductionFreeGrantException::require($pdo->query('SELECT 1 FROM '.$table.' LIMIT 1')->fetchColumn() === false, 'retained_unguarded_schema');
            }
            ProductionFreeGrantException::require($ddl || ($actual !== false && $missing === []), 'schema');
            ProductionFreeGrantException::require($actual !== false || count($missing) === count($guards), 'schema');
            $plans[] = ['create' => $actual === false ? $create : null, 'guards' => $missing];
        }
        // The entire existing graph, its parents and foreign names are inspected before the first write.
        if ($ddl) {
            foreach ($plans as $plan) {
                if ($plan['create'] !== null) {
                    $pdo->exec($plan['create']);
                }
                foreach ($plan['guards'] as $sql) {
                    $pdo->exec($sql);
                }
            }
            $this->up(false);
        }
    }

    public function down(): never
    {
        throw new LogicException('Production free grant originals require an explicit retention migration.');
    }

    /** Required permanent parent shape, read before the first owned DDL and again after the last. */
    public function parentFloor(PDO $pdo, string $driver): void
    {
        foreach (self::PARENTS as $parent => $columns) {
            if ($driver === 'sqlite') {
                $statement = $pdo->prepare('SELECT name FROM sqlite_temp_master WHERE lower(name) = lower(?) OR lower(tbl_name) = lower(?)');
                $statement->execute([$parent, $parent]);
                ProductionFreeGrantException::require($statement->fetchAll() === [], 'parent_floor');
                $statement = $pdo->prepare('SELECT name, type FROM main.sqlite_master WHERE lower(name) = lower(?)');
                $statement->execute([$parent]);
                ProductionFreeGrantException::require($statement->fetchAll(PDO::FETCH_ASSOC) === [['name' => $parent, 'type' => 'table']], 'parent_floor');
                $actual = [];
                foreach ($pdo->query('PRAGMA main.table_xinfo("'.$parent.'")')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    $actual[$column['name']] = $column;
                }
                foreach ($columns as $column => $expected) {
                    [$type, $notNull] = $expected['sqlite'];
                    ProductionFreeGrantException::require(isset($actual[$column]) && $actual[$column]['type'] === $type
                        && (int) $actual[$column]['notnull'] === $notNull && (int) $actual[$column]['hidden'] === 0
                        && (int) $actual[$column]['pk'] === ($column === 'id' ? 1 : 0), 'parent_floor');
                }
                ProductionFreeGrantException::require(count(array_filter($actual, fn (array $column): bool => (int) $column['pk'] > 0)) === 1, 'parent_floor');
            } else {
                $statement = $pdo->prepare('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND lower(TABLE_NAME) = lower(?)');
                $statement->execute([$parent]);
                ProductionFreeGrantException::require($statement->fetchAll(PDO::FETCH_ASSOC) === [['TABLE_NAME' => $parent, 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB']], 'parent_floor');
                $shown = $pdo->query('SHOW CREATE TABLE `'.$parent.'`')->fetch(PDO::FETCH_NUM);
                ProductionFreeGrantException::require(! str_contains(strtoupper($shown[1]), 'TEMPORARY'), 'parent_floor');
                $statement = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY ?');
                $statement->execute([$parent]);
                $actual = array_column($statement->fetchAll(PDO::FETCH_ASSOC), null, 'COLUMN_NAME');
                foreach ($columns as $column => $expected) {
                    [$type, $nullable] = $expected['mysql'];
                    ProductionFreeGrantException::require(isset($actual[$column]) && $actual[$column]['COLUMN_TYPE'] === $type
                        && $actual[$column]['IS_NULLABLE'] === $nullable && (string) $actual[$column]['GENERATION_EXPRESSION'] === '', 'parent_floor');
                }
                $statement = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
                $statement->execute([$parent]);
                ProductionFreeGrantException::require($statement->fetchAll(PDO::FETCH_COLUMN) === ['id'], 'parent_floor');
            }
        }
    }

    private function physical(string $logical): string
    {
        if (in_array($logical, self::TABLES, true)) {
            return DB::connection()->getTablePrefix().$logical;
        }
        ProductionFreeGrantException::require(array_key_exists($logical, self::PARENTS), 'schema');

        return $logical;
    }

    private function definition(string $logical, string $driver): array
    {
        $name = $this->physical($logical);
        $ascii = $driver === 'mysql' ? ' CHARACTER SET ascii COLLATE ascii_bin' : ' COLLATE BINARY';
        $text = $driver === 'mysql' ? 'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : 'TEXT';
        $reference = ($driver === 'mysql' ? 'BIGINT UNSIGNED' : 'INTEGER').' NOT NULL';
        $id = 'VARCHAR(36)'.$ascii.' NOT NULL';
        $hash = 'VARCHAR(64)'.$ascii.' NOT NULL';
        $utc = 'VARCHAR(19)'.$ascii.' NOT NULL';
        $literal = fn (int $length): string => 'VARCHAR('.$length.')'.$ascii.' NOT NULL';
        $provenance = "provenance IN ('synthetic_rehearsal','verified_production')";
        $columns = ['id' => $id];
        $unique = [];
        $foreign = [];
        $uuids = ['id'];
        $hashes = ['seal'];
        $times = ['created_at'];
        $check = 'length(id) = 36 AND length(seal) = 64 AND length(created_at) = 19';
        [$definitions, $reviews, $availability, $origins, $work, $originals, $revocations, $authorizations] = self::TABLES;
        if ($logical === $definitions) {
            $columns += ['definition_hash' => $hash, 'family' => $literal(64), 'purpose' => $literal(64), 'version' => 'INTEGER NOT NULL',
                'author_user_id' => $reference, 'terms_hash' => $hash, 'template_hash' => $hash, 'profile_hash' => $hash,
                'asset_manifest_hash' => $hash, 'source_manifest_hash' => $hash, 'asset_count' => 'INTEGER NOT NULL',
                'max_origins' => 'INTEGER NOT NULL', 'provenance' => $literal(32)];
            $unique = [['definition_hash']];
            $foreign = ['author_user_id' => 'users'];
            $hashes = [...$hashes, 'definition_hash', 'terms_hash', 'template_hash', 'profile_hash', 'asset_manifest_hash', 'source_manifest_hash'];
            $check .= " AND family = 'production-free-origin-v1' AND purpose = 'production-free-license-grant-v1' AND version = 1"
                .' AND author_user_id > 0 AND asset_count BETWEEN 1 AND 16 AND max_origins BETWEEN 1 AND 1000000 AND '.$provenance;
        } elseif ($logical === $reviews) {
            $columns += ['definition_id' => $id, 'definition_hash' => $hash, 'terms_hash' => $hash, 'reviewer_user_id' => $reference,
                'decision' => $literal(16), 'provenance' => $literal(32)];
            $unique = [['definition_id']];
            $foreign = ['definition_id' => $definitions, 'reviewer_user_id' => 'users'];
            $uuids[] = 'definition_id';
            $hashes = [...$hashes, 'definition_hash', 'terms_hash'];
            $check .= " AND reviewer_user_id > 0 AND decision = 'approved' AND ".$provenance;
        } elseif ($logical === $availability) {
            $columns += ['definition_id' => $id, 'review_id' => $id, 'ordinal' => 'INTEGER NOT NULL', 'kind' => $literal(16),
                'actor_user_id' => $reference];
            $unique = [['definition_id', 'ordinal']];
            $foreign = ['definition_id' => $definitions, 'review_id' => $reviews, 'actor_user_id' => 'users'];
            $uuids = [...$uuids, 'definition_id', 'review_id'];
            $check .= " AND ordinal BETWEEN 0 AND 9999 AND kind IN ('open','closed') AND actor_user_id > 0";
        } elseif ($logical === $origins) {
            $columns += ['definition_id' => $id, 'availability_id' => $id, 'account_id' => $reference, 'user_id' => $reference,
                'identity_origin_id' => $id, 'owner_binding_hash' => $hash, 'request_key_hash' => $hash, 'definition_hash' => $hash,
                'terms_hash' => $hash, 'profile_hash' => $hash, 'asset_manifest_hash' => $hash, 'assent_hash' => $hash,
                'purpose' => $literal(64), 'provenance' => $literal(32)];
            $unique = [['account_id', 'definition_id'], ['request_key_hash']];
            $foreign = ['definition_id' => $definitions, 'availability_id' => $availability, 'account_id' => 'customer_accounts', 'user_id' => 'users'];
            $uuids = [...$uuids, 'definition_id', 'availability_id', 'identity_origin_id'];
            $hashes = [...$hashes, 'owner_binding_hash', 'request_key_hash', 'definition_hash', 'terms_hash', 'profile_hash', 'asset_manifest_hash', 'assent_hash'];
            $check .= " AND account_id > 0 AND user_id > 0 AND purpose = 'production-free-license-grant-v1' AND ".$provenance;
        } elseif ($logical === $work) {
            $columns += ['origin_id' => $id, 'claim_id' => $id, 'ordinal' => 'INTEGER NOT NULL', 'kind' => $literal(16), 'lease_expires_at' => $utc];
            $unique = [['origin_id', 'ordinal']];
            $foreign = ['origin_id' => $origins];
            $uuids = [...$uuids, 'origin_id', 'claim_id'];
            $times[] = 'lease_expires_at';
            $check .= " AND ordinal BETWEEN 0 AND 31 AND kind IN ('claimed','failed') AND created_at < lease_expires_at";
        } elseif ($logical === $originals) {
            $columns += ['origin_id' => $id, 'work_id' => $id, 'claim_id' => $id, 'sha256' => $hash, 'bytes' => 'INTEGER NOT NULL',
                'profile_hash' => $hash, 'input_hash' => $hash, 'text_digest' => $hash];
            $unique = [['origin_id'], ['work_id']];
            $foreign = ['origin_id' => $origins, 'work_id' => $work];
            $uuids = [...$uuids, 'origin_id', 'work_id', 'claim_id'];
            $hashes = [...$hashes, 'sha256', 'profile_hash', 'input_hash', 'text_digest'];
            $check .= ' AND bytes BETWEEN 32 AND 16777216';
        } elseif ($logical === $revocations) {
            $columns += ['origin_id' => $id, 'actor_user_id' => $reference, 'reason_hash' => $hash];
            $unique = [['origin_id']];
            $foreign = ['origin_id' => $origins, 'actor_user_id' => 'users'];
            $uuids[] = 'origin_id';
            $hashes[] = 'reason_hash';
            $check .= ' AND actor_user_id > 0';
        } elseif ($logical === $authorizations) {
            $columns += ['origin_id' => $id, 'account_id' => $reference, 'user_id' => $reference, 'role' => $literal(16),
                'artifact_sha256' => $hash, 'token_hash' => $hash, 'expires_at' => $utc];
            $unique = [['token_hash']];
            $foreign = ['origin_id' => $origins, 'account_id' => 'customer_accounts', 'user_id' => 'users'];
            $uuids[] = 'origin_id';
            $hashes = [...$hashes, 'artifact_sha256', 'token_hash'];
            $times[] = 'expires_at';
            $check .= " AND account_id > 0 AND user_id > 0 AND role IN ('contract','master_wav','download_mp3','stems_zip') AND created_at < expires_at";
        } else {
            $columns += ['authorization_id' => $id, 'origin_id' => $id, 'account_id' => $reference, 'user_id' => $reference,
                'role' => $literal(16), 'artifact_sha256' => $hash, 'bytes' => ($driver === 'mysql' ? 'BIGINT UNSIGNED' : 'INTEGER').' NOT NULL'];
            $unique = [['authorization_id']];
            $foreign = ['authorization_id' => $authorizations, 'origin_id' => $origins, 'account_id' => 'customer_accounts', 'user_id' => 'users'];
            $uuids = [...$uuids, 'authorization_id', 'origin_id'];
            $hashes[] = 'artifact_sha256';
            $check .= " AND account_id > 0 AND user_id > 0 AND role IN ('contract','master_wav','download_mp3','stems_zip') AND bytes BETWEEN 1 AND 4294967296";
        }
        $columns += ['payload_ciphertext' => $text.' NOT NULL', 'seal' => $hash, 'created_at' => $utc];
        $quote = $driver === 'mysql' ? '`' : '"';
        $sql = implode(', ', array_map(fn ($column, $type) => $quote.$column.$quote.' '.$type, array_keys($columns), $columns));
        $sql .= ', PRIMARY KEY (id), CONSTRAINT '.$quote.$name.'_bounds'.$quote.' CHECK ('.$check.')';
        foreach ($unique as $index => $parts) {
            $sql .= ', CONSTRAINT '.$quote.$name.'_u'.$index.$quote.' UNIQUE ('.implode(', ', $parts).')';
        }
        foreach (array_keys($foreign) as $index => $column) {
            $sql .= ', CONSTRAINT '.$quote.$name.'_f'.$index.$quote.' FOREIGN KEY ('.$column.') REFERENCES '.$quote.$this->physical($foreign[$column]).$quote.' (id) ON UPDATE RESTRICT ON DELETE RESTRICT';
        }
        $primary = ['id'];

        return compact('sql', 'columns', 'primary', 'unique', 'check', 'foreign', 'uuids', 'hashes', 'times');
    }

    /** Append-only guards: byte-exact shapes, prefix ordering and parent/lineage predicates on insert. */
    private function guards(string $logical, string $driver, string $name, string $table): array
    {
        [$definitions, $reviews, $availability, $origins, $work, $originals, $revocations, $authorizations] = array_map($this->table(...), self::TABLES);
        $definition = $this->definition($logical, $driver);
        $shape = [];
        foreach ($definition['uuids'] as $column) {
            $shape[] = $this->uuidShape('NEW.'.$column, $driver);
        }
        foreach ($definition['hashes'] as $column) {
            $shape[] = $this->hashShape('NEW.'.$column, $driver);
        }
        foreach ($definition['times'] as $column) {
            $shape[] = $this->utcShape('NEW.'.$column, $driver);
        }
        $staff = fn (string $column): string => 'EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.'.$column.' AND u.is_admin = 1 AND u.email_verified_at IS NOT NULL)';
        $customer = 'EXISTS (SELECT 1 FROM customer_accounts c JOIN users u ON u.id = c.user_id WHERE c.id = NEW.account_id AND u.id = NEW.user_id AND c.active = 1 AND u.is_admin = 0 AND u.email_verified_at IS NOT NULL)';
        $insert = implode(' AND ', $shape).' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE id = NEW.id)';
        if ($logical === self::TABLES[0]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE definition_hash = NEW.definition_hash) AND '.$staff('author_user_id');
        } elseif ($logical === self::TABLES[1]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE definition_id = NEW.definition_id)'
                .' AND EXISTS (SELECT 1 FROM '.$definitions.' d WHERE d.id = NEW.definition_id AND d.definition_hash = NEW.definition_hash AND d.terms_hash = NEW.terms_hash AND d.provenance = NEW.provenance AND d.author_user_id <> NEW.reviewer_user_id AND d.created_at <= NEW.created_at)'
                .' AND '.$staff('reviewer_user_id');
        } elseif ($logical === self::TABLES[2]) {
            $insert .= ' AND NEW.ordinal = (SELECT COUNT(*) FROM '.$table.' WHERE definition_id = NEW.definition_id)'
                .' AND EXISTS (SELECT 1 FROM '.$reviews.' r WHERE r.id = NEW.review_id AND r.definition_id = NEW.definition_id AND r.created_at <= NEW.created_at)'
                ." AND ((NEW.ordinal = 0 AND NEW.kind = 'open') OR EXISTS (SELECT 1 FROM ".$table.' p WHERE p.definition_id = NEW.definition_id AND p.ordinal = NEW.ordinal - 1 AND p.kind <> NEW.kind AND p.created_at <= NEW.created_at))'
                .' AND '.$staff('actor_user_id');
        } elseif ($logical === self::TABLES[3]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE (account_id = NEW.account_id AND definition_id = NEW.definition_id) OR request_key_hash = NEW.request_key_hash)'
                .' AND EXISTS (SELECT 1 FROM '.$definitions.' d WHERE d.id = NEW.definition_id AND d.definition_hash = NEW.definition_hash AND d.terms_hash = NEW.terms_hash AND d.profile_hash = NEW.profile_hash AND d.asset_manifest_hash = NEW.asset_manifest_hash AND d.provenance = NEW.provenance AND d.max_origins > (SELECT COUNT(*) FROM '.$table.' WHERE definition_id = NEW.definition_id))'
                .' AND EXISTS (SELECT 1 FROM '.$availability." a WHERE a.id = NEW.availability_id AND a.definition_id = NEW.definition_id AND a.kind = 'open' AND a.created_at <= NEW.created_at AND a.ordinal = (SELECT MAX(ordinal) FROM ".$availability.' WHERE definition_id = NEW.definition_id))'
                .' AND '.$customer;
        } elseif ($logical === self::TABLES[4]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$originals.' WHERE origin_id = NEW.origin_id)'
                .' AND NEW.ordinal = (SELECT COUNT(*) FROM '.$table.' WHERE origin_id = NEW.origin_id)'
                .' AND EXISTS (SELECT 1 FROM '.$origins.' o WHERE o.id = NEW.origin_id AND o.created_at <= NEW.created_at)'
                ." AND ((NEW.kind = 'claimed' AND NOT EXISTS (SELECT 1 FROM ".$table.' WHERE origin_id = NEW.origin_id AND claim_id = NEW.claim_id)'
                .' AND (NEW.ordinal = 0 OR EXISTS (SELECT 1 FROM '.$table." p WHERE p.origin_id = NEW.origin_id AND p.ordinal = NEW.ordinal - 1 AND (p.kind = 'failed' OR p.lease_expires_at <= NEW.created_at))))"
                ." OR (NEW.kind = 'failed' AND EXISTS (SELECT 1 FROM ".$table." p WHERE p.origin_id = NEW.origin_id AND p.ordinal = NEW.ordinal - 1 AND p.kind = 'claimed' AND p.claim_id = NEW.claim_id AND p.lease_expires_at = NEW.lease_expires_at AND p.created_at <= NEW.created_at)))";
        } elseif ($logical === self::TABLES[5]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE origin_id = NEW.origin_id OR work_id = NEW.work_id)'
                .' AND EXISTS (SELECT 1 FROM '.$work." w WHERE w.id = NEW.work_id AND w.origin_id = NEW.origin_id AND w.claim_id = NEW.claim_id AND w.kind = 'claimed' AND w.created_at <= NEW.created_at AND NEW.created_at < w.lease_expires_at AND w.ordinal = (SELECT MAX(ordinal) FROM ".$work.' WHERE origin_id = NEW.origin_id))'
                .' AND EXISTS (SELECT 1 FROM '.$origins.' o WHERE o.id = NEW.origin_id AND o.profile_hash = NEW.profile_hash)';
        } elseif ($logical === self::TABLES[6]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE origin_id = NEW.origin_id)'
                .' AND EXISTS (SELECT 1 FROM '.$origins.' o WHERE o.id = NEW.origin_id AND o.created_at <= NEW.created_at)'
                .' AND '.$staff('actor_user_id');
        } elseif ($logical === self::TABLES[7]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE token_hash = NEW.token_hash)'
                .' AND EXISTS (SELECT 1 FROM '.$origins.' o WHERE o.id = NEW.origin_id AND o.account_id = NEW.account_id AND o.user_id = NEW.user_id AND o.created_at <= NEW.created_at)'
                .' AND NOT EXISTS (SELECT 1 FROM '.$revocations.' WHERE origin_id = NEW.origin_id)'
                ." AND (NEW.role <> 'contract' OR EXISTS (SELECT 1 FROM ".$originals.' WHERE origin_id = NEW.origin_id AND sha256 = NEW.artifact_sha256))'
                .' AND '.$customer;
        } else {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE authorization_id = NEW.authorization_id)'
                .' AND EXISTS (SELECT 1 FROM '.$authorizations.' a WHERE a.id = NEW.authorization_id AND a.origin_id = NEW.origin_id AND a.account_id = NEW.account_id AND a.user_id = NEW.user_id AND a.role = NEW.role AND a.artifact_sha256 = NEW.artifact_sha256 AND a.created_at <= NEW.created_at AND NEW.created_at < a.expires_at)'
                .' AND NOT EXISTS (SELECT 1 FROM '.$revocations.' WHERE origin_id = NEW.origin_id)';
        }
        $result = [];
        foreach (['insert' => $insert, 'update' => '0 = 1', 'delete' => '0 = 1'] as $event => $condition) {
            $guard = $name.'_'.$event;
            $condition = $driver === 'sqlite' ? str_replace('main.', '', $condition) : $condition;
            $body = "BEGIN IF NOT COALESCE(({$condition}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain production free grants'; END IF; END";
            $sql = $driver === 'mysql' ? 'CREATE TRIGGER `'.$guard.'` BEFORE '.strtoupper($event).' ON '.$table.' FOR EACH ROW '.$body
                : 'CREATE TRIGGER "'.$guard.'" BEFORE '.strtoupper($event).' ON '.$table.' WHEN NOT COALESCE(('.$condition."),0) BEGIN SELECT RAISE(ABORT, 'Retain production free grants'); END";
            $result[$guard] = ['sql' => $sql, 'event' => strtoupper($event), 'body' => $body];
        }

        return $result;
    }

    /** Stored bytes, not character semantics; SQLite LENGTH/GLOB alone stop at an embedded NUL. */
    private function uuidShape(string $value, string $driver): string
    {
        if ($driver === 'sqlite') {
            return "TYPEOF({$value}) = 'text' AND LENGTH(CAST({$value} AS BLOB)) = 36 AND SUBSTR({$value}, 9, 1) = '-' AND SUBSTR({$value}, 14, 1) = '-'"
                ." AND SUBSTR({$value}, 19, 1) = '-' AND SUBSTR({$value}, 24, 1) = '-' AND LENGTH(REPLACE({$value}, '-', '')) = 32"
                ." AND REPLACE({$value}, '-', '') NOT GLOB '*[^0-9a-f]*'";
        }

        return "OCTET_LENGTH({$value}) = 36 AND REGEXP_LIKE({$value}, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c')";
    }

    private function hashShape(string $value, string $driver): string
    {
        if ($driver === 'sqlite') {
            return "TYPEOF({$value}) = 'text' AND LENGTH(CAST({$value} AS BLOB)) = 64 AND {$value} NOT GLOB '*[^0-9a-f]*'";
        }

        return "OCTET_LENGTH({$value}) = 64 AND REGEXP_LIKE({$value}, '^[0-9a-f]{64}$', 'c')";
    }

    private function utcShape(string $value, string $driver): string
    {
        if ($driver === 'sqlite') {
            return "TYPEOF({$value}) = 'text' AND LENGTH(CAST({$value} AS BLOB)) = 19"
                ." AND {$value} GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]'";
        }

        return "OCTET_LENGTH({$value}) = 19 AND REGEXP_LIKE({$value}, '^[0-9]{4}-[01][0-9]-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]$', 'c')";
    }

    private function assertNamespace(PDO $pdo, string $driver): void
    {
        $reserved = [];
        foreach (self::TABLES as $logical) {
            $name = DB::connection()->getTablePrefix().$logical;
            $this->table($logical);
            $reserved[strtolower($name)] = ['name' => $name, 'type' => 'table'];
            foreach (['insert', 'update', 'delete'] as $event) {
                $guard = $name.'_'.$event;
                $reserved[strtolower($guard)] = ['name' => $guard, 'type' => 'trigger'];
            }
        }
        if ($driver === 'sqlite') {
            // Inline non-integer primary keys and UNIQUE clauses create schema-global autoindexes owned by the table.
            $indexes = [];
            foreach (self::TABLES as $logical) {
                $name = DB::connection()->getTablePrefix().$logical;
                for ($index = 1; $index <= 1 + count($this->definition($logical, $driver)['unique']); $index++) {
                    $symbol = 'sqlite_autoindex_'.$name.'_'.$index;
                    $indexes[strtolower($symbol)] = ['name' => $symbol, 'type' => 'index', 'tbl_name' => $name, 'sql' => null];
                }
            }
            $objects = $pdo->query('SELECT name, type, tbl_name, sql FROM main.sqlite_master')->fetchAll(PDO::FETCH_ASSOC);
            $rows = [];
            $seen = [];
            foreach ($objects as $object) {
                $key = strtolower($object['name']);
                if (isset($indexes[$key])) {
                    ProductionFreeGrantException::require(! isset($seen[$key]) && $indexes[$key] === $object, 'schema_namespace');
                    $seen[$key] = true;
                }
                $rows[] = ['name' => $object['name'], 'type' => $object['type']];
            }
        } else {
            // CHECK and FK symbols are schema-global in distinct native namespaces; require exact ownership.
            $statement = $pdo->prepare('SELECT CONSTRAINT_NAME, TABLE_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND CONSTRAINT_TYPE = ? AND CONSTRAINT_NAME = ?');
            foreach (self::TABLES as $logical) {
                $name = DB::connection()->getTablePrefix().$logical;
                $definition = $this->definition($logical, $driver);
                $constraints = [$name.'_bounds' => 'CHECK'];
                foreach (array_keys($definition['foreign']) as $index => $column) {
                    $constraints[$name.'_f'.$index] = 'FOREIGN KEY';
                }
                foreach ($constraints as $symbol => $type) {
                    $statement->execute([$type, $symbol]);
                    $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                    ProductionFreeGrantException::require($objects === [] || $objects === [['CONSTRAINT_NAME' => $symbol, 'TABLE_NAME' => $name, 'CONSTRAINT_TYPE' => $type]], 'schema_namespace');
                }
            }
            $rows = $pdo->query("SELECT TABLE_NAME AS name, 'table' AS type FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() UNION ALL SELECT TRIGGER_NAME AS name, 'trigger' AS type FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reserved as $expected) {
                if ($expected['type'] === 'trigger') {
                    try {
                        $pdo->query('SHOW CREATE TABLE `'.$expected['name'].'`')->fetchAll();
                        throw new ProductionFreeGrantException('schema_namespace');
                    } catch (PDOException $error) {
                        if ($error->getCode() !== '42S02') {
                            throw $error;
                        }
                    }
                }
            }
        }
        $seen = [];
        foreach ($rows as $row) {
            $key = strtolower($row['name']);
            if (isset($reserved[$key])) {
                ProductionFreeGrantException::require(! isset($seen[$key]) && $reserved[$key] === $row, 'schema_namespace');
                $seen[$key] = true;
            }
        }
    }

    /** No foreign table, view or trigger may depend on owned evidence; it would make retention someone else's. */
    private function assertNoExternalDependents(PDO $pdo, string $driver): void
    {
        $owned = array_map(fn (string $logical): string => strtolower(DB::connection()->getTablePrefix().$logical), self::TABLES);
        if ($driver === 'sqlite') {
            $objects = $pdo->query("SELECT name, type, tbl_name, sql FROM main.sqlite_master WHERE type IN ('table', 'view', 'trigger')")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($objects as $object) {
                $mine = in_array(strtolower($object['tbl_name']), $owned, true);
                if ($object['type'] === 'table' && ! $mine) {
                    foreach ($pdo->query('PRAGMA main.foreign_key_list("'.str_replace('"', '""', $object['name']).'")')->fetchAll(PDO::FETCH_ASSOC) as $key) {
                        ProductionFreeGrantException::require(! in_array(strtolower($key['table']), $owned, true), 'external_dependent');
                    }
                } elseif (! $mine && is_string($object['sql'])) {
                    foreach ($owned as $name) {
                        ProductionFreeGrantException::require(! str_contains(strtolower($object['sql']), $name), 'external_dependent');
                    }
                }
            }

            return;
        }
        $placeholders = implode(', ', array_fill(0, count($owned), '?'));
        $statement = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND lower(REFERENCED_TABLE_NAME) IN ('.$placeholders.') AND lower(TABLE_NAME) NOT IN ('.$placeholders.')');
        $statement->execute([...$owned, ...$owned]);
        ProductionFreeGrantException::require($statement->fetchAll() === [], 'external_dependent');
        $statement = $pdo->prepare('SELECT VIEW_NAME FROM information_schema.VIEW_TABLE_USAGE WHERE BINARY VIEW_SCHEMA = BINARY DATABASE() AND lower(TABLE_NAME) IN ('.$placeholders.')');
        $statement->execute($owned);
        ProductionFreeGrantException::require($statement->fetchAll() === [], 'external_dependent');
        $triggers = $pdo->query('SELECT EVENT_OBJECT_TABLE, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($triggers as $trigger) {
            if (! in_array(strtolower($trigger['EVENT_OBJECT_TABLE']), $owned, true)) {
                foreach ($owned as $name) {
                    ProductionFreeGrantException::require(! str_contains(strtolower($trigger['ACTION_STATEMENT']), $name), 'external_dependent');
                }
            }
        }
    }

    private function mysqlDefinition(PDO $pdo, string $name, array $definition): void
    {
        $s = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $s->execute([$name]);
        $columns = $s->fetchAll(PDO::FETCH_ASSOC);
        ProductionFreeGrantException::require(array_column($columns, 'COLUMN_NAME') === array_keys($definition['columns']), 'schema');
        foreach ($columns as $column) {
            $expected = $definition['columns'][$column['COLUMN_NAME']];
            ProductionFreeGrantException::require($column['COLUMN_TYPE'] === (str_starts_with($expected, 'INTEGER') ? 'int' : (str_starts_with($expected, 'BIGINT UNSIGNED') ? 'bigint unsigned' : strtolower(explode(' ', $expected)[0]))) && $column['COLUMN_DEFAULT'] === null && $column['EXTRA'] === ''
                && $column['IS_NULLABLE'] === (str_contains($expected, 'NOT NULL') ? 'NO' : 'YES')
                && $column['COLLATION_NAME'] === (str_contains($expected, 'ascii_bin') ? 'ascii_bin' : (str_contains($expected, 'utf8mb4_bin') ? 'utf8mb4_bin' : null)), 'schema');
        }
        $indexes = $pdo->query('SHOW INDEX FROM `'.$name.'`')->fetchAll(PDO::FETCH_ASSOC);
        $actual = [];
        foreach ($indexes as $index) {
            ProductionFreeGrantException::require($index['Index_type'] === 'BTREE' && $index['Sub_part'] === null && $index['Non_unique'] == ($index['Key_name'] === 'PRIMARY' || preg_match('/_u[0-9]+\z/D', $index['Key_name']) === 1 ? 0 : 1), 'schema');
            $actual[$index['Key_name']][] = $index['Column_name'];
        }
        $wanted = ['PRIMARY' => $definition['primary']];
        foreach ($definition['unique'] as $index => $parts) {
            $wanted[$name.'_u'.$index] = $parts;
        }
        foreach (array_keys($definition['foreign']) as $index => $column) {
            if (! array_any($wanted, fn ($parts): bool => $parts[0] === $column)) {
                $wanted[$name.'_f'.$index] = [$column];
            }
        }
        ksort($actual);
        ksort($wanted);
        ProductionFreeGrantException::require($actual === $wanted, 'schema');
        $s = $pdo->prepare('SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND TABLE_NAME = ?');
        $s->execute([$name]);
        $actual = array_column($s->fetchAll(PDO::FETCH_ASSOC), 'CONSTRAINT_TYPE', 'CONSTRAINT_NAME');
        $wanted = ['PRIMARY' => 'PRIMARY KEY', $name.'_bounds' => 'CHECK'];
        foreach ($definition['unique'] as $index => $parts) {
            $wanted[$name.'_u'.$index] = 'UNIQUE';
        }
        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        foreach (array_keys($definition['foreign']) as $index => $column) {
            $target = $definition['foreign'][$column];
            $wanted[$name.'_f'.$index] = 'FOREIGN KEY';
            $s = $pdo->prepare('SELECT COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY ? AND CONSTRAINT_NAME = ?');
            $s->execute([$name, $name.'_f'.$index]);
            ProductionFreeGrantException::require($s->fetchAll(PDO::FETCH_ASSOC) === [['COLUMN_NAME' => $column, 'REFERENCED_TABLE_SCHEMA' => $schema, 'REFERENCED_TABLE_NAME' => $this->physical($target), 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => 1]], 'schema');
            $s = $pdo->prepare('SELECT UNIQUE_CONSTRAINT_SCHEMA, UNIQUE_CONSTRAINT_NAME, REFERENCED_TABLE_NAME, MATCH_OPTION, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY ? AND CONSTRAINT_NAME = ?');
            $s->execute([$name, $name.'_f'.$index]);
            ProductionFreeGrantException::require($s->fetchAll(PDO::FETCH_ASSOC) === [['UNIQUE_CONSTRAINT_SCHEMA' => $schema, 'UNIQUE_CONSTRAINT_NAME' => 'PRIMARY', 'REFERENCED_TABLE_NAME' => $this->physical($target), 'MATCH_OPTION' => 'NONE', 'UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'RESTRICT']], 'schema');
        }
        ksort($actual);
        ksort($wanted);
        ProductionFreeGrantException::require($actual === $wanted, 'schema');
        $s = $pdo->prepare("SELECT c.CHECK_CLAUSE, t.ENFORCED FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON BINARY t.CONSTRAINT_SCHEMA = BINARY c.CONSTRAINT_SCHEMA AND BINARY t.CONSTRAINT_NAME = BINARY c.CONSTRAINT_NAME WHERE BINARY t.TABLE_SCHEMA = BINARY DATABASE() AND BINARY t.TABLE_NAME = BINARY ? AND t.CONSTRAINT_TYPE = 'CHECK' AND t.CONSTRAINT_NAME = ?");
        $s->execute([$name, $name.'_bounds']);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        ProductionFreeGrantException::require(count($rows) === 1 && $rows[0]['ENFORCED'] === 'YES' && $this->check($rows[0]['CHECK_CLAUSE']) === $this->check($definition['check']), 'schema');
    }

    private function sql(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql), ' ;');
    }

    private function check(string $sql): string
    {
        // Native dictionaries introduce/escape these exact ASCII enum literals. Admit only the owned spellings.
        foreach ([...self::PROVENANCES, self::FAMILY, self::PURPOSE, ...self::ROLES, 'approved', 'open', 'closed', 'claimed', 'failed'] as $literal) {
            $sql = str_replace("_utf8mb4\\'".$literal."\\'", "'".$literal."'", $sql);
        }

        return strtolower(preg_replace('/[\s`()]+/', '', $sql));
    }
}
