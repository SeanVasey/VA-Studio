<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationContext;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipReservationFixtures;
use Tests\TestCase;

class MembershipReservationClockClosureTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipReservationFixtures;

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

    public function test_terminal_clock_callback_cannot_withdraw_identity_after_the_last_precommit_owner_check(): void
    {
        $fixture = $this->reservationFixture();
        $at = CarbonImmutable::now('UTC');
        $armed = false;
        $changed = 0;
        $primary = DB::connection()->getPdo();
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function () use (&$armed): void {
            $armed = true;
        });
        CarbonImmutable::setTestNow(function () use ($fixture, $at, $primary, &$armed, &$changed) {
            if ($armed && self::insideDeadlineClosure()) {
                $armed = false;
                $statement = $primary->prepare('UPDATE customer_accounts SET active = 0, access_version = access_version + 1 WHERE id = ?');
                $statement->execute([$fixture['buyer']['principal']->accountId]);
                $changed += $statement->rowCount();
            }

            return $at;
        });
        $this->refuses(fn () => $this->reserveFixture($fixture), 'operation_refused');
        $this->assertSame(1, $changed);
        $this->assertSame(0, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertSame(1, (int) DB::table('customer_accounts')->where('id', $fixture['buyer']['principal']->accountId)->value('active'));
    }

    public function test_native_committed_clock_callback_owner_withdrawal_refuses_receipt_without_undoing_reservation(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires two physical MySQL connections and the committed read-only frame.');
        }
        $fixture = $this->reservationFixture();
        $settings = DB::connection()->getConfig();
        $dsn = ! empty($settings['unix_socket']) ? 'mysql:unix_socket='.$settings['unix_socket']
            : 'mysql:host='.$settings['host'].';port='.$settings['port'];
        $other = new PDO($dsn.';dbname='.$settings['database'].';charset=utf8mb4', $settings['username'], $settings['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $at = CarbonImmutable::now('UTC');
        $armed = false;
        $changed = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, static function () use (&$armed): void {
            $armed = true;
        });
        CarbonImmutable::setTestNow(function () use ($fixture, $at, $other, &$armed, &$changed) {
            if ($armed && self::insideDeadlineClosure()) {
                $armed = false;
                $statement = $other->prepare('UPDATE customer_accounts SET active = 0, access_version = access_version + 1 WHERE id = ?');
                $statement->execute([$fixture['buyer']['principal']->accountId]);
                $changed += $statement->rowCount();
            }

            return $at;
        });
        $this->refuses(fn () => $this->reserveFixture($fixture), 'committed_receipt_refused');
        $this->assertSame(1, $changed);
        $this->assertSame(1, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(2, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertSame(0, (int) DB::table('customer_accounts')->where('id', $fixture['buyer']['principal']->accountId)->value('active'));
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private static function insideDeadlineClosure(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24) as $frame) {
            if (($frame['class'] ?? null) === MembershipReservationContext::class && ($frame['function'] ?? null) === 'checkDeadlines') {
                return true;
            }
        }

        return false;
    }

    private function refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Expected the current-owner closure to refuse.');
        } catch (MembershipException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
