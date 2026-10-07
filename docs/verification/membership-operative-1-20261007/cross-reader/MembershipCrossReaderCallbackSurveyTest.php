<?php

namespace Tests\Feature\MembershipReview;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityRows;
use App\Domain\Grants\Member\MemberGrantSchema;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipRows;
use Illuminate\Support\Facades\DB;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/**
 * Throwaway survey probe (evidence only, not product source). It counts, and never acts on, application
 * SQLite callbacks registered before each captured reader runs its own SQL. Identity/checkout runtime is
 * not edited by this lane; the observation file is the report input.
 */
class MembershipCrossReaderCallbackSurveyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static int $calls = 0;

    public static function cases(): array
    {
        $cases = [];
        foreach (['function_lower', 'function_length', 'collation_binary'] as $primitive) {
            foreach (['identity_rows_construct', 'current_rows_users_by_id', 'member_grant_schema_assert_owned', 'membership_rows_construct'] as $reader) {
                $cases[$primitive.'/'.$reader] = [$primitive, $reader];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_survey_application_callbacks_inside_captured_readers(string $primitive, string $reader): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite application-callback primitive only.');
        }
        config(['production-memberships.enabled' => true]);
        /** @var Sqlite $pdo */
        $pdo = DB::connection()->getPdo();
        $this->assertTrue(match ($primitive) {
            'function_lower' => $pdo->createFunction('lower', static function (mixed $value): mixed {
                self::$calls++;

                return is_string($value) ? strtolower($value) : $value;
            }, 1),
            'function_length' => $pdo->createFunction('length', static function (mixed $value): mixed {
                self::$calls++;

                return $value === null ? null : strlen((string) $value);
            }, 1),
            'collation_binary' => $pdo->createCollation('BINARY', static function (string $a, string $b): int {
                self::$calls++;

                return strcmp($a, $b);
            }),
        });
        self::$calls = 0;
        $outcome = 'admitted';
        try {
            match ($reader) {
                'identity_rows_construct' => DB::transaction(fn () => new IdentityRows($pdo, 'sqlite')),
                'current_rows_users_by_id' => DB::transaction(fn () => (new CurrentRows($pdo, 'sqlite'))->rows('users', 'id = ?', [1])),
                'member_grant_schema_assert_owned' => (new MemberGrantSchema)->assertOwned($pdo),
                'membership_rows_construct' => DB::transaction(fn () => new MembershipRows),
            };
        } catch (MembershipException $error) {
            $outcome = 'refused:'.$error->reason;
        } catch (\Throwable $error) {
            $outcome = 'error:'.class_basename($error);
        }
        $file = base_path('docs/verification/membership-operative-1-20261007/cross-reader/observation-sqlite.json');
        $observed = is_file($file) ? json_decode(file_get_contents($file), true) : ['driver' => 'sqlite', 'sqlite_version' => null, 'cases' => []];
        $observed['sqlite_version'] = $pdo->query('select sqlite_version()')->fetchColumn();
        $observed['cases'][$primitive][$reader] = ['outcome' => $outcome, 'application_callbacks' => self::$calls];
        file_put_contents($file, json_encode($observed, JSON_PRETTY_PRINT)."\n");
        DB::purge();
        $this->assertTrue(true);
    }
}
