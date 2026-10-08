<?php

namespace Tests\Feature\ProductionSuppression;

use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureShape;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProductionSuppressionFixtures;
use Tests\TestCase;

/**
 * Raw-SQL probes of the created_at shape in the four insert guards. The runtime validates every read with
 * ProductionFeatureShape::timestamp(), so a row whose created_at fails it would commit, fail every graph read and stay
 * unrepairable under the append-only guards. SQLite stores any text in a datetime column, and the lexical
 * "parent.created_at <= NEW.created_at" check admits 'zzzz'.
 */
class ProductionSuppressionTimestampGuardTest extends TestCase
{
    use ProductionSuppressionFixtures;

    private const VALID = '2026-10-07 12:00:00';

    /**
     * Refused on every driver. On MySQL the TIMESTAMP column refuses an unparsable or calendar-invalid string under strict
     * sql_mode, and the trigger refuses the zero date it becomes under a non-strict one. The values after 'zzzz' are chosen to
     * sort at or after VALID, so the lexical parent-ordering check cannot be what refuses them.
     */
    private const MALFORMED = [
        'non-date text' => 'zzzz',
        'calendar-invalid February 30th' => '2026-02-30 00:00:00',
        'calendar-invalid November 31st' => '2026-11-31 00:00:00',
        'month 13' => '2026-13-01 00:00:00',
        'zero date' => '0000-00-00 00:00:00',
    ];

    /**
     * Refused on SQLite, where the guard sees the raw text. MySQL coerces these to a valid TIMESTAMP before the trigger runs
     * (for example it reads the T as the date and time separator), so the trigger can only judge what was stored.
     */
    private const RAW_TEXT_ONLY = [
        'ISO T separator' => '2026-10-07T12:00:00',
        'missing seconds' => '2026-10-07 13:00',
        'trailing newline' => "2026-10-07 12:00:00\n",
        'leading space' => ' 2026-10-07 12:00:00',
        'fractional seconds' => '2026-10-07 12:00:00.5',
        // SQLite's datetime() round-trips an hour of 24 unchanged, so the pattern and the round trip alone would keep it.
        'hour 24' => '2026-10-07 24:20:59',
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_created_at_the_runtime_validates_must_be_a_real_timestamp_at_the_trigger(): void
    {
        Carbon::setTestNow(self::VALID);
        $this->suppressionSetup();
        $owner = $this->featureIdentity('consent_preferences', 'timestamp-guard@example.test');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());
        $event = DB::table('production_consent_events')->sole();
        $this->assertSame(self::VALID, $event->created_at);
        $hash = fn (string $seed) => hash('sha256', $seed);

        $malformed = self::MALFORMED + (DB::connection()->getDriverName() === 'sqlite' ? self::RAW_TEXT_ONLY : []);
        $this->assertTrue(ProductionFeatureShape::timestamp(self::VALID));
        foreach ($malformed as $label => $shape) {
            $this->assertFalse(ProductionFeatureShape::timestamp($shape), $label.' must fail the runtime check.');
        }

        // Arrow functions capture by value, so the parent ids live in an object the closures share.
        $ids = new \ArrayObject;
        $target = fn (string $at) => DB::table('production_suppression_targets')->insertGetId(['public_id' => (string) Str::uuid(), 'binding_id' => $event->binding_id, 'purpose' => 'email_marketing',
            'recipient_hmac' => $event->recipient_hmac, 'recipient_ciphertext' => 'synthetic', 'withdrawal_event_id' => $event->id, 'created_at' => $at]);
        $intent = fn (string $at) => DB::table('production_suppression_intents')->insertGetId(['public_id' => (string) Str::uuid(), 'target_id' => $ids['target'],
            'withdrawal_event_id' => $event->id, 'created_at' => $at]);
        $attempt = fn (string $at) => DB::table('production_suppression_attempts')->insertGetId(['public_id' => (string) Str::uuid(), 'target_id' => $ids['target'],
            'intent_id' => $ids['intent'], 'provider_hash' => $hash('provider'), 'provider_ciphertext' => 'synthetic', 'request_hash' => $hash('request'), 'created_at' => $at]);
        $confirmation = fn (string $at) => DB::table('production_suppression_confirmations')->insertGetId(['attempt_id' => $ids['attempt'], 'request_hash' => $hash('request'),
            'receipt_hash' => $hash('receipt'), 'receipt_ciphertext' => 'synthetic', 'created_at' => $at]);

        // Each table: every malformed value is refused and leaves no row; the valid control is then accepted, and the next
        // table's parent is that control row.
        foreach (['target' => [$target, 'production_suppression_targets'], 'intent' => [$intent, 'production_suppression_intents'],
            'attempt' => [$attempt, 'production_suppression_attempts'], 'confirmation' => [$confirmation, 'production_suppression_confirmations']] as $key => [$insert, $table]) {
            $this->refuseAll($insert, $table, $malformed);
            $ids[$key] = $insert(self::VALID);
            $this->assertSame(self::VALID, DB::table($table)->value('created_at'), $table.' keeps the accepted control exactly.');
            $this->assertSame(1, DB::table($table)->count(), $table);
        }
    }

    private function refuseAll(callable $insert, string $table, array $malformed): void
    {
        $count = DB::table($table)->count();
        foreach ($malformed as $label => $shape) {
            try {
                $insert($shape);
                $this->fail("$table accepted a created_at of $label.");
            } catch (QueryException $error) {
                // MySQL strict mode refuses before the trigger; either refusal leaves nothing behind.
                $this->assertTrue(str_contains($error->getMessage(), 'Retained production suppression refused') || DB::connection()->getDriverName() === 'mysql',
                    "$table $label was refused for another reason: ".$error->getMessage());
            }
        }
        $this->assertSame($count, DB::table($table)->count(), "$table kept a malformed row.");
    }
}
