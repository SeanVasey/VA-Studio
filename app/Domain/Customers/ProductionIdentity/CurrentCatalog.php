<?php

namespace App\Domain\Customers\ProductionIdentity;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use LogicException;
use PDO;
use Pdo\Mysql;
use PDOStatement;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/** Private native catalog transport. No PDO/callback/raw SQL accessor. */
final class CurrentCatalog
{
    private ?PDO $catalog = null;

    private array $identity;

    private array $transportIdentity;

    private int $boundaries = 0;

    private int $holders = 0;

    private bool $failed = false;

    private static int $companionStatements = 0;

    private static int $connections = 0;

    private static int $connectionAttempts = 0;

    private static int $connectNanoseconds = 0;

    private readonly IdentityMetadataAdmission $admission;

    private readonly Connection $connection;

    private readonly array $configuration;

    private const CONTEXT = 'SELECT VERSION() version,@@server_uuid server_uuid,CURRENT_USER() principal,USER() login,CURRENT_ROLE() roles,DATABASE() database_name,CONNECTION_ID() connection_id,@@SESSION.autocommit autocommit,@@SESSION.transaction_isolation session_isolation,@@SESSION.sql_mode sql_mode,@@SESSION.time_zone time_zone,@@SESSION.character_set_client charset_client,@@SESSION.collation_connection collation_connection,@@SESSION.character_set_results charset_results,@@SESSION.sql_select_limit select_limit,@@SESSION.foreign_key_checks foreign_key_checks,@@port server_port,@@socket server_socket,(SELECT HOST FROM information_schema.PROCESSLIST WHERE ID=CONNECTION_ID()) client_endpoint';

    private const STRICT_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    private function __construct(private readonly PDO $primary)
    {
        try {
            $this->admission = IdentityMetadataAdmission::capture($primary);
            $this->connection = $this->admission->connection();
            if ($this->connection->getRawPdo() !== $primary) {
                self::refuse();
            }
            $this->configuration = $this->rawConfiguration();
            [$raw, $resolved] = $this->configuration;
            $descriptor = CatalogWriterDescriptor::validate($raw, $resolved);
            $this->native($primary);
            $this->identity = $this->context($primary, false);
            $this->transportIdentity = $this->transport($primary, false);
            if ($this->identity['database_name'] !== $descriptor['database']) {
                self::refuse();
            }
            self::tlsRequest($descriptor, $this->transportIdentity['tls']);
            $binding = CatalogSocketBinding::attest($primary, $descriptor, $this->identity, $this->transportIdentity['connection_status']);
            $this->admission->requireCurrent($primary);
            if ($this->configuration !== $this->rawConfiguration()) {
                self::refuse();
            }
            $this->native($primary);
            if ($binding !== CatalogSocketBinding::attest($primary, $descriptor, $this->identity, $this->transportIdentity['connection_status'])) {
                self::refuse();
            }
            self::$connectionAttempts++;
            $connectStarted = \hrtime(true);
            try {
                $this->catalog = new PDO($descriptor['dsn'], $descriptor['username'], $descriptor['password'], $descriptor['options']);
            } finally {
                self::$connectNanoseconds += \hrtime(true) - $connectStarted;
            }
            self::$connections++;
            // Capture actual fresh-session Questions, including any native constructor SQL plus this sample.
            $initial = $this->catalog->query("SHOW SESSION STATUS LIKE 'Questions'");
            $initialRows = $initial->fetchAll(PDO::FETCH_NUM);
            if (! $initial->closeCursor() || \count($initialRows) !== 1 || $initialRows[0][0] !== 'Questions'
                || ! \is_numeric($initialRows[0][1]) || (int) $initialRows[0][1] < 1) {
                self::refuse();
            }
            self::$companionStatements += (int) $initialRows[0][1];
            self::$companionStatements++;
            $this->catalog->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
            self::$companionStatements++;
            $this->catalog->exec("SET SESSION time_zone='+00:00', sql_mode='".self::STRICT_MODE."', lock_wait_timeout=1, innodb_lock_wait_timeout=1");
            $this->catalogIdentity = $this->context($this->catalog, true);
            $this->compareIdentities($this->catalogIdentity, $this->identity);
            if ($this->transport($this->catalog, true) !== $this->transportIdentity) {
                self::refuse();
            }
        } catch (Throwable) {
            $this->catalog = null;
            $this->failed = true;
            // No previous exception/DSN/options/credentials in a public failure chain.
            self::refuse();
        }
    }

