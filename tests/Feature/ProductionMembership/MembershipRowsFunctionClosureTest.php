<?php

namespace Tests\Feature\ProductionMembership;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipRows;
use Closure;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/**
 * Regression for review finding F1 (docs/verification/membership-257-258-composition-20261007/DECISION.md).
 * The first case is the reviewer's probe: an application SQLite function overriding lower() ran 30,912
 * times inside captured metadata SQL and withdrew the policy while the reader still succeeded.
 * Invariant: no application callback runs during captured reader SQL, and the policy stays enabled.
 */
class MembershipRowsFunctionClosureTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static int $calls = 0;

    public function test_captured_reader_runs_no_application_sqlite_function_during_metadata_sql(): void
    {
        if ($this->nativeHasNoCallbackSurface()) {
            return;
        }
        config(['production-memberships.enabled' => true]);
        DB::transaction(function () {
            $rows = new MembershipRows;
            $pdo = DB::connection()->getPdo();
            $this->assertInstanceOf(Sqlite::class, $pdo);
            self::$calls = 0;
            // The captured reader holds a running statement, so SQLite refuses to replace a built-in.
            $registered = $pdo->createFunction('lower', $this->withdrawing(), 1);
            $refused = $this->refused(fn () => $rows->assertCurrent());
            $this->assertFalse($registered, 'SQLite must refuse to replace lower() on a captured handle.');
            $this->assertFalse($refused, 'The unchanged handle remains an admitted reader.');
            $this->assertSame(0, self::$calls);
            $this->assertTrue(config('production-memberships.enabled'));
        });
    }

    public static function preCaptureCallbacks(): array
    {
        return [['lower'], ['length'], ['max'], ['count'], ['new_name']];
    }

    #[DataProvider('preCaptureCallbacks')]
    public function test_function_or_aggregate_registered_before_capture_refuses_before_any_reader_sql(string $case): void
    {
        if ($this->nativeHasNoCallbackSurface()) {
            return;
        }
        $this->assertPreCaptureRefusal($case, fn (Sqlite $pdo) => match ($case) {
            'lower' => $pdo->createFunction('lower', $this->withdrawing(), 1),
            'length' => $pdo->createFunction('length', $this->withdrawing(), 1),
            'max' => $pdo->createAggregate('max', $this->withdrawingStep(), fn (mixed $context): mixed => $context, 1),
            'count' => $pdo->createAggregate('count', $this->withdrawingStep(), fn (mixed $context): mixed => $context, -1),
            'new_name' => $pdo->createFunction('membership_probe', $this->withdrawing(), 1),
        }, 'sqlite_application_function');
    }

    public static function postCaptureCallbacks(): array
    {
        return [['max_aggregate'], ['nocase'], ['binary'], ['rtrim'], ['new_function'], ['new_collation']];
    }

    #[DataProvider('postCaptureCallbacks')]
    public function test_new_function_or_collation_after_capture_refuses_and_builtin_replacements_are_blocked(string $case): void
    {
        if ($this->nativeHasNoCallbackSurface()) {
            return;
        }
        config(['production-memberships.enabled' => true]);
        try {
            DB::transaction(function () use ($case) {
                $rows = new MembershipRows;
                /** @var Sqlite $pdo */
                $pdo = DB::connection()->getPdo();
                self::$calls = 0;
                $registered = match ($case) {
                    'max_aggregate' => $pdo->createAggregate('max', $this->withdrawingStep(), fn (mixed $context): mixed => $context, 1),
                    'nocase' => $pdo->createCollation('NOCASE', $this->withdrawingCollation()),
                    'binary' => $pdo->createCollation('BINARY', $this->withdrawingCollation()),
                    'rtrim' => $pdo->createCollation('RTRIM', $this->withdrawingCollation()),
                    'new_function' => $pdo->createFunction('membership_probe', $this->withdrawing(), 1),
                    'new_collation' => $pdo->createCollation('MEMBERSHIP_PROBE', $this->withdrawingCollation()),
                };
                $refused = $this->refused(fn () => $rows->assertCurrent());
                if (str_starts_with($case, 'new_')) {
                    // A new name is not a replacement, so SQLite admits it; the catalog read refuses it.
                    $this->assertTrue($registered, $case);
                    $this->assertTrue($refused, $case.' must be refused before any reader SQL.');
                } else {
                    $this->assertFalse($registered, $case.' replacement must be refused by SQLite while captured.');
                    $this->assertFalse($refused, $case);
                }
                $this->assertSame(0, self::$calls, $case);
                $this->assertTrue(config('production-memberships.enabled'), $case);
            });
        } finally {
            DB::purge();
        }
    }

    public function test_new_collation_registered_before_capture_refuses(): void
    {
        if ($this->nativeHasNoCallbackSurface()) {
            return;
        }
        $this->assertPreCaptureRefusal('collation', fn (Sqlite $pdo) => $pdo->createCollation('MEMBERSHIP_PROBE', $this->withdrawingCollation()),
            'sqlite_application_collation');
    }

    /**
     * Characterization, not approval: SQLite lists no difference when a built-in collation is replaced
     * before capture, so that callback runs inside reader SQL. This is why verified_production is refused
     * on SQLite by both the policy and the captured reader (below) and SQLite serves rehearsal only.
     */
    public function test_builtin_collation_replaced_before_capture_is_an_undetectable_sqlite_residual(): void
    {
        if ($this->nativeHasNoCallbackSurface()) {
            return;
        }
        config(['production-memberships.enabled' => true]);
        /** @var Sqlite $pdo */
        $pdo = DB::connection()->getPdo();
        self::$calls = 0;
        $pdo->createCollation('BINARY', static function (string $left, string $right): int {
            self::$calls++;

            return strcmp($left, $right);
        });
        try {
            $refused = $this->refused(fn () => DB::transaction(fn () => new MembershipRows));
            $this->assertFalse($refused, 'If this starts refusing, the residual is closed: update the verification README.');
            $this->assertGreaterThan(0, self::$calls);
        } finally {
            DB::purge();
        }
    }

    public function test_verified_production_is_refused_on_sqlite_by_policy_and_captured_reader(): void
    {
        config(['production-memberships.enabled' => true, 'production-memberships.version' => MembershipPolicy::VERSION,
            'production-memberships.provenance' => IdentityPolicy::PRODUCTION, 'production-memberships.approved_policy_hash' => str_repeat('a', 64)]);
        if (DB::getDriverName() === 'mysql') {
            // Native passes the driver gate and still stops at the absent reviewed capabilities.
            try {
                (new MembershipPolicy)->current();
                $this->fail('No reviewed capability is bound.');
            } catch (MembershipException $error) {
                $this->assertSame('source_capability_absent', $error->reason);
            }
            DB::transaction(fn () => (new MembershipRows)->assertCurrent());

            return;
        }
        try {
            (new MembershipPolicy)->current();
            $this->fail('verified_production must not be admitted on SQLite.');
        } catch (MembershipException $error) {
            $this->assertSame('provenance', $error->reason);
        }
        try {
            DB::transaction(fn () => new MembershipRows);
            $this->fail('A SQLite reader must not serve verified_production.');
        } catch (MembershipException $error) {
            $this->assertSame('provenance_driver', $error->reason);
        }
        config(['production-memberships.provenance' => IdentityPolicy::REHEARSAL]);
        DB::transaction(fn () => new MembershipRows);
    }

    /**
     * Regression for review R-1 (docs/verification/membership-operative-1-20261007/independent-review/DECISION.md). The frame's pin is
     * an object property closed in __destruct, so destroying a clone released the live frame's pin and SQLite then admitted a
     * built-in collation replacement whose callback ran 25,812 times inside assertCurrent(). A captured frame is not clonable.
     */
    public function test_a_clone_of_a_captured_frame_is_refused_and_destroying_it_never_releases_the_live_pin(): void
    {
        config(['production-memberships.enabled' => true]);
        try {
            DB::transaction(function () {
                $rows = new MembershipRows;
                $cloneRefused = $this->refused(function () use ($rows): void {
                    $copy = clone $rows;
                    unset($copy);
                });
                if (DB::getDriverName() === 'sqlite') {
                    /** @var Sqlite $pdo */
                    $pdo = DB::connection()->getPdo();
                    self::$calls = 0;
                    $registered = $pdo->createCollation('BINARY', $this->withdrawingCollation());
                    $this->assertFalse($registered, 'The live frame still pins the handle, so SQLite must refuse to replace BINARY.');
                    $this->assertFalse($this->refused(fn () => $rows->assertCurrent()));
                    $this->assertSame(0, self::$calls);
                    $this->assertTrue(config('production-memberships.enabled'));
                }
                $this->assertTrue($cloneRefused, 'A captured frame must refuse clone.');
                $rows->assertCurrent();
            });
        } finally {
            DB::purge();
        }
    }

    public function test_class_fetch_mode_is_refused_before_metadata_reads(): void
    {
        config(['production-memberships.enabled' => true]);
        DB::transaction(function () {
            $rows = new MembershipRows;
            $pdo = DB::connection()->getPdo();
            $original = $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_CLASS | PDO::FETCH_CLASSTYPE);
            try {
                $this->assertTrue($this->refused(fn () => $rows->assertCurrent()));
            } finally {
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, $original);
            }
            $rows->assertCurrent();
        });
    }

    private function assertPreCaptureRefusal(string $case, Closure $register, string $reason): void
    {
        config(['production-memberships.enabled' => true]);
        /** @var Sqlite $pdo */
        $pdo = DB::connection()->getPdo();
        self::$calls = 0;
        $this->assertTrue($register($pdo), $case);
        try {
            DB::transaction(fn () => new MembershipRows);
            $this->fail($case.' must refuse the captured reader.');
        } catch (MembershipException $error) {
            $this->assertSame($reason, $error->reason, $case);
        } finally {
            // Drop the handle that carries the application callback.
            DB::purge();
        }
        $this->assertSame(0, self::$calls, $case);
        $this->assertTrue(config('production-memberships.enabled'), $case);
    }

    private function refused(Closure $operation): bool
    {
        try {
            $operation();

            return false;
        } catch (MembershipException) {
            return true;
        }
    }

    private function withdrawing(): Closure
    {
        return static function (mixed $value): mixed {
            self::$calls++;
            config(['production-memberships.enabled' => false]);

            return is_string($value) ? strtolower($value) : $value;
        };
    }

    private function withdrawingStep(): Closure
    {
        return static function (mixed $context, int $row, mixed $value): mixed {
            self::$calls++;
            config(['production-memberships.enabled' => false]);

            return $value;
        };
    }

    private function withdrawingCollation(): Closure
    {
        return static function (string $left, string $right): int {
            self::$calls++;
            config(['production-memberships.enabled' => false]);

            return strcmp($left, $right);
        };
    }

    /**
     * MySQL runs every case (CI requires zero native skips). Its PDO exposes no SQL-level PHP callback,
     * so the native assertion is that surface's absence plus an admitted captured reader.
     */
    private function nativeHasNoCallbackSurface(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return false;
        }
        $pdo = DB::connection()->getPdo();
        $this->assertInstanceOf(Mysql::class, $pdo);
        foreach (['createFunction', 'createAggregate', 'createCollation', 'sqliteCreateFunction', 'sqliteCreateAggregate', 'sqliteCreateCollation'] as $method) {
            $this->assertFalse(method_exists($pdo, $method), $method);
        }
        config(['production-memberships.enabled' => true]);
        DB::transaction(fn () => (new MembershipRows)->assertCurrent());
        $this->assertTrue(config('production-memberships.enabled'));

        return true;
    }
}
