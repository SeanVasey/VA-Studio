<?php

namespace App\Domain\Services\Projects\Attachments;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Services\Projects\ServiceProjectInput;
use App\Domain\Services\Projects\ServiceProjectPolicy;
use App\Domain\Services\Projects\ServiceProjects;
use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\AttachmentRows;
use App\Domain\SupportAttachments\AttachmentSourceToken;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\CanonicalJson;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use JsonSerializable;
use LogicException;
use PDO;
use WeakReference;

/** Current source evidence owned by one outer consumer transaction, never a browser capability. */
final class ServiceProjectAttachmentSourceV1 implements AttachmentSourceToken, JsonSerializable
{
    private const PURPOSES = ['intake', 'process', 'list', 'download', 'delete'];

    private const CLOSED = ['withdrawn', 'cancellation_requested', 'cancelled'];

    private bool $valid = true;

    private bool $committed = false;

    private bool $transactionEnded = false;

    private readonly string $transactionMarker;

    private function __construct(private readonly PDO $primary, private readonly string $sourceId,
        private readonly string $purpose, private readonly AttachmentActor $actor, private readonly array $authority,
        private readonly array $graph, private readonly array $binding, private readonly string $actorHash)
    {
        $this->transactionMarker = 'service_source_'.bin2hex(random_bytes(12));
        $this->primary->exec('SAVEPOINT '.$this->transactionMarker);
        $weak = WeakReference::create($this);
        $connection = DB::connection();
        foreach ([TransactionCommitted::class, TransactionRolledBack::class] as $event) {
            Event::listen($event, static function ($event) use ($weak, $connection): void {
                if ($event->connection === $connection && ($token = $weak->get())) {
                    $token->valid = false;
                    if (! $token->transactionEnded) {
                        $token->transactionEnded = true;
                        $token->committed = $event instanceof TransactionCommitted
                            && $connection->transactionLevel() === 0 && ! $token->primary->inTransaction();
                    }
                }
            });
        }
    }

    public static function lock(string $sourceId, ?int $expectedVersion, string $purpose, AttachmentActor $actor, AttachmentRows $rows): self
    {
        $rows->assertCurrent();
        AttachmentException::require(DB::transactionLevel() === 1, 503);
        app(ServiceProjectPolicy::class)->requireEnabled();
        ServiceProjectInput::key($sourceId);
        AttachmentException::require(in_array($purpose, self::PURPOSES, true), 404);
        AttachmentException::require($expectedVersion === null || $expectedVersion >= 0 && $expectedVersion <= 1000, 409);
        AttachmentException::require($purpose !== 'intake' || $expectedVersion !== null, 409);
        $authority = self::authority($actor, $rows);
        $graph = self::graph($sourceId, $rows);
        if ($actor->audience === 'customer') {
            AttachmentException::require((int) $graph['project']['customer_account_id'] === $actor->principal->accountId, 404);
        }
        $state = app(ServiceProjects::class)->attachmentGraphState($graph['project'], $graph['events']);
        AttachmentException::require($expectedVersion === null || $state['version'] === $expectedVersion, 409);
        AttachmentException::require(! in_array($purpose, ['intake', 'process'], true) || ! in_array($state['status'], self::CLOSED, true), 409);
        $parent = $graph['project'];
        $binding = ['schema_version' => 'support-source-v1', 'family' => 'test_service_project_v1', 'kind' => 'service_project', 'public_id' => $sourceId,
            'source_numeric_id' => (int) $parent['id'], 'owner_binding' => ['kind' => 'customer_account', 'id' => (int) $parent['customer_account_id']],
            'origin' => 'test-service-project-v1', 'parent_hash' => CanonicalJson::hash($parent),
            'brief_hash' => $parent['brief_hash'], 'service_hash' => $parent['service_hash'], 'service_version_id' => (int) $parent['service_version_id'],
            'accepted_source_version' => $state['version'], 'source_graph_hash' => CanonicalJson::hash($graph),
            'intake_open' => ! in_array($state['status'], self::CLOSED, true),
            'policy_version' => 'service-attachment-source-v1', 'policy_hash' => CanonicalJson::hash(['version' => 1, 'purposes' => self::PURPOSES, 'closed' => self::CLOSED, 'retained_access' => ['list', 'download', 'delete'], 'provenance' => 'test-service-project-v1'])];
        $actorHash = CanonicalJson::hash(['audience' => $actor->audience, 'user_id' => (int) $authority['user']['id'],
            'account_id' => $authority['account'] === null ? null : (int) $authority['account']['id']]);
        $token = new self($rows->identity(), $sourceId, $purpose, $actor, $authority, $graph, $binding, $actorHash);
        $token->proveCurrent($rows);

        return $token;
    }