    private array $catalogIdentity;

    public static function capture(PDO $primary): self
    {
        CatalogSocketBinding::requireUntainted();

        return new self($primary);
    }

    /** All companion SQL, including constructor setup and context, contributes to the aggregate gate. */
    public static function statementCount(): int
    {
        return self::$companionStatements;
    }

    public static function accounting(): array
    {
        return ['companion_sql' => self::$companionStatements, 'connections' => self::$connections,
            'connect_ns' => self::$connectNanoseconds, 'connection_attempts' => self::$connectionAttempts];
    }

    public function retain(): void
    {
        $this->requireCurrent();
        $this->holders++;
    }

    public function release(): bool
    {
        if ($this->holders < 1) {
            self::refuse();
        }
        $this->holders--;
        if ($this->holders === 0) {
            $this->invalidate();

            return true;
        }

        return false;
    }

    public function hasHolders(): bool
    {
        return $this->holders > 0;
    }

    public function enter(): void
    {
        $this->requireCurrent();
        if ($this->boundaries === 0) {
            $this->verifyLive();
        }
        $this->boundaries++;
    }

    public function leave(): void
    {
        if ($this->boundaries < 1) {
            self::refuse();
        }
        $this->boundaries--;
        if ($this->boundaries === 0) {
            $this->verifyLive();
        }
    }

    public function rows(CatalogRead $query, array $bindings = []): array
    {
        if ($this->boundaries < 1) {
            self::refuse();
        }

        return $this->execute($query->value, $bindings);
    }

    public function identity(CatalogRead $query, array $names): array
    {
        $plan = self::identityPlan($query, $names);
        $names = $plan['names'];
        $result = \array_fill_keys($names, []);
        if ($plan['sql'] === null) {
            return $result;
        }
        foreach ($this->execute($plan['sql'], $plan['bindings']) as $row) {
            $name = $names[(int) $row['identity_lookup']];
            foreach (\array_keys($row) as $column) {
                if (\str_starts_with($column, 'identity_')) {
                    unset($row[$column]);
                }
            }
            $result[$name][] = $row;
        }

        return $result;
    }

    private static function identityPlan(CatalogRead $query, array $names): array
    {
        $sql = $query->value;
        if (! \array_is_list($names) || \count($names) > 128 || ! \array_all($names, fn ($name) => \is_string($name) && \strlen($name) <= 256)) {
            self::refuse();
        }
        if (\preg_match('/\ASELECT (.+?) FROM (information_schema\..+?) WHERE (.+) AND (LOWER\()?((?:[a-z]\.)?[A-Z_]+)(\)?)=\?(?: ORDER BY ([a-zA-Z_.,]+))?\z/', $sql, $part) !== 1
            || $part[4] !== ($part[6] === '' ? '' : 'LOWER(')) {
            throw new LogicException('Unsupported identity dictionary lookup.');
        }
        $names = \array_values(\array_unique($names));
        if ($names === []) {
            return ['sql' => null, 'bindings' => [], 'names' => []];
        }
        $order = isset($part[7]) && $part[7] !== '' ? \explode(',', $part[7]) : [];
        $ordering = \implode('', \array_map(fn (int $index, string $column): string => ', '.$column.' AS identity_order_'.$index, \array_keys($order), $order));
        $predicate = $part[4].'identity_key'.$part[6].'=?';
        $branches = \array_map(fn (int $position): string => 'SELECT /*+ NO_MERGE(lookup) */ '.$position.' AS identity_lookup, lookup.* FROM lookup WHERE '.$predicate, \array_keys($names));
        // IN is the disjunction of the branch equalities (same operands, same comparison collation),
        // so the prefilter keeps exactly the rows some branch would match, including LOWER() aliases.
        $prefilter = ' AND '.$part[4].$part[5].$part[6].' IN ('.\implode(',', \array_fill(0, \count($names), '?')).')';
        $statement = 'WITH lookup AS (SELECT '.$part[1].', '.$part[5].' AS identity_key'.$ordering.' FROM '.$part[2].' WHERE '.$part[3].$prefilter.') '.\implode(' UNION ALL ', $branches)
            .($order === [] ? '' : ' ORDER BY identity_lookup'.\implode('', \array_map(fn (int $index): string => ', identity_order_'.$index, \array_keys($order))));

        return ['sql' => $statement, 'bindings' => [...$names, ...$names], 'names' => $names];
    }

