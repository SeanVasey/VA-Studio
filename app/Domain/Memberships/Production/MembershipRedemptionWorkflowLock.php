<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Grants\Member\MemberOriginalFinishBoundary;
use App\Domain\Grants\Member\MemberOriginalIoFrame;
use PDO;
use Throwable;

/** Native scheduling only. This lock supplies no identity, source, grant or mutation authority. */
final class MembershipRedemptionWorkflowLock
{
    private bool $acquisitionStarted = false;

    private bool $releaseAttempted = false;

    private bool $released = false;

    private function __construct(private readonly PDO $primary, private readonly MemberOriginalIoFrame $idle,
        private readonly MemberOriginalFinishBoundary $finish, private readonly string $schema,
        private readonly int $session, private readonly string $name, private readonly int $originalDeadline) {}

    public static function name(string $schema, string $redemptionId): string
    {
        MembershipValues::id($redemptionId);
        MembershipException::require(preg_match('/\A[a-zA-Z0-9_]+\z/D', $schema) === 1, 'changed_schema');

        return 'va_redemption_'.substr(hash('sha256', $schema."\0".$redemptionId), 0, 48);
    }

    public static function acquire(PDO $primary, string $redemptionId, int $originalDeadline): ?self
    {
        MembershipAwardConfiguration::plainPrimary($primary);
        if ($primary->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return null;
        }
        MembershipException::require($primary->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'changed_primary');
        $idle = MemberOriginalIoFrame::captureIdle();
        MembershipException::require($idle->identityPrimary() === $primary, 'changed_primary');
        $finish = MemberOriginalFinishBoundary::capture();
        $route = $primary->query('SELECT CONNECTION_ID() session_id, DATABASE() schema_name')->fetch(PDO::FETCH_ASSOC);
        MembershipException::require(is_array($route) && is_string($route['schema_name'])
            && (int) $route['session_id'] > 0, 'coordination_refused');
        $lock = new self($primary, $idle, $finish, $route['schema_name'], (int) $route['session_id'],
            self::name($route['schema_name'], $redemptionId), $originalDeadline);
        try {
            $lock->assertDeadline($originalDeadline);
            // MySQL locks are recursive. A pre-existing acquisition by this session is foreign.
            MembershipException::require($lock->owner() !== $lock->session, 'coordination_refused');
            $seconds = min(60, max(0, intdiv($originalDeadline - hrtime(true), 1_000_000_000)));
            $statement = $primary->prepare('SELECT GET_LOCK(?, ?)');
            $lock->acquisitionStarted = true;
            $statement->execute([$lock->name, $seconds]);
            $value = $statement->fetchColumn();
            MembershipException::require($value !== null && $value !== false, 'coordination_refused');
            MembershipException::require((int) $value === 1, 'operation_deadline');
            $lock->assertDeadline($originalDeadline);
            $idle->assertStillIdle();
            MembershipException::require($lock->owner() === $lock->session, 'coordination_refused');

            return $lock;
        } catch (Throwable $error) {
            $lock->closeAfterRefusal();
            throw $error;
        }
    }

    /** Sole native unlock, followed by cached, callback-free admission under every caller cap. */
    public function finish(int $effectiveDeadline): void
    {
        $this->idle->assertStillIdle();
        $this->finish->assertCurrent();
        $this->assertDeadline($effectiveDeadline);
        $this->releaseOwned(true);
        $this->finish->assertCurrent();
        $this->idle->assertStillIdle();
        $this->assertDeadline($effectiveDeadline);
    }

    /** No rollback, commit, route adoption or release of another session's/reentrant lock. */
    public function closeAfterRefusal(): void
    {
        if (! $this->acquisitionStarted || $this->released) {
            return;
        }
        MembershipException::require(! $this->releaseAttempted, 'coordination_refused');
        $owner = $this->owner();
        if ($owner !== $this->session) {
            // GET_LOCK may have timed out without acquiring anything.
            $this->released = true;

            return;
        }
        $this->releaseOwned(false);
    }

    private function releaseOwned(bool $requireOriginalSchema): void
    {
        MembershipException::require(! $this->released && ! $this->releaseAttempted
            && $this->owner($requireOriginalSchema) === $this->session, 'coordination_refused');
        $statement = $this->primary->prepare('SELECT RELEASE_LOCK(?)');
        $this->releaseAttempted = true;
        $statement->execute([$this->name]);
        MembershipException::require((int) $statement->fetchColumn() === 1, 'coordination_refused');
        // A legitimate waiter can acquire immediately. Only continued ownership by US is invalid.
        MembershipException::require($this->owner($requireOriginalSchema) !== $this->session, 'coordination_refused');
        $this->released = true;
    }

    private function owner(bool $requireOriginalSchema = false): ?int
    {
        MembershipAwardConfiguration::plainPrimary($this->primary);
        MembershipException::require($this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'coordination_refused');
        $statement = $this->primary->prepare('SELECT CONNECTION_ID() session_id, DATABASE() schema_name, IS_USED_LOCK(?) owner_id');
        $statement->execute([$this->name]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        MembershipException::require(is_array($row) && (int) $row['session_id'] === $this->session
            && (! $requireOriginalSchema || $row['schema_name'] === $this->schema), 'coordination_refused');

        return $row['owner_id'] === null ? null : (int) $row['owner_id'];
    }

    private function assertDeadline(int $effectiveDeadline): void
    {
        MembershipException::require(hrtime(true) < min($this->originalDeadline, $effectiveDeadline), 'operation_deadline');
    }

    public function __clone(): never
    {
        throw new MembershipException('private_coordination');
    }

    public function __serialize(): never
    {
        throw new MembershipException('private_coordination');
    }

    public function __unserialize(array $data): void
    {
        throw new MembershipException('private_coordination');
    }
}
