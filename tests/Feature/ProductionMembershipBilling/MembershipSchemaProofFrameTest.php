<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityRows;
use App\Domain\Customers\ProductionIdentity\SchemaProofFrame;
use App\Domain\Memberships\Production\MembershipAwards;
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
 * reuse still sees new dependent objects. Shared cases run on both drivers; the native-only result-charset control
 * requires MySQL. Cross-session MySQL legs use a separate session where SQLite has only one connection.
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
        $other = null;
        if (DB::getDriverName() === 'mysql') {
            // Another session: creating a view takes no metadata lock on the tables it reads. Under REPEATABLE READ the
            // frame's information_schema listings stay at its first dictionary read, for a complete proof as much as for
            // a reuse; READ COMMITTED reads the current dictionary on every statement, as the committed frames do.
            DB::connection()->getPdo()->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $other = $this->independentConnection();
        }
        $frame = $this->ownedFrame();
        $pdo = DB::connection()->getPdo();
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
                // SET never commits, so the marker survives. The complete proof requires foreign_key_checks = 1, and its
                // own dictionary reads come back truncated under sql_select_limit or re-encoded under character_set_results.
                $results = (string) $pdo->query('SELECT @@SESSION.character_set_results')->fetchColumn();
                foreach ([['SET SESSION foreign_key_checks=0', 'SET SESSION foreign_key_checks=1', false],
                    ['SET SESSION sql_select_limit=2', 'SET SESSION sql_select_limit=DEFAULT', true],
                    ['SET SESSION character_set_results=utf32', 'SET SESSION character_set_results='.$pdo->quote($results), true]] as [$change, $undo, $any]) {
                    // A fresh remembered proof under the frame's current session state...
                    $this->guards($pdo);
                    $this->guards($pdo);
                    $pdo->exec($change);
                    try {
                        // ...is not reused once one of the token's session inputs moved: the complete proof refuses.
                        $this->guardsRefusedIn($frame, $pdo, $any);
                    } finally {
                        $pdo->exec($undo);
                    }
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

    /**
     * G1 N-1: a result-charset change alone must never be answered from a remembered proof. Inside an owned frame the
     * catalog lease refuses first and closes the frame (the name predates that guard and is pinned by the skip census).
     */
    public function test_native_result_charset_alone_invalidates_a_remembered_complete_proof(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL session result-charset control.');
        }
        $pdo = DB::connection()->getPdo();
        $results = $pdo->query('SELECT @@SESSION.character_set_results')->fetchColumn();
        // A frame can only open on the canonical utf8mb4 session (CurrentCatalog::context requires it).
        $this->assertSame('utf8mb4', $results);
        $changed = 'latin1';
        $frame = $this->ownedFrame();
        $calls = 0;
        $prove = function () use ($pdo, &$calls): void {
            // Exercise the existing complete guards, not a fabricated schema or PDO double.
            $this->guards($pdo);
            $calls++;
        };
        $consult = static fn () => SchemaProofFrame::prove($pdo, self::class.'::result_charset', ['users'], $prove);
        $otherLegs = static fn (): array => [
            $pdo->query('SELECT CONNECTION_ID(), DATABASE(), @@SESSION.foreign_key_checks, @@SESSION.sql_mode, '
                .'@@SESSION.sql_select_limit')->fetchAll(PDO::FETCH_NUM),
            $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_create_table', 'Com_drop_table')")->fetchAll(PDO::FETCH_NUM),
        ];
        try {
            $consult();
            $this->assertSame(1, $calls);
            $consult();
            $this->assertSame(1, $calls, 'Unchanged session must actually reuse the completed proof.');
            $before = $otherLegs();
            $pdo->exec('SET SESSION character_set_results='.$pdo->quote($changed));
            $this->assertSame($changed, $pdo->query('SELECT @@SESSION.character_set_results')->fetchColumn());
            $this->assertSame($before, $otherLegs(), 'No other session-token leg or temporary-table counter changed.');
            $frame->admit($pdo);
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame(1, DB::connection()->transactionLevel());
            // The frame's catalog lease re-reads the session context (character_set_results included) on every consult,
            // so a changed result charset refuses and closes the frame before any remembered proof could answer.
            try {
                $consult();
                $this->fail('A changed result charset must refuse the framed consult.');
            } catch (LogicException $error) {
                $this->assertSame('Current private catalog admission required.', $error->getMessage());
            }
            $this->assertSame(1, $calls, 'A changed result charset must never be answered from the remembered proof.');
            $pdo->exec('SET SESSION character_set_results='.$pdo->quote($results));
            $this->assertSame($results, $pdo->query('SELECT @@SESSION.character_set_results')->fetchColumn());
            $this->assertSame($before, $otherLegs());
            // Restoring the charset does not revive the closed frame: the next consult runs the real complete proof.
            $consult();
            $this->assertSame(2, $calls, 'A closed frame never answers from memory again.');
            $consult();
            $this->assertSame(3, $calls, 'The frame is closed, not merely cleared: it never remembers a proof again.');
        } finally {
            try {
                $pdo->exec('SET SESSION character_set_results='.$pdo->quote($results));
            } finally {
                DB::rollBack();
            }
        }
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->guardsOutsideAFrame();
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
                foreach ($source->query('SELECT name, sql FROM main.sqlite_master WHERE sql IS NOT NULL ORDER BY rowid')->fetchAll(PDO::FETCH_NUM) as [$name, $sql]) {
                    if (! str_starts_with($name, 'sqlite_')) {
                        $second->exec($sql);
                    }
                }
                $this->assertSame($source->query('SELECT type, name, tbl_name, sql FROM main.sqlite_master')->fetchAll(PDO::FETCH_NUM),
                    $second->query('SELECT type, name, tbl_name, sql FROM main.sqlite_master')->fetchAll(PDO::FETCH_NUM));
                $second->exec('PRAGMA foreign_keys=OFF');
            } else {
                $second->exec('SET SESSION foreign_key_checks=0');
                // MySQL: the current-catalog admission compares the companion's session with this one, foreign_key_checks
                // included, so a frame on a session with foreign keys off is refused before any proof can be used at all.
                try {
                    $this->ownedFrame();
                    $this->fail('A frame on a session with foreign keys off was admitted.');
                } catch (LogicException $refused) {
                    $this->assertSame('Current private catalog admission required.', $refused->getMessage());
                } finally {
                    DB::rollBack();
                }

                return;
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

    public function test_the_award_terminal_phase_proves_in_full_after_remembered_proofs(): void
    {
        $fixture = $this->reservationFixture(award: false);
        $other = DB::getDriverName() === 'mysql' ? $this->independentConnection() : null;
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$calls, $other): void {
            $calls++;
            $sql = 'CREATE TABLE '.self::COLLISION.' (id INTEGER PRIMARY KEY)';
            $other === null ? DB::connection()->getPdo()->exec($sql) : $other->exec($sql);
        });
        try {
            $this->expectReservationRefused(fn () => (new MembershipAwards)->award($fixture['invoice']['id'],
                $fixture['buyer']['principal'], $fixture['buyer']['user']));
            $this->assertSame(1, $calls);
            $this->assertSame(0, DB::table(MembershipPeriodRecords::PERIODS)->count());
            $this->assertSame(0, DB::table(MembershipPeriodRecords::EVENTS)->count());
        } finally {
            $other?->exec('DROP TABLE IF EXISTS '.self::COLLISION);
        }
    }

    private function ownedFrame(): MembershipAwardTransaction
    {
        DB::beginTransaction();

        return new MembershipAwardTransaction(DB::connection(), DB::connection()->getPdo());
    }

    /**
     * Both guards refuse while $frame (kept alive by this argument) is still registered for $pdo. $any accepts any
     * refusal: a complete proof whose own reads come back truncated or re-encoded can fail on a missing key first.
     */
    private function guardsRefusedIn(MembershipAwardTransaction $frame, PDO $pdo, bool $any = false): void
    {
        $this->refused(fn () => (new MembershipSchema)->assertOwned($pdo), $any ? Throwable::class : LogicException::class);
        $this->refused(fn () => IdentityRows::from(new CurrentRows($pdo, DB::getDriverName())), $any ? Throwable::class : IdentityException::class);
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
            $this->assertInstanceOf($class, $error, $error::class.': '.$error->getMessage());

            return;
        }
        $this->fail('Guard unexpectedly passed.');
    }

    /** A pre-commit refusal of an owned writer: nothing was committed. */
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