    public function membership(CatalogRead $query, array $parameters, string $name): array
    {
        $plan = self::membershipPlan($query, $parameters, $name);
        $rows = $this->execute($plan['sql'], $plan['bindings']);
        $parameters = \array_fill_keys($plan['names'], []);
        $names = \array_keys($parameters);
        $batch = \array_fill_keys($names, []);
        foreach ($rows as $row) {
            $target = $names[(int) $row['membership_batch']];
            foreach (\array_keys($row) as $column) {
                if (\str_starts_with($column, 'membership_')) {
                    unset($row[$column]);
                }
            }
            $batch[$target][] = $row;
        }

        return $batch;
    }

    private static function membershipPlan(CatalogRead $query, array $parameters, string $name): array
    {
        $sql = $query->value;
        if ($parameters === [] || \count($parameters) > 128) {
            self::refuse();
        }
        if (\preg_match('/\ASELECT (.+?) FROM (information_schema\..+?) WHERE (.+?)(?: ORDER BY ([a-zA-Z_., ]+))?\z/D', $sql, $parts) !== 1
            || ! isset($parameters[$name])) {
            throw new LogicException('Unsupported membership dictionary lookup.');
        }
        $lookups = [];
        $where = \preg_replace_callback('/((?:BINARY )?(?:lower\()?([a-zA-Z_][a-zA-Z0-9_.]*)(?:\))? = (?:BINARY |lower\()?\?(?:\))?)/i', function (array $match) use (&$lookups): string {
            $lookups[] = ['predicate' => $match[1], 'column' => $match[2]];

            return '1 = 1';
        }, $parts[3]);
        if ($lookups === [] || \substr_count($sql, '?') !== \count($lookups)) {
            throw new LogicException('Unsupported membership dictionary parameters.');
        }
        foreach ($parameters as $values) {
            if (! \is_array($values) || \count($values) !== \count($lookups) || ! \array_all($values, fn (mixed $value): bool => \is_string($value))) {
                throw new LogicException('Unsupported membership dictionary values.');
            }
        }
        $order = isset($parts[4]) ? \array_map(\trim(...), \explode(',', $parts[4])) : [];
        $fields = \implode('', \array_map(fn (int $index, array $lookup): string => ', '.$lookup['column'].' AS membership_key_'.$index, \array_keys($lookups), $lookups));
        $fields .= \implode('', \array_map(fn (int $index, string $column): string => ', '.$column.' AS membership_order_'.$index, \array_keys($order), $order));
        $original = \implode(' AND ', \array_column($lookups, 'predicate'));
        $predicate = \implode(' AND ', \array_map(fn (int $index, array $lookup): string => \str_replace($lookup['column'], 'membership_key_'.$index, $lookup['predicate']), \array_keys($lookups), $lookups));
        // Materialize the selected dictionary rows once, preserving native comparison collations.
        $filter = \implode(' OR ', \array_fill(0, \count($parameters), '('.$original.')'));
        $branches = \array_map(fn (int $index): string => 'SELECT /*+ NO_MERGE(membership_lookup) */ '.$index.' AS membership_batch, membership_lookup.* FROM membership_lookup WHERE '.$predicate, range(0, \count($parameters) - 1));
        $bindings = \array_merge(...\array_values($parameters));
        $compiled = 'WITH membership_lookup AS (SELECT '.$parts[1].$fields.' FROM '.$parts[2].' WHERE '.$where.' AND ('.$filter.')) '.\implode(' UNION ALL ', $branches)
                .($order === [] ? '' : ' ORDER BY membership_batch'.\implode('', \array_map(fn (int $index): string => ', membership_order_'.$index, \array_keys($order))));

        return ['sql' => $compiled, 'bindings' => [...$bindings, ...$bindings], 'names' => \array_keys($parameters)];
    }

