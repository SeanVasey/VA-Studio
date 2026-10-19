<?php

namespace App\Domain\Customers\ProductionIdentity;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;
use Throwable;
use WeakMap;
use WeakReference;

/**
 * Frame-scoped memo of full schema-ownership proofs for one owned outer transaction.
 *
 * Without an open frame for the exact PDO, prove() runs the guard's full proof, exactly as before. An owned frame
 * (`MembershipAwardTransaction`) opens one immediately after its random savepoint marker exists. Inside it, a guard's
 * full proof runs once, after every base table that proof inspects has been opened in this transaction, and is reused
 * only while the frame token captured before that proof is unchanged:
 *
 * - the owner's witness (its marker, released and re-set) proves the same physical transaction on the same
 *   connection and PDO; a commit, rollback, implicit commit or lost marker fails it and closes this frame for good;
 * - MySQL: the session inputs a statement can change without committing: the connection id, selected database,
 *   foreign_key_checks, sql_mode and the session CREATE/DROP TABLE counters. CREATE TEMPORARY TABLE is the only
 *   way MySQL shadows a table without an implicit commit, and every such path increments Com_create_table;
 * - SQLite: the complete main and temp catalogs and the attached database list. SQLite DDL is transactional, so the
 *   same connection can change any schema object without ending the transaction; there are no metadata locks.
 *
 * On MySQL, an opened table keeps a shared metadata lock until the transaction ends, so another session's ALTER,
 * RENAME, DROP, CREATE INDEX, CREATE or DROP TRIGGER on it, or a new foreign key that references it, waits for this
 * commit or rollback. Creating a view, a routine or a trigger on another table takes no such lock, so every memo hit
 * on MySQL repeats the identity cross-schema dependents scan. terminal() disables the memo for the rest of the frame:
 * the owned terminal pre-commit phase proves every guard in full, as it did before this memo existed.
 *
 * Nothing here outlives its owner: the registry holds weak references, the memo lives in this instance, and a frame
 * whose witness fails is closed and forgotten.
 */
final class SchemaProofFrame
{
    /** @var WeakMap<PDO, WeakReference<self>>|null */
    private static ?WeakMap $frames = null;

    /** @var array<string, string> guard => frame token captured before its last complete proof */
    private array $proofs = [];

    private bool $terminal = false;

    private bool $closed = false;

    private function __construct(private readonly Connection $connection, private readonly PDO $primary,
        private readonly string $driver, private readonly Closure $witness) {}

    /**
     * Register the owned frame for its exact PDO. $witness must throw unless the owner's original transaction is
     * still the current one on $connection; it is called before every memo decision.
     */
    public static function open(Connection $connection, PDO $primary, Closure $witness): self
    {
        $driver = $primary->getAttribute(PDO::ATTR_DRIVER_NAME);
        $frame = new self($connection, $primary, is_string($driver) ? $driver : '', $witness);
        if (! in_array($driver, ['mysql', 'sqlite'], true) || $connection->getRawPdo() !== $primary || ! $primary->inTransaction()) {
            // Never remembers anything; every guard keeps proving in full.
            $frame->closed = true;

            return $frame;
        }
        self::$frames ??= new WeakMap;
        self::$frames[$primary] = WeakReference::create($frame);

        return $frame;
    }

    /**
     * Run $prove (the guard's unchanged complete proof) unless the open owned frame for exactly this PDO already holds
     * a proof of $guard under the current frame token. $tables are the base tables $prove inspects; they are opened
     * in the transaction before the proof so that their definitions stay locked for the rest of the frame.
     *
     * @param  list<string>  $tables
     */
    public static function prove(PDO $primary, string $guard, array $tables, Closure $prove): void
    {
        $frame = self::$frames !== null && isset(self::$frames[$primary]) ? self::$frames[$primary]->get() : null;
        if (! $frame instanceof self || $frame->primary !== $primary) {
            $prove();

            return;
        }
        $frame->consult($guard, $tables, $prove);
    }