    public function proveCurrent(AttachmentRows $rows): void
    {
        $rows->assertCurrent();
        AttachmentException::require($this->valid && $rows->identity() === $this->primary && DB::transactionLevel() === 1, 503);
        // Container resolution itself may invoke callbacks. Resolve dependencies
        // before the terminal actor/account/source bytes, never after them.
        $policy = app(ServiceProjectPolicy::class);
        $customerPolicy = $this->actor->audience === 'customer' ? app(CustomerAccessPolicy::class) : null;
        // Gate/MFA may query framework models. Their callbacks precede the terminal raw fence.
        self::staffPolicy($this->actor, $this->authority['user']);
        // A private savepoint disappears even on a direct PDO commit/reopen that bypasses
        // Laravel transaction events. Releasing it never rolls back consumer writes.
        $this->primary->exec('RELEASE SAVEPOINT '.$this->transactionMarker);
        $this->primary->exec('SAVEPOINT '.$this->transactionMarker);
        $current = self::authority($this->actor, $rows, false, $customerPolicy);
        AttachmentException::require($current === $this->authority, 403);
        AttachmentException::require(self::graph($this->sourceId, $rows) === $this->graph, 409);
        // Admission may be withdrawn by the last framework query or MFA provider.
        // Recheck after every callback-capable policy operation and raw evidence read.
        $policy->requireEnabled();
        $customerPolicy?->requireEnabled();
        $rows->assertCurrent();
    }

    public function authorizeMutation(int $expectedVersion, string $purpose, AttachmentRows $rows): void
    {
        AttachmentException::require(in_array($purpose, ['intake', 'process'], true)
            && ($this->purpose === 'list' || $this->purpose === $purpose), 403);
        AttachmentException::require($expectedVersion >= 0 && $expectedVersion <= 1000
            && $this->version() === $expectedVersion && $this->binding['intake_open'] === true, 409);
        $this->proveCurrent($rows);
    }

