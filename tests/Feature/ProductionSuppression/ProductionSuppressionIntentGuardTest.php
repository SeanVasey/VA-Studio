<?php

namespace Tests\Feature\ProductionSuppression;

use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionIntents;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProductionSuppressionFixtures;
use Tests\TestCase;

/** Raw-SQL probes of the intents insert guard: a writer outside the runtime must not be able to commit an unreadable row. */
class ProductionSuppressionIntentGuardTest extends TestCase
{
    use ProductionSuppressionFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_intent_is_refused_before_its_withdrawal_event_and_accepted_at_the_same_instant(): void
    {
        $provider = $this->suppressionSetup();
        $owner = $this->featureIdentity('consent_preferences', 'intent-guard@example.test');
        $base = Carbon::now()->utc()->startOfSecond();
        $at = fn (int $seconds) => Carbon::setTestNow($base->copy()->addSeconds($seconds));

        // The runtime records the first withdrawal, its target and its intent at +0s.
        $at(0);
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());
        $this->assertSame(['status' => 'unknown'], (new ProductionSuppressionIntents($provider))->request($owner, 1));
        $target = DB::table('production_suppression_targets')->sole();
        $this->assertSame(1, DB::table('production_suppression_intents')->count());

        // A later explicit withdrawal on the same binding and recipient is a distinct event, at +2s.
        $at(1);
        $preferences->change($owner, $this->productionGrant(1));
        $at(2);
        $preferences->change($owner, $this->productionWithdraw(2));
        $second = DB::table('production_consent_events')->where('status', 'withdrawn')->orderByDesc('revision')->first();
        $this->assertSame($base->copy()->addSeconds(2)->format('Y-m-d H:i:s'), $second->created_at);
        $this->assertGreaterThan($target->withdrawal_event_id, $second->id);

        $intent = fn (string $time) => DB::table('production_suppression_intents')->insertGetId(['public_id' => (string) Str::uuid(), 'target_id' => $target->id,
            'withdrawal_event_id' => $second->id, 'created_at' => $time]);

        // The target already exists and predates the intent, so only the withdrawal-event timestamp can make this row invalid.
        $before = $base->copy()->addSeconds(1)->format('Y-m-d H:i:s');
        $this->assertGreaterThanOrEqual($target->created_at, $before);
        $this->assertLessThan($second->created_at, $before);
        try {
            $intent($before);
            $this->fail('An intent timestamped before its withdrawal event was accepted.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Retained production suppression refused', $error->getMessage());
        }
        $this->assertSame(1, DB::table('production_suppression_intents')->count(), 'A refused intent leaves no row behind.');

        // Positive control: the same instant as the withdrawal is accepted.
        $this->assertGreaterThan(0, $intent($second->created_at));
        $this->assertSame(2, DB::table('production_suppression_intents')->count());
    }
}
