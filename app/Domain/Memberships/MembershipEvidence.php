<?php

namespace App\Domain\Memberships;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerPrincipal;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use PDO;

/** Internal current authority and raw evidence; no owner keys or credentials leave the module. */
final class MembershipEvidence
{
    private ?PDO $primary = null;

    private ?string $driver = null;

    public function operator(User $actor, ?int $customerUserId = null): array
    {
        $this->capturePrimary();
        $ids = array_values(array_unique([$actor->exists ? (int) $actor->getKey() : 0, ...($customerUserId === null ? [] : [$customerUserId])]));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            if ($id < 1 || User::query()->lockForUpdate()->find($id) === null) {
                throw new AuthorizationException;
            }
        }
        $rows = $this->users($ids);
        $this->recheckOperator($actor);
        $this->sameUsers($rows);

        return $rows;
    }

    public function buyer(CustomerPrincipal $principal, User $actor): array
    {
        $this->capturePrimary();
        app(CustomerAccess::class)->lock($principal, $principal->ownerKey, $actor);
        $users = $this->users([$principal->userId]);
        $account = $this->account($principal->accountId);
        $user = $users[$principal->userId];
        $stamp = hash_hmac('sha256', "customer-credential-v1\0".$user['password'], (string) config('app.key'));
        if ((bool) $user['is_admin'] || $user['email_verified_at'] === null || ! (bool) $account['active']
            || (int) $account['user_id'] !== $principal->userId || (int) $account['access_version'] !== $principal->accessVersion
            || ! hash_equals($account['owner_key'], $principal->ownerKey) || ! hash_equals($stamp, $principal->credentialStamp)) {
            throw new AuthorizationException;
        }

        return ['users' => $users, 'account' => $account];
    }

    public function account(int $id, ?int $userId = null): array
    {
        $row = $this->row('customer_accounts', $id);
        $user = $this->row('users', (int) $row['user_id']);
        if (($userId !== null && (int) $row['user_id'] !== $userId) || ! (bool) $row['active'] || (int) $row['access_version'] < 1
            || (bool) $user['is_admin'] || $user['email_verified_at'] === null) {
            throw new AuthorizationException;
        }

        return $row;
    }

    public function recheckOperator(User $actor): void
    {
        app(MembershipPolicy::class)->requireEnabled();
        $current = User::query()->lockForUpdate()->find($actor->getKey());
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }
    }

    public function users(array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = $this->row('users', $id);
        }

        return $rows;
    }

    public function sameUsers(array $rows): void
    {
        foreach ($rows as $id => $row) {
            $this->same('users', (int) $id, $row);
        }
    }

    public function row(string $table, int $id): array
    {
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        if ($row === null) {
            app(MembershipPolicy::class)->reject('membership', 'Retained membership evidence is unavailable.');
        }

        return (array) $row;
    }

    public function same(string $table, int $id, array $expected): void
    {
        if ($this->row($table, $id) !== $expected) {
            app(MembershipPolicy::class)->reject('membership', 'Membership evidence changed during the command.');
        }
    }

    public function cursor(string $type, int $id): ?int
    {
        $value = DB::table('audit_events')->where('subject_type', $type)->where('subject_id', $id)->orderByDesc('id')->lockForUpdate()->value('id');

        return $value === null ? null : (int) $value;
    }

    public function audit(string $action, string $type, int $id, array $context, int $actorId): array
    {
        $at = now()->utc()->startOfSecond()->format('Y-m-d H:i:s');
        $event = AuditEvent::create(['actor_id' => $actorId, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id,
            'context' => $context, 'created_at' => $at]);
        $row = $this->row('audit_events', (int) $event->getKey());
        if ((int) $row['actor_id'] !== $actorId || $row['action'] !== $action || $row['subject_type'] !== $type || (int) $row['subject_id'] !== $id
            || CanonicalJson::hash(json_decode($row['context'], true, 32, JSON_THROW_ON_ERROR)) !== CanonicalJson::hash($context)
            || $row['created_at'] !== $at
            || $this->cursor($type, $id) !== (int) $event->getKey()) {
            app(MembershipPolicy::class)->reject('membership', 'The exact membership audit was not retained.');
        }

        return $row;
    }

    public function auditRows(string $type, int $id): array
    {
        $rows = DB::table('audit_events')->where('subject_type', $type)->where('subject_id', $id)->orderBy('id')
            ->limit(MembershipPolicy::MAX_EVENTS * 2 + 1)->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
        if (count($rows) > MembershipPolicy::MAX_EVENTS * 2) {
            app(MembershipPolicy::class)->reject('membership', 'Retain this bounded audit history for separate review.');
        }

        return $rows;
    }

    public function prove(array $users, ?array $account, array $rows, ?array $audit, array $ranges = [], array $cursors = []): void
    {
        // Capture was before application callbacks. Reuse that primary transaction directly:
        // QueryBuilder raw reads still dispatch QueryExecuted and are not a terminal proof.
        if ($this->primary === null || ! $this->primary->inTransaction()) {
            throw new LogicException('Membership final proof requires its captured primary transaction.');
        }
        foreach ($users as $id => $row) {
            $this->primarySame('users', (int) $id, $row);
        }
        if ($account !== null) {
            $this->primarySame('customer_accounts', (int) $account['id'], $account);
        }
        foreach ($rows as [$table, $id, $expected]) {
            $this->primarySame($table, $id, $expected);
        }
        foreach ($ranges as [$table, $where, $order, $limit, $expected]) {
            if ($this->primaryRows($table, $where, $order, $limit) !== $expected) {
                app(MembershipPolicy::class)->reject('membership', 'Unexpected membership evidence in the retained range.');
            }
        }
        foreach ($cursors as [$type, $id, $expected]) {
            $latest = $this->primaryRows('audit_events', ['subject_type' => $type, 'subject_id' => $id], 'id', 1, true);
            if (($latest === [] ? null : (int) $latest[0]['id']) !== $expected) {
                app(MembershipPolicy::class)->reject('membership', 'Unexpected extra membership audit evidence.');
            }
        }
        if ($audit !== null) {
            $this->primarySame('audit_events', (int) $audit['id'], $audit);
            $latest = $this->primaryRows('audit_events', ['subject_type' => $audit['subject_type'], 'subject_id' => (int) $audit['subject_id']], 'id', 1, true);
            if ($latest === [] || (int) $latest[0]['id'] !== (int) $audit['id']) {
                app(MembershipPolicy::class)->reject('membership', 'Unexpected extra membership audit evidence.');
            }
        }
    }

    private function capturePrimary(): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() === 0 || ! in_array($connection->getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new LogicException('Membership authority requires its own supported transaction.');
        }
        $this->primary = $connection->getPdo();
        $this->driver = $connection->getDriverName();
    }

    private function primarySame(string $table, int $id, array $expected): void
    {
        $actual = $this->primaryRows($table, ['id' => $id], 'id', 2);
        if (count($actual) !== 1 || $actual[0] !== $expected) {
            app(MembershipPolicy::class)->reject('membership', 'Membership evidence changed during the command.');
        }
    }

    private function primaryRows(string $table, array $where, string $order, int $limit, bool $descending = false): array
    {
        $allowed = [
            'users' => ['id'], 'customer_accounts' => ['id'], 'membership_plans' => ['id'],
            'membership_plan_versions' => ['id', 'membership_plan_id', 'number'],
            'membership_credit_buckets' => ['id'],
            'membership_credit_events' => ['id', 'membership_credit_bucket_id', 'sequence'],
            'audit_events' => ['id', 'subject_type', 'subject_id'],
        ];
        if (! isset($allowed[$table]) || $where === [] || array_diff(array_keys($where), $allowed[$table]) !== []
            || ! in_array($order, $allowed[$table], true) || $limit < 1 || $limit > MembershipPolicy::MAX_EVENTS * 2 + 1) {
            throw new LogicException('Unsupported membership primary proof selector.');
        }
        $quote = $this->driver === 'mysql' ? chr(96) : '"';
        $identifier = fn ($name) => $quote.$name.$quote;
        $sql = 'SELECT * FROM '.$identifier($table).' WHERE '.implode(' AND ', array_map(fn ($field) => $identifier($field).' = ?', array_keys($where)))
            .' ORDER BY '.$identifier($order).($descending ? ' DESC' : ' ASC').' LIMIT '.$limit.($this->driver === 'mysql' ? ' FOR UPDATE' : '');
        $statement = $this->primary->prepare($sql);
        $statement->execute(array_values($where));

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