    /** Identity base tables whose definitions the identity ownership proof and IdentityRows inspect. */
    public static function identityTables(): array
    {
        return ['users', 'customer_accounts', 'quote_owners', 'audit_events', ...array_keys(IdentitySchema::definitions())];
    }

    /** The owned terminal phase: every later proof in this frame is complete, and none is remembered. */
    public function terminal(): void
    {
        $this->terminal = true;
        $this->proofs = [];
    }

    /** Forget every proof and unregister; a closed frame never answers from memory again. */
    public function close(): void
    {
        $this->closed = true;
        $this->proofs = [];
        if (self::$frames !== null && isset(self::$frames[$this->primary]) && self::$frames[$this->primary]->get() === $this) {
            unset(self::$frames[$this->primary]);
        }
    }

    /** @param  list<string>  $tables */
    private function consult(string $guard, array $tables, Closure $prove): void
    {
        $token = $this->terminal || $this->closed ? null : $this->token();
        if ($token === null) {
            $prove();

            return;
        }
        if (($this->proofs[$guard] ?? null) === $token) {
            if ($this->driver === 'mysql') {
                (new IdentityMigrationOwnership)->assertNoDependents($this->primary);
            }

            return;
        }
        unset($this->proofs[$guard]);
        $pinned = $this->pin($tables);
        $prove();
        // Remember only a proof whose tables are all held and whose token did not move while it ran.
        if ($pinned && $this->token() === $token) {
            $this->proofs[$guard] = $token;
        }
    }

    /** Null when the frame is no longer the owner's current transaction, or its session cannot be read. */
    private function token(): ?string
    {
        try {
            if (DB::connection() !== $this->connection || $this->connection->getRawPdo() !== $this->primary
                || $this->connection->transactionLevel() !== 1 || ! $this->primary->inTransaction()
                || $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) !== $this->driver) {
                $this->close();

                return null;
            }
            ($this->witness)();
        } catch (Throwable) {
            $this->close();

            return null;
        }
        try {
            if ($this->driver === 'mysql') {
                $session = $this->primary->query('SELECT CONNECTION_ID(), DATABASE(), @@SESSION.foreign_key_checks, @@SESSION.sql_mode')->fetchAll(PDO::FETCH_NUM);
                $counters = $this->primary->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_create_table', 'Com_drop_table')")->fetchAll(PDO::FETCH_NUM);
                $names = array_column($counters, 0);
                sort($names);
                if (count($session) !== 1 || $names !== ['Com_create_table', 'Com_drop_table']) {
                    return null;
                }
                $state = [$session, $counters];
            } else {
                // No WHERE or ORDER BY: these catalog reads call no function and compare no text.
                $state = [$this->primary->query('SELECT type, name, tbl_name, sql FROM main.sqlite_master')->fetchAll(PDO::FETCH_NUM),
                    $this->primary->query('SELECT type, name, tbl_name, sql FROM sqlite_temp_master')->fetchAll(PDO::FETCH_NUM),
                    $this->primary->query('PRAGMA database_list')->fetchAll(PDO::FETCH_NUM)];
            }
        } catch (Throwable) {
            return null;
        }

        return hash('sha256', serialize($state));
    }

    /**
     * MySQL: open every inspected base table before the proof reads its definition. A shadowing temporary table takes
     * no metadata lock, but the proof that follows refuses any temporary shadow. SQLite has no metadata locks.
     *
     * @param  list<string>  $tables
     */
    private function pin(array $tables): bool
    {
        if ($this->driver !== 'mysql') {
            return true;
        }
        foreach ($tables as $table) {
            if (preg_match('/\A[a-z0-9_]+\z/D', $table) !== 1) {
                return false;
            }
            try {
                $this->primary->query('SELECT * FROM `'.$table.'` LIMIT 0')->fetchAll(PDO::FETCH_NUM);
            } catch (PDOException) {
                return false;
            }
        }

        return true;
    }

    public function __clone(): void
    {
        throw new LogicException('Schema proofs are never copied.');
    }

    public function __serialize(): never
    {
        throw new LogicException('Schema proofs are never serialized.');
    }
}