    /** Mint only during the original live proof. The returned closure cannot renew that token. */
    public function captureCommittedRead(AttachmentRows $rows): Closure
    {
        AttachmentException::require(in_array($this->purpose, ['list', 'download'], true), 403);
        $rows->assertCurrent();
        AttachmentException::require($this->valid && $rows->identity() === $this->primary && DB::transactionLevel() === 1);
        // Resolve every framework/container dependency before the existing terminal proof.
        $application = app();
        $config = app('config');
        $manager = DB::getFacadeRoot();
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $prefix = $connection->getTablePrefix();
        $name = $connection->getName();
        $database = $driver === 'mysql' ? $this->primary->query('SELECT DATABASE()')->fetchColumn() : 'main';
        $environment = $application->environment();
        $flags = [$config->get('services-projects.test_enabled'), $config->get('customer.test_accounts_enabled')];
        $credentialKey = $config->get('app.key');
        AttachmentException::require(is_string($credentialKey) && $credentialKey !== '', 403);
        $credentialKeyHash = hash('sha256', $credentialKey);
        $keyMatches = static function () use ($config, $credentialKeyHash): bool {
            $key = $config->get('app.key');

            return is_string($key) && $key !== '' && hash_equals($credentialKeyHash, hash('sha256', $key));
        };
        $panel = $this->actor->audience === 'operator' ? Filament::getPanel('admin') : null;
        $gate = $this->actor->audience === 'operator' ? Gate::getFacadeRoot() : null;
        $panelPolicy = $panel === null ? null : [$panel->isMultiFactorAuthenticationRequired(), $panel->getMultiFactorAuthenticationProviders()];
        $tables = [];
        foreach (['users', 'customer_accounts', 'service_projects', 'service_project_events'] as $table) {
            $tables[$table] = $rows->table($table);
        }
        $this->proveCurrent($rows);
        AttachmentException::require([$config->get('services-projects.test_enabled'), $config->get('customer.test_accounts_enabled')] === $flags, 404);
        AttachmentException::require($keyMatches(), 403);

        return function () use ($application, $config, $manager, $connection, $driver, $prefix, $name, $database, $environment, $flags, $panel, $gate, $panelPolicy, $tables, $keyMatches): void {
            AttachmentException::require($this->committed && ! $this->valid, 503);
            // These getters and Gate/MFA providers can evaluate arbitrary closures. All run
            // before the final permanent actor/source reads; this phase is callback-capable.
            AttachmentException::require(app() === $application && app('config') === $config && DB::getFacadeRoot() === $manager
                && DB::connection() === $connection, 503);
            AttachmentException::require($connection->transactionLevel() === 0 && ! $this->primary->inTransaction(), 503);
            if ($this->actor->audience === 'operator') {
                AttachmentException::require(Gate::getFacadeRoot() === $gate && Filament::getPanel('admin') === $panel, 403);
                $user = self::hydrate($this->authority['user']);
                AttachmentException::require($gate->forUser($user)->allows('administer-catalog', [false])
                    && AdminMultiFactor::satisfiedBy($user, $panel, lockForUpdate: false), 403);
                AttachmentException::require([$panel->isMultiFactorAuthenticationRequired(), $panel->getMultiFactorAuthenticationProviders()] === $panelPolicy, 403);
            }
            $currentEnvironment = $application->environment();
            AttachmentException::require($this->actor->user?->exists === true
                && (int) $this->actor->user->getKey() === (int) $this->authority['user']['id'], 403);
            AttachmentException::require(app('config') === $config && DB::getFacadeRoot() === $manager && DB::connection() === $connection, 503);
            $assertClosed = function () use ($manager, $connection, $driver, $prefix, $name, $database): void {
                // Pure cached framework/primary identity checks, with no connection resolver.
                AttachmentException::require($manager->getDefaultConnection() === $name
                    && ($manager->getConnections()[$name] ?? null) === $connection
                    && $connection->getRawPdo() === $this->primary && $connection->getDriverName() === $driver
                    && $connection->getTablePrefix() === $prefix && $connection->transactionLevel() === 0
                    && ! $this->primary->inTransaction(), 503);
                if ($driver === 'mysql') {
                    AttachmentException::require($this->primary->query('SELECT DATABASE()')->fetchColumn() === $database, 503);
                }
            };
            $assertClosed();
            foreach (array_keys($tables) as $logical) {
                $physical = $prefix.$logical;
                if ($driver === 'sqlite') {
                    $query = $this->primary->prepare('SELECT 1 FROM sqlite_temp_master WHERE name = ? COLLATE NOCASE LIMIT 1');
                    $query->execute([$physical]);
                    AttachmentException::require($query->fetchColumn() === false, 503);
                    $query = $this->primary->prepare('SELECT type, name FROM main.sqlite_master WHERE name = ? COLLATE NOCASE');
                    $query->execute([$physical]);
                    AttachmentException::require($query->fetchAll(PDO::FETCH_NUM) === [['table', $physical]], 503);
                } else {
                    $definition = $this->primary->query('SHOW CREATE TABLE '.$tables[$logical])->fetch(PDO::FETCH_NUM);
                    AttachmentException::require(is_array($definition) && ! str_contains(strtoupper($definition[1]), 'CREATE TEMPORARY TABLE'), 503);
                }
            }
            $read = function (string $table, string $where, array $bindings, int $limit) use ($tables): array {
                $statement = $this->primary->prepare('SELECT * FROM '.$tables[$table].' WHERE '.$where.' ORDER BY id LIMIT '.$limit);
                $statement->execute($bindings);

                return $statement->fetchAll(PDO::FETCH_ASSOC);
            };
            $users = $read('users', 'id = ?', [(int) $this->authority['user']['id']], 2);
            $account = $this->authority['account'];
            $accounts = $account === null ? [] : $read('customer_accounts', 'id = ?', [(int) $account['id']], 2);
            AttachmentException::require($users === [$this->authority['user']] && $accounts === ($account === null ? [] : [$account]), 403);
            $projects = $read('service_projects', 'public_id = ?', [$this->sourceId], 2);
            $events = $read('service_project_events', 'project_id = ?', [(int) $this->graph['project']['id']], 1001);
            usort($events, fn (array $first, array $second): int => (int) $first['number'] <=> (int) $second['number']);
            AttachmentException::require($projects === [$this->graph['project']] && $events === $this->graph['events'], 409);
            // No service/container/model/provider resolution follows these raw evidence reads.
            AttachmentException::require($keyMatches(), 403);
            AttachmentException::require($currentEnvironment === $environment && in_array($currentEnvironment, ['local', 'testing'], true)
                && $config->get('services-projects.test_enabled') === true
                && [$config->get('services-projects.test_enabled'), $config->get('customer.test_accounts_enabled')] === $flags
                && ($this->actor->audience !== 'customer' || $config->get('customer.test_accounts_enabled') === true), 404);
            $assertClosed();
        };
    }

