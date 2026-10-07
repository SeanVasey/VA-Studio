<?php

namespace Tests\Feature\ProductionIdentityAdapters;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureAccess;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeaturePolicy;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\IdentityRows;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class ProductionIdentityCommittedFrameTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identitySetup();
        DB::unprepared('DROP TABLE IF EXISTS committed_frame_fixture');
        DB::unprepared('CREATE TABLE committed_frame_fixture (id integer PRIMARY KEY)');
        config(['production-account-features.enabled' => true, 'production-account-features.provenance' => IdentityPolicy::REHEARSAL,
            'production-account-features.versions' => ProductionAccountFeaturePolicy::VERSIONS]);
    }

    private function owner(): ProductionAccountFeatureIdentity
    {
        $verified = $this->enrollThroughLocalSmtp();
        $request = Request::create('/customer/library', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('_production_customer_identity', ['binding_digest' => $verified['principal']->sessionBindingDigest()]);
        Auth::guard('customer')->setUser($verified['user']);
        $request->setUserResolver(fn () => $verified['user']);

        return (new ProductionAccountFeatureAccess)->forRequest($request, 'listening_library');
    }

    private function reader(): CurrentRows
    {
        return new CurrentRows(DB::connection()->getPdo(), DB::getDriverName());
    }

    private function write(ProductionAccountFeatureIdentity $owner): array
    {
        $reader = $this->reader();

        return DB::transaction(function () use ($owner, $reader): array {
            $access = new ProductionAccountFeatureAccess;
            $proof = $access->lock($owner, $reader);
            DB::table('committed_frame_fixture')->insert(['id' => 1]);
            $access->proveCurrent($owner, $reader, $proof);

            return $proof;
        });
    }

    public function test_committed_read_frame_is_read_only_one_use_and_fires_no_new_framework_commit_event(): void
    {
        $owner = $this->owner();
        $events = 0;
        Event::listen(TransactionCommitted::class, function () use (&$events): void {
            $events++;
        });
        $deadline = hrtime(true) + 30_000_000_000;
        $proof = $this->write($owner);
        $this->assertSame(1, $events);
        $frame = IdentityCommittedFrame::begin($this->reader(), $deadline);
        try {
            $this->assertSame(0, DB::transactionLevel());
            $access = new ProductionAccountFeatureAccess;
            $access->lockCommitted($owner, $frame, $proof);
            $binding = $access->durableBinding($owner);
            $original = $access->verifyOriginalBindingCommitted($owner, $binding, $frame);
            $privateProjection = ['saved' => DB::connection()->getPdo()->query('SELECT id FROM committed_frame_fixture')->fetchColumn()];
            try {
                DB::connection()->getPdo()->exec('UPDATE users SET name=\'Raw write refused\'');
                $this->fail('Owned validation frame must reject real source writes.');
            } catch (PDOException) {
                $this->assertSame('Declared buyer', $owner->actor()->fresh()->name);
            }
            $access->proveOriginalBindingCommitted($owner, $binding, $frame, $original);
            $access->proveCommitted($owner, $frame, $proof);
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame(1, $events);
            $this->assertSame(['saved' => 1], $privateProjection);
            try {
                $access->proveCommitted($owner, $frame, $proof);
                $this->fail('A committed frame cannot authorize twice.');
            } catch (IdentityException) {
                $this->assertTrue(true);
            }
        } finally {
            $frame->close();
        }
        DB::transaction(fn () => DB::table('committed_frame_fixture')->insert(['id' => 2]));
        $this->assertSame(2, $events);
        $this->assertSame(2, DB::table('committed_frame_fixture')->count());
    }

    public static function withdrawals(): array
    {
        return ['credential' => ['credential'], 'actor' => ['actor'], 'feature config' => ['feature'], 'identity config' => ['identity']];
    }

    #[DataProvider('withdrawals')]
    public function test_normal_commit_callback_withdrawal_retains_durable_write_but_releases_no_private_response(string $withdrawal): void
    {
        $owner = $this->owner();
        $replacement = Hash::make('WithdrawnCredential456');
        $originalActor = $owner->actor()->id;
        $armed = true;
        Event::listen(TransactionCommitted::class, function () use ($withdrawal, $owner, $replacement, &$armed): void {
            if (! $armed) {
                return;
            }
            $armed = false;
            match ($withdrawal) {
                'credential' => DB::table('users')->where('id', $owner->principal()->userId)->update(['password' => $replacement]),
                'actor' => $owner->actor()->id = $owner->actor()->id + 1000,
                'feature' => config(['production-account-features.enabled' => false]),
                'identity' => config(['production-customer-identity.enabled' => false]),
            };
        });
        $deadline = hrtime(true) + 30_000_000_000;
        $proof = $this->write($owner);
        $this->assertSame(1, DB::table('committed_frame_fixture')->count());
        $frame = IdentityCommittedFrame::begin($this->reader(), $deadline);
        $released = false;
        try {
            try {
                (new ProductionAccountFeatureAccess)->proveCommitted($owner, $frame, $proof);
                $released = true;
                $this->fail('Postcommit withdrawal must not release private output.');
            } catch (IdentityException) {
                $this->assertFalse($released);
            }
        } finally {
            $frame->close();
            $owner->actor()->id = $originalActor;
        }
        $this->assertSame(1, DB::table('committed_frame_fixture')->count(), 'Ordinary committed mutation is retained; retry must report uncertainty.');
    }

    public function test_direct_commit_reopen_loses_physical_anchor_and_is_not_rolled_back_as_owned_work(): void
    {
        $owner = $this->owner();
        $proof = $this->write($owner);
        $pdo = DB::connection()->getPdo();
        $frame = IdentityCommittedFrame::begin($this->reader(), hrtime(true) + 30_000_000_000);
        $pdo->commit();
        $pdo->beginTransaction();
        try {
            try {
                (new ProductionAccountFeatureAccess)->proveCommitted($owner, $frame, $proof);
                $this->fail('A replacement physical transaction must not reuse the original frame.');
            } catch (IdentityException) {
                $this->assertTrue($pdo->inTransaction());
            }
            $frame->close();
            $this->assertTrue($pdo->inTransaction(), 'Cleanup does not own the replacement transaction.');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $frame->close();
        }
        $this->assertSame(1, DB::table('committed_frame_fixture')->count());
    }

    public function test_foreign_reader_existing_or_nested_transaction_and_expired_original_deadline_are_refused(): void
    {
        $pdo = DB::connection()->getPdo();
        if (DB::getDriverName() === 'sqlite') {
            $foreign = new CurrentRows(new PDO('sqlite::memory:'), 'sqlite');
        } else {
            $name = 'identity_committed_foreign';
            config(['database.connections.'.$name => config('database.connections.'.DB::getDefaultConnection())]);
            $foreign = new CurrentRows(DB::connection($name)->getPdo(), 'mysql');
        }
        foreach ([$foreign, $this->reader()] as $reader) {
            try {
                IdentityCommittedFrame::begin($reader, hrtime(true) + ($reader === $foreign ? 30_000_000_000 : -1));
                $this->fail('Foreign reader or expired original deadline must refuse.');
            } catch (IdentityException) {
                $this->assertFalse($pdo->inTransaction());
            }
        }
        DB::beginTransaction();
        try {
            try {
                IdentityCommittedFrame::begin($this->reader(), hrtime(true) + 30_000_000_000);
                $this->fail('Framework transaction cannot become committed validation.');
            } catch (IdentityException) {
                $this->assertSame(1, DB::transactionLevel());
            }
        } finally {
            DB::rollBack();
        }
        $frame = IdentityCommittedFrame::begin($this->reader(), hrtime(true) + 30_000_000_000);
        try {
            try {
                new IdentityRows($pdo, DB::getDriverName());
                $this->fail('Ordinary reader admission must remain unchanged in raw transaction mode.');
            } catch (IdentityException) {
                $this->assertSame(0, DB::transactionLevel());
            }
            try {
                DB::beginTransaction();
                $this->fail('Nested Laravel work cannot run in owned raw validation frame.');
            } catch (PDOException) {
                $this->assertSame(0, DB::transactionLevel());
            }
        } finally {
            $frame->close();
        }
        $this->assertFalse($pdo->inTransaction());
    }

    public function test_close_preserves_replacement_transaction_read_only_state(): void
    {
        $pdo = DB::connection()->getPdo();
        $frame = IdentityCommittedFrame::begin($this->reader(), hrtime(true) + 30_000_000_000);
        try {
            $pdo->commit();
            $pdo->beginTransaction();
            $pdo->exec('SAVEPOINT foreign_replacement_fixture');
            $before = DB::getDriverName() === 'sqlite' ? (int) $pdo->query('PRAGMA query_only')->fetchColumn()
                : $pdo->query('SELECT @@SESSION.transaction_read_only')->fetchColumn();
            $frame->close();
            $this->assertTrue($pdo->inTransaction());
            $after = DB::getDriverName() === 'sqlite' ? (int) $pdo->query('PRAGMA query_only')->fetchColumn()
                : $pdo->query('SELECT @@SESSION.transaction_read_only')->fetchColumn();
            $this->assertSame($before, $after, 'Cleanup must not change a foreign transaction session setting.');
            $pdo->exec('RELEASE SAVEPOINT foreign_replacement_fixture');
            $this->addToAssertionCount(1);
            if (DB::getDriverName() === 'sqlite') {
                try {
                    $pdo->exec('INSERT INTO committed_frame_fixture (id) VALUES (42)');
                    $this->fail('The untouched replacement transaction remains read-only.');
                } catch (PDOException) {
                    $this->assertSame(1, $after);
                }
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $frame->close();
            // Restore the original idle setting only after the caller ends the replacement.
            if (DB::getDriverName() === 'sqlite') {
                $this->assertSame(0, (int) $pdo->query('PRAGMA query_only')->fetchColumn());
                $pdo->exec('PRAGMA query_only=OFF');
            }
        }
    }

    public function test_expiring_original_deadline_cannot_be_renewed_by_starting_final_proof(): void
    {
        $owner = $this->owner();
        $proof = $this->write($owner);
        $frame = IdentityCommittedFrame::begin($this->reader(), hrtime(true) + 100_000_000);
        usleep(150_000);
        try {
            try {
                (new ProductionAccountFeatureAccess)->proveCommitted($owner, $frame, $proof);
                $this->fail('Final proof cannot renew the retained deadline.');
            } catch (IdentityException) {
                $this->assertTrue(true);
            }
        } finally {
            $frame->close();
        }
        $this->assertSame(1, DB::table('committed_frame_fixture')->count());
    }

    public function test_active_secondary_transaction_refuses_a_new_committed_frame(): void
    {
        $name = 'identity_frame_secondary';
        config(['database.connections.'.$name => config('database.connections.'.DB::getDefaultConnection())]);
        $secondary = DB::connection($name);
        $secondary->beginTransaction();
        try {
            try {
                IdentityCommittedFrame::begin($this->reader(), hrtime(true) + 30_000_000_000);
                $this->fail('An active secondary transaction must refuse the frame.');
            } catch (IdentityException) {
                $this->assertSame(1, $secondary->transactionLevel());
                $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            }
        } finally {
            $secondary->rollBack();
            DB::purge($name);
        }
    }

    public function test_native_validation_reads_current_committed_credential_instead_of_an_old_repeatable_read_snapshot(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Read-only READ COMMITTED visibility requires native MySQL.');
        }
        $owner = $this->owner();
        $proof = $this->write($owner);
        $replacement = Hash::make('ConcurrentWithdrawal456');
        $name = 'identity_frame_concurrent';
        config(['database.connections.'.$name => config('database.connections.'.DB::getDefaultConnection())]);
        $other = DB::connection($name)->getPdo();
        $frame = IdentityCommittedFrame::begin($this->reader(), hrtime(true) + 30_000_000_000);
        try {
            $pdo = DB::connection()->getPdo();
            $this->assertSame($owner->actor()->getAuthPassword(), $pdo->query('SELECT password FROM users WHERE id='.(int) $owner->principal()->userId)->fetchColumn());
            $statement = $other->prepare('UPDATE users SET password=? WHERE id=?');
            $statement->execute([$replacement, $owner->principal()->userId]);
            try {
                (new ProductionAccountFeatureAccess)->proveCommitted($owner, $frame, $proof);
                $this->fail('Committed concurrent withdrawal must be visible to final validation.');
            } catch (IdentityException) {
                $this->assertTrue(true);
            }
        } finally {
            $frame->close();
            DB::purge($name);
        }
        $this->assertSame($replacement, DB::table('users')->where('id', $owner->principal()->userId)->value('password'));
        $this->assertSame(1, DB::table('committed_frame_fixture')->count());
    }

    public static function unresolvedConnections(): array
    {
        return ['primary resolver' => ['primary'], 'secondary resolver' => ['secondary'], 'secondary null' => ['null']];
    }

    #[DataProvider('unresolvedConnections')]
    public function test_late_unresolved_connection_is_refused_without_running_a_callback_after_feature_policy(string $kind): void
    {
        $owner = $this->owner();
        $proof = $this->write($owner);
        $access = new ProductionAccountFeatureAccess;
        $primary = DB::connection()->getPdo();
        $frame = IdentityCommittedFrame::begin($this->reader(), hrtime(true) + 30_000_000_000);
        $access->lockCommitted($owner, $frame, $proof);
        $name = 'identity_frame_late_resolver';
        if ($kind === 'primary') {
            $connection = DB::connection();
        } else {
            config(['database.connections.'.$name => config('database.connections.'.DB::getDefaultConnection())]);
            $connection = DB::connection($name);
        }
        $physical = $connection->getPdo();
        $callbacks = 0;
        $connection->setPdo($kind === 'null' ? null : function () use ($physical, &$callbacks): PDO {
            $callbacks++;
            config(['production-account-features.enabled' => false]);

            return $physical;
        });
        try {
            try {
                $access->proveCommitted($owner, $frame, $proof);
                $this->fail('Late unresolved connection cannot release a private response.');
            } catch (IdentityException) {
                $this->assertSame(0, $callbacks);
                $this->assertTrue(config('production-account-features.enabled'));
                $this->assertSame(1, $primary->query('SELECT COUNT(*) FROM committed_frame_fixture')->fetchColumn());
            }
        } finally {
            $connection->setPdo($physical);
            $frame->close();
            if ($kind !== 'primary') {
                DB::purge($name);
            }
        }
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame(1, DB::table('committed_frame_fixture')->count());
    }
}