    private function execute(string $sql, array $bindings): array
    {
        $this->requireCurrent();
        if ($this->boundaries < 1) {
            self::refuse();
        }
        if (\count($bindings) > 1024 || ! \array_is_list($bindings)
            || ! \array_all($bindings, fn ($value) => (\is_string($value) && \strlen($value) <= 4096) || \is_int($value))
            || \substr_count($sql, '?') !== \count($bindings)) {
            self::refuse();
        }
        try {
            self::$companionStatements++;
            $statement = $this->catalog->prepare($sql);
            $statement->execute($bindings);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (! $statement->closeCursor() || $this->catalog->inTransaction()) {
                self::refuse();
            }

            return $rows;
        } catch (Throwable) {
            $this->failed = true;
            $this->catalog = null;
            self::refuse();
        }
    }

    private function requireCurrent(): void
    {
        try {
            CatalogSocketBinding::requireUntainted();
        } catch (Throwable $error) {
            $this->invalidate();
            throw $error;
        }
        try {
            $this->admission->requireCurrent($this->primary);
            if ($this->failed || ! $this->catalog instanceof PDO || $this->connection->getRawPdo() !== $this->primary
                || $this->configuration !== $this->rawConfiguration() || $this->catalog->inTransaction()) {
                self::refuse();
            }
            $this->native($this->primary);
            $this->native($this->catalog);
        } catch (Throwable) {
            $this->failed = true;
            $this->catalog = null;
            self::refuse();
        }
    }

