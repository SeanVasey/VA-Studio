<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityRows;
use App\Domain\Memberships\Production\MembershipAwardTransaction;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use App\Domain\Memberships\Production\MembershipSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipReservationFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Frame-scoped schema proofs (SchemaProofFrame): a complete proof is reused only inside its owned frame, on its own
 * connection, while nothing a same-session statement can change has moved, and never in the terminal phase; every
 * reuse still sees new dependent objects. Every case runs on both drivers; the MySQL legs use a separate session where
 * SQLite has only one connection.
 */
class MembershipSchemaProofFrameTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipReservationFixtures;

    /** A reserved identity index name used as a table: a namespace collision only a complete identity proof sees. */
    private const COLLISION = 'pia_address_unique';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reservationSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_temporary_shadow_made_after_a_remembered_proof_is_refused_by_both_guards(): void
    {
        $frame = $this->ownedFrame();
        $pdo = DB::connection()->getPdo();
        try {
            $first = $this->statements(fn () => $this->guards($pdo));
            $remembered = $this->statements(fn () => $this->guards($pdo));
            if ($first !== null) {
                // Measured on MySQL 8.4.11: the remembered pair costs a small fraction of the complete pair.
                $this->assertLessThan(intdiv($first, 4), $remembered);
            }
            $pdo->exec(DB::getDriverName() === 'mysql' ? 'CREATE TEMPORARY TABLE customer_accounts (id BIGINT UNSIGNED PRIMARY KEY)'
                : 'CREATE TEMP TABLE customer_accounts (id INTEGER PRIMARY KEY)');
            try {
                $this->guardsRefusedIn($frame, $pdo);
            } finally {
                $pdo->exec(DB::getDriverName() === 'mysql' ? 'DROP TEMPORARY TABLE customer_accounts' : 'DROP TABLE temp.customer_accounts');
            }
            $this->guards($pdo);
            $this->assertInstanceOf(MembershipAwardTransaction::class, $frame);
        } finally {
            DB::rollBack();
        }
    }

    public function test_a_dependent_view_created_after_a_remembered_proof_is_refused_at_the_next_guard(): void
    {
        $frame = $this->ownedFrame();
        $pdo = DB::connection()->getPdo();
        // Another session on MySQL: creating a view takes no metadata lock on the tables it reads.
        $other = DB::getDriverName() === 'mysql' ? $this->independentConnection() : null;
        try {
            $this->guards($pdo);
            $this->guards($pdo);
            $sql = 'CREATE VIEW synthetic_origin_view AS SELECT id FROM production_identity_origins';
            $other === null ? $pdo->exec($sql) : $other->exec($sql);
            $this->guardsRefusedIn($frame, $pdo);
        } finally {
            DB::rollBack();
            $other?->exec('DROP VIEW IF EXISTS synthetic_origin_view');
        }
        $this->guardsOutsideAFrame();
    }

    public function test_a_session_change_after_a_remembered_proof_sends_the_guards_back_to_a_complete_proof(): void
    {
        $frame = $this->ownedFrame();
        $pdo = DB::connection()->getPdo();
        try {
            $this->guards($pdo);
            $this->guards($pdo);
            if (DB::getDriverName() === 'mysql') {
                // SET never commits, so the marker survives; the complete proof requires foreign_key_checks = 1.
                $pdo->exec('SET SESSION foreign_key_checks=0');
                try {
                    $this->guardsRefusedIn($frame, $pdo);
                } finally {
                    $pdo->exec('SET SESSION foreign_key_checks=1');
                }
            } else {
                // SQLite ignores this pragma inside a transaction, so the remembered proof stays true.
                $pdo->exec('PRAGMA foreign_keys=OFF');
                $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
            }
            $this->guards($pdo);
        } finally {
            DB::rollBack();
        }
    }

    public function test_a_remembered_proof_is_never_used_by_another_connection(): void
    {
        $first = DB::getDefaultConnection();
        $frame = $this->ownedFrame();
        $this->guards(DB::connection()->getPdo());
        DB::rollBack();
        config(['database.connections.schema_proof_second' => config('database.connections.'.$first)]);
        try {
            DB::setDefaultConnection('schema_proof_second');
            $second = DB::connection()->getPdo();
            $this->assertInstanceOf(MembershipAwardTransaction::class, $frame);
            if (DB::getDriverName() === 'sqlite') {
                // A separate in-memory database with byte-identical catalogs, only foreign keys off.
                $source = DB::connection($first)->getPdo();
                foreach ($source->query("SELECT name, sql FROM main.sqlite_master WHERE sql IS NOT NULL ORDER BY rowid")->fetchAll(PDO::FETCH_NUM) as [$name, $sql]) {
                    if (! str_starts_with($name, 'sqlite_')) {
                        $second->exec($sql);
                    }
                }
                $this->assertSame($source->query('SELECT type, name, tbl_name, sql FROM main.sqlite_master')->fetchAll(PDO::FETCH_NUM),
                    $second->query('SELECT type, name, tbl_name, sql FROM main.sqlite_master')->fetchAll(PDO::FETCH_NUM));
                $second->exec('PRAGMA foreign_keys=OFF');
            } else {
                $second->exec('SET SESSION foreign_key_checks=0');
            }
            $secondFrame = $this->ownedFrame();
            try {
                $this->guardsRefusedIn($secondFrame, $second);
            } finally {
                DB::rollBack();
            }
        } finally {
            DB::setDefaultConnection($first);
            DB::purge('schema_proof_second');
        }
    }

    public function test_a_remembered_proof_ends_with_its_transaction(): void
    {
        $frame = $this->ownedFrame();
        $pdo = DB::connection()->getPdo();
        $this->guards($pdo);
        DB::commit();
        $other = null;
        if (DB::getDriverName() === 'sqlite') {
            // Effective only outside a transaction, so it can differ only across frames.
            $pdo->exec('PRAGMA foreign_keys=OFF');
        } else {
            $other = $this->independentConnection();
            $other->exec('CREATE TABLE `'.self::COLLISION.'` (id INT PRIMARY KEY) ENGINE=InnoDB');
        }
        try {
            DB::beginTransaction();
            try {
                // The ended frame is still referenced and registered; its marker no longer exists.
                $this->guardsRefusedIn($frame, $pdo);
            } finally {
                DB::rollBack();
            }
        } finally {
            if ($other === null) {
                $pdo->exec('PRAGMA foreign_keys=ON');
            } else {
                $other->exec('DROP TABLE `'.self::COLLISION.'`');
            }
        }
    }

    public function test_definitions_proven_in_the_frame_cannot_change_under_it(): void
    {
        // The owner must stay referenced: the registry holds frames weakly, so a dropped owner ends its proofs.
        $frame = $this->ownedFrame();
        $pdo = DB::connection()->getPdo();
        try {
            $this->guards($pdo);
            if (DB::getDriverName() === 'mysql') {
                // Opened before the proof: another session's DDL waits for this frame instead of passing a remembered proof.
                $other = $this->independentConnection();
                $other->exec('SET SESSION lock_wait_timeout=1');
                foreach (["ALTER TABLE quote_owners COMMENT='synthetic'", 'CREATE TRIGGER synthetic_quote_owner_insert BEFORE INSERT ON quote_owners FOR EACH ROW SET @synthetic = 1',
                    "ALTER TABLE audit_events COMMENT='synthetic'"] as $statement) {
                    try {
                        $other->exec($statement);
                        $this->fail('DDL passed a held frame: '.$statement);
                    } catch (PDOException $error) {
                        $this->assertSame(1205, (int) ($error->errorInfo[1] ?? 0), $statement);
                    }
                }
            } else {
                // SQLite DDL is transactional on the same connection; the catalog token sends the guard back to a complete proof.
                $pdo->exec('ALTER TABLE quote_owners ADD COLUMN synthetic_extra TEXT');
                $this->guardsRefusedIn($frame, $pdo);
            }
        } finally {
            DB::rollBack();
        }
        $this->guardsOutsideAFrame();
    }

    public function test_the_terminal_phase_proves_in_full_after_remembered_proofs(): void
    {
        $fixture = $this->reservationFixture();
        $other = DB::getDriverName() === 'mysql' ? $this->independentConnection() : null;
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$calls, $other): void {
            $calls++;
            // Neither metadata-locked nor a same-session change on MySQL: only a complete terminal proof sees it.
            $sql = 'CREATE TABLE '.self::COLLISION.' (id INTEGER PRIMARY KEY)';
            $other === null ? DB::connection()->getPdo()->exec($sql) : $other->exec($sql);
        });
        try {
            $this->expectReservationRefused(fn () => $this->reserveFixture($fixture));
            $this->assertSame(1, $calls);
            $this->assertSame(0, DB::table(MembershipReservationRecords::TABLE)->count());
            $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
        } finally {
            $other?->exec('DROP TABLE IF EXISTS '.self::COLLISION);
        }
    }

    private function ownedFrame(): MembershipAwardTransaction
    {
        DB::beginTransaction();

        return new MembershipAwardTransaction(DB::connection(), DB::connection()->getPdo());
    }

    /** Both guards refuse while $frame (kept alive by this argument) is still registered for $pdo. */
    private function guardsRefusedIn(MembershipAwardTransaction $frame, PDO $pdo): void
    {
        $this->refused(fn () => (new MembershipSchema)->assertOwned($pdo), LogicException::class);
        $this->refused(fn () => IdentityRows::from(new CurrentRows($pdo, DB::getDriverName())), IdentityException::class);
        $this->assertInstanceOf(MembershipAwardTransaction::class, $frame);
    }

    private function guards(PDO $pdo): void
    {
        (new MembershipSchema)->assertOwned($pdo);
        IdentityRows::from(new CurrentRows($pdo, DB::getDriverName()));
    }

    /** The plain transaction path is unchanged: complete proofs pass again once the frame rolled back its change. */
    private function guardsOutsideAFrame(): void
    {
        DB::transaction(fn () => $this->guards(DB::connection()->getPdo()));
    }

    /** MySQL session statement count of $operation; null on SQLite, which has no such counter. */
    private function statements(callable $operation): ?int
    {
        $pdo = DB::connection()->getPdo();
        if (DB::getDriverName() !== 'mysql') {
            $operation();

            return null;
        }
        $read = static fn (): int => (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
        $before = $read();
        $operation();

        return $read() - $before - 1;
    }

    private function refused(callable $operation, string $class): void
    {
        try {
            $operation();
        } catch (Throwable $error) {
            $this->assertInstanceOf($class, $error);

            return;
        }
        $this->fail('Guard unexpectedly passed.');
    }

    private function expectReservationRefused(callable $operation): void
    {
        try {
            $operation();
        } catch (MembershipException $error) {
            $this->assertNotSame('committed_receipt_refused', $error->reason);

            return;
        }
        $this->fail('Reservation unexpectedly committed.');
    }

    private function independentConnection(): PDO
    {
        $db = DB::connection()->getConfig();
        $address = empty($db['unix_socket']) ? 'host='.$db['host'].';port='.$db['port'] : 'unix_socket='.$db['unix_socket'];
        $pdo = new PDO('mysql:'.$address.';dbname='.$db['database'].';charset=utf8mb4', $db['username'], $db['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => false]);
        $pdo->exec('SET SESSION lock_wait_timeout=3, innodb_lock_wait_timeout=3');
        $this->assertNotSame(DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),
            $pdo->query('SELECT CONNECTION_ID()')->fetchColumn());

        return $pdo;
    }
}
