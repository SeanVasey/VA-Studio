<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\DataRightsException;
use App\Domain\Customers\DataRights\Models\CustomerDataRequest;
use App\Domain\Customers\DataRights\Models\CustomerDataRequestEvent;
use App\Domain\Customers\DataRights\ProductionAccountClosure;
use App\Domain\Customers\DataRights\RetentionReport;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeaturePolicy;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Filament\Resources\CustomerDataRequestResource\Pages\ListCustomerDataRequests;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\Support\ProductionDataRightsFixtures;
use Tests\TestCase;

/**
 * BL-44 (FP-082, D-30 step 3b, DD-47 to DD-49): data rights and account closure for production-signed-in customers, on SQLite.
 * The race behavior of the same paths is in ProductionDataRightsConcurrencyTest (native MySQL).
 */
class ProductionDataRightsTest extends TestCase
{
    use ProductionCheckoutJourneyFixture;
    use ProductionDataRightsFixtures;

    private const RETENTION = ['policy_reference' => 'Synthetic retention schedule SR-1 (test only)',
        'financial_records_years' => 7, 'consent_records_years' => 3, 'correspondence_records_years' => 2, 'security_records_years' => 1,
        'evidence_records_years' => 7, 'abandoned_checkout_days' => 30, 'operational_records_days' => 14];

    /** Tables a closure must leave byte for byte (audit events only grow). */
    private const KEPT = ['production_identity_origins', 'production_identity_verifications', 'production_identity_challenges',
        'production_account_feature_bindings', 'production_consent_events', 'production_consent_states', 'production_checkout_orders',
        'production_checkout_reviews', 'production_checkout_exemption_bases', 'production_checkout_payments', 'orders', 'license_grants'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->identitySetup();
        $this->withoutVite();
        config(['customer.test_accounts_enabled' => false, 'customer-data-rights.enabled' => true, 'customer-data-rights.retention' => self::RETENTION,
            'production-account-features.enabled' => true, 'production-account-features.provenance' => IdentityPolicy::REHEARSAL,
            'production-account-features.versions' => ProductionAccountFeaturePolicy::VERSIONS,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.mounted' => true,
            'production_checkout.reconciliation_enabled' => true, 'production_checkout.provider_io_enabled' => true]);
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
    }

    /** The per-route throttles count every attempt, invalid ones included; tests that probe many bodies start each from zero. */
    private function resetLimits(): void
    {
        Cache::flush();
    }

