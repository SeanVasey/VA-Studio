<?php

namespace App\Domain\Customers\Preferences;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerPrincipal;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;

/** Captured-primary current authority and bounded retained graph; no QueryExecuted callbacks. */
final class ConsentEvidence
{
    private Connection $connection;

    private PDO $primary;

    private string $database;

    private string $driver;

    public function __construct()
    {
        $this->connection = DB::connection();
        $this->primary = $this->connection->getPdo();
        $this->database = $this->connection->getDatabaseName();
        $this->driver = (string) $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (! in_array($this->driver, ['sqlite', 'mysql'], true) || ! $this->primary->inTransaction()) {
            throw new ConsentException(503);
        }
    }

    public function prove(CustomerPrincipal $principal, string $email, array $states, array $events, array $policies, ?array $configuredRange): void
    {
        app(CustomerAccessPolicy::class)->requireEnabled();
        if (DB::connection() !== $this->connection || $this->connection->getPdo() !== $this->primary
            || $this->connection->getDatabaseName() !== $this->database || ! $this->primary->inTransaction()) {
            throw new ConsentException(503);
        }
        $users = $this->rows('users', 'id=?', [$principal->userId], 2);
        $accounts = $this->rows('customer_accounts', 'id=?', [$principal->accountId], 2);
        $key = config('app.key');
        if (count($users) !== 1 || count($accounts) !== 1 || ! is_string($key) || $key === '') {
            throw new CustomerAccessException;
        }
        $user = $users[0];
        $account = $accounts[0];
        if ((int) $user['is_admin'] !== 0 || $user['email_verified_at'] === null || $user['email'] !== $email
            || (int) $account['active'] !== 1 || (int) $account['user_id'] !== $principal->userId
            || (int) $account['access_version'] !== $principal->accessVersion || ! hash_equals($account['owner_key'], $principal->ownerKey)
            || ! hash_equals(hash_hmac('sha256', "customer-credential-v1\0".$user['password'], $key), $principal->credentialStamp)) {
            throw new CustomerAccessException;
        }
        $actualState = $this->rows('customer_consent_states', 'customer_account_id=? AND purpose=?', [$principal->accountId, ConsentPolicy::PURPOSE], 2);
        $actualEvents = $this->rows('customer_consent_events', 'customer_account_id=? AND purpose=?', [$principal->accountId, ConsentPolicy::PURPOSE], 2, 'revision DESC');
        if (self::normalized($actualState) !== self::normalized($states) || self::normalized($actualEvents) !== self::normalized($events)) {
            throw new ConsentException(503);
        }
        foreach ($policies as $policy) {
            if (self::normalized($this->rows('customer_consent_policies', 'id=?', [$policy['id']], 2)) !== self::normalized([$policy])) {
                throw new ConsentException(503);
            }
        }
        if ($configuredRange !== null && self::normalized($this->rows('customer_consent_policies', 'purpose=? AND version=?', [ConsentPolicy::PURPOSE, $configuredRange['version']], 2)) !== self::normalized($configuredRange['rows'])) {
            throw new ConsentException(503);
        }
    }

    public static function normalized(array $rows): array
    {
        return array_map(function (array $row): array {
            $row = array_map(fn ($value) => $value === null ? null : (string) $value, $row);
            ksort($row);

            return $row;
        }, $rows);
    }

    private function rows(string $table, string $where, array $values, int $limit, ?string $order = null): array
    {
        // Table/predicate/order are closed internal literals, never customer inputs.
        if ($this->driver === 'sqlite') {
            $shadow = $this->primary->prepare('SELECT COUNT(*) FROM sqlite_temp_master WHERE lower(name)=?');
            $shadow->execute([$table]);
            if ((int) $shadow->fetchColumn() !== 0) {
                throw new ConsentException(503);
            }
            $qualified = 'main."'.$table.'"';
        } else {
            $qualified = '`'.str_replace('`', '``', $this->database).'`.`'.$table.'`';
            $schema = (array) $this->primary->query('SHOW CREATE TABLE '.$qualified)->fetch(PDO::FETCH_ASSOC);
            if (str_contains(strtoupper((string) ($schema['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
                throw new ConsentException(503);
            }
        }
        $statement = $this->primary->prepare('SELECT * FROM '.$qualified.' WHERE '.$where.($order ? ' ORDER BY '.$order : '').' LIMIT '.$limit.($this->driver === 'mysql' ? ' FOR UPDATE' : ''));
        $statement->execute($values);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
