<?php

namespace Tests\Feature;

use App\Domain\Customers\Preferences\Suppression\SuppressionSchema;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use Tests\TestCase;
use Throwable;

class CustomerSuppressionNativeAdmissionTest extends TestCase
{
    public function test_foreign_reserved_constraint_is_refused_before_any_owned_ddl(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual native schema-wide foreign-key namespace collision.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        foreach (array_reverse(SuppressionSchema::TABLES) as $table) {
            $this->assertSame(0, DB::table($table)->count());
            DB::unprepared('DROP TABLE `'.$table.'`');
        }
        DB::table('migrations')->where('migration', '2026_10_07_251000_customer_suppression')->delete();
        DB::unprepared('CREATE TABLE suppression_review_foreign_marker (id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED NULL, CONSTRAINT customer_suppression_intents_consent_event_id_fk FOREIGN KEY (user_id) REFERENCES users(id)) ENGINE=InnoDB');
        DB::table('suppression_review_foreign_marker')->insert(['id' => 9123, 'user_id' => null]);
        $pdo = DB::connection()->getPdo();
        $catalog = fn () => $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'customer_suppression_%' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
        $before = $catalog();
        $ddl = [];
        DB::listen(function (QueryExecuted $query) use (&$ddl) {
            if (preg_match('/\A(?:CREATE|ALTER|DROP)\b/i', $query->sql)) {
                $ddl[] = $query->sql;
            }
        });
        $error = null;
        try {
            (new SuppressionSchema)->up();
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $after = $catalog();
        file_put_contents('/tmp/va-suppression-native-constraint-snapshot.json', json_encode([
            'driver' => 'mysql', 'version' => $pdo->query('SELECT VERSION()')->fetchColumn(),
            'before' => $before, 'after' => $after, 'ddl' => $ddl,
            'refusal' => $error?->getMessage(), 'refusalClass' => $error ? $error::class : null,
            'foreignMarker' => $pdo->query('SELECT id,user_id FROM suppression_review_foreign_marker')->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->assertSame([['id' => 9123, 'user_id' => null]], $pdo->query('SELECT id,user_id FROM suppression_review_foreign_marker')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertInstanceOf(LogicException::class, $error, 'Refuse the foreign reserved name during admission.');
        $this->assertSame([], $ddl, 'Refuse before the first owned DDL.');
        $this->assertSame($before, $after, 'Preserve the actual catalog.');
    }
}
