<?php

namespace Tests\Feature\ProductionSuppression;

use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\Preferences\Suppression\SuppressionSchema;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionIntents;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionReceipt;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionRequest;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionSchema;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\ProductionSuppressionFixtures;
use Tests\Support\RecordingSuppressionProvider;
use Tests\TestCase;

class ProductionSuppressionJourneyTest extends TestCase
{
    use ProductionSuppressionFixtures;

    private const OWNER = 'suppression-owner@example.test';

    private RecordingSuppressionProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->suppressionSetup();
    }

    public function test_withdraw_attempt_committed_before_transport_unknown_without_resend_until_inspect_confirms(): void
    {
        $owner = $this->withdrawn();
        $intents = new ProductionSuppressionIntents($this->provider);

        $this->assertSame(['status' => 'unknown'], $intents->request($owner, 1));
        $this->assertCount(1, $this->provider->suppressed);
        $call = $this->provider->suppressed[0];
        $this->assertSame(self::OWNER, $call['recipient']);
        $this->assertSame([0, false, 1], [$call['transactionLevel'], $call['inTransaction'], $call['durableAttempt']], 'Commit, then I/O.');
        $rows = $this->suppressionRows();
        $this->assertSame([1, 1, 1, 0], array_map('count', array_values($rows)));
        $this->assertSame(DB::table('production_consent_events')->value('id'), $rows['production_suppression_targets'][0]['withdrawal_event_id']);

        // Ambiguous outcome: no blind resend from a repeated request or from reconciliation.
        $this->assertSame(['status' => 'unknown'], $intents->request($owner, 1));
        $this->assertSame(['status' => 'unknown'], $intents->reconcile($owner));
        $this->assertCount(1, $this->provider->suppressed);
        $this->assertCount(1, $this->provider->inspected);

        // Inspection receipts that are not exactly scoped to this request never confirm.
        foreach ([fn (ProductionSuppressionRequest $request) => new ProductionSuppressionReceipt($request->operationId(), hash('sha256', 'other'), $request->recipientHmac(), $request->providerHash(), 'SYNTHETIC-1', 'suppressed'),
            fn (ProductionSuppressionRequest $request) => new ProductionSuppressionReceipt($request->operationId(), $request->requestHash(), $request->recipientHmac(), $request->providerHash(), 'SYNTHETIC-1', 'pending'),
            fn (ProductionSuppressionRequest $request) => new ProductionSuppressionReceipt((string) Str::uuid(), $request->requestHash(), $request->recipientHmac(), $request->providerHash(), 'SYNTHETIC-1', 'suppressed'),
            fn () => throw new \RuntimeException('SYNTHETIC inspect failure')] as $answer) {
            $this->provider->answer = $answer;
            $this->assertSame(['status' => 'unknown'], $intents->reconcile($owner));
        }
        $this->assertSame(0, DB::table('production_suppression_confirmations')->count());

        $this->provider->answer = RecordingSuppressionProvider::positive(...);
        $this->assertSame(['status' => 'confirmed'], $intents->reconcile($owner));
        $this->assertSame(1, DB::table('production_suppression_confirmations')->count());
        $inspections = count($this->provider->inspected);
        $this->assertSame(['status' => 'confirmed'], $intents->reconcile($owner));
        $this->assertSame(['status' => 'confirmed'], $intents->status($owner));
        $this->assertSame(['status' => 'confirmed'], $intents->request($owner, 1));
        $this->assertCount($inspections, $this->provider->inspected, 'Nothing unknown remains to inspect.');
        $this->assertCount(1, $this->provider->suppressed);
        foreach ($this->provider->inspected as $inspection) {
            $this->assertSame([self::OWNER, $call['operation'], $call['request']], [$inspection['recipient'], $inspection['operation'], $inspection['request']]);
        }
    }

    public function test_later_grant_never_deletes_or_reverses_a_suppression(): void
    {
        $owner = $this->withdrawn();
        $intents = new ProductionSuppressionIntents($this->provider);
        $intents->request($owner, 1);
        $this->provider->answer = RecordingSuppressionProvider::positive(...);
        $this->assertSame(['status' => 'confirmed'], $intents->reconcile($owner));
        $before = $this->suppressionRows();

        $preferences = new ProductionConsentPreferences;
        $this->assertSame('granted', $preferences->change($owner, $this->productionGrant(1))['preferences']['purposes'][0]['status']);
        $this->assertSame($before, $this->suppressionRows());
        $this->assertSame(['status' => 'confirmed'], $intents->status($owner));
        // The retained withdrawal still selects the same intent; no new attempt, no transport.
        $this->assertSame(['status' => 'confirmed'], $intents->request($owner, 2));
        $this->assertSame($before, $this->suppressionRows());

        // A second explicit withdrawal records its own intent on the same target, still without a resend.
        $preferences->change($owner, $this->productionWithdraw(2));
        $this->assertSame(['status' => 'confirmed'], $intents->request($owner, 3));
        $after = $this->suppressionRows();
        $this->assertSame([1, 2, 1, 1], array_map('count', array_values($after)));
        $this->assertCount(1, $this->provider->suppressed);

        foreach (ProductionSuppressionSchema::TABLES as $table) {
            foreach (["UPDATE $table SET created_at=created_at", "DELETE FROM $table"] as $sql) {
                try {
                    DB::statement($sql);
                    $this->fail($sql.' must be refused by the retention guard.');
                } catch (QueryException $error) {
                    $this->assertStringContainsString('Retained production suppression refused', $error->getMessage());
                }
            }
        }
        $this->assertSame($after, $this->suppressionRows());
    }

    public function test_email_change_never_retargets_and_reconcile_inspects_the_authentic_captured_target(): void
    {
        $owner = $this->withdrawn();
        $intents = new ProductionSuppressionIntents($this->provider);
        $this->assertSame(['status' => 'unknown'], $intents->request($owner, 1));
        $target = (array) DB::table('production_suppression_targets')->sole();
        $capture = json_decode(Crypt::decryptString($target['recipient_ciphertext']), true);
        $this->assertSame(self::OWNER, $capture['email']);
        $this->provider->answer = RecordingSuppressionProvider::positive(...);

        // The production identity has no email-change flow: a changed account address fails the identity
        // floor before any 254 lookup, so nothing is inspected, rewritten or re-targeted to the new address.
        $original = DB::table('users')->where('id', $owner->principal()->userId)->value('email');
        DB::table('users')->where('id', $owner->principal()->userId)->update(['email' => 'suppression-changed@example.test']);
        foreach ([fn () => $intents->reconcile($owner), fn () => $intents->request($owner, 1)] as $call) {
            try {
                $call();
                $this->fail('A changed account address must not authorize suppression work.');
            } catch (IdentityException) {
                $this->assertSame([], $this->provider->inspected);
            }
        }
        $this->assertSame([$target], DB::table('production_suppression_targets')->get()->map(fn ($row) => (array) $row)->all());

        DB::table('users')->where('id', $owner->principal()->userId)->update(['email' => $original]);
        $this->assertSame(['status' => 'confirmed'], $intents->reconcile($owner));
        $this->assertCount(1, $this->provider->inspected);
        // Inspect and the single transport both used the captured target from the durable row.
        $this->assertSame([self::OWNER, self::OWNER], [$this->provider->inspected[0]['recipient'], $this->provider->suppressed[0]['recipient']]);
        $this->assertSame([$target], DB::table('production_suppression_targets')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_postcommit_authority_loss_after_the_durable_attempt_never_reaches_transport(): void
    {
        $owner = $this->withdrawn();
        $fired = false;
        Event::listen(TransactionCommitted::class, function () use ($owner, &$fired): void {
            if (! $fired && DB::table('production_suppression_attempts')->count() === 1) {
                $fired = true;
                DB::table('users')->where('id', $owner->principal()->userId)->update(['password' => Hash::make('SuppressionAuthorityLost456')]);
            }
        });
        try {
            (new ProductionSuppressionIntents($this->provider))->request($owner, 1);
            $this->fail('A failed postcommit proof is an unknown outcome, not a transport authorization.');
        } catch (ProductionFeatureException $error) {
            $this->assertSame(503, $error->status);
        }
        $this->assertTrue($fired);
        // The attempt may be durable; without proven authority nothing was sent and nothing will be resent blindly.
        $this->assertSame(1, DB::table('production_suppression_attempts')->count());
        $this->assertSame([], $this->provider->suppressed);
    }

    public function test_unbound_provider_records_a_pending_intent_without_any_attempt_or_transport(): void
    {
        $owner = $this->withdrawn();
        $this->assertSame(['status' => 'pending'], (new ProductionSuppressionIntents)->request($owner, 1));
        $this->assertSame(['status' => 'pending'], (new ProductionSuppressionIntents(new RecordingSuppressionProvider(hash('sha256', 'another reviewed binding'))))->request($owner, 1));
        $this->assertSame(['status' => 'pending'], (new ProductionSuppressionIntents)->reconcile($owner));
        $this->assertSame([1, 1, 0, 0], array_map('count', array_values($this->suppressionRows())));
        config(['production-suppression.provider' => null]);
        $this->assertSame(['status' => 'pending'], (new ProductionSuppressionIntents($this->provider))->request($owner, 1));
        $this->assertSame([], $this->provider->suppressed);
    }

    public function test_disabled_or_malformed_parent_refuses_before_any_lookup_or_write(): void
    {
        $owner = $this->withdrawn();
        foreach ([['enabled' => false, 'provider' => $this->providerBinding()], ['enabled' => 'yes', 'provider' => null],
            ['enabled' => true, 'provider' => ['adapter' => 'x']], ['enabled' => true, 'provider' => null, 'extra' => 1]] as $parent) {
            config(['production-suppression' => $parent]);
            foreach ([fn () => (new ProductionSuppressionIntents($this->provider))->request($owner, 1), fn () => (new ProductionSuppressionIntents($this->provider))->reconcile($owner)] as $call) {
                try {
                    $call();
                    $this->fail('Default-off or malformed suppression configuration must refuse.');
                } catch (ProductionFeatureException $error) {
                    $this->assertSame(503, $error->status);
                }
            }
        }
        $this->assertSame([0, 0, 0, 0], array_map('count', array_values($this->suppressionRows())));
        $this->assertSame([], $this->provider->suppressed);
    }

    public function test_legacy_251_history_never_becomes_production_lineage(): void
    {
        $legacy = CustomerFixtures::account(['email' => 'legacy-251-suppression@example.test']);
        ConsentFixtures::configure();
        (new CustomerConsentPreferences)->change($legacy['principal'], $legacy['user'], ConsentFixtures::withdraw());
        $legacyRows = [];
        foreach (SuppressionSchema::TABLES as $table) {
            $legacyRows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $this->assertCount(1, $legacyRows['customer_suppression_targets']);

        $owner = $this->featureIdentity('consent_preferences', self::OWNER);
        (new ProductionConsentPreferences)->initialize($owner);
        $intents = new ProductionSuppressionIntents($this->provider);
        $this->assertSame(['status' => 'not_requested'], $intents->status($owner));
        $this->assertSame(['status' => 'not_requested'], $intents->request($owner, 0));
        $this->assertSame([0, 0, 0, 0], array_map('count', array_values($this->suppressionRows())));

        (new ProductionConsentPreferences)->change($owner, $this->productionWithdraw());
        $this->assertSame(['status' => 'unknown'], $intents->request($owner, 1));
        $target = DB::table('production_suppression_targets')->sole();
        $this->assertSame(DB::table('production_account_feature_bindings')->where('feature', 'consent_preferences')->value('id'), $target->binding_id);
        $this->assertSame('withdrawn', DB::table('production_consent_events')->where('id', $target->withdrawal_event_id)->value('status'));
        foreach (SuppressionSchema::TABLES as $table) {
            $this->assertSame($legacyRows[$table], DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        }
    }

    private function withdrawn(): ProductionAccountFeatureIdentity
    {
        $owner = $this->featureIdentity('consent_preferences', self::OWNER);
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());

        return $owner;
    }
}
