<?php

namespace Tests\Feature\ProductionSuppression;

use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProductionSuppressionFixtures;
use Tests\TestCase;

/**
 * Raw-SQL probes of the public_id shape in the insert guards. The runtime validates every read with Str::isUuid(),
 * so a row whose id fails it would commit, fail every graph read and stay unrepairable under the append-only guards.
 */
class ProductionSuppressionPublicIdGuardTest extends TestCase
{
    use ProductionSuppressionFixtures;

    private int $targetId;

    private int $intentId;

    /** 36-character values the old length-only guard accepted. */
    private const SHAPES = [
        'non-hex characters in the right positions' => 'zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz',
        'no hyphens' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'misplaced hyphen' => 'aaaaaaaaa-aaa-aaaa-aaaa-aaaaaaaaaaaa',
        'trailing newline' => "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa\n",
        'surrounding spaces' => ' aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa ',
    ];

    public function test_every_public_id_the_runtime_validates_must_be_a_uuid_at_the_trigger(): void
    {
        $this->suppressionSetup();
        $owner = $this->featureIdentity('consent_preferences', 'public-id-guard@example.test');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());
        $event = DB::table('production_consent_events')->sole();
        $at = $event->created_at;
        $hash = fn (string $seed) => hash('sha256', $seed);

        $upper = strtoupper((string) Str::uuid());
        $lower = (string) Str::uuid();
        $this->assertTrue(Str::isUuid($upper) && Str::isUuid($lower), 'The accepted shapes are exactly what Str::isUuid() accepts.');
        foreach (self::SHAPES as $label => $shape) {
            $this->assertSame(36, strlen($shape), $label);
            $this->assertFalse(Str::isUuid($shape), $label.' must fail the runtime check.');
        }

        $target = fn (string $id) => DB::table('production_suppression_targets')->insertGetId(['public_id' => $id, 'binding_id' => $event->binding_id, 'purpose' => 'email_marketing',
            'recipient_hmac' => $event->recipient_hmac, 'recipient_ciphertext' => 'synthetic', 'withdrawal_event_id' => $event->id, 'created_at' => $at]);
        $intent = fn (string $id) => DB::table('production_suppression_intents')->insertGetId(['public_id' => $id, 'target_id' => $this->targetId,
            'withdrawal_event_id' => $event->id, 'created_at' => $at]);
        $attempt = fn (string $id) => DB::table('production_suppression_attempts')->insertGetId(['public_id' => $id, 'target_id' => $this->targetId,
            'intent_id' => $this->intentId, 'provider_hash' => $hash('provider'), 'provider_ciphertext' => 'synthetic', 'request_hash' => $hash('request'), 'created_at' => $at]);

        // Targets: an uppercase UUID is what Str::isUuid() accepts, so it is accepted.
        $this->refuseAll($target, 'production_suppression_targets');
        $this->targetId = $target($upper);
        $this->assertSame($upper, DB::table('production_suppression_targets')->value('public_id'));

        // Intents: a lowercase UUID.
        $this->refuseAll($intent, 'production_suppression_intents');
        $this->intentId = $intent($lower);
        $this->assertSame($lower, DB::table('production_suppression_intents')->value('public_id'));

        // Attempts: an uppercase UUID again, after every malformed shape failed first.
        $this->refuseAll($attempt, 'production_suppression_attempts');
        $attempt(strtoupper((string) Str::uuid()));
        $this->assertSame(1, DB::table('production_suppression_attempts')->count());
    }

    private function refuseAll(callable $insert, string $table): void
    {
        $count = DB::table($table)->count();
        foreach (self::SHAPES as $label => $shape) {
            try {
                $insert($shape);
                $this->fail("$table accepted a public id with $label.");
            } catch (QueryException $error) {
                $this->assertStringContainsString('Retained production suppression refused', $error->getMessage(), "$table $label");
            }
        }
        $this->assertSame($count, DB::table($table)->count(), "$table kept a malformed row.");
    }
}