    private static function authority(AttachmentActor $actor, AttachmentRows $rows, bool $frameworkPolicy = true, ?CustomerAccessPolicy $customerPolicy = null): array
    {
        AttachmentException::require(in_array($actor->audience, ['customer', 'operator'], true) && $actor->user?->exists === true, 403);
        $userId = (int) $actor->user->getKey();
        AttachmentException::require($userId > 0, 403);
        $access = null;
        if ($actor->audience === 'customer') {
            $customerPolicy ??= app(CustomerAccessPolicy::class);
            $access = app(CustomerAccess::class);
            $customerPolicy->requireEnabled();
        }
        $rows->assertCurrent();
        $user = $rows->one('users', 'id = ?', [$userId]);
        AttachmentException::require($user !== [] && $user['email_verified_at'] !== null, 403);
        $account = null;
        if ($actor->audience === 'customer') {
            $principal = $actor->principal;
            AttachmentException::require($principal instanceof CustomerPrincipal && $principal->userId === $userId && ! $user['is_admin'], 403);
            $account = $rows->one('customer_accounts', 'id = ?', [$principal->accountId]);
            $hydrated = self::hydrate($user);
            AttachmentException::require($account !== [] && $account['active'] && (int) $account['user_id'] === $userId
                && (int) $account['access_version'] === $principal->accessVersion && $principal->accessVersion >= 1
                && hash_equals($account['owner_key'], $principal->ownerKey)
                && hash_equals($access->stamp($hydrated), $principal->credentialStamp), 403);
        } else {
            AttachmentException::require($actor->principal === null && $user['is_admin'], 403);
            if ($frameworkPolicy) {
                self::staffPolicy($actor, $user);
            }
        }

        return compact('user', 'account');
    }

    private static function staffPolicy(AttachmentActor $actor, array $rawUser): void
    {
        if ($actor->audience !== 'operator') {
            return;
        }
        $user = self::hydrate($rawUser);
        AttachmentException::require(Gate::forUser($user)->allows('administer-catalog', [true])
            && AdminMultiFactor::satisfiedBy($user, lockForUpdate: true), 403);
    }

    private static function hydrate(array $row): User
    {
        $user = new User;
        $user->setRawAttributes($row, true);
        $user->exists = true;

        return $user;
    }

    private static function graph(string $sourceId, AttachmentRows $rows): array
    {
        $project = $rows->one('service_projects', 'public_id = ?', [$sourceId]);
        AttachmentException::require($project !== [], 404);
        $events = $rows->current()->rows($rows->table('service_project_events'), 'project_id = ?', [(int) $project['id']], 1001);
        AttachmentException::require(count($events) <= 1000, 503);
        usort($events, fn (array $first, array $second): int => (int) $first['number'] <=> (int) $second['number']);

        return compact('project', 'events');
    }

    public function binding(): array
    {
        return $this->binding;
    }

    public function originBinding(): array
    {
        return array_diff_key($this->binding, array_flip(['accepted_source_version', 'source_graph_hash', 'intake_open']));
    }

    public function version(): int
    {
        return $this->binding['accepted_source_version'];
    }

    public function actorBinding(): string
    {
        return $this->actorHash;
    }

    public function __serialize(): array
    {
        throw new LogicException('Service source evidence cannot be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Service source evidence is not a browser projection.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => $this->purpose, 'version' => $this->version(), 'active' => $this->valid];
    }

    private function __clone() {}
}