    private function native(PDO $pdo): void
    {
        if (! \in_array($pdo::class, [PDO::class, 'Pdo\\Mysql'], true) || ! (new ReflectionClass($pdo))->isInternal()
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) !== [PDOStatement::class]
            || $pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_NATURAL
            || $pdo->getAttribute(PDO::ATTR_ORACLE_NULLS) !== PDO::NULL_NATURAL
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) !== false
            || $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES) !== false
            || $pdo->getAttribute(PDO::ATTR_PERSISTENT) !== false) {
            self::refuse();
        }
    }

    private function verifyLive(): void
    {
        try {
            $this->requireCurrent();
            $primary = $this->context($this->primary, false);
            $catalog = $this->context($this->catalog, true);
            if ($primary !== $this->identity || $catalog !== $this->catalogIdentity) {
                self::refuse();
            }
            $this->compareIdentities($catalog, $primary);
        } catch (Throwable) {
            $this->failed = true;
            $this->catalog = null;
            self::refuse();
        }
    }

    private function context(PDO $pdo, bool $companion): array
    {
        if ($companion) {
            self::$companionStatements++;
        }
        $statement = $pdo->query(self::CONTEXT.($companion ? ',@@SESSION.lock_wait_timeout metadata_lock_timeout,@@SESSION.innodb_lock_wait_timeout metadata_row_timeout' : ''));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (! $statement->closeCursor() || \count($rows) !== 1) {
            self::refuse();
        }
        $row = $rows[0];
        foreach (['version', 'server_uuid', 'principal', 'login', 'roles', 'database_name', 'client_endpoint'] as $field) {
            if (! \is_string($row[$field] ?? null) || $row[$field] === '') {
                self::refuse();
            }
        }
        if (! \is_int($row['connection_id'] ?? null) || $row['connection_id'] < 1) {
            self::refuse();
        }
        if (($row['session_isolation'] ?? null) !== 'REPEATABLE-READ' || ($row['autocommit'] ?? null) !== 1
            || ($row['sql_mode'] ?? null) !== self::STRICT_MODE || ($row['time_zone'] ?? null) !== '+00:00'
            || ($row['charset_client'] ?? null) !== 'utf8mb4' || ($row['collation_connection'] ?? null) !== 'utf8mb4_unicode_ci'
            || ($row['charset_results'] ?? null) !== 'utf8mb4' || ($row['foreign_key_checks'] ?? null) !== 1
            || (string) ($row['select_limit'] ?? '') !== '18446744073709551615'
            || ($companion && ($pdo->inTransaction() || ($row['metadata_lock_timeout'] ?? null) !== 1 || ($row['metadata_row_timeout'] ?? null) !== 1))) {
            self::refuse();
        }

        return $row;
    }

    private static function tlsRequest(#[\SensitiveParameter] array $descriptor, array $primary): void
    {
        $cipher = $primary['Ssl_cipher'] ?? null;
        $version = $primary['Ssl_version'] ?? null;
        if (! \is_string($cipher) || ! \is_string($version) || (($cipher === '') !== ($version === ''))) {
            self::refuse();
        }
        $material = false;
        foreach ([Mysql::ATTR_SSL_CA, Mysql::ATTR_SSL_CAPATH, Mysql::ATTR_SSL_CERT, Mysql::ATTR_SSL_KEY, Mysql::ATTR_SSL_CIPHER] as $key) {
            $material = $material || \array_key_exists($key, $descriptor['options']);
        }
        if (($cipher !== '') !== $material || ($cipher !== '' && ($descriptor['options'][Mysql::ATTR_SSL_VERIFY_SERVER_CERT] ?? null) !== true)) {
            self::refuse();
        }
    }

    private function transport(PDO $pdo, bool $companion): array
    {
        $status = $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
        if (! \is_string($status) || $status === '') {
            self::refuse();
        }
        if ($companion) {
            self::$companionStatements++;
        }
        $statement = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher','Ssl_version')");
        $rows = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
        if (! $statement->closeCursor() || \count($rows) !== 2 || ! \array_key_exists('Ssl_cipher', $rows)
            || ! \array_key_exists('Ssl_version', $rows) || ! \is_string($rows['Ssl_cipher']) || ! \is_string($rows['Ssl_version'])) {
            self::refuse();
        }
        \ksort($rows);

        return ['connection_status' => $status, 'tls' => $rows];
    }

    private function compareIdentities(array $catalog, array $primary): void
    {
        unset($catalog['connection_id'], $primary['connection_id'], $catalog['client_endpoint'], $primary['client_endpoint'], $catalog['metadata_lock_timeout'], $catalog['metadata_row_timeout']);
        if ($catalog !== $primary) {
            self::refuse();
        }
    }

    private function rawConfiguration(): array
    {
        $application = Container::getInstance();
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($application);
        $repository = $instances['config'] ?? null;
        if (! $repository instanceof Repository || $repository::class !== Repository::class) {
            self::refuse();
        }
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($repository);
        $database = $items['database'] ?? null;
        if (! \is_array($database) || ! \is_string($database['default'] ?? null) || ! \is_array($database['connections'] ?? null)
            || ! \is_array($database['connections'][$database['default']] ?? null)) {
            self::refuse();
        }
        $resolved = (new ReflectionProperty(Connection::class, 'config'))->getValue($this->connection);
        if (! \is_array($resolved) || ($resolved['name'] ?? null) !== $database['default']) {
            self::refuse();
        }

        return [$database['connections'][$database['default']], $resolved];
    }

    private static function refuse(): never
    {
        throw new LogicException('Current private catalog admission required.');
    }

    public function invalidate(): void
    {
        $this->failed = true;
        $this->catalog = null;
    }

    public function __destruct()
    {
        $this->catalog = null;
    }

    public function __serialize(): never
    {
        self::refuse();
    }

    public function __debugInfo(): array
    {
        return ['catalog' => 'private'];
    }

    private function __clone() {}
}
