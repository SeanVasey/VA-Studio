<?php

namespace Tests\Feature\ProductionMembership;

use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipRows;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOStatement;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class MembershipNativeStatementClosureTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_captured_reader_refuses_application_statement_callbacks_before_any_metadata_sql(): void
    {
        config(['production-memberships.enabled' => true]);
        DB::transaction(function () {
            $rows = new MembershipRows;
            $pdo = DB::connection()->getPdo();
            $original = $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
            MembershipWithdrawingStatement::$calls = 0;
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [MembershipWithdrawingStatement::class]);
            try {
                $refused = false;
                try {
                    $rows->assertCurrent();
                } catch (MembershipException) {
                    $refused = true;
                }
                $this->assertTrue($refused, 'A retained reader must reject a callback-capable native statement factory.');
                $this->assertSame(0, MembershipWithdrawingStatement::$calls);
                $this->assertTrue(config('production-memberships.enabled'));
            } finally {
                $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $original);
            }
        });
    }
}

final class MembershipWithdrawingStatement extends PDOStatement
{
    public static int $calls = 0;

    protected function __construct() {}

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        self::$calls++;
        config(['production-memberships.enabled' => false]);

        return parent::fetchAll($mode, ...$args);
    }
}