    private function requestDeletion(array $customer): string
    {
        $this->signIn($customer);

        return $this->postJson('/account/data-requests/deletion', ['confirm' => true, 'acknowledge_download_forfeiture' => true])->assertCreated()->json('deletion.requestId');
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (self::KEPT as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    public function test_every_route_answers_404_for_a_production_customer_until_the_flag_and_the_production_identity_policy_both_admit_it(): void
    {
        $alice = $this->customer('alice-gate@example.test');
        $this->signIn($alice);
        $routes = fn () => [
            $this->getJson('/account/data-requests'),
            $this->postJson('/account/data-requests/export', ['confirm' => true]),
            $this->postJson('/account/data-requests/export/download', ['requestId' => '00000000-0000-4000-8000-000000000000', 'token' => str_repeat('a', 64)]),
            $this->postJson('/account/data-requests/deletion', ['confirm' => true, 'acknowledge_download_forfeiture' => true]),
        ];
        foreach ([[false, true, true], [true, false, true], ['true', true, true], [1, true, true]] as [$flag, $identity, $unused]) {
            config(['customer-data-rights.enabled' => $flag, 'production-customer-identity.enabled' => $identity]);
            $this->resetLimits();
            foreach ($routes() as $response) {
                $response->assertNotFound()->assertJsonPath('reason', 'not_enabled')->assertHeader('Cache-Control', 'no-store, private');
            }
        }
        // The provenance must also fit the environment: a production provenance is refused outside production, so the policy is off.
        config(['customer-data-rights.enabled' => true, 'production-customer-identity.enabled' => true,
            'production-customer-identity.provenance' => IdentityPolicy::PRODUCTION]);
        $this->resetLimits();
        foreach ($routes() as $response) {
            $response->assertNotFound();
        }
        foreach (['customer_data_requests', 'customer_data_request_events', 'customer_data_exports'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        config(['production-customer-identity.provenance' => IdentityPolicy::REHEARSAL]);
        $this->resetLimits();
        $this->getJson('/account/data-requests')->assertOk()->assertJsonPath('dataRequests.requests', []);
    }

    public function test_the_production_and_test_paths_never_widen_each_other(): void
    {
        $alice = $this->customer('alice-paths@example.test');
        // A production session while only the test customer policy is on: 404, never a fallback to the test path.
        config(['production-customer-identity.enabled' => false, 'customer.test_accounts_enabled' => true]);
        $this->signIn($alice);
        $this->getJson('/account/data-requests')->assertNotFound();
        // A test-account marker while only the production path is on: 404 as well.
        config(['production-customer-identity.enabled' => true, 'customer.test_accounts_enabled' => false]);
        $this->flushSession();
        $this->withSession(['_customer_access' => ['account_id' => 1, 'access_version' => 1, 'credential_stamp' => 'x']])->actingAs($alice['user'], 'customer');
        $this->getJson('/account/data-requests')->assertNotFound();
        // Both markers at once are never a customer.
        config(['customer.test_accounts_enabled' => true]);
        $this->withSession(['_customer_access' => ['account_id' => 1, 'access_version' => 1, 'credential_stamp' => 'x'],
            '_production_customer_identity' => ['binding_digest' => $alice['principal']->sessionBindingDigest()]])->actingAs($alice['user'], 'customer');
        $this->getJson('/account/data-requests')->assertForbidden()->assertJsonPath('reason', 'sign_in_required');
        $this->postJson('/account/data-requests/deletion', ['confirm' => true, 'acknowledge_download_forfeiture' => true])->assertForbidden();
        $this->assertDatabaseCount('customer_data_requests', 0);
    }

    public function test_guests_staff_forged_markers_and_stale_credentials_cannot_use_the_routes(): void
    {
        $alice = $this->customer('alice-auth@example.test');
        $this->postJson('/account/data-requests/export', ['confirm' => true])->assertForbidden();

        // Staff session, even with a copied marker: staff are never customers.
        $staff = $this->staff();
        $this->resetLimits();
        $this->withSession(['_production_customer_identity' => ['binding_digest' => $alice['principal']->sessionBindingDigest()]])->actingAs($staff, 'customer');
        $this->postJson('/account/data-requests/deletion', ['confirm' => true, 'acknowledge_download_forfeiture' => true])->assertForbidden();

        // Another customer's marker on this customer's session; a malformed marker; no marker at all.
        $bob = $this->customer('bob-auth@example.test');
        foreach ([['binding_digest' => $bob['principal']->sessionBindingDigest()], ['binding_digest' => str_repeat('0', 64)], ['binding_digest' => 7],
            ['binding_digest' => $alice['principal']->sessionBindingDigest(), 'extra' => 1], []] as $marker) {
            $this->resetLimits();
            $this->flushSession();
            $this->withSession(['_production_customer_identity' => $marker])->actingAs($alice['user'], 'customer');
            $this->postJson('/account/data-requests/export', ['confirm' => true])->assertForbidden();
        }
        $this->resetLimits();
        $this->flushSession();
        $this->actingAs($alice['user'], 'customer');
        $this->postJson('/account/data-requests/export', ['confirm' => true])->assertForbidden();

        // A credential change after sign-in (recovery) invalidates the old session's marker.
        $this->signIn($alice);
        DB::table('users')->where('id', $alice['user']->id)->update(['password' => Hash::make('Changed-after-sign-in-42!')]);
        $this->postJson('/account/data-requests/export', ['confirm' => true])->assertForbidden();
        $this->assertDatabaseCount('customer_data_requests', 0);
    }

    public function test_an_export_contains_only_the_requesters_own_records_and_no_request_field_can_choose_another_account(): void
    {
        $alice = $this->customer('alice-export@example.test');
        $bob = $this->customer('bob-export@example.test');
        $this->populate($alice, 'SYNTHETIC alice private playlist', consent: true);
        $this->populate($bob, 'SYNTHETIC bob private playlist', consent: true);
        $before = $this->snapshot();

        $this->signIn($alice);
        // The body is exactly {"confirm": true}: an account, user, owner or origin field is refused, whatever it names.
        foreach ([['confirm' => true, 'accountId' => $bob['principal']->accountId], ['confirm' => true, 'account' => $this->accountOf($bob)->public_id],
            ['confirm' => true, 'userId' => $bob['user']->id], ['confirm' => true, 'origin' => $bob['binding']['origin_id']], ['confirm' => 'true'], []] as $body) {
            $this->resetLimits();
            $this->postJson('/account/data-requests/export', $body)->assertStatus(422);
        }
        $this->assertDatabaseCount('customer_data_requests', 0);
        $this->resetLimits();
        $ready = $this->postJson('/account/data-requests/export', ['confirm' => true])->assertCreated()->json('export');
        $raw = DB::table('customer_data_exports')->sole();
        foreach (['alice-export@example.test', 'SYNTHETIC alice private playlist', $ready['downloadToken']] as $plain) {
            $this->assertStringNotContainsString($plain, $raw->ciphertext);
        }

        // Bob, signed in, cannot fetch Alice's export with her request id and token.
        $this->signIn($bob);
        $this->postJson('/account/data-requests/export/download', ['requestId' => $ready['requestId'], 'token' => $ready['downloadToken']])
            ->assertNotFound()->assertJsonPath('reason', 'export_not_found');
        $this->assertNotNull(DB::table('customer_data_exports')->sole()->ciphertext);
        $this->getJson('/account/data-requests')->assertOk()->assertJsonPath('dataRequests.requests', []);

        $this->signIn($alice);
        $response = $this->postJson('/account/data-requests/export/download', ['requestId' => $ready['requestId'], 'token' => $ready['downloadToken']])->assertOk();
        $document = json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(4, $document['exportSchema']);
        $this->assertSame($this->accountOf($alice)->public_id, $document['account']['id']);
        $this->assertSame('alice-export@example.test', $document['account']['email']);
        $this->assertSame(['listening_library', 'consent_preferences'], array_column($document['productionAccount']['bindings'], 'feature'));
        $this->assertSame(1, $document['productionAccount']['listeningLibrary']['version']);
        $this->assertSame(['email_marketing'], array_column($document['productionAccount']['consent'], 'purpose'));
        $json = $response->getContent();
        foreach (['bob-export@example.test', 'SYNTHETIC bob private playlist', $this->accountOf($bob)->public_id, $bob['binding']['origin_id']] as $foreign) {
            $this->assertStringNotContainsString($foreign, $json);
        }
        // Reading changed nothing that was retained.
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_deletion_request_replay_returns_the_open_request_and_a_closed_account_cannot_ask_again(): void
    {
        $alice = $this->customer('alice-replay@example.test');
        $this->signIn($alice);
        $first = $this->postJson('/account/data-requests/deletion', ['confirm' => true, 'acknowledge_download_forfeiture' => true])->assertCreated()->json('deletion');
        $replay = $this->postJson('/account/data-requests/deletion', ['confirm' => true, 'acknowledge_download_forfeiture' => true])->assertOk()->json('deletion');
        $this->assertSame([$first['requestId'], 'received', false, true], [$replay['requestId'], $replay['state'], $first['replayed'], $replay['replayed']]);
        $this->assertSame(1, CustomerDataRequest::where('kind', 'deletion')->count());
        $this->assertSame(1, CustomerDataRequestEvent::count());
        // Nothing was erased by asking.
        $this->assertTrue($this->accountOf($alice)->active);

        app(CustomerDataRights::class)->approveDeletion($first['requestId'], $this->staff(), 0);
        $this->postJson('/account/data-requests/deletion', ['confirm' => true, 'acknowledge_download_forfeiture' => true])->assertForbidden();
        $this->assertSame(1, CustomerDataRequest::where('kind', 'deletion')->count());
    }

    public function test_the_per_route_rate_limits_hold_for_a_production_customer(): void
    {
        $alice = $this->customer('alice-limit@example.test');
        $this->signIn($alice);
        $statuses = [];
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $this->postJson('/account/data-requests/export', ['confirm' => true])->getStatusCode();
        }
        $this->assertSame([201, 409, 409, 429], $statuses);
        $statuses = [];
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $this->postJson('/account/data-requests/deletion', ['confirm' => true, 'acknowledge_download_forfeiture' => true])->getStatusCode();
        }
        $this->assertSame([201, 200, 200, 429], $statuses);
        $this->assertSame(1, CustomerDataRequest::where('kind', 'deletion')->count());
    }

    public function test_approval_needs_enrolled_staff_the_retention_schedule_and_the_production_path(): void
    {
        $alice = $this->customer('alice-staff@example.test');
        $request = $this->requestDeletion($alice);
        $before = $this->snapshot();
        $rights = app(CustomerDataRights::class);

        foreach ([fn () => $this->staff(enrolled: false), fn () => $alice['user']->fresh()] as $actor) {
            try {
                $rights->approveDeletion($request, $actor(), 0);
                $this->fail('Approval needs an enrolled administrator.');
            } catch (AuthorizationException) {
                $this->assertTrue($this->accountOf($alice)->active);
            }
        }
        $staff = $this->staff();
        config(['customer-data-rights.retention.financial_records_years' => null]);
        try {
            $rights->approveDeletion($request, $staff, 0);
            $this->fail('An unset retention value refuses.');
        } catch (DataRightsException $error) {
            $this->assertSame('retention_policy_unset', $error->reason);
        }
        foreach ([['customer-data-rights.enabled', false], ['production-customer-identity.enabled', false]] as [$key, $value]) {
            config(['customer-data-rights.retention' => self::RETENTION, $key => $value]);
            try {
                $rights->approveDeletion($request, $staff, 0);
                $this->fail('A production account is closed only where the production path is on.');
            } catch (DataRightsException $error) {
                $this->assertSame([404, 'not_enabled'], [$error->status, $error->reason]);
            }
            config([$key => true]);
        }
        // The test customer policy alone never admits a production account.
        config(['production-customer-identity.enabled' => false, 'customer.test_accounts_enabled' => true]);
        try {
            $rights->approveDeletion($request, $staff, 0);
            $this->fail('The test customer policy must not close a production account.');
        } catch (DataRightsException $error) {
            $this->assertSame('not_enabled', $error->reason);
        }
        config(['production-customer-identity.enabled' => true, 'customer.test_accounts_enabled' => false]);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('received', CustomerDataRequest::sole()->state);
        $this->assertTrue($this->accountOf($alice)->active);
    }

    public function test_approval_closes_the_account_erases_the_library_contents_and_keeps_every_retained_record(): void
    {
        $alice = $this->customer('alice-close@example.test');
        $bob = $this->customer('bob-close@example.test');
        $this->populate($alice, 'SYNTHETIC alice private playlist', consent: true);
        $this->populate($bob, 'SYNTHETIC bob private playlist', consent: true);
        $request = $this->requestDeletion($alice);
        $export = $this->postJson('/account/data-requests/export', ['confirm' => true])->assertCreated()->json('export');
        $before = $this->snapshot();
        $libraries = DB::table('production_listening_libraries')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $aliceBinding = DB::table('production_account_feature_bindings')->where('account_id', $alice['principal']->accountId)->where('feature', 'listening_library')->value('id');
        $bobUser = (array) DB::table('users')->where('id', $bob['user']->id)->first();
        DB::table('sessions')->insert(['id' => 'synthetic-alice-session', 'user_id' => $alice['user']->id, 'payload' => 'x', 'last_activity' => time()]);
        $staff = $this->staff();

        $result = app(CustomerDataRights::class)->approveDeletion($request, $staff, 0);

        $this->assertSame('completed', $result['state']);
        $this->assertEquals(['customer_access' => 'withdrawn', 'pending_exports' => 'purged', 'listening_library' => 'none', 'sessions' => 'ended',
            'sign_in_profile' => 'pseudonymized', 'production_listening_library' => 'contents_erased', 'production_feature_bindings' => 'retained'], $result['actions']);

        // Erased: the library's contents, as the next version of the same row (the database admits nothing else), and the sign-in.
        $after = DB::table('production_listening_libraries')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertSame($libraries[1], $after[1], 'Another customer\'s library is untouched.');
        $mine = $after[0];
        $this->assertSame([(int) $aliceBinding, 2, $libraries[0]['created_at']], [(int) $mine['binding_id'], (int) $mine['version'], $mine['created_at']]);
        $state = json_decode(Crypt::decryptString($mine['payload']), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(['schema' => 1, 'accountId' => $alice['principal']->accountId, 'version' => 2, 'favorites' => [], 'playlists' => []], $state);
        $this->assertStringNotContainsString('SYNTHETIC alice private playlist', json_encode($mine));
        $account = $this->accountOf($alice);
        $this->assertFalse($account->active);
        $this->assertSame(2, $account->access_version);
        $erased = DB::table('users')->where('id', $alice['user']->id)->first();
        $this->assertSame(['Erased customer', 'erased+'.$account->public_id.'@erased.invalid', null], [$erased->name, $erased->email, $erased->email_verified_at]);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $alice['user']->id)->count());
        $this->assertSame($bobUser, (array) DB::table('users')->where('id', $bob['user']->id)->first());
        $this->assertTrue($this->accountOf($bob)->active);
        // The prepared export is purged, the account can no longer sign in, and nothing queued behind the approval can act.
        $this->assertNull(DB::table('customer_data_exports')->sole()->ciphertext);
        $this->signIn($alice);
        $this->postJson('/account/data-requests/export/download', ['requestId' => $export['requestId'], 'token' => $export['downloadToken']])->assertForbidden();
        $this->assertNull((new ProductionCustomerSessions)->authenticate('alice-close@example.test', 'MailboxPassword123'));
        $this->assertNull((new ProductionCustomerSessions)->authenticate('erased+'.$account->public_id.'@erased.invalid', 'MailboxPassword123'));

        // Kept, byte for byte: identity evidence, bindings, the consent ledger, checkout records, orders and grants.
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(2, DB::table('production_account_feature_bindings')->where('account_id', $alice['principal']->accountId)->count());
        $this->assertSame(2, DB::table('production_consent_events')->count());

        // The completion event lists every retained family with its retention, and the production facts.
        $event = CustomerDataRequestEvent::where('event', 'deletion_completed')->sole();
        $detail = json_decode($event->detail, true, 64, JSON_THROW_ON_ERROR);
        $retained = array_column($detail['retained'], null, 'record');
        $this->assertSame(array_keys(CustomerDataRights::RETAINED), array_column($detail['retained'], 'record'));
        $this->assertSame(['consent_records_years', 2, 1, 0], [$retained['production_account_feature_bindings']['retention'], $retained['production_account_feature_bindings']['count'],
            $retained['production_consent_events']['count'], $retained['production_suppression_records']['count']]);
        $this->assertSame('immutable_record_retained', $retained['production_account_feature_bindings']['reason']);
        $this->assertSame(['deletion_approval', 0], [$detail['consent_clock']['basis'], $detail['entitlements_retained']]);
        $this->assertNotNull($detail['consent_clock']['starts_at']);
        $this->assertEquals($detail['actions'], $result['actions']);
    }

    public function test_a_replayed_or_stale_approval_changes_nothing_further(): void
    {
        $alice = $this->customer('alice-replay2@example.test');
        $this->populate($alice, 'SYNTHETIC replay playlist');
        $request = $this->requestDeletion($alice);
        $staff = $this->staff();
        $rights = app(CustomerDataRights::class);
        try {
            $rights->approveDeletion($request, $staff, 5);
            $this->fail('A stale version refuses.');
        } catch (DataRightsException $error) {
            $this->assertSame('request_changed', $error->reason);
        }
        $this->assertSame(1, (int) DB::table('production_listening_libraries')->value('version'));
        $rights->approveDeletion($request, $staff, 0);
        $closed = [DB::table('production_listening_libraries')->first(), $this->accountOf($alice)->access_version, DB::table('audit_events')->count()];
        foreach ([0, 1] as $version) {
            try {
                $rights->approveDeletion($request, $staff, $version);
                $this->fail('A resolved request cannot be approved again.');
            } catch (DataRightsException $error) {
                $this->assertSame('request_changed', $error->reason);
            }
        }
        try {
            $rights->rejectDeletion($request, $staff, 1, 'Synthetic late rejection');
            $this->fail('A resolved request cannot be rejected.');
        } catch (DataRightsException $error) {
            $this->assertSame('request_changed', $error->reason);
        }
        $this->assertEquals($closed, [DB::table('production_listening_libraries')->first(), $this->accountOf($alice)->access_version, DB::table('audit_events')->count()]);
        $this->assertSame(1, CustomerDataRequestEvent::where('event', 'deletion_completed')->count());
    }

    public function test_a_rejection_changes_no_customer_data(): void
    {
        $alice = $this->customer('alice-reject@example.test');
        $this->populate($alice, 'SYNTHETIC reject playlist');
        $request = $this->requestDeletion($alice);
        $before = [$this->snapshot(), DB::table('production_listening_libraries')->get()->all(), (array) DB::table('users')->where('id', $alice['user']->id)->first()];
        app(CustomerDataRights::class)->rejectDeletion($request, $this->staff(), 0, 'Synthetic: identity could not be confirmed');
        $this->assertEquals($before, [$this->snapshot(), DB::table('production_listening_libraries')->get()->all(), (array) DB::table('users')->where('id', $alice['user']->id)->first()]);
        $this->assertTrue($this->accountOf($alice)->active);
        $this->signIn($alice);
        $this->getJson('/account/data-requests')->assertOk()->assertJsonPath('dataRequests.requests.0.state', 'rejected');
    }

    public function test_approval_refuses_while_a_checkout_session_may_still_complete_and_succeeds_once_it_settled(): void
    {
        $f = $this->payable();
        $buyer = $f['buyer'];
        $request = $this->requestDeletion($buyer);
        $rights = app(CustomerDataRights::class);
        $staff = $this->staff();
        // An accepted order with no provider session in flight is a financial record, not open commerce.
        $f['hosted']->initiate($buyer['principal'], $buyer['user'], $f['order']['orderId']);
        $before = $this->snapshot();
        try {
            $rights->approveDeletion($request, $staff, 0);
            $this->fail('An unpaid session that may still complete holds the closure.');
        } catch (DataRightsException $error) {
            $this->assertSame([409, 'open_commerce'], [$error->status, $error->reason]);
        }
        $this->assertTrue($this->accountOf($buyer)->active);
        $this->assertSame('received', CustomerDataRequest::sole()->state);
        $this->assertSame($before, $this->snapshot());

        // The payment is observed: the order is a retained financial record and the closure goes through, keeping it.
        $f['gateway']->paid = true;
        $f['hosted']->reconcile($buyer['principal'], $buyer['user'], $f['order']['orderId'], 'cs_test_SYNTHETIC');
        $this->assertSame(1, DB::table('production_checkout_payments')->count());
        $paid = $this->snapshot();
        $result = $rights->approveDeletion($request, $staff, 0);
        $this->assertSame('withdrawn', $result['actions']['customer_access']);
        $this->assertSame($paid, $this->snapshot());
        $detail = json_decode(CustomerDataRequestEvent::where('event', 'deletion_completed')->sole()->detail, true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(['held_with_order', null], [$detail['consent_clock']['basis'], $detail['consent_clock']['starts_at']]);
    }

    public function test_the_consent_clock_starts_at_deletion_approval_only_for_an_account_with_no_retained_order(): void
    {
        $f = $this->payable();
        $withOrder = $f['buyer'];
        $withoutOrder = $this->customer('alice-clock@example.test');
        $stillOpen = $this->customer('bob-clock@example.test');
        $rejected = $this->customer('carol-clock@example.test');
        foreach ([$withOrder, $withoutOrder, $stillOpen, $rejected] as $customer) {
            $this->populate($customer, 'SYNTHETIC clock playlist', consent: true);
        }
        $staff = $this->staff();
        foreach ([$withOrder, $withoutOrder] as $customer) {
            app(CustomerDataRights::class)->approveDeletion($this->requestDeletion($customer), $staff, 0);
        }
        // A request that was only made, or was rejected (which also sets `resolved_at`), starts no clock.
        $this->requestDeletion($stillOpen);
        app(CustomerDataRights::class)->rejectDeletion($this->requestDeletion($rejected), $staff, 0, 'Synthetic: identity could not be confirmed');

        $past = function (): array {
            $classes = array_column(app(RetentionReport::class)->build(now()->toImmutable())['classes'], null, 'class');

            return array_column($classes['consent']['tables'], null, 'table');
        };
        $approved = now();
        $this->travelTo($approved->copy()->addYears(3)->subDay());
        $this->assertSame(0, $past()['production_account_feature_bindings']['past_period'], 'The period runs from approval, so it has not yet run.');
        $this->travelTo($approved->copy()->addYears(3)->addDay());
        $report = $past();
        // Two bindings and one consent event per account; only the account without a retained order is past, and its rows follow.
        $this->assertSame(2, $report['production_account_feature_bindings']['past_period']);
        $this->assertSame(1, $report['production_consent_events']['past_period']);
        $this->assertSame(1, $report['production_consent_states']['past_period']);
        $this->assertSame(['production_account_feature_bindings'], [$report['production_consent_events']['follows']]);
        // The account whose order is still retained stays held, and so does the open request's account: 6 of the 8 bindings are not past.
        $this->assertSame(8, DB::table('production_account_feature_bindings')->count());
    }

    public function test_a_principal_minted_before_the_closure_cannot_act_after_it(): void
    {
        $alice = $this->customer('alice-stale@example.test');
        $request = $this->requestDeletion($alice);
        app(CustomerDataRights::class)->approveDeletion($request, $this->staff(), 0);
        $rights = app(CustomerDataRights::class);
        $before = [DB::table('customer_data_requests')->count(), DB::table('customer_data_exports')->count(), DB::table('audit_events')->count()];
        // The principal and actor objects still exist (a command that was queued behind the approval holds exactly these). The locked
        // re-read of the identity evidence is what refuses them; the objects themselves prove nothing.
        foreach ([fn () => $rights->requestProductionExport($alice['principal'], $alice['user']), fn () => $rights->requestProductionDeletion($alice['principal'], $alice['user'], true),
            fn () => $rights->listProduction($alice['principal'], $alice['user']),
            fn () => $rights->downloadProduction($alice['principal'], $alice['user'], '00000000-0000-4000-8000-000000000000', str_repeat('a', 64))] as $call) {
            try {
                $call();
                $this->fail('A principal minted before the closure acted after it.');
            } catch (IdentityException|DataRightsException $error) {
                $this->assertContains($error::class, [IdentityException::class, DataRightsException::class]);
            }
        }
        $this->assertSame($before, [DB::table('customer_data_requests')->count(), DB::table('customer_data_exports')->count(), DB::table('audit_events')->count()]);
    }

    public function test_the_owner_comes_from_the_production_origin_never_from_the_request(): void
    {
        $alice = $this->customer('alice-origin@example.test');
        $bob = $this->customer('bob-origin@example.test');
        // A principal minted for Alice cannot be used with Bob's user, however the call is composed.
        try {
            app(CustomerDataRights::class)->requestProductionExport($alice['principal'], $bob['user']);
            $this->fail('A principal and a different actor never match.');
        } catch (IdentityException) {
            $this->assertDatabaseCount('customer_data_requests', 0);
        }
        // No query string or header can name an account: the middleware refuses the query and the controller ignores the rest.
        $this->signIn($alice);
        $this->getJson('/account/data-requests?accountId='.$bob['principal']->accountId)->assertStatus(422);
        $this->withHeaders(['X-Account-Id' => (string) $bob['principal']->accountId, 'X-Owner-Key' => str_repeat('b', 64)])
            ->postJson('/account/data-requests/deletion', ['confirm' => true, 'acknowledge_download_forfeiture' => true])->assertCreated();
        $this->assertSame([$alice['principal']->accountId], CustomerDataRequest::pluck('customer_account_id')->all());
    }

    public static function badClosureBodies(): array
    {
        return [
            'confirm only' => ['{"confirm":true}'],
            'acknowledgment missing' => ['{"confirm":true,"acknowledge_download_forfeiture":null}'],
            'acknowledgment false' => ['{"confirm":true,"acknowledge_download_forfeiture":false}'],
            'acknowledgment string' => ['{"confirm":true,"acknowledge_download_forfeiture":"true"}'],
            'acknowledgment only' => ['{"acknowledge_download_forfeiture":true}'],
            'confirm false' => ['{"confirm":false,"acknowledge_download_forfeiture":true}'],
            'extra key' => ['{"confirm":true,"acknowledge_download_forfeiture":true,"note":"x"}'],
            'duplicate key' => ['{"confirm":true,"acknowledge_download_forfeiture":true,"acknowledge_download_forfeiture":true}'],
        ];
    }

    #[DataProvider('badClosureBodies')]
    public function test_a_production_closure_request_needs_exactly_confirm_and_the_download_forfeiture_acknowledgment(string $body): void
    {
        $alice = $this->customer('alice-ack-bad@example.test');
        $this->signIn($alice);
        $this->call('POST', '/account/data-requests/deletion', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)
            ->assertStatus(422)->assertJsonPath('reason', 'invalid_request');
        $this->assertDatabaseCount('customer_data_requests', 0);
        $this->assertDatabaseCount('customer_data_request_events', 0);
    }

    public function test_the_acknowledgment_is_sealed_into_the_request_and_shown_to_staff_and_the_service_refuses_a_false_one(): void
    {
        $alice = $this->customer('alice-ack@example.test');
        $request = $this->requestDeletion($alice);
        $row = CustomerDataRequest::where('public_id', $request)->sole();
        $event = json_decode(CustomerDataRequestEvent::where('request_id', $row->id)->where('event', 'requested')->sole()->detail, true, 64, JSON_THROW_ON_ERROR);
        $this->assertEquals(['version' => 'closure-download-forfeiture-v1', 'at' => now()->utc()->toIso8601ZuluString()], $event['download_forfeiture_acknowledgment']);
        $this->assertEquals($event['download_forfeiture_acknowledgment'], CustomerDataRights::closureAcknowledgment($row));
        $this->assertSame('closure-download-forfeiture-v1', json_decode((string) DB::table('audit_events')->where('action', 'customer.data_deletion.requested')->value('context'), true)['download_forfeiture_acknowledgment']);

        Livewire::actingAs($this->staff())->test(ListCustomerDataRequests::class)
            ->assertTableColumnStateSet('closure_acknowledgment', 'closure-download-forfeiture-v1 at '.now()->utc()->toIso8601ZuluString(), $row);

        $bob = $this->customer('bob-ack@example.test');
        try {
            app(CustomerDataRights::class)->requestProductionDeletion($bob['principal'], $bob['user'], false);
            $this->fail('The service refuses a closure request without the acknowledgment.');
        } catch (DataRightsException $error) {
            $this->assertSame([422, 'invalid_request'], [$error->status, $error->reason]);
        }
        $this->assertSame(1, CustomerDataRequest::count());
    }

    public function test_approval_refuses_a_production_closure_request_that_lacks_the_acknowledgment(): void
    {
        $alice = $this->customer('alice-noack@example.test');
        $this->populate($alice, 'SYNTHETIC noack playlist');
        $at = now()->utc();
        // A request opened before the acknowledgment existed: its sealed `requested` event carries only the kind.
        $row = CustomerDataRequest::create(['public_id' => (string) Str::uuid(), 'customer_account_id' => $alice['principal']->accountId, 'kind' => 'deletion',
            'state' => 'received', 'open_slot' => 1, 'version' => 0, 'last_event_sequence' => 0, 'created_at' => $at, 'updated_at' => $at]);
        $detail = CanonicalJson::encode(['kind' => 'deletion']);
        CustomerDataRequestEvent::create(['request_id' => $row->id, 'sequence' => 1, 'event' => 'requested', 'actor_user_id' => null,
            'detail' => $detail, 'detail_hash' => hash('sha256', $detail), 'created_at' => $at]);
        $row->forceFill(['last_event_sequence' => 1])->save();
        $this->assertNull(CustomerDataRights::closureAcknowledgment($row->fresh()));
        $before = [$this->snapshot(), DB::table('production_listening_libraries')->get()->all()];

        try {
            app(CustomerDataRights::class)->approveDeletion($row->public_id, $this->staff(), 0);
            $this->fail('An unacknowledged production closure cannot be approved.');
        } catch (DataRightsException $error) {
            $this->assertSame([409, 'acknowledgment_missing'], [$error->status, $error->reason]);
        }
        $this->assertEquals($before, [$this->snapshot(), DB::table('production_listening_libraries')->get()->all()]);
        $this->assertTrue($this->accountOf($alice)->active);
        $this->assertSame('received', $row->fresh()->state);
    }

    /** @return array{0: int, 1: int, 2: int} order, request and binding ids of a synthetic V2 session row set for an origin */
    private function taxRows(string $origin, string $tag, string $created, string $expires): array
    {
        $common = fn (string $public): array => ['public_id' => $public, 'created_at' => $created, 'payload_ciphertext' => 'synthetic', 'payload_hash' => str_repeat('a', 64),
            'canonicalization_version' => CanonicalJson::VERSION];
        $order = DB::table('production_tax_checkout_orders')->insertGetId($common((string) Str::uuid()) + ['buyer_origin_id' => $origin, 'candidate_id' => 1,
            'request_key' => hash('sha256', 'k'.$tag), 'request_hash' => hash('sha256', 'h'.$tag), 'currency' => 'USD', 'subtotal_minor' => 1000, 'line_count' => 1]);
        $public = (string) Str::uuid();
        $request = DB::table('production_tax_checkout_requests')->insertGetId($common($public) + ['order_id' => $order, 'account_id' => 'acct_SYNTHETIC', 'funds_mode' => 'test',
            'idempotency_key' => 'va-production-tax-checkout-v2-'.$public, 'currency' => 'USD', 'subtotal_minor' => 1000, 'tax_behavior' => 'exclusive',
            'maximum_rate_bps' => 2500, 'provider_expires_at' => $expires]);
        $binding = DB::table('production_tax_checkout_bindings')->insertGetId($common((string) Str::uuid()) + ['request_id' => $request, 'account_id' => 'acct_SYNTHETIC',
            'funds_mode' => 'test', 'provider_session_id' => 'cs_test_SYNTHETIC'.$tag]);

        return [$order, $request, $binding];
    }

    public function test_an_unpaid_v2_tax_checkout_session_holds_the_closure_until_it_expired_or_was_paid(): void
    {
        $iso = fn (CarbonImmutable $t): string => $t->utc()->format('Y-m-d\TH:i:s\Z');
        $now = CarbonImmutable::now('UTC');
        $closure = new ProductionAccountClosure;

        $open = $this->customer('alice-tax-open@example.test');
        $this->assertSame([], $closure->holds($this->accountOf($open)), 'No V2 order at all.');
        $this->taxRows($open['binding']['origin_id'], 'o', $iso($now->subMinutes(10)), $iso($now->addMinutes(20)));
        $this->assertSame(['unpaid_tax_checkout_session'], $closure->holds($this->accountOf($open)), 'The provider session is still open.');

        // Expired a minute ago: still inside the reconcile grace, so a late payment event can still be recorded.
        $grace = $this->customer('alice-tax-grace@example.test');
        $this->taxRows($grace['binding']['origin_id'], 'g', $iso($now->subHour()), $iso($now->subMinute()));
        $this->assertSame(['unpaid_tax_checkout_session'], $closure->holds($this->accountOf($grace)));

        // Expired past the grace and never paid: abandoned, no hold.
        $expired = $this->customer('alice-tax-expired@example.test');
        $this->taxRows($expired['binding']['origin_id'], 'e', $iso($now->subHours(3)), $iso($now->subSeconds(ProductionAccountClosure::TAX_SESSION_RECONCILE_GRACE_SECONDS + 60)));
        $this->assertSame([], $closure->holds($this->accountOf($expired)));

        // A reviewed (paid) session never holds, even though its provider expiry has not passed.
        $paid = $this->customer('alice-tax-paid@example.test');
        [$order, $request, $binding] = $this->taxRows($paid['binding']['origin_id'], 'p', $iso($now->subMinutes(10)), $iso($now->addMinutes(20)));
        $this->assertSame(['unpaid_tax_checkout_session'], $closure->holds($this->accountOf($paid)), 'Unpaid until reviewed.');
        DB::table('production_tax_checkout_reviewed_sessions')->insert(['public_id' => (string) Str::uuid(), 'created_at' => $iso($now->subMinutes(5)), 'payload_ciphertext' => 'synthetic',
            'payload_hash' => str_repeat('a', 64), 'canonicalization_version' => CanonicalJson::VERSION, 'binding_id' => $binding, 'request_id' => $request, 'order_id' => $order,
            'account_id' => 'acct_SYNTHETIC', 'funds_mode' => 'test', 'provider_session_id' => 'cs_test_SYNTHETICp', 'provider_payment_id' => 'pi_SYNTHETIC', 'currency' => 'USD',
            'tax_behavior' => 'exclusive', 'amount_subtotal_minor' => 1000, 'amount_tax_minor' => 100, 'amount_total_minor' => 1100, 'observed_at' => $iso($now->subMinutes(6))]);
        $this->assertSame([], $closure->holds($this->accountOf($paid)));
    }
}
