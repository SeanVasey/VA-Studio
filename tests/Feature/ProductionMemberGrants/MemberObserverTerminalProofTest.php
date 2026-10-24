<?php

namespace Tests\Feature\ProductionMemberGrants;

use App\Domain\Customers\ProductionIdentity\SchemaProofFrame;
use App\Domain\Grants\Member\MemberActivationObserver;
use App\Domain\Grants\Member\MemberActivationReadObserver;
use App\Domain\Grants\Member\MemberGrantAuthoringObserver;
use App\Domain\Grants\Member\MemberGrantRecords;
use App\Domain\Grants\Member\MemberOriginalObserver;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionProperty;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;
use Throwable;

/**
 * The owned terminal phase of each member observer proves every schema guard in full after remembered proofs
 * (SchemaProofFrame). Each case arms one final committing callback inside that observer's frame, after the frame has
 * remembered proofs. On MySQL another session then creates a table under a reserved identity index name: it takes no
 * metadata lock and moves no frame token, so only a complete terminal proof refuses it. SQLite DDL on the same
 * connection moves the catalog token whether or not the phase began, so on both drivers the case also requires the
 * observer to have put the frame into its terminal phase before the commit.
 */
class MemberObserverTerminalProofTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberActivationFixtures;

    /** A reserved identity index name used as a table: a namespace collision only a complete identity proof sees. */
    private const COLLISION = 'pia_address_unique';

    private ?PDO $other = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberActivationSetup();
    }

    protected function tearDown(): void
    {
        try {
            $this->other?->exec('DROP TABLE IF EXISTS '.self::COLLISION);
        } finally {
            $this->other = null;
            CarbonImmutable::setTestNow();
            parent::tearDown();
        }
    }

    public function test_the_authoring_terminal_phase_proves_in_full_after_remembered_proofs(): void
    {
        $seen = $this->arm(MemberGrantAuthoringObserver::class);
        $this->refusedInTerminal($seen, fn () => $this->memberActivationFixture());
        $this->assertSame(0, DB::table(MemberGrantRecords::PROFILES)->count());
        $this->assertSame(0, DB::table(MemberGrantRecords::DEFINITIONS)->count());
    }

    public function test_the_original_terminal_phase_proves_in_full_after_remembered_proofs(): void
    {
        // Original preparation (MemberOriginals) owns the MemberOriginalObserver frames; activation has its own.
        $fixture = $this->memberOriginalFixture();
        $before = $this->memberOriginalEffects();
        $seen = $this->arm(MemberOriginalObserver::class);
        $this->refusedInTerminal($seen, fn () => $this->prepareMemberOriginal($fixture));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_the_activation_terminal_phase_proves_in_full_after_remembered_proofs(): void
    {
        $fixture = $this->memberActivationFixture();
        $authorization = $this->confirmMemberActivation($fixture);
        $before = $this->memberOriginalEffects();
        $seen = $this->arm(MemberActivationObserver::class);
        $this->refusedInTerminal($seen, fn () => $this->activateMemberFixture($fixture, $authorization));
        $this->assertSame(0, DB::table('production_member_activations')->count());
        $this->assertMemberOriginalEffectsUnchanged($before);
    }

    public function test_the_activation_read_terminal_phase_proves_in_full_after_remembered_proofs(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->activateMemberFixture($fixture);
        $retained = $this->memberOriginalEffects();
        $seen = $this->arm(MemberActivationReadObserver::class);
        $this->refusedInTerminal($seen, fn () => $this->readMemberActivation($fixture));
        $this->assertSame($retained, $this->memberOriginalEffects());
    }

    /**
     * On the first outer committing event dispatched through $observer, record that event's open frame and create
     * the collision. The returned object is filled in by the callback.
     *
     * @param  class-string  $observer
     */
    private function arm(string $observer): object
    {
        $seen = (object) ['frame' => null, 'terminal' => null, 'remembered' => null];
        if (DB::getDriverName() === 'mysql') {
            $this->other = $this->independentConnection();
        }
        $other = $this->other;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function () use ($observer, $seen, $other): void {
            if ($seen->frame !== null || ! DB::connection()->getEventDispatcher() instanceof $observer || DB::transactionLevel() !== 1) {
                return;
            }
            $frame = self::frameFor(DB::connection()->getRawPdo());
            $seen->frame = $frame ?? false;
            if ($frame === null) {
                return;
            }
            $seen->terminal = self::frameState($frame, 'terminal');
            $seen->remembered = array_keys(self::frameState($frame, 'proofs'));
            $sql = 'CREATE TABLE '.self::COLLISION.' (id INTEGER PRIMARY KEY)';
            $other === null ? DB::connection()->getPdo()->exec($sql) : $other->exec($sql);
        });

        return $seen;
    }

    /** $operation is refused before its commit, and the armed frame entered its terminal phase first. */
    private function refusedInTerminal(object $seen, Closure $operation): void
    {
        try {
            $operation();
            $this->fail('The terminal phase admitted a reserved-name collision.');
        } catch (Throwable $error) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $error);
            $this->assertStringNotContainsString('committed', (string) ($error->reason ?? ''), $error::class);
        }
        $this->assertInstanceOf(SchemaProofFrame::class, $seen->frame, 'No owned frame was open at the committing event.');
        // The callback ran inside a frame that was still answering from memory...
        $this->assertFalse($seen->terminal);
        $this->assertNotSame([], $seen->remembered);
        // ...and the observer then disabled the memo for its terminal proofs.
        $this->assertTrue(self::frameState($seen->frame, 'terminal'));
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        if ($this->other === null) {
            // SQLite: the collision was the refused transaction's own DDL and rolled back with it.
            $this->assertFalse(DB::getSchemaBuilder()->hasTable(self::COLLISION));
        }
    }

    private static function frameFor(PDO $pdo): ?SchemaProofFrame
    {
        $frames = (new ReflectionProperty(SchemaProofFrame::class, 'frames'))->getValue();
        if ($frames === null || ! isset($frames[$pdo])) {
            return null;
        }
        $frame = $frames[$pdo]->get();

        return $frame instanceof SchemaProofFrame ? $frame : null;
    }

    private static function frameState(SchemaProofFrame $frame, string $name): mixed
    {
        return (new ReflectionProperty(SchemaProofFrame::class, $name))->getValue($frame);
    }

    private function independentConnection(): PDO
    {
        $db = DB::connection()->getConfig();
        $address = empty($db['unix_socket']) ? 'host='.$db['host'].';port='.$db['port'] : 'unix_socket='.$db['unix_socket'];
        $pdo = new PDO('mysql:'.$address.';dbname='.$db['database'].';charset=utf8mb4', $db['username'], $db['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => false]);
        $pdo->exec('SET SESSION lock_wait_timeout=3, innodb_lock_wait_timeout=3');

        return $pdo;
    }
}
